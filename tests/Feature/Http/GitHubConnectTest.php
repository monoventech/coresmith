<?php

declare(strict_types=1);

use App\Enums\SourceProviderType;
use App\Http\Controllers\Settings\SourceProviderConnectController;
use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\User;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubConnector;
use App\Services\Git\GitHub\GitHubSettingsPresenter;
use App\Services\Git\GitHub\InstallationChoices;
use App\Services\Git\GitHub\RepositoryCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

function githubConnectTeam(string $role = 'owner'): array
{
    $team = Team::factory()->create(['slug' => 'monoven']);
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role]);
    $app = GitHubApp::factory()->for($team)->create(['slug' => 'coresmith-monoven', 'name' => 'Coresmith Monoven', 'client_id' => 'Iv1.client', 'client_secret' => 'client-secret']);

    return [$team, $user->fresh(), $app];
}

function githubConnectIntent(Team $team, GitHubApp $app, string $state = 'the-state', bool $bounced = false): array
{
    return [SourceProviderConnectController::STATE_KEY => ['state' => $state, 'type' => 'github_app', 'team' => $team->getKey(), 'app' => $app->getKey(), 'at' => now()->timestamp, 'bounced' => $bounced]];
}

function githubConnectInstallation(int $id, string $login, string $type = 'Organization', string $selection = 'selected'): array
{
    return ['id' => $id, 'account' => ['login' => $login, 'type' => $type], 'repository_selection' => $selection, 'suspended_at' => null];
}

function githubConnectFake(array $installations, string $clientId = 'Iv1.client', array $github = [], array $override = []): void
{
    $github = array_merge(['login' => 'oseughu', 'visible' => 4, 'total' => 4], $github);
    $count = fn(int|array $counts, string $installation): int => is_array($counts) ? $counts[$installation] : $counts;

    Http::fake(array_merge([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user_token', 'token_type' => 'bearer', 'expires_in' => 28800]),
        'api.github.com/user' => Http::response(['login' => $github['login'], 'id' => 10, 'type' => 'User']),
        'api.github.com/user/installations?*' => Http::response(['total_count' => count($installations), 'installations' => $installations]),
        'api.github.com/user/installations/*/repositories*' => fn($request) => Http::response([
            'total_count' => $count($github['visible'], (string) preg_replace('#^.*/user/installations/([0-9]+)/repositories.*$#', '$1', $request->url())),
            'repositories' => [],
        ]),
        'api.github.com/app/installations/*/access_tokens' => fn($request) => Http::response([
            'token' => 'ghs_counting_' . preg_replace('#^.*/app/installations/([0-9]+)/access_tokens$#', '$1', $request->url()),
            'expires_at' => '2030-01-01T00:00:00Z',
        ], 201),
        'api.github.com/installation/repositories*' => fn($request) => Http::response([
            'total_count' => $count($github['total'], substr((string) ($request->header('Authorization')[0] ?? ''), strlen('Bearer ghs_counting_'))),
            'repositories' => [],
        ]),
        'api.github.com/installation/token' => Http::response(null, 204),
        "api.github.com/applications/{$clientId}/token" => Http::response(null, 204),
    ], $override));
}

function githubConnectPem(): string
{
    static $pem = null;

    if ($pem === null) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    }

    return $pem;
}

function githubConnectCounted(string $installation, int $times = 1): void
{
    expect(Http::recorded(fn($request) => $request->method() === 'POST'
        && $request->url() === "https://api.github.com/app/installations/{$installation}/access_tokens"
        && $request->body() === '{"permissions":{"metadata":"read"}}'))->toHaveCount($times)
        ->and(Http::recorded(fn($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.github.com/installation/repositories?per_page=1'
            && $request->hasHeader('Authorization', "Bearer ghs_counting_{$installation}")))->toHaveCount($times)
        ->and(Http::recorded(fn($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.github.com/installation/token'
            && $request->hasHeader('Authorization', "Bearer ghs_counting_{$installation}")))->toHaveCount($times);
}

function githubConnectRevokedLast(): void
{
    $order = Http::recorded()->values()->map(fn(array $pair): string => $pair[0]->method() . ' ' . $pair[0]->url());
    $revoked = $order->search('DELETE https://api.github.com/applications/Iv1.client/token');
    $asked = $order->keys()->filter(fn(int $index): bool => Http::recorded()->values()[$index][0]->hasHeader('Authorization', 'Bearer ghu_user_token'));

    expect($revoked)->toBeInt()
        ->and($asked->all())->not->toBe([])
        ->and($asked->max())->toBeLessThan($revoked);
}

function githubConnectAskedAs(string $url): Closure
{
    return fn($request): bool => $request->method() === 'GET'
        && $request->url() === $url
        && $request->hasHeader('Authorization', 'Bearer ghu_user_token');
}

function githubConnectCallback(array $query): string
{
    return route('source-providers.callback', 'github_app') . '?' . http_build_query($query);
}

function githubConnectEnv(): void
{
    config(['services.github' => [
        'app_id' => '1041207',
        'app_slug' => 'coresmith-cloud',
        'client_id' => 'Iv1.env',
        'client_secret' => 'env-secret',
        'private_key' => githubConnectPem(),
        'webhook_secret' => 'env-hook-secret',
    ]]);
}

function githubConnectOffer(User $user, Team $team, GitHubApp $app, ?array $installations = null, array $github = []): TestResponse
{
    githubConnectFake($installations ?? [
        githubConnectInstallation(1, 'monoventech', 'Organization', 'all'),
        githubConnectInstallation(2, 'oseughu', 'User', 'selected'),
    ], github: $github);

    return test()->actingAs($user)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state']));
}

function githubConnectRevoked(string $clientId = 'Iv1.client'): Closure
{
    return fn($request): bool => $request->method() === 'DELETE'
        && $request->url() === "https://api.github.com/applications/{$clientId}/token"
        && $request['access_token'] === 'ghu_user_token';
}

function githubConnectStoredAnywhere(string $needle): bool
{
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table'") as $table) {
        foreach (DB::table($table->name)->get() as $row) {
            if (str_contains((string) json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE), $needle)) {
                return true;
            }
        }
    }

    return str_contains((string) json_encode(session()->all(), JSON_INVALID_UTF8_SUBSTITUTE), $needle);
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
});

