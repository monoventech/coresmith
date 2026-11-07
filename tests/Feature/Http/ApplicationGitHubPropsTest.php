<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function githubPropsMember(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    return $user->fresh();
}

function githubPropsConnection(Team $team): SourceProvider
{
    return SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'access_token' => 'ghs_supersecret',
        'token_expires_at' => now()->addHour(),
    ]);
}

function githubPropsApp(Team $team, ?SourceProvider $connection, array $attributes = []): Application
{
    return Application::factory()->for($team)->for(Server::factory()->for($team))->create([
        'repository_url' => 'https://github.com/monoventech/relay.git',
        'repository_branch' => 'trunk',
        ...($connection === null ? [] : [
            'repository_provider' => 'github',
            'source_provider_id' => $connection->id,
            'repository_id' => '70000003',
        ]),
        ...$attributes,
    ]);
}

function githubPropsKey(): array
{
    return [
        'deploy_key_public' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAID1mMDNsL9LCfDTYhnoHpWmxAPDE+kQmRCK3NHkfGfMp deploy@coresmith',
        'deploy_key_private' => "-----BEGIN OPENSSH PRIVATE KEY-----\nstored\n-----END OPENSSH PRIVATE KEY-----",
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config(['inertia.ssr.enabled' => false]);
});

test('a linked app shows how it clones and no deploy key', function (array $attributes) {
    $team = Team::factory()->create();
    $application = githubPropsApp($team, githubPropsConnection($team), $attributes);

    $response = $this->actingAs(githubPropsMember($team))->get(route('servers.applications.show', [$application->server_id, $application]));

    $response->assertOk()->assertInertia(fn($page) => $page
        ->component('applications/Show')
        ->where('application.github', [
            'repository' => 'monoventech/relay',
            'branch' => 'trunk',
            'usable' => true,
            'problem' => null,
            'settings_href' => route('source-providers.index'),
        ])
        ->where('application.deploy_key', null));

    expect($response->getContent())->not->toContain('ghs_supersecret')
        ->and($application->fresh()->deploy_key_public)->toBe($attributes['deploy_key_public'] ?? null);
})->with([
    'cloned over https' => [[]],
    'a key left from an ssh address' => [['repository_url' => 'git@github.com:monoventech/relay.git', ...githubPropsKey()]],
]);

test('a linked app whose connection was removed or suspended names the problem', function (Closure $break, string $problem) {
    $team = Team::factory()->create();
    $connection = githubPropsConnection($team);
    $application = githubPropsApp($team, $connection);

    $break($connection);

    $this->actingAs(githubPropsMember($team))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('application.github', [
                'repository' => 'monoventech/relay',
                'branch' => 'trunk',
                'usable' => false,
                'problem' => $problem,
                'settings_href' => route('source-providers.index'),
            ])
            ->where('application.deploy_key', null));
})->with([
    'disconnected' => [fn(SourceProvider $connection) => $connection->disconnect(), 'removed'],
    'uninstalled on github' => [fn(SourceProvider $connection) => $connection->markUninstalled(), 'removed'],
    'suspended on github' => [fn(SourceProvider $connection) => $connection->markSuspended(now()), 'suspended'],
    'its github app is gone' => [fn(SourceProvider $connection) => GitHubApp::query()->whereKey($connection->github_app_id)->delete(), 'removed'],
]);

test('a removed connection keeps the app on its deploy key when it was added by an ssh address', function () {
    $team = Team::factory()->create();
    $connection = githubPropsConnection($team);
    $application = githubPropsApp($team, $connection, ['repository_url' => 'git@github.com:monoventech/relay.git', ...githubPropsKey()]);

    $connection->disconnect();

    $this->actingAs(githubPropsMember($team))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page
            ->where('application.github.usable', false)
            ->where('application.github.problem', 'removed')
            ->where('application.deploy_key.public_key', githubPropsKey()['deploy_key_public'])
            ->where('application.deploy_key.repository', 'monoventech/relay'));
});

test('an unlinked app has no github block', function (array $attributes, bool $keyed) {
    $team = Team::factory()->create();
    $application = githubPropsApp($team, null, $attributes);

    $this->actingAs(githubPropsMember($team))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('application.github', null)
            ->where('application.deploy_key', fn($key) => ($key !== null) === $keyed));
})->with([
    'a public https address' => [[], false],
    'an ssh address with its deploy key' => [['repository_url' => 'git@github.com:monoventech/relay.git'], true],
]);

test('an app linked to another kind of git provider has no github block', function () {
    $team = Team::factory()->create();
    $gitlab = SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'access_token' => 'glpat-secret']);
    $application = githubPropsApp($team, null, ['source_provider_id' => $gitlab->id]);

    $this->actingAs(githubPropsMember($team))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page->where('application.github', null));
});

test('the way to git settings carries the team address', function () {
    $team = Team::factory()->create(['slug' => 'monoven']);
    $connection = githubPropsConnection($team);
    $application = githubPropsApp($team, $connection);

    $connection->disconnect();

    $this->actingAs(githubPropsMember($team))
        ->get('/monoven/servers/' . $application->server_id . '/applications/' . $application->id)
        ->assertInertia(fn($page) => $page->where('application.github.settings_href', 'http://localhost/monoven/settings/git'));
});
