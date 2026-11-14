<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Detection\RepositoryReader;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSSHTransport;

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

/**
 * @return array{User, Server}
 */
function stackEndpointServer(array $server = []): array
{
    $team = Team::factory()->owned()->create();

    return [$team->owner->fresh(), Server::factory()->for($team)->create(['name' => 'web-01', 'status' => ServerStatus::Active, ...$server])];
}

function stackEndpointAnswer(string $fixture, string $installed = ''): FakeSSHTransport
{
    $probe = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => base_path('tests/Fixtures/repositories/' . $fixture)])->input(app(RepositoryProbe::class)->script())->run('bash -s')->output();
    $transport = (new FakeSSHTransport)->respondTo(RepositoryReader::MARKER, "@@commit 9f3c2e1a4b5c6d7e8f9012345678901234567890\n" . $installed . $probe);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    return $transport;
}

/**
 * @param  array<string, string>  $query
 */
function stackEndpointGet(User $user, Server $server, array $query): TestResponse
{
    return test()->actingAs($user)->getJson(route('servers.repository-stack', [$server, ...$query]));
}

test('the form\'s detection reads a public repository and fills in a ready Laravel stack', function () {
    [$owner, $server] = stackEndpointServer();
    stackEndpointAnswer('laravel-13');

    stackEndpointGet($owner, $server, ['repository_url' => 'https://github.com/laravel/laravel.git', 'repository_branch' => '13.x'])
        ->assertOk()
        ->assertJsonPath('state', 'read')
        ->assertJsonPath('verdict', 'ready')
        ->assertJsonPath('fields.type', 'php')
        ->assertJsonPath('fields.framework', 'laravel')
        ->assertJsonPath('fields.web_directory', '/public')
        ->assertJsonPath('commit', '9f3c2e1a4b5c6d7e8f9012345678901234567890')
        ->assertJson(fn($json) => $json->whereType('id', 'string')->etc());
});

test('the version shown is the one the deploy would pick from what the server already has', function () {
    [$owner, $server] = stackEndpointServer();
    stackEndpointAnswer('laravel-locked-84', "@@installed php 8.4.11\n@@installed php 8.5.10\n");

    stackEndpointGet($owner, $server, ['repository_url' => 'https://github.com/acme/shop.git', 'repository_branch' => 'main'])
        ->assertJsonPath('fields.version', '8.4.11');
});

test('a git@ url is never read before the app exists', function () {
    [$owner, $server] = stackEndpointServer();
    $transport = stackEndpointAnswer('laravel-13');

    stackEndpointGet($owner, $server, ['repository_url' => 'git@github.com:acme/shop.git', 'repository_branch' => 'main'])
        ->assertOk()
        ->assertJsonPath('state', 'needs_key');

    expect($transport->commands())->toBe([]);
});

test('detection waits for a server that is not ready', function () {
    [$owner, $server] = stackEndpointServer(['status' => ServerStatus::Creating]);
    $transport = stackEndpointAnswer('laravel-13');

    stackEndpointGet($owner, $server, ['repository_url' => 'https://github.com/laravel/laravel.git', 'repository_branch' => '13.x'])
        ->assertJsonPath('state', 'server_not_ready');

    expect($transport->commands())->toBe([]);
});

test('a read git refused says what to do and that it is settled', function () {
    [$owner, $server] = stackEndpointServer();
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo(RepositoryReader::MARKER, "fatal: could not read Username for 'https://github.com': terminal prompts disabled\n", 71));

    stackEndpointGet($owner, $server, ['repository_url' => 'https://github.com/acme/private.git', 'repository_branch' => 'main'])
        ->assertJsonPath('state', 'failed')
        ->assertJsonPath('definitive', true)
        ->assertJson(fn($json) => $json->where('failure', fn(string $failure): bool => str_contains($failure, 'git@'))->etc());
});

test('another team\'s server is refused', function () {
    [, $server] = stackEndpointServer();
    [$stranger] = stackEndpointServer();

    stackEndpointGet($stranger, $server, ['repository_url' => 'https://github.com/laravel/laravel.git', 'repository_branch' => '13.x'])->assertForbidden();
});

test('someone who cannot open the Add an app form cannot read repositories through it either', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'db']);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value, 'sees_everything' => false]);
    $form = test()->actingAs($member->fresh())->get(route('servers.applications.create', $server))->status();

    expect($form)->toBeGreaterThanOrEqual(400);
    stackEndpointGet($member->fresh(), $server, ['repository_url' => 'https://github.com/laravel/laravel.git', 'repository_branch' => '13.x'])->assertStatus($form);
});

test('a person can read twenty repositories a minute through the form', function () {
    [$owner, $server] = stackEndpointServer();
    stackEndpointAnswer('static-html');

    foreach (range(1, 20) as $attempt) {
        stackEndpointGet($owner, $server, ['repository_url' => 'https://github.com/acme/site.git', 'repository_branch' => 'main'])->assertOk();
    }

    stackEndpointGet($owner, $server, ['repository_url' => 'https://github.com/acme/site.git', 'repository_branch' => 'main'])->assertTooManyRequests();
});

test('the form is told which folders hold an app, and a root with none of its own asks for one', function () {
    [$owner, $server] = stackEndpointServer();
    stackEndpointAnswer('empty-repository', "@@manifest apps/api/Gemfile\n@@manifest apps/web/package.json\n");

    stackEndpointGet($owner, $server, ['repository_url' => 'https://github.com/acme/mono.git', 'repository_branch' => 'main'])
        ->assertOk()
        ->assertJsonPath('folders', ['apps/api', 'apps/web'])
        ->assertJsonPath('unsettled.type', "There is no composer.json, Gemfile, requirements.txt, pyproject.toml, package.json, deno.json or index.html at the repository's root, so Coresmith can't tell what the app runs on. Pick the folder the app is in.");
});

test('a folder is read on its own, and its messages name it', function () {
    [$owner, $server] = stackEndpointServer();
    stackEndpointAnswer('empty-repository', "@@manifest apps/web/package.json\n");

    stackEndpointGet($owner, $server, ['repository_url' => 'https://github.com/acme/mono.git', 'repository_branch' => 'main', 'directory' => 'apps/web'])
        ->assertOk()
        ->assertJsonPath('folders', ['apps/web'])
        ->assertJsonPath('unsettled.type', "There is no composer.json, Gemfile, requirements.txt, pyproject.toml, package.json, deno.json or index.html in apps/web, so Coresmith can't tell what the app runs on.");
});