test('connecting sends the owner to the team\'s app install page with a fresh state', function () {
    $this->freezeTime();
    [$team, $owner, $app] = githubConnectTeam();

    $target = $this->actingAs($owner)
        ->get(route('source-providers.connect', ['type' => 'github_app', 'app' => $app->id]))
        ->assertRedirect()
        ->headers->get('Location');

    preg_match('/^https:\/\/github\.com\/apps\/coresmith-monoven\/installations\/new\?state=([A-Za-z0-9]{40})$/D', (string) $target, $matches);

    expect($matches)->toHaveCount(2)
        ->and(session(SourceProviderConnectController::STATE_KEY))->toBe([
            'state' => $matches[1],
            'type' => 'github_app',
            'team' => $team->id,
            'app' => $app->id,
            'at' => now()->timestamp,
            'bounced' => false,
        ]);
});

test('each connection attempt gets a state of its own', function () {
    [, $owner, $app] = githubConnectTeam();
    $url = route('source-providers.connect', ['type' => 'github_app', 'app' => $app->id]);

    $first = $this->actingAs($owner)->get($url)->headers->get('Location');
    $second = $this->actingAs($owner)->get($url)->headers->get('Location');

    expect($first)->not->toBe($second)
        ->and((string) $second)->toEndWith('state=' . session(SourceProviderConnectController::STATE_KEY)['state']);
});

test('linking an existing installation goes to github\'s authorize page', function () {
    [, $owner, $app] = githubConnectTeam();

    $target = (string) $this->actingAs($owner)
        ->get(route('source-providers.connect', ['type' => 'github_app', 'app' => $app->id, 'mode' => 'link']))
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

    expect($target)->toStartWith('https://github.com/login/oauth/authorize?')
        ->and($query)->toBe([
            'client_id' => 'Iv1.client',
            'redirect_uri' => 'http://localhost/settings/source-providers/github_app/callback',
            'state' => session(SourceProviderConnectController::STATE_KEY)['state'],
        ])
        ->and($target)->not->toContain('client-secret');
});

test('without an app named the team\'s own app is the one installed', function () {
    [, $owner, $app] = githubConnectTeam();

    $target = $this->actingAs($owner)
        ->get(route('source-providers.connect', 'github_app'))
        ->assertRedirect()
        ->headers->get('Location');

    expect((string) $target)->toStartWith('https://github.com/apps/coresmith-monoven/installations/new?state=')
        ->and(session(SourceProviderConnectController::STATE_KEY)['app'])->toBe($app->id);
});

test('a member cannot start a connection', function () {
    [, $member, $app] = githubConnectTeam('member');

    $this->actingAs($member)
        ->get(route('source-providers.connect', ['type' => 'github_app', 'app' => $app->id]))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('source-providers.connect', 'github_app'))
        ->assertForbidden();

    expect(session(SourceProviderConnectController::STATE_KEY))->toBeNull();
});

test('an app key the team does not have is a not found', function (string $key) {
    [, $owner] = githubConnectTeam();
    $keys = ['another team\'s app' => GitHubApp::factory()->create()->id, 'no such app' => '01jaj4v0z8x6v3c2c2e8n1q6m7', 'not a key at all' => '../../etc/passwd', 'the environment app' => 'env'];

    $this->actingAs($owner)
        ->get(route('source-providers.connect', ['type' => 'github_app', 'app' => $keys[$key]]))
        ->assertNotFound();

    expect(session(SourceProviderConnectController::STATE_KEY))->toBeNull();
})->with(['another team\'s app', 'no such app', 'not a key at all', 'the environment app']);

test('an app key that is not a single value is a not found', function () {
    [, $owner, $app] = githubConnectTeam();

    $this->actingAs($owner)
        ->get(route('source-providers.connect', 'github_app') . '?app[]=' . $app->id)
        ->assertNotFound();
});

test('no app at all is a not found', function () {
    $team = Team::factory()->create(['slug' => 'monoven']);
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner->fresh())
        ->get(route('source-providers.connect', 'github_app'))
        ->assertNotFound();

    expect(session(SourceProviderConnectController::STATE_KEY))->toBeNull();
});

test('with an environment app every team installs that one', function () {
    githubConnectEnv();
    [, $owner, $app] = githubConnectTeam();

    foreach ([route('source-providers.connect', 'github_app'), route('source-providers.connect', ['type' => 'github_app', 'app' => 'env'])] as $url) {
        $target = $this->actingAs($owner)->get($url)->assertRedirect()->headers->get('Location');

        expect((string) $target)->toMatch('/^https:\/\/github\.com\/apps\/coresmith-cloud\/installations\/new\?state=[A-Za-z0-9]{40}$/D')
            ->and(session(SourceProviderConnectController::STATE_KEY)['app'])->toBe('env');
    }

    $this->actingAs($owner)
        ->get(route('source-providers.connect', ['type' => 'github_app', 'app' => $app->id]))
        ->assertNotFound();
});

