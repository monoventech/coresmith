<?php

declare(strict_types=1);

use App\Enums\SourceProviderType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Git\DeployKeyManager;
use App\Services\SSH\HostKeyFingerprint;
use Illuminate\Support\Facades\Http;

function providerUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('the page lists the teams connections without leaking any credential', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create([
        'client_secret' => 'cs_supersecret',
        'webhook_secret' => 'whsec_supersecret',
    ]))->create([
        'type' => SourceProviderType::GitHubApp->value,
        'name' => 'oseughu',
        'access_token' => 'ghs_supersecret',
        'refresh_token' => 'rt_supersecret',
    ]);

    $response = $this->actingAs(providerUser($team, TeamRole::Owner))
        ->get(route('source-providers.index'))
        ->assertOk();

    $response->assertInertia(fn($page) => $page
        ->component('settings/Git')
        ->has('providers', 1)
        ->where('providers.0.name', 'oseughu')
        ->where('providers.0.label', 'GitHub')
        ->where('providers.0.scopes', ['contents:read', 'metadata:read', 'statuses:write'])
        ->where('providers.0.connected', true));

    expect($response->getContent())->not->toContain('ghs_supersecret')
        ->and($response->getContent())->not->toContain('rt_supersecret')
        ->and($response->getContent())->not->toContain('cs_supersecret')
        ->and($response->getContent())->not->toContain('whsec_supersecret')
        ->and($response->getContent())->not->toContain('PRIVATE KEY');
});

test('the page lists the teams deploy keys and webhooks without leaking either secret', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = app(DeployKeyManager::class)->ensure(Application::factory()->for($team)->for($server)->create([
        'name' => 'Storefront',
        'repository_url' => 'git@github.com:monoven/storefront.git',
    ]));
    WebhookEndpoint::factory()->for($application)->create(['secret' => 'whsec_supersecret']);
    WebhookEndpoint::factory()->for($application)->create(['active' => false]);
    app(DeployKeyManager::class)->ensure(Application::factory()->for(Team::factory())->create(['name' => 'Someone Elses']));

    $response = $this->actingAs(providerUser($team, TeamRole::Member))
        ->get(route('source-providers.index'))
        ->assertOk();

    $response->assertInertia(fn($page) => $page
        ->component('settings/Git')
        ->has('deployKeys', 1)
        ->where('deployKeys.0.name', 'Storefront')
        ->where('deployKeys.0.repository', 'monoven/storefront')
        ->where('deployKeys.0.fingerprint', HostKeyFingerprint::fromPublicKey((string) $application->deploy_key_public))
        ->where('webhooks.count', 1));

    expect($response->getContent())->not->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($response->getContent())->not->toContain('whsec_supersecret')
        ->and($response->getContent())->not->toContain('Someone Elses');
});

test('the page advertises the exact scopes each provider asks for', function () {
    $team = Team::factory()->create();

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->has('connectable', 2)
            ->where('connectable.0.type', 'gitlab')
            ->where('connectable.0.scopes', ['read_repository', 'read_api', 'api'])
            ->where('connectable.1.type', 'bitbucket')
            ->where('connectable.1.scopes', ['repository', 'webhook', 'repository:admin']));
});

test('github is offered through its own cards once the team has registered an app or the environment has one', function (string $setup, bool $expected) {
    config(['services.github' => ['app_id' => null, 'app_slug' => 'coresmith', 'client_id' => null, 'client_secret' => null, 'private_key' => null, 'webhook_secret' => null]]);
    $team = Team::factory()->create();

    if ($setup === 'another teams app') {
        GitHubApp::factory()->for(Team::factory()->create())->create();
    }

    if ($setup === 'its own app') {
        GitHubApp::factory()->for($team)->create();
    }

    if ($setup === 'the environment app') {
        config(['services.github' => ['app_id' => '1041207', 'app_slug' => 'coresmith', 'client_id' => 'Iv1.env', 'client_secret' => 'env-secret', 'private_key' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----", 'webhook_secret' => null]]);
    }

    $response = $this->actingAs(providerUser($team, TeamRole::Owner))
        ->get(route('source-providers.index'))
        ->assertOk();

    expect(array_column($response->inertiaProps('connectable'), 'type'))->toBe(['gitlab', 'bitbucket'])
        ->and($response->inertiaProps('github.connect') !== null)->toBe($expected);
})->with([
    'nothing registered' => ['nothing', false],
    'only another teams app' => ['another teams app', false],
    'an app of its own' => ['its own app', true],
    'the environment app' => ['the environment app', true],
]);

test('a provider whose token was cleared is shown as disconnected', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'access_token' => null]);

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('providers.0.connected', false));
});

