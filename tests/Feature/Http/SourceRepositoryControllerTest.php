<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitHub\RepositoryCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

function repositoryPickerTeam(string $role): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role]);

    return [$team, $user->fresh()];
}

function repositoryPickerConnection(Team $team, array $attributes = []): SourceProvider
{
    $provider = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create(array_merge([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'access_token' => 'ghs_supersecret',
        'token_expires_at' => now()->addHour(),
    ], $attributes));

    Cache::put(RepositoryCatalog::key($provider), [
        'total' => 2,
        'truncated' => false,
        'repositories' => [
            ['id' => '70000001', 'full_name' => 'monoventech/atlas', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => '2026-10-17T09:00:00Z'],
            ['id' => '70000002', 'full_name' => 'monoventech/forge', 'private' => false, 'default_branch' => 'trunk', 'archived' => false, 'pushed_at' => '2026-10-10T09:00:00Z'],
        ],
    ], now()->addHour());

    return $provider;
}

function repositoryPickerPath(SourceProvider $provider, ?string $repository = null): string
{
    return $repository === null
        ? route('source-providers.repositories', $provider, false)
        : route('source-providers.branches', [$provider, $repository], false);
}

function repositoryPickerListing(): array
{
    return ['total_count' => 2, 'repositories' => [
        ['id' => 70000001, 'full_name' => 'monoventech/atlas', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => '2026-10-17T09:00:00Z'],
        ['id' => 70000002, 'full_name' => 'monoventech/forge', 'private' => false, 'default_branch' => 'trunk', 'archived' => false, 'pushed_at' => '2026-10-10T09:00:00Z'],
    ]];
}

beforeEach(fn() => Http::preventStrayRequests());

test('an owner or admin gets the repositories as json', function (string $role) {
    [$team, $user] = repositoryPickerTeam($role);
    $provider = repositoryPickerConnection($team);

    $response = $this->actingAs($user)->getJson(repositoryPickerPath($provider))->assertOk();

    $response
        ->assertJsonStructure(['repositories' => [['id', 'full_name', 'private', 'default_branch']], 'total', 'truncated'])
        ->assertJsonPath('repositories.0.id', '70000001')
        ->assertJsonPath('repositories.0.full_name', 'monoventech/atlas')
        ->assertJsonPath('repositories.0.private', true)
        ->assertJsonPath('repositories.0.default_branch', 'main')
        ->assertJsonPath('repositories.1.id', '70000002')
        ->assertJsonPath('repositories.1.private', false)
        ->assertJsonPath('total', 2)
        ->assertJsonPath('truncated', false);

    expect($response->getContent())->not->toContain('ghs_')
        ->and($response->getContent())->not->toContain('access_token');
    Http::assertNothingSent();
})->with(['owner', 'admin']);

test('a truncated catalog says so to the browser', function () {
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);
    Cache::put(RepositoryCatalog::key($provider), [
        'total' => 2345,
        'truncated' => true,
        'repositories' => [['id' => '70000001', 'full_name' => 'monoventech/atlas', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => null]],
    ], now()->addHour());

    $this->actingAs($user)->getJson(repositoryPickerPath($provider))
        ->assertOk()
        ->assertJsonPath('total', 2345)
        ->assertJsonPath('truncated', true)
        ->assertJsonCount(1, 'repositories');
});

test('a cold cache is filled from github once and then served from the cache', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(repositoryPickerListing())]);
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);
    Cache::forget(RepositoryCatalog::key($provider));

    $this->actingAs($user)->getJson(repositoryPickerPath($provider))
        ->assertOk()
        ->assertJsonPath('repositories.0.full_name', 'monoventech/atlas')
        ->assertJsonPath('total', 2);
    $this->actingAs($user)->getJson(repositoryPickerPath($provider))->assertOk();

    Http::assertSentCount(1);
    expect(Cache::has(RepositoryCatalog::key($provider)))->toBeTrue();
});

test('a member who cannot create apps is refused', function () {
    [$team, $user] = repositoryPickerTeam('member');
    $provider = repositoryPickerConnection($team);

    $this->actingAs($user)->getJson(repositoryPickerPath($provider))->assertForbidden();
    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000001'))->assertForbidden();

    Http::assertNothingSent();
});

test('the ability is judged in the connections own team, not in any team the person is in', function () {
    [$team, $user] = repositoryPickerTeam('member');
    Team::factory()->create()->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $provider = repositoryPickerConnection($team);

    $this->actingAs($user->fresh())->getJson(repositoryPickerPath($provider))->assertForbidden();
});