test('the app names the team, so a registration finished after switching teams still connects', function () {
    [$monoven, $owner, $app] = githubConnectTeam();
    $acme = Team::factory()->create(['slug' => 'acme']);
    $acme->members()->attach($owner, ['role' => 'owner']);
    $owner->forceFill(['current_team_id' => $acme->id])->save();

    $this->actingAs($owner->fresh())
        ->get(route('source-providers.connect', ['type' => 'github_app', 'app' => $app->id]))
        ->assertRedirect();

    expect(session(SourceProviderConnectController::STATE_KEY)['team'])->toBe($monoven->id);
});

test('the app\'s team must still be managed by whoever follows the link', function () {
    [$monoven, $owner, $app] = githubConnectTeam();
    $acme = Team::factory()->create(['slug' => 'acme']);
    $acme->members()->attach($owner, ['role' => 'owner']);
    $monoven->members()->updateExistingPivot($owner->id, ['role' => 'member']);
    $owner->forceFill(['current_team_id' => $acme->id])->save();

    $this->actingAs($owner->fresh())
        ->get(route('source-providers.connect', ['type' => 'github_app', 'app' => $app->id]))
        ->assertForbidden();

    expect(session(SourceProviderConnectController::STATE_KEY))->toBeNull();
});

test('under another team\'s address an app key is a not found', function () {
    [, $owner, $app] = githubConnectTeam();
    $acme = Team::factory()->create(['slug' => 'acme']);
    $acme->members()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner->fresh())
        ->get("/acme/settings/source-providers/github_app/connect?app={$app->id}")
        ->assertNotFound();

    $this->actingAs($owner->fresh())
        ->get("/monoven/settings/source-providers/github_app/connect?app={$app->id}")
        ->assertRedirect();
});

test('on a team\'s own domain the connection moves to the canonical address with its query', function () {
    [$team, $owner, $app] = githubConnectTeam();
    TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'panel.monoven.test']);

    $this->actingAs($owner)
        ->get("http://panel.monoven.test/settings/source-providers/github_app/connect?app={$app->id}")
        ->assertRedirect("http://localhost/settings/source-providers/github_app/connect?app={$app->id}");

    expect(session(SourceProviderConnectController::STATE_KEY))->toBeNull();
});

test('an installation id the user cannot reach is refused even with a valid state', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(route('source-providers.callback', 'github_app') . '?code=c0de&state=the-state&installation_id=99999&setup_action=install')
        ->assertSessionHas('error', 'Your GitHub account cannot reach that installation, so nothing was connected.');

    expect(SourceProvider::count())->toBe(0);
    Http::assertSent(fn($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/applications/Iv1.client/token'));
});

test('an installation id that is not a number is refused', function (string $installation) {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => $installation]))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'Your GitHub account cannot reach that installation, so nothing was connected.');

    expect(SourceProvider::withTrashed()->count())->toBe(0);
})->with(['51842x', '-51842', '0x51842', '5184 2', '051842']);

test('a callback with matching state links the installation the user can reach', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842', 'setup_action' => 'install']))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('success', 'GitHub connected. Repositories on monoventech can be picked when you add an app.');

    $provider = SourceProvider::sole();

    expect($provider->team_id)->toBe($team->id)
        ->and($provider->type)->toBe(SourceProviderType::GitHubApp)
        ->and($provider->github_app_id)->toBe($app->id)
        ->and($provider->installation_id)->toBe('51842')
        ->and($provider->name)->toBe('monoventech')
        ->and($provider->account_type)->toBe('Organization')
        ->and($provider->repository_selection)->toBe('selected')
        ->and($provider->suspended_at)->toBeNull()
        ->and($provider->scopes)->toBe(['contents:read', 'metadata:read', 'statuses:write'])
        ->and($provider->access_token)->toBeNull()
        ->and($provider->refresh_token)->toBeNull()
        ->and($provider->token_expires_at)->toBeNull()
        ->and($provider->connected())->toBeTrue()
        ->and(session(SourceProviderConnectController::STATE_KEY))->toBeNull();

    Http::assertSentCount(7);
    Http::assertSent(fn($request) => $request->method() === 'POST'
        && $request->url() === 'https://github.com/login/oauth/access_token'
        && $request['client_id'] === 'Iv1.client'
        && $request['code'] === 'c0de'
        && $request['redirect_uri'] === 'http://localhost/settings/source-providers/github_app/callback');
    Http::assertSent(githubConnectAskedAs('https://api.github.com/user/installations?per_page=100'));
    Http::assertSent(githubConnectAskedAs('https://api.github.com/user/installations/51842/repositories?per_page=1'));
    Http::assertSent(githubConnectRevoked());
    githubConnectCounted('51842');

    expect(githubConnectStoredAnywhere('ghu_user_token'))->toBeFalse()
        ->and(githubConnectStoredAnywhere('ghs_counting'))->toBeFalse();
});

test('a single reachable installation is linked when github names none', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'oseughu', 'User', 'all')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state']))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('success', 'GitHub connected. Repositories on oseughu can be picked when you add an app.');

    expect(SourceProvider::sole())
        ->installation_id->toBe('51842')
        ->account_type->toBe('User')
        ->repository_selection->toBe('all');
});

test('a suspended personal installation is linked and marked suspended', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([array_merge(githubConnectInstallation(51842, 'oseughu', 'User', 'all'), ['suspended_at' => '2026-10-18T14:00:00Z'])]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertSessionHas('success');

    $provider = SourceProvider::sole();

    expect($provider->suspended_at?->toIso8601String())->toBe('2026-10-18T14:00:00+00:00')
        ->and($provider->connected())->toBeFalse();
});