test('a member sees the connections but gets no management controls', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create(['type' => 'gitlab']);

    $this->actingAs(providerUser($team, TeamRole::Member))
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('can.manage', false)->has('providers', 1));
});

test('a member cannot disconnect a provider', function () {
    Http::fake();
    $team = Team::factory()->create();
    $source = SourceProvider::factory()->for($team)->create(['type' => 'gitlab']);

    $this->actingAs(providerUser($team, TeamRole::Member))
        ->delete(route('source-providers.destroy', $source))
        ->assertForbidden();

    expect(SourceProvider::find($source->getKey()))->not->toBeNull();
    Http::assertNothingSent();
});

test('another teams provider cannot be disconnected', function () {
    Http::fake();
    $source = SourceProvider::factory()->for(Team::factory()->create())->create(['type' => 'gitlab']);

    $this->actingAs(providerUser(Team::factory()->create(), TeamRole::Owner))
        ->delete(route('source-providers.destroy', $source))
        ->assertForbidden();

    expect(SourceProvider::find($source->getKey()))->not->toBeNull();
});

test('disconnecting revokes the token at the provider and erases it locally', function () {
    Http::fake(['gitlab.com/oauth/revoke' => Http::response([])]);
    $team = Team::factory()->create();
    $source = SourceProvider::factory()->for($team)->create([
        'type' => 'gitlab',
        'access_token' => 'to-be-revoked',
        'refresh_token' => 'also-gone',
    ]);

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->delete(route('source-providers.destroy', $source))
        ->assertRedirect()
        ->assertSessionHas('success', 'Disconnected. Apps keep deploying with their own deploy keys.');

    $trashed = SourceProvider::withTrashed()->find($source->getKey());

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->access_token)->toBeNull()
        ->and($trashed->refresh_token)->toBeNull()
        ->and($trashed->token_expires_at)->toBeNull();

    Http::assertSent(fn($request) => str_contains($request->url(), 'oauth/revoke'));
});

test('disconnecting a github connection revokes its live installation token and erases it locally', function () {
    Http::fake(['api.github.com/installation/token' => Http::response(null, 204)]);
    $team = Team::factory()->create();
    $source = SourceProvider::factory()->for($team)->githubApp()->create(['access_token' => 'ghs_to_revoke', 'refresh_token' => 'rt_left_over', 'token_expires_at' => now()->addHour()]);

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->delete(route('source-providers.destroy', $source))
        ->assertRedirect()
        ->assertSessionHas('success', 'Disconnected. To remove the app\'s access on GitHub too, uninstall it there.');

    $trashed = SourceProvider::withTrashed()->find($source->getKey());

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->access_token)->toBeNull()
        ->and($trashed->refresh_token)->toBeNull()
        ->and($trashed->token_expires_at)->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/installation/token'
        && $request->hasHeader('Authorization', 'Bearer ghs_to_revoke'));
});

test('a github connection with nothing to revoke is still disconnected without a request', function (string $case) {
    Http::fake();
    $team = Team::factory()->create();
    $source = SourceProvider::factory()->for($team)->githubApp()->create(['access_token' => 'ghs_stale', 'token_expires_at' => now()->subHour()]);

    if ($case === 'the app was removed') {
        $source->update(['token_expires_at' => now()->addHour()]);
        $source->githubApp->delete();
    }

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->delete(route('source-providers.destroy', $source))
        ->assertRedirect()
        ->assertSessionHas('success', 'Disconnected. To remove the app\'s access on GitHub too, uninstall it there.');

    $trashed = SourceProvider::withTrashed()->find($source->getKey());

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->access_token)->toBeNull();

    Http::assertNothingSent();
})->with(['the token expired', 'the app was removed']);

test('a provider that refuses the revoke call is still disconnected locally', function () {
    Http::fake(['gitlab.com/oauth/revoke' => Http::response(['error' => 'invalid_token'], 401)]);
    $team = Team::factory()->create();
    $source = SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'access_token' => 'dead']);

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->delete(route('source-providers.destroy', $source))
        ->assertRedirect();

    expect(SourceProvider::withTrashed()->find($source->getKey())->access_token)->toBeNull();
});

test('a user with no team gets a not found rather than an error', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('source-providers.index'))
        ->assertNotFound();
});

test('the page is behind authentication', function () {
    $this->get(route('source-providers.index'))->assertRedirect(route('login'));
});
