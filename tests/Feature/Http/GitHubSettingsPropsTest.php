<?php

declare(strict_types=1);

use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\Sso\AddressResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function githubSettingsPropsTeam(string $role = 'owner'): array
{
    $team = Team::factory()->create(['slug' => 'monoven']);
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role]);

    return [$team, $user->fresh()];
}

function githubSettingsPropsApp(Team $team, array $attributes = []): GitHubApp
{
    return GitHubApp::factory()->for($team)->create(array_merge([
        'name' => 'Coresmith Monoven',
        'slug' => 'coresmith-monoven',
        'owner_login' => 'monoventech',
        'owner_type' => 'Organization',
    ], $attributes));
}

function githubSettingsPropsConnection(Team $team, ?GitHubApp $app, array $attributes = []): SourceProvider
{
    return SourceProvider::factory()->for($team)->create(array_merge([
        'type' => 'github_app',
        'github_app_id' => $app?->id,
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'access_token' => 'ghs_supersecret',
        'token_expires_at' => now()->addMinutes(42),
    ], $attributes));
}

function githubSettingsPropsCatalog(SourceProvider $provider, int $total): void
{
    Cache::put(RepositoryCatalog::key($provider), [
        'total' => $total,
        'truncated' => false,
        'repositories' => array_map(fn(int $i): array => [
            'id' => (string) (70000000 + $i),
            'full_name' => "monoventech/repo-{$i}",
            'private' => true,
            'default_branch' => 'main',
            'archived' => false,
            'pushed_at' => null,
        ], range(1, $total)),
    ], now()->addHour());
}

function githubSettingsPropsEnv(?string $webhookSecret = null): void
{
    config(['services.github' => [
        'app_id' => '1041207',
        'app_slug' => 'coresmith-cloud',
        'client_id' => 'Iv1.env',
        'client_secret' => 'env-secret',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----",
        'webhook_secret' => $webhookSecret,
    ]]);
}

beforeEach(function () {
    Http::preventStrayRequests();

    config(['inertia.ssr.enabled' => false]);

    config(['services.github' => [
        'app_id' => null,
        'app_slug' => 'coresmith',
        'client_id' => null,
        'client_secret' => null,
        'private_key' => null,
        'webhook_secret' => null,
    ]]);

    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['140.82.112.3'];
        }
    });
});

test('each installation shows real counts, permissions and where to manage it', function (?string $hook, string $permissions) {
    [$team, $owner] = githubSettingsPropsTeam();
    $app = githubSettingsPropsApp($team, ['hook_url' => $hook]);
    $provider = githubSettingsPropsConnection($team, $app);
    githubSettingsPropsCatalog($provider, 4);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->has('github.connections', 1)
            ->where('github.connections.0', [
                'id' => $provider->id,
                'account' => 'monoventech',
                'account_type' => 'Organization',
                'repository_selection' => 'selected',
                'repositories' => 4,
                'repositories_href' => route('source-providers.repositories', $provider),
                'permissions' => $permissions,
                'token_expires_at' => $provider->fresh()->token_expires_at->toIso8601String(),
                'suspended' => false,
                'manage_url' => 'https://github.com/organizations/monoventech/settings/installations/51842',
                'app_name' => 'Coresmith Monoven',
                'disconnect_href' => route('source-providers.destroy', $provider),
            ]));
})->with([
    'an app without a webhook' => [null, 'contents read-only, commit statuses'],
    'an app with a webhook' => ['https://panel.example.com/api/webhooks/github/01jaj4v0z8x6v3c2c2e8n1q6m7', 'contents read-only, commit statuses, webhooks'],
]);

test('the environment app counts as having a webhook when its secret is configured', function (?string $secret, string $permissions) {
    githubSettingsPropsEnv($secret);
    [$team, $owner] = githubSettingsPropsTeam();
    githubSettingsPropsConnection($team, null);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.connections.0.permissions', $permissions)
            ->where('github.connections.0.app_name', 'Coresmith'));
})->with([
    'no webhook secret' => [null, 'contents read-only, commit statuses'],
    'a webhook secret' => ['env-hook-secret', 'contents read-only, commit statuses, webhooks'],
]);

test('an unknown count is left out rather than guessed', function () {
    Http::fake();
    [$team, $owner] = githubSettingsPropsTeam();
    githubSettingsPropsConnection($team, githubSettingsPropsApp($team));

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.connections.0.repositories', null));

    Http::assertNothingSent();
});

test('a user account installation is managed from the personal settings page', function () {
    [$team, $owner] = githubSettingsPropsTeam();
    githubSettingsPropsConnection($team, githubSettingsPropsApp($team), ['name' => 'oseughu', 'account_type' => 'User', 'repository_selection' => 'all']);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.connections.0.account_type', 'User')
            ->where('github.connections.0.repository_selection', 'all')
            ->where('github.connections.0.manage_url', 'https://github.com/settings/installations/51842'));
});

test('a token is shown only while it is held', function (string $case, ?string $expected) {
    [$team, $owner] = githubSettingsPropsTeam();
    $provider = githubSettingsPropsConnection($team, githubSettingsPropsApp($team), match ($case) {
        'a live token' => ['token_expires_at' => now()->addMinutes(42)],
        'an expired token' => ['token_expires_at' => now()->subMinute()],
        'no token' => ['access_token' => null, 'token_expires_at' => null],
    });

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.connections.0.token_expires_at', $expected === null ? null : $provider->fresh()->token_expires_at->toIso8601String()));
})->with([
    'a live token' => ['a live token', 'set'],
    'an expired token' => ['an expired token', null],
    'no token' => ['no token', null],
]);

test('a suspended installation says so', function () {
    [$team, $owner] = githubSettingsPropsTeam();
    githubSettingsPropsConnection($team, githubSettingsPropsApp($team), ['suspended_at' => now()]);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.connections.0.suspended', true));
});

