<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\ApplicationType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\DeployGuard;
use App\Services\Detection\RepositoryReader;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    Queue::fake();
});

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

/**
 * @param  array<string, mixed>  $attributes
 * @return array{User, Application}
 */
function repositoryCheckApp(array $attributes = []): array
{
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-01', 'status' => ServerStatus::Active]);
    $application = Application::factory()->for($team)->for($server)->create([
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => null,
        'repository_url' => 'https://github.com/acme/shop.git',
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'repository_check_pending' => true,
        'repository_checked_at' => null,
        ...$attributes,
    ]);

    return [$team->owner->fresh(), $application];
}

function repositoryCheckAnswer(string $fixture): void
{
    $probe = Process::env([RepositoryProbe::DIRECTORY_VARIABLE => base_path('tests/Fixtures/repositories/' . $fixture)])->input(app(RepositoryProbe::class)->script())->run('bash -s')->output();
    $transport = (new FakeSSHTransport)->respondTo(RepositoryReader::MARKER, "@@commit 9f3c2e1a4b5c6d7e8f9012345678901234567890\n" . $probe);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
}

function repositoryCheckPost(User $user, Application $application): TestResponse
{
    return test()->actingAs($user)->post(route('servers.applications.repository-check', [$application->server_id, $application]));
}

test('an app waiting for its check cannot be deployed from its page, and the guard everything asks says why', function () {
    [$owner, $application] = repositoryCheckApp();

    expect(app(DeployGuard::class)->refusal($application))->toBe(DeployGuard::UNCHECKED);

    test()->actingAs($owner)->post(route('servers.applications.deploy', [$application->server_id, $application]))
        ->assertSessionHas('error', DeployGuard::UNCHECKED);

    expect(Deployment::query()->where('application_id', $application->id)->count())->toBe(0);
});

test('an app waiting for its check cannot be deployed through the API', function () {
    [$owner, $application] = repositoryCheckApp();
    $token = $owner->createToken('deploy', [Ability::DeployApplication->value]);
    ApiToken::whereKey($token->accessToken->getKey())->update(['team_id' => $application->team_id]);

    test()->postJson(route('api.applications.deploy', $application), [], ['Authorization' => 'Bearer ' . $token->plainTextToken])
        ->assertStatus(409)
        ->assertJson(['message' => DeployGuard::UNCHECKED]);

    expect(Deployment::query()->where('application_id', $application->id)->count())->toBe(0);
});

test('an app made before this release never waits for a check', function () {
    $application = Application::factory()->create();

    expect($application->fresh()->repository_check_pending)->toBeFalse()
        ->and(app(DeployGuard::class)->refusal($application))->toBeNull();
});

test('a check that passes clears the gate and records when', function () {
    [$owner, $application] = repositoryCheckApp();
    repositoryCheckAnswer('laravel-13');

    repositoryCheckPost($owner, $application)->assertRedirect()->assertSessionHas('success', 'Checked. Ready to deploy.');

    $application->refresh();

    expect($application->repository_check_pending)->toBeFalse()
        ->and($application->repository_checked_at)->not->toBeNull()
        ->and($application->repository_check['state'] ?? null)->toBe('passed')
        ->and(app(DeployGuard::class)->refusal($application))->toBeNull();
});

test('a stack the repository cannot run gets the detected one as a proposal', function () {
    [$owner, $application] = repositoryCheckApp();
    repositoryCheckAnswer('slim-skeleton');

    repositoryCheckPost($owner, $application)->assertRedirect();

    $check = $application->fresh()->repository_check;

    expect($application->fresh()->repository_check_pending)->toBeTrue()
        ->and($check['state'])->toBe('proposal')
        ->and($check['blockers'][0]['reason'])->toContain('artisan')
        ->and($check['proposal']['fields'])->toMatchArray(['type' => 'php', 'framework' => '', 'web_directory' => '/public'])
        ->and($check['proposal']['label'])->toStartWith('Plain PHP, PHP ');
});

test('using the proposal changes the stack and clears the gate', function () {
    [$owner, $application] = repositoryCheckApp(['web_directory' => '/']);
    repositoryCheckAnswer('slim-skeleton');
    repositoryCheckPost($owner, $application);

    test()->actingAs($owner)->put(route('servers.applications.repository-check.apply', [$application->server_id, $application]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Saved.');

    $application->refresh();

    expect($application->framework)->toBeNull()
        ->and($application->web_directory)->toBe('/public')
        ->and($application->repository_check_pending)->toBeFalse()
        ->and($application->repository_checked_at)->not->toBeNull();
});

test('there is nothing to use when no proposal stands', function () {
    [$owner, $application] = repositoryCheckApp();

    test()->actingAs($owner)->put(route('servers.applications.repository-check.apply', [$application->server_id, $application]))
        ->assertSessionHas('error');

    expect($application->fresh()->repository_check_pending)->toBeTrue();
});

test('a repository that cannot deploy as is keeps the gate and lists each fix', function () {
    [$owner, $application] = repositoryCheckApp(['type' => ApplicationType::Ruby, 'framework' => 'sinatra', 'web_directory' => '/public']);
    repositoryCheckAnswer('sinatra-no-lock');

    repositoryCheckPost($owner, $application);

    $check = $application->fresh()->repository_check;

    expect($application->fresh()->repository_check_pending)->toBeTrue()
        ->and($check['state'])->toBe('blocked')
        ->and($check['blockers'][0]['fix'])->toContain('Commit Gemfile.lock');
});

test('a read that fails keeps the gate and says why', function () {
    [$owner, $application] = repositoryCheckApp(['repository_url' => 'git@gitlab.com:acme/shop.git']);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo(RepositoryReader::MARKER, "git@gitlab.com: Permission denied (publickey).\nfatal: Could not read from remote repository.\n", 71));

    repositoryCheckPost($owner, $application);

    $check = $application->fresh()->repository_check;

    expect($check['state'])->toBe('failed')
        ->and($check['failure'])->toContain("refused this app's deploy key")
        ->and($application->fresh()->repository_check_pending)->toBeTrue();
});

test('only someone who can deploy the app can check it', function () {
    [, $application] = repositoryCheckApp();
    $member = User::factory()->create();
    $application->team->members()->attach($member, ['role' => TeamRole::Member->value, 'sees_everything' => false]);
    $deploy = test()->actingAs($member->fresh())->post(route('servers.applications.deploy', [$application->server_id, $application]))->status();

    expect($deploy)->toBeGreaterThanOrEqual(400);
    repositoryCheckPost($member->fresh(), $application)->assertStatus($deploy);
});

test('the app page carries the check while the app waits for it, and drops it once it passed', function () {
    [$owner, $application] = repositoryCheckApp(['repository_url' => 'git@gitlab.com:acme/shop.git']);

    test()->actingAs($owner)->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page
            ->where('application.repository_check.access', 'key')
            ->where('application.repository_check.check_href', route('servers.applications.repository-check', [$application->server_id, $application])));

    $application->forceFill(['repository_check_pending' => false])->save();

    test()->actingAs($owner)->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page->where('application.repository_check', null));
});