test('linking is recorded without the user token', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']));

    $activity = Activity::query()->where('description', 'github_installation_linked')->sole();

    expect($activity->subject?->is(SourceProvider::sole()))->toBeTrue()
        ->and($activity->causer?->is($owner))->toBeTrue()
        ->and($activity->properties->all())->toBe(['installation_id' => '51842', 'account' => 'monoventech', 'app' => $app->id])
        ->and(json_encode($activity->properties))->not->toContain('ghu_user_token')
        ->and(json_encode($activity->properties))->not->toContain('c0de');
});

test('the environment app links an installation without an app record', function () {
    githubConnectEnv();
    $team = Team::factory()->create(['slug' => 'monoven']);
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => 'owner']);
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')], 'Iv1.env');

    $this->actingAs($owner->fresh())
        ->withSession([SourceProviderConnectController::STATE_KEY => ['state' => 'the-state', 'type' => 'github_app', 'team' => $team->id, 'app' => 'env', 'at' => now()->timestamp, 'bounced' => false]])
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertSessionHas('success', 'GitHub connected. Repositories on monoventech can be picked when you add an app.');

    expect(SourceProvider::sole()->github_app_id)->toBeNull();
    Http::assertSent(githubConnectRevoked('Iv1.env'));
});

test('a callback without state bounces once through github\'s authorize page', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);
    $query = ['installation_id' => '51842', 'setup_action' => 'install', 'code' => 'x'];

    $target = (string) $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback($query))
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url($target, PHP_URL_QUERY), $authorize);

    expect($target)->toStartWith('https://github.com/login/oauth/authorize?')
        ->and($authorize)->toBe([
            'client_id' => 'Iv1.client',
            'redirect_uri' => 'http://localhost/settings/source-providers/github_app/callback',
            'state' => 'the-state',
        ])
        ->and(session(SourceProviderConnectController::STATE_KEY))->toMatchArray(['state' => 'the-state', 'team' => $team->id, 'app' => $app->id, 'bounced' => true]);

    Http::assertNothingSent();

    $this->actingAs($owner)
        ->get(githubConnectCallback($query))
        ->assertSessionHas('error', 'That connection attempt could not be verified. Start again from this page.');

    Http::assertNothingSent();
    expect(SourceProvider::count())->toBe(0);
});

test('after the bounce github\'s answer with the state links the installation', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['installation_id' => '51842', 'setup_action' => 'install', 'code' => 'x']))
        ->assertRedirect();

    $this->actingAs($owner)
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state']))
        ->assertSessionHas('success', 'GitHub connected. Repositories on monoventech can be picked when you add an app.');

    expect(SourceProvider::sole()->installation_id)->toBe('51842');
    Http::assertNotSent(fn($request) => $request->url() === 'https://github.com/login/oauth/access_token' && $request['code'] === 'x');
});

test('a callback without state is not bounced for an attempt that is too old', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([]);
    $intent = githubConnectIntent($team, $app);

    $this->travel(16)->minutes();

    $this->actingAs($owner)
        ->withSession($intent)
        ->get(githubConnectCallback(['installation_id' => '51842', 'setup_action' => 'install', 'code' => 'x']))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'That connection attempt could not be verified. Start again from this page.');

    expect(session(SourceProviderConnectController::STATE_KEY)['bounced'])->toBeFalse();
    Http::assertNothingSent();
});

test('a callback with no intent is refused and sends nothing', function () {
    [, $owner] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    foreach ([['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842'], ['code' => 'c0de', 'installation_id' => '51842']] as $query) {
        $this->actingAs($owner)
            ->get(githubConnectCallback($query))
            ->assertRedirect('http://localhost/monoven/settings/git')
            ->assertSessionHas('error', 'That connection attempt could not be verified. Start again from this page.');
    }

    Http::assertNothingSent();
    expect(SourceProvider::count())->toBe(0);
});

test('a callback whose state does not match is refused and the attempt stays usable', function (array|string $state) {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => $state]))
        ->assertSessionHas('error', 'That connection attempt could not be verified. Start again from this page.');

    Http::assertNothingSent();

    $this->actingAs($owner)
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state']))
        ->assertSessionHas('success');
})->with([
    'a forged state' => ['forged'],
    'the state with more on the end' => ['the-state-and-more'],
    'a list of states' => [['the-state']],
]);

test('a github callback leaves a connection attempt for another provider alone', function () {
    [$team, $owner] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);
    $gitlab = ['state' => 'gitlab-state', 'type' => 'gitlab', 'team' => $team->id];

    foreach ([['code' => 'c0de', 'state' => 'gitlab-state'], ['code' => 'c0de']] as $query) {
        $this->actingAs($owner)
            ->withSession([SourceProviderConnectController::STATE_KEY => $gitlab])
            ->get(githubConnectCallback($query))
            ->assertSessionHas('error', 'That connection attempt could not be verified. Start again from this page.');

        expect(session(SourceProviderConnectController::STATE_KEY))->toBe($gitlab);
    }

    Http::assertNothingSent();
    expect(SourceProvider::count())->toBe(0);
});

test('the state is single use', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);
    $callback = githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']);

    $this->actingAs($owner)->withSession(githubConnectIntent($team, $app))->get($callback)->assertSessionHas('success');

    $this->actingAs($owner)
        ->get($callback)
        ->assertSessionHas('error', 'That connection attempt could not be verified. Start again from this page.');

    Http::assertSentCount(7);
    expect(SourceProvider::count())->toBe(1);
});