test('a connection whose app is gone is still listed, without an app name', function () {
    [$team, $owner] = githubSettingsPropsTeam();
    $app = githubSettingsPropsApp($team);
    githubSettingsPropsConnection($team, $app);
    $app->delete();

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->has('github.connections', 1)
            ->where('github.connections.0.app_name', null)
            ->where('github.connections.0.permissions', 'contents read-only, commit statuses'));
});

test('only this team\'s live github connections are listed', function () {
    [$team, $owner] = githubSettingsPropsTeam();
    $app = githubSettingsPropsApp($team);
    $live = githubSettingsPropsConnection($team, $app, ['name' => 'monoventech']);
    githubSettingsPropsConnection($team, $app, ['name' => 'monoven-labs', 'installation_id' => '51843'])->delete();
    githubSettingsPropsConnection(Team::factory()->create(), null, ['name' => 'someone-else']);
    SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'name' => 'gitlab-account']);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->has('github.connections', 1)
            ->where('github.connections.0.id', $live->id));
});

test('a registered app with no installation is offered for install', function () {
    [$team, $owner] = githubSettingsPropsTeam();
    $app = githubSettingsPropsApp($team);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->has('github.apps', 1)
            ->where('github.apps.0', [
                'key' => $app->id,
                'name' => 'Coresmith Monoven',
                'slug' => 'coresmith-monoven',
                'owner' => 'monoventech',
                'owner_type' => 'Organization',
                'settings_url' => 'https://github.com/organizations/monoventech/settings/apps/coresmith-monoven',
                'installed' => false,
                'install_href' => route('source-providers.connect', ['type' => 'github_app', 'app' => $app->id]),
            ]));
});

test('a registered app is installed once one of its installations is connected', function () {
    [$team, $owner] = githubSettingsPropsTeam();
    $app = githubSettingsPropsApp($team);
    githubSettingsPropsConnection($team, $app);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.apps.0.installed', true));
});

test('a disconnected installation leaves its app uninstalled', function () {
    [$team, $owner] = githubSettingsPropsTeam();
    $app = githubSettingsPropsApp($team);
    githubSettingsPropsConnection($team, $app)->delete();

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.apps.0.installed', false)->has('github.connections', 0));
});

test('with the environment app configured the team\'s own app is not offered', function () {
    githubSettingsPropsEnv();
    [$team, $owner] = githubSettingsPropsTeam();
    githubSettingsPropsApp($team);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.apps', [])
            ->where('github.connect', [
                'install_href' => route('source-providers.connect', ['type' => 'github_app', 'app' => 'env']),
                'link_href' => route('source-providers.connect', ['type' => 'github_app', 'app' => 'env', 'mode' => 'link']),
            ]));
});

test('connecting is offered to whoever can manage while an app is available', function (string $setup, string $role, bool $offered) {
    [$team, $user] = githubSettingsPropsTeam($role);
    $key = match ($setup) {
        'an app with no connection' => githubSettingsPropsApp($team)->id,
        'an app with a connection' => tap(githubSettingsPropsApp($team), fn(GitHubApp $app) => githubSettingsPropsConnection($team, $app))->id,
        'the environment app' => tap('env', fn() => githubSettingsPropsEnv()),
        'no app' => null,
    };

    $this->actingAs($user)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.can.manage', $role !== 'member')
            ->where('github.connect', $offered ? [
                'install_href' => route('source-providers.connect', ['type' => 'github_app', 'app' => $key]),
                'link_href' => route('source-providers.connect', ['type' => 'github_app', 'app' => $key, 'mode' => 'link']),
            ] : null));
})->with([
    'an owner, an app with no connection' => ['an app with no connection', 'owner', true],
    'an owner, an app with a connection' => ['an app with a connection', 'owner', true],
    'an admin, the environment app' => ['the environment app', 'admin', true],
    'an owner, no app' => ['no app', 'owner', false],
    'a member, an app with no connection' => ['an app with no connection', 'member', false],
    'a member, the environment app' => ['the environment app', 'member', false],
]);

test('a member sees the connections but no way to change them', function () {
    [$team, $member] = githubSettingsPropsTeam('member');
    githubSettingsPropsConnection($team, githubSettingsPropsApp($team));

    $this->actingAs($member)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->has('github.connections', 1)
            ->where('github.can', ['manage' => false])
            ->where('github.connect', null)
            ->where('github.choose', null));
});

test('with nothing to choose there is no chooser', function () {
    [$team, $owner] = githubSettingsPropsTeam();
    githubSettingsPropsApp($team);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.choose', null)->where('github.connections', []));
});

test('no credential reaches the page', function () {
    [$team, $owner] = githubSettingsPropsTeam();
    $app = githubSettingsPropsApp($team, ['client_secret' => 'cs_supersecret', 'webhook_secret' => 'whsec_supersecret']);
    githubSettingsPropsConnection($team, $app, ['access_token' => 'ghs_supersecret', 'refresh_token' => 'rt_supersecret']);
    githubSettingsPropsApp(Team::factory()->create(), ['client_secret' => 'cs_othersecret']);

    $content = (string) $this->actingAs($owner)->get(route('source-providers.index'))->assertOk()->getContent();

    expect($content)->not->toContain('cs_supersecret')
        ->and($content)->not->toContain('whsec_supersecret')
        ->and($content)->not->toContain('cs_othersecret')
        ->and($content)->not->toContain('rt_supersecret')
        ->and($content)->not->toContain('PRIVATE KEY')
        ->and($content)->not->toContain('ghs_')
        ->and($content)->not->toContain('client_secret')
        ->and($content)->not->toContain('webhook_secret');
});