test('a connection from another team is a not found, even for that other teams owner working in this teams address', function () {
    [$theirs, $owner] = repositoryPickerTeam('owner');
    $mine = Team::factory()->create(['slug' => 'acme']);
    $mine->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $theirs->forceFill(['slug' => 'monoven'])->save();
    $connection = repositoryPickerConnection($theirs);
    [, $stranger] = repositoryPickerTeam('owner');

    $this->actingAs($stranger)->getJson(repositoryPickerPath($connection))->assertNotFound();
    $this->actingAs($stranger)->getJson(repositoryPickerPath($connection, '70000001'))->assertNotFound();
    $this->actingAs($owner)->getJson('/acme' . repositoryPickerPath($connection))->assertNotFound();
    $this->actingAs($owner)->getJson('/acme' . repositoryPickerPath($connection, '70000001'))->assertNotFound();
    $this->actingAs($owner)->getJson('/monoven' . repositoryPickerPath($connection))->assertOk()->assertJsonPath('total', 2);

    Http::assertNothingSent();
});

test('a connection that is disconnected, suspended or not linked to an installation is a not found', function (string $state) {
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);

    match ($state) {
        'disconnected' => $provider->delete(),
        'suspended' => $provider->markSuspended(now()),
        'uninstalled' => $provider->forceFill(['installation_id' => null])->save(),
    };

    $this->actingAs($user)->getJson(repositoryPickerPath($provider))->assertNotFound();
    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000001'))->assertNotFound();
    Http::assertNothingSent();
})->with(['disconnected', 'suspended', 'uninstalled']);

test('a connection that is not a github app is a not found', function (string $type) {
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team, ['type' => $type, 'access_token' => 'token']);

    $this->actingAs($user)->getJson(repositoryPickerPath($provider))->assertNotFound();
    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000001'))->assertNotFound();
    Http::assertNothingSent();
})->with(['gitlab', 'bitbucket', 'custom']);

test('a github failure is a bad gateway with a sentence', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(['message' => 'Server Error'], 500)]);
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);
    Cache::forget(RepositoryCatalog::key($provider));

    $this->actingAs($user)->getJson(repositoryPickerPath($provider))
        ->assertStatus(502)
        ->assertExactJson(['message' => 'GitHub did not send the repository list. GitHub rejected the request: Server Error']);

    expect(Cache::has(RepositoryCatalog::key($provider)))->toBeFalse();
});

test('a github that does not answer is a bad gateway too', function () {
    Http::fake(fn() => throw new ConnectionException('down'));
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);
    Cache::forget(RepositoryCatalog::key($provider));

    $this->actingAs($user)->getJson(repositoryPickerPath($provider))
        ->assertStatus(502)
        ->assertExactJson(['message' => 'GitHub did not send the repository list. GitHub did not answer.']);
});

test('a connection made through an app the panel no longer has is a bad gateway that says what to do', function () {
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);
    Cache::forget(RepositoryCatalog::key($provider));
    GitHubApp::query()->delete();

    $this->actingAs($user)->getJson(repositoryPickerPath($provider))
        ->assertStatus(502)
        ->assertExactJson(['message' => 'GitHub did not send the repository list. This GitHub connection was made through an app this panel no longer has. Connect GitHub again from Settings, Git.']);

    Http::assertNothingSent();
});

test('branches come back with the default first', function () {
    Http::fake(['api.github.com/repos/monoventech/forge/branches*' => Http::response([['name' => 'release/2.0'], ['name' => 'trunk']])]);
    [$team, $user] = repositoryPickerTeam('admin');
    $provider = repositoryPickerConnection($team);

    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000002'))
        ->assertOk()
        ->assertExactJson(['branches' => ['trunk', 'release/2.0'], 'default' => 'trunk']);
    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000002'))->assertOk();

    Http::assertSentCount(1);
});

test('branches of a repository the connection does not list are a not found json after one more look', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(repositoryPickerListing())]);
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);

    $this->actingAs($user)->withHeaders(['Accept' => 'text/html'])->get(repositoryPickerPath($provider, '70000999'))
        ->assertNotFound()
        ->assertExactJson(['message' => 'GitHub does not give Coresmith access to that repository. Pick another, or add it under Manage repositories on GitHub.']);

    Http::assertSentCount(1);
    Http::assertNotSent(fn($request) => str_contains($request->url(), '/branches'));
});

test('branches of a repository that grew into the list since it was cached are found', function () {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response(['total_count' => 3, 'repositories' => [
            ['id' => 70000003, 'full_name' => 'monoventech/newest', 'private' => true, 'default_branch' => 'develop', 'archived' => false, 'pushed_at' => '2026-10-18T09:00:00Z'],
            ...repositoryPickerListing()['repositories'],
        ]]),
        'api.github.com/repos/monoventech/newest/branches*' => Http::response([['name' => 'develop'], ['name' => 'main']]),
    ]);
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);

    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000003'))
        ->assertOk()
        ->assertExactJson(['branches' => ['develop', 'main'], 'default' => 'develop']);
});