test('a declined authorization stores nothing', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    foreach ([['error' => 'access_denied', 'state' => 'the-state'], ['state' => 'the-state']] as $query) {
        $this->actingAs($owner)
            ->withSession(githubConnectIntent($team, $app))
            ->get(githubConnectCallback($query))
            ->assertRedirect('http://localhost/monoven/settings/git')
            ->assertSessionHas('error', 'GitHub did not grant access. Nothing was connected.');
    }

    Http::assertNothingSent();
    expect(SourceProvider::count())->toBe(0);
});

test('a code github refuses stores nothing', function () {
    [$team, $owner, $app] = githubConnectTeam();
    Http::fake(['github.com/login/oauth/access_token' => Http::response(['error' => 'bad_verification_code', 'error_description' => 'The code passed is incorrect or expired.'])]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'used', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'GitHub would not complete the connection. Nothing was saved.');

    Http::assertSentCount(1);
    expect(SourceProvider::count())->toBe(0);
});

test('an installation list github will not give stores nothing and still revokes the user token', function (string $failure) {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([], override: ['api.github.com/user/installations?*' => match ($failure) {
        'a refusal' => Http::response(['message' => 'Bad credentials'], 401),
        'an outage' => Http::response(['message' => 'Server Error'], 502),
        'no answer' => Http::failedConnection(),
    }]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'GitHub would not complete the connection. Nothing was saved.');

    Http::assertSent(githubConnectRevoked());
    expect(SourceProvider::withTrashed()->count())->toBe(0)
        ->and(githubConnectStoredAnywhere('ghu_user_token'))->toBeFalse();
})->with(['a refusal', 'an outage', 'no answer']);

test('a user token github will not revoke still links the installation', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')], override: ['api.github.com/applications/Iv1.client/token' => Http::failedConnection()]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertSessionHas('success', 'GitHub connected. Repositories on monoventech can be picked when you add an app.');

    expect(SourceProvider::sole()->installation_id)->toBe('51842');
});

test('no reachable installation says to install first', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state']))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'Your GitHub account cannot reach an installation of Coresmith Monoven. Install it first, then connect.');

    Http::assertSent(githubConnectRevoked());
    expect(SourceProvider::count())->toBe(0)
        ->and(session(GitHubConnector::CHOICES_KEY))->toBeNull();
});

test('several reachable installations open a chooser', function () {
    [$team, $owner, $app] = githubConnectTeam();

    githubConnectOffer($owner, $team, $app)
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionMissing('error')
        ->assertSessionMissing('success');

    expect(SourceProvider::count())->toBe(0)
        ->and(session(GitHubConnector::CHOICES_KEY))->toMatchArray([
            'team' => $team->id,
            'user' => $owner->id,
            'app' => $app->id,
            'installations' => [
                ['id' => '1', 'account' => 'monoventech', 'account_type' => 'Organization', 'repository_selection' => 'all', 'suspended_at' => null, 'user_login' => null, 'user_repositories' => 4],
                ['id' => '2', 'account' => 'oseughu', 'account_type' => 'User', 'repository_selection' => 'selected', 'suspended_at' => null, 'user_login' => 'oseughu', 'user_repositories' => null],
            ],
        ]);

    Http::assertSent(githubConnectRevoked());
    Http::assertSent(githubConnectAskedAs('https://api.github.com/user'));
    Http::assertSent(githubConnectAskedAs('https://api.github.com/user/installations/1/repositories?per_page=1'));
    Http::assertNotSent(fn($request) => str_contains($request->url(), '/access_tokens'));
    githubConnectRevokedLast();

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.choose', [
                'app' => 'Coresmith Monoven',
                'installations' => [
                    ['id' => '1', 'account' => 'monoventech', 'account_type' => 'Organization', 'repository_selection' => 'all'],
                    ['id' => '2', 'account' => 'oseughu', 'account_type' => 'User', 'repository_selection' => 'selected'],
                ],
                'post_href' => route('github.installations.store'),
            ]));
});

test('choosing an offered installation links it', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectOffer($owner, $team, $app);

    $this->actingAs($owner)
        ->post(route('github.installations.store'), ['installation_id' => '2'])
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('success', 'GitHub connected. Repositories on oseughu can be picked when you add an app.');

    expect(SourceProvider::sole())
        ->team_id->toBe($team->id)
        ->github_app_id->toBe($app->id)
        ->installation_id->toBe('2')
        ->name->toBe('oseughu')
        ->account_type->toBe('User')
        ->repository_selection->toBe('selected')
        ->and(session(GitHubConnector::CHOICES_KEY))->toBeNull();

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.choose', null));
});

test('an installation id posted as a number is matched too', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectOffer($owner, $team, $app);

    $this->actingAs($owner)
        ->postJson(route('github.installations.store'), ['installation_id' => 1])
        ->assertRedirect('http://localhost/monoven/settings/git');

    expect(SourceProvider::sole()->installation_id)->toBe('1');
});

test('choosing an installation that was not offered is refused', function (mixed $installation) {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectOffer($owner, $team, $app);

    $this->actingAs($owner)
        ->post(route('github.installations.store'), $installation === null ? [] : ['installation_id' => $installation])
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'Your GitHub account cannot reach that installation, so nothing was connected.');

    expect(SourceProvider::withTrashed()->count())->toBe(0)
        ->and(session(GitHubConnector::CHOICES_KEY))->not->toBeNull();
})->with([
    'another installation' => ['51842'],
    'nothing' => [null],
    'a list' => [['2']],
    'an id with more on the end' => ['2 or 1'],
]);

test('an expired choice says so', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectOffer($owner, $team, $app);

    $this->travel(11)->minutes();

    $this->actingAs($owner)
        ->post(route('github.installations.store'), ['installation_id' => '2'])
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'That choice expired. Connect GitHub again.');

    expect(SourceProvider::count())->toBe(0);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.choose', null));
});

test('a choice is offered only to the person github showed it to', function () {
    [$team, $owner, $app] = githubConnectTeam();
    $other = User::factory()->create();
    $team->members()->attach($other, ['role' => 'owner']);
    githubConnectOffer($owner, $team, $app);

    $this->actingAs($other->fresh())
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.choose', null));

    $this->actingAs($other->fresh())
        ->post(route('github.installations.store'), ['installation_id' => '2'])
        ->assertSessionHas('error', 'That choice expired. Connect GitHub again.');

    expect(SourceProvider::count())->toBe(0);
});

test('a choice cannot be made for a team the chooser no longer manages', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectOffer($owner, $team, $app);
    $team->members()->updateExistingPivot($owner->id, ['role' => 'member']);

    $this->actingAs($owner->fresh())
        ->post(route('github.installations.store'), ['installation_id' => '2'])
        ->assertForbidden();

    expect(SourceProvider::count())->toBe(0);
});

test('reconnecting a removed installation restores the same connection and its apps', function () {
    [$team, $owner, $app] = githubConnectTeam();
    $removed = SourceProvider::factory()->for($team)->githubApp($app)->create([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
    ]);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create([
        'source_provider_id' => $removed->id,
        'repository_id' => '70000001',
        'repository_url' => 'https://github.com/monoventech/atlas.git',
    ]);
    $removed->markUninstalled();
    Cache::put(RepositoryCatalog::key($removed), ['total' => 9, 'truncated' => false, 'repositories' => []], now()->addHour());
    githubConnectFake([githubConnectInstallation(51842, 'monoventech', 'Organization', 'all')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertSessionHas('success');

    $restored = SourceProvider::sole();

    expect(SourceProvider::count())->toBe(1)
        ->and(SourceProvider::withTrashed()->count())->toBe(1)
        ->and($restored->id)->toBe($removed->id)
        ->and($restored->deleted_at)->toBeNull()
        ->and($restored->repository_selection)->toBe('all')
        ->and($application->fresh()->source_provider_id)->toBe($removed->id)
        ->and($application->fresh()->usesGitHubToken())->toBeTrue()
        ->and(Cache::has(RepositoryCatalog::key($removed)))->toBeFalse();
});

test('the same installation linked through another app is a connection of its own', function () {
    [$team, $owner, $app] = githubConnectTeam();
    $earlier = SourceProvider::factory()->for($team)->create(['type' => 'github_app', 'github_app_id' => null, 'installation_id' => '51842', 'name' => 'monoventech']);
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertSessionHas('success');

    expect(SourceProvider::count())->toBe(2)
        ->and($earlier->fresh()->github_app_id)->toBeNull()
        ->and(SourceProvider::where('github_app_id', $app->id)->sole()->installation_id)->toBe('51842');
});

test('one team linking an installation leaves another team\'s link to it alone', function () {
    [$team, $owner, $app] = githubConnectTeam();
    $other = SourceProvider::factory()->for(Team::factory()->create())->githubApp($app)->create(['installation_id' => '51842', 'name' => 'monoventech', 'access_token' => 'ghs_theirs']);
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertSessionHas('success');

    expect(SourceProvider::count())->toBe(2)
        ->and($other->fresh()->team_id)->not->toBe($team->id)
        ->and($other->fresh()->access_token)->toBe('ghs_theirs')
        ->and(SourceProvider::where('team_id', $team->id)->sole()->installation_id)->toBe('51842');
});

test('the callback refuses a team the user no longer manages', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);
    $team->members()->updateExistingPivot($owner->id, ['role' => 'member']);

    $this->actingAs($owner->fresh())
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertForbidden();

    Http::assertNothingSent();
    expect(SourceProvider::count())->toBe(0);
});

test('the callback refuses an app that has since been removed', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);
    $intent = githubConnectIntent($team, $app);
    $app->delete();

    $this->actingAs($owner)
        ->withSession($intent)
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'That connection attempt could not be verified. Start again from this page.');

    Http::assertNothingSent();
    expect(SourceProvider::count())->toBe(0);
});

test('disconnecting github with an expired token forgets the installation without a request', function () {
    [$team, $owner, $app] = githubConnectTeam();
    Http::fake();
    $provider = SourceProvider::factory()->for($team)->githubApp($app)->create([
        'access_token' => 'ghs_stale',
        'refresh_token' => 'stale-refresh',
        'token_expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($owner)
        ->from(route('source-providers.index'))
        ->delete(route('source-providers.destroy', $provider))
        ->assertRedirect(route('source-providers.index'))
        ->assertSessionHas('success', 'Disconnected. To remove the app\'s access on GitHub too, uninstall it there.');

    $trashed = SourceProvider::withTrashed()->find($provider->id);

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->access_token)->toBeNull()
        ->and($trashed->refresh_token)->toBeNull()
        ->and($trashed->token_expires_at)->toBeNull();

    Http::assertNothingSent();
});

test('disconnecting github revokes a live installation token without minting one', function () {
    [$team, $owner, $app] = githubConnectTeam();
    Http::fake(['api.github.com/installation/token' => Http::response(null, 204)]);
    $provider = SourceProvider::factory()->for($team)->githubApp($app)->create([
        'access_token' => 'ghs_live',
        'token_expires_at' => now()->addHour(),
    ]);

    $this->actingAs($owner)
        ->delete(route('source-providers.destroy', $provider))
        ->assertSessionHas('success', 'Disconnected. To remove the app\'s access on GitHub too, uninstall it there.');

    expect(SourceProvider::withTrashed()->find($provider->id))
        ->trashed()->toBeTrue()
        ->access_token->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/installation/token'
        && $request->hasHeader('Authorization', 'Bearer ghs_live'));
});