test('a branch list github will not send is a bad gateway that names the branch list', function () {
    Http::fake(['api.github.com/repos/monoventech/atlas/branches*' => Http::response(['message' => 'Not Found'], 404)]);
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);

    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000001'))
        ->assertStatus(502)
        ->assertExactJson(['message' => 'GitHub did not send the branch list. GitHub rejected the request: Not Found']);

    expect(Cache::has(RepositoryCatalog::branchesKey($provider, '70000001')))->toBeFalse();
});

test('a branch list github does not answer for is a bad gateway that names the branch list', function () {
    Http::fake(fn() => throw new ConnectionException('down'));
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);

    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000001'))
        ->assertStatus(502)
        ->assertExactJson(['message' => 'GitHub did not send the branch list. GitHub did not answer.']);
});

test('a branch list for a connection whose app is gone names the branch list and what to do', function () {
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);
    GitHubApp::query()->delete();

    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000001'))
        ->assertStatus(502)
        ->assertExactJson(['message' => 'GitHub did not send the branch list. This GitHub connection was made through an app this panel no longer has. Connect GitHub again from Settings, Git.']);

    Http::assertNothingSent();
});

test('when the repository list is what github will not send, the branches endpoint names the repository list', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(['message' => 'Server Error'], 500)]);
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);
    Cache::forget(RepositoryCatalog::key($provider));

    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000001'))
        ->assertStatus(502)
        ->assertExactJson(['message' => 'GitHub did not send the repository list. GitHub rejected the request: Server Error']);

    Http::assertNotSent(fn($request) => str_contains($request->url(), '/branches'));
});

test('a repository id that is not a number never reaches the controller', function () {
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);

    foreach (['abc', '70000001abc', '-1', '1.5'] as $id) {
        $this->actingAs($user)->getJson("/settings/source-providers/{$provider->id}/repositories/{$id}/branches")->assertNotFound();
    }

    Http::assertNothingSent();
});

test('both endpoints sit behind sign-in and named rate limits', function () {
    [$team] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);

    $this->get(repositoryPickerPath($provider))->assertRedirect(route('login'));
    $this->get(repositoryPickerPath($provider, '70000001'))->assertRedirect(route('login'));
    $this->getJson(repositoryPickerPath($provider))->assertUnauthorized();
    $this->getJson(repositoryPickerPath($provider, '70000001'))->assertUnauthorized();

    expect(Route::getRoutes()->getByName('source-providers.repositories')->gatherMiddleware())->toContain('throttle:30,1,source-repositories')
        ->and(Route::getRoutes()->getByName('source-providers.branches')->gatherMiddleware())->toContain('throttle:30,1,source-branches')
        ->and(Route::getRoutes()->getByName('source-providers.repositories')->uri())->toBe('settings/source-providers/{sourceProvider}/repositories')
        ->and(Route::getRoutes()->getByName('source-providers.branches')->uri())->toBe('settings/source-providers/{sourceProvider}/repositories/{repositoryId}/branches')
        ->and(Route::getRoutes()->getByName('source-providers.repositories')->wheres)->toBe(['sourceProvider' => '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}'])
        ->and(Route::getRoutes()->getByName('source-providers.branches')->wheres)->toBe(['sourceProvider' => '[0-7][0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{25}', 'repositoryId' => '[0-9]+']);
});

test('the rate limit stops a person who hammers an endpoint and leaves the other bucket alone', function () {
    Http::fake(['api.github.com/repos/monoventech/atlas/branches*' => Http::response([['name' => 'main']])]);
    [$team, $user] = repositoryPickerTeam('owner');
    $provider = repositoryPickerConnection($team);

    foreach (range(1, 30) as $ignored) {
        $this->actingAs($user)->getJson(repositoryPickerPath($provider))->assertOk();
    }

    $this->actingAs($user)->getJson(repositoryPickerPath($provider))->assertStatus(429);
    $this->actingAs($user)->getJson(repositoryPickerPath($provider, '70000001'))->assertOk();
});

test('the picker knows the not visible sentence by the catalog\'s own words', function () {
    $source = (string) file_get_contents(resource_path('js/lib/github.ts'));

    preg_match("~export const REPOSITORY_NOT_VISIBLE =\s+'([^']+)'~", $source, $sentence);

    expect($sentence[1] ?? null)->toBe(RepositoryCatalog::NOT_VISIBLE);
});