test('an outside collaborator cannot link an organization\'s whole installation', function (array $query) {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')], github: ['visible' => 1, 'total' => 9]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', ...$query]))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', "Your GitHub account can't see every repository this installation covers, so Coresmith won't link it. Ask an owner of monoventech to connect GitHub.");

    expect(SourceProvider::withTrashed()->count())->toBe(0)
        ->and(Activity::query()->where('description', 'github_installation_linked')->count())->toBe(0);
    Http::assertSent(githubConnectAskedAs('https://api.github.com/user/installations/51842/repositories?per_page=1'));
    Http::assertSent(githubConnectRevoked());
    githubConnectCounted('51842');
    githubConnectRevokedLast();
})->with([
    'named by github' => [['installation_id' => '51842', 'setup_action' => 'install']],
    'the only one reachable' => [[]],
]);

test('an organization member who can see every repository the installation covers links it', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech', 'Organization', 'all')], github: ['visible' => 9, 'total' => 9]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertSessionHas('success', 'GitHub connected. Repositories on monoventech can be picked when you add an app.');

    expect(SourceProvider::sole()->installation_id)->toBe('51842');
    Http::assertSent(githubConnectRevoked());
    Http::assertNotSent(fn($request) => $request->url() === 'https://api.github.com/user');
    githubConnectCounted('51842');
});

test('someone else\'s personal installation cannot be linked', function (array $query) {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51900, 'victim', 'User', 'all')], github: ['login' => 'oseughu']);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', ...$query]))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'This installation belongs to victim. Sign in to GitHub as victim to connect it.');

    expect(SourceProvider::withTrashed()->count())->toBe(0);
    Http::assertSent(githubConnectAskedAs('https://api.github.com/user'));
    Http::assertSent(githubConnectRevoked());
    Http::assertNotSent(fn($request) => str_contains($request->url(), '/access_tokens') || str_contains($request->url(), '/repositories'));
    githubConnectRevokedLast();
})->with([
    'named by github' => [['installation_id' => '51900', 'setup_action' => 'install']],
    'the only one reachable' => [[]],
]);

test('a personal installation of the signed-in account is linked', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51900, 'oseughu', 'User', 'all')], github: ['login' => 'oseughu']);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51900']))
        ->assertSessionHas('success', 'GitHub connected. Repositories on oseughu can be picked when you add an app.');

    expect(SourceProvider::sole())
        ->installation_id->toBe('51900')
        ->account_type->toBe('User');
    Http::assertSent(githubConnectAskedAs('https://api.github.com/user'));
    Http::assertSent(githubConnectRevoked());
    Http::assertNotSent(fn($request) => str_contains($request->url(), '/access_tokens'));
});

test('a check github cannot finish refuses the link and still revokes the user token', function (string $check) {
    [$team, $owner, $app] = githubConnectTeam();
    $personal = in_array($check, ['the signed-in account', 'an answer with no account'], true);
    githubConnectFake([githubConnectInstallation(51842, $personal ? 'oseughu' : 'monoventech', $personal ? 'User' : 'Organization')], override: match ($check) {
        'the signed-in account' => ['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)],
        'an answer with no account' => ['api.github.com/user' => Http::response(['id' => 10])],
        'what the user can see' => ['api.github.com/user/installations/*/repositories*' => Http::response(['message' => 'Server Error'], 502)],
        'an answer with no count' => ['api.github.com/user/installations/*/repositories*' => Http::response(['repositories' => []])],
        'a token to count with' => ['api.github.com/app/installations/*/access_tokens' => Http::response(['message' => 'Not Found'], 404)],
        'a suspended installation' => ['api.github.com/app/installations/*/access_tokens' => Http::response(['message' => 'This installation has been suspended'], 403)],
        'what the installation covers' => ['api.github.com/installation/repositories*' => Http::failedConnection()],
    });

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'GitHub would not complete the connection. Nothing was saved.');

    expect(SourceProvider::withTrashed()->count())->toBe(0)
        ->and(githubConnectStoredAnywhere('ghu_user_token'))->toBeFalse();
    Http::assertSent(githubConnectRevoked());
})->with([
    'the signed-in account',
    'an answer with no account',
    'what the user can see',
    'an answer with no count',
    'a token to count with',
    'a suspended installation',
    'what the installation covers',
]);

test('the token minted to count an installation is revoked even when the count fails', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')], override: ['api.github.com/installation/repositories*' => Http::response(['message' => 'Server Error'], 500)]);

    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertSessionHas('error', 'GitHub would not complete the connection. Nothing was saved.');

    githubConnectCounted('51842');
    expect(githubConnectStoredAnywhere('ghs_counting'))->toBeFalse();
});

test('a pick from the chooser is checked like a direct link, with what github showed before the token was revoked', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectOffer($owner, $team, $app, [
        githubConnectInstallation(1, 'monoventech', 'Organization', 'all'),
        githubConnectInstallation(2, 'someone-else', 'User', 'all'),
        githubConnectInstallation(3, 'victim-org', 'Organization', 'selected'),
    ], ['login' => 'oseughu', 'visible' => ['1' => 6, '3' => 1], 'total' => ['1' => 6, '3' => 9]]);
    $asUser = fn(): int => Http::recorded(fn($request) => $request->hasHeader('Authorization', 'Bearer ghu_user_token'))->count();
    $offered = $asUser();

    Http::assertSent(githubConnectRevoked());

    $this->actingAs($owner)
        ->post(route('github.installations.store'), ['installation_id' => '3'])
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', "Your GitHub account can't see every repository this installation covers, so Coresmith won't link it. Ask an owner of victim-org to connect GitHub.");

    $this->actingAs($owner)
        ->post(route('github.installations.store'), ['installation_id' => '2'])
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'This installation belongs to someone-else. Sign in to GitHub as someone-else to connect it.');

    expect(SourceProvider::count())->toBe(0)
        ->and(session(GitHubConnector::CHOICES_KEY))->not->toBeNull();

    $this->actingAs($owner)
        ->post(route('github.installations.store'), ['installation_id' => '1'])
        ->assertSessionHas('success', 'GitHub connected. Repositories on monoventech can be picked when you add an app.');

    expect(SourceProvider::sole()->installation_id)->toBe('1')
        ->and($asUser())->toBe($offered);
    githubConnectCounted('3');
    githubConnectCounted('1');
    Http::assertNotSent(fn($request) => str_contains($request->url(), '/app/installations/2/'));
});

test('a pick whose installation github will not count is refused and stays on offer', function () {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectFake([
        githubConnectInstallation(1, 'monoventech', 'Organization', 'all'),
        githubConnectInstallation(2, 'oseughu', 'User', 'selected'),
    ], override: ['api.github.com/installation/repositories*' => Http::response(['message' => 'Server Error'], 500)]);
    $this->actingAs($owner)
        ->withSession(githubConnectIntent($team, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state']))
        ->assertRedirect('http://localhost/monoven/settings/git');

    $this->actingAs($owner)
        ->post(route('github.installations.store'), ['installation_id' => '1'])
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'GitHub would not complete the connection. Nothing was saved.');

    expect(SourceProvider::count())->toBe(0)
        ->and(session(GitHubConnector::CHOICES_KEY))->not->toBeNull();
    githubConnectCounted('1');
});

test('the callback links to the team the attempt was started for, not the one last worked in', function () {
    [$monoven, $owner, $app] = githubConnectTeam();
    $acme = Team::factory()->create(['slug' => 'acme']);
    $acme->members()->attach($owner, ['role' => 'owner']);
    $owner->forceFill(['current_team_id' => $acme->id])->save();
    githubConnectFake([githubConnectInstallation(51842, 'monoventech')]);

    $this->actingAs($owner->fresh())
        ->withSession(githubConnectIntent($monoven, $app))
        ->get(githubConnectCallback(['code' => 'c0de', 'state' => 'the-state', 'installation_id' => '51842']))
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('success');

    expect(SourceProvider::sole()->team_id)->toBe($monoven->id)
        ->and($acme->sourceProviders()->count())->toBe(0);
});

test('a choice links to the team it was offered for, not the one last worked in', function () {
    [$monoven, $owner, $app] = githubConnectTeam();
    $acme = Team::factory()->create(['slug' => 'acme']);
    $acme->members()->attach($owner, ['role' => 'owner']);
    githubConnectOffer($owner, $monoven, $app);
    $owner->forceFill(['current_team_id' => $acme->id])->save();

    $this->actingAs($owner->fresh())
        ->post(route('github.installations.store'), ['installation_id' => '2'])
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('success');

    expect(SourceProvider::sole()->team_id)->toBe($monoven->id)
        ->and($acme->sourceProviders()->count())->toBe(0);
});

test('a choice whose app is no longer offered is refused', function (string $change) {
    [$team, $owner, $app] = githubConnectTeam();
    githubConnectOffer($owner, $team, $app);

    match ($change) {
        'the app was removed' => $app->delete(),
        'the environment app replaced it' => githubConnectEnv(),
    };

    $this->actingAs($owner)
        ->post(route('github.installations.store'), ['installation_id' => '2'])
        ->assertRedirect('http://localhost/monoven/settings/git')
        ->assertSessionHas('error', 'That connection attempt could not be verified. Start again from this page.');

    expect(SourceProvider::withTrashed()->count())->toBe(0);

    $this->actingAs($owner)
        ->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.choose', null));
})->with(['the app was removed', 'the environment app replaced it']);

test('choosing an installation is throttled in a bucket of its own', function () {
    $route = Route::getRoutes()->getByName('github.installations.store');

    expect($route->methods())->toBe(['POST'])
        ->and($route->uri())->toBe('settings/source-providers/github_app/installations')
        ->and($route->gatherMiddleware())->toContain('throttle:10,1,github-choose')
        ->and($route->gatherMiddleware())->toContain('auth');
});

test('every parameter that carries a credential is marked sensitive', function () {
    $named = ['code', 'secret', 'token', 'userToken', 'clientSecret', 'webhookSecret', 'privateKey', 'available'];
    $unmarked = [];

    foreach ([GitHubConnector::class, GitHubAppHealth::class, InstallationChoices::class, GitHubSettingsPresenter::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $carries = ($type instanceof ReflectionNamedType && $type->getName() === GitHubAppCredentials::class)
                    || in_array($parameter->getName(), $named, true);

                if ($carries && $parameter->getAttributes(SensitiveParameter::class) === []) {
                    $unmarked[] = "{$class}::{$method->getName()} \${$parameter->getName()}";
                }
            }
        }
    }

    expect($unmarked)->toBe([]);
});
