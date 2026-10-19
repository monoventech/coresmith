<?php

declare(strict_types=1);

use App\Exceptions\GitProviderException;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Services\Git\GitHub\RepositoryCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function catalogConnection(array $attributes = []): SourceProvider
{
    return SourceProvider::factory()->githubApp()->create(array_merge([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'access_token' => 'ghs_live',
        'token_expires_at' => now()->addHour(),
    ], $attributes));
}

function catalogRepository(int $id, string $fullName, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'full_name' => $fullName,
        'private' => true,
        'default_branch' => 'main',
        'archived' => false,
        'pushed_at' => '2026-10-17T09:00:00Z',
    ], $overrides);
}

function catalogListing(array $repositories, ?int $total = null): array
{
    return ['total_count' => $total ?? count($repositories), 'repositories' => $repositories];
}

function catalogEntry(string $id, string $fullName, string $defaultBranch = 'main', bool $private = true): array
{
    return [
        'id' => $id,
        'full_name' => $fullName,
        'private' => $private,
        'default_branch' => $defaultBranch,
        'archived' => false,
        'pushed_at' => '2026-10-17T09:00:00Z',
    ];
}

function catalogCatalog(): RepositoryCatalog
{
    return app(RepositoryCatalog::class);
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->freezeTime();
});

test('the list is fetched once and served from the cache for five minutes', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(catalogListing([catalogRepository(70000001, 'monoventech/atlas')]))]);
    $provider = catalogConnection();

    $first = catalogCatalog()->for($provider);
    $second = catalogCatalog()->for($provider);

    expect($first)->toBe([
        'total' => 1,
        'truncated' => false,
        'repositories' => [catalogEntry('70000001', 'monoventech/atlas')],
    ])
        ->and($second)->toBe($first)
        ->and(RepositoryCatalog::MINUTES)->toBe(5);
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.github.com/installation/repositories?per_page=100&page=1'
        && $request->hasHeader('Authorization', 'Bearer ghs_live'));

    $this->travel(299)->seconds();
    catalogCatalog()->for($provider);
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    catalogCatalog()->for($provider);
    Http::assertSentCount(2);

    $this->travel(6)->minutes();
    catalogCatalog()->for($provider);
    Http::assertSentCount(3);
});

test('a repository granted after the list was cached is found by fetching again', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::sequence()
        ->push(catalogListing([catalogRepository(70000001, 'monoventech/atlas')]))
        ->push(catalogListing([
            catalogRepository(70000002, 'monoventech/forge', ['default_branch' => 'trunk', 'private' => false]),
            catalogRepository(70000001, 'monoventech/atlas'),
        ]))]);
    $provider = catalogConnection();

    expect(catalogCatalog()->for($provider)['repositories'])->toHaveCount(1);

    $found = catalogCatalog()->find($provider, '70000002');

    expect($found)->toBe(catalogEntry('70000002', 'monoventech/forge', 'trunk', false))
        ->and(catalogCatalog()->cached($provider)['repositories'])->toHaveCount(2)
        ->and(catalogCatalog()->cached($provider)['total'])->toBe(2);
    Http::assertSentCount(2);

    expect(catalogCatalog()->find($provider, '70000001')['full_name'])->toBe('monoventech/atlas');
    Http::assertSentCount(2);
});

test('a repository github still does not list is null after exactly one more fetch', function (string $id) {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(catalogListing([catalogRepository(70000001, 'monoventech/atlas')]))]);
    $provider = catalogConnection();

    catalogCatalog()->for($provider);

    expect(catalogCatalog()->find($provider, $id))->toBeNull();
    Http::assertSentCount(2);
})->with([
    'an id that is not listed' => ['70000009'],
    'a listed id with a leading zero' => ['070000001'],
    'a listed id with digits after it' => ['700000010'],
]);

test('a listed repository costs no fetch at all', function () {
    Http::fake();
    $provider = catalogConnection();
    Cache::put(RepositoryCatalog::key($provider), [
        'total' => 1,
        'truncated' => false,
        'repositories' => [catalogEntry('70000001', 'monoventech/atlas')],
    ], now()->addHour());

    expect(catalogCatalog()->find($provider, '70000001'))->toBe(catalogEntry('70000001', 'monoventech/atlas'));
    Http::assertNothingSent();
});

test('an unknown id on a cold cache costs one fetch, not two', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(catalogListing([catalogRepository(70000001, 'monoventech/atlas')]))]);

    expect(catalogCatalog()->find(catalogConnection(), '70000009'))->toBeNull();
    Http::assertSentCount(1);
});

test('unknown ids refetch the list at most once a minute for each connection', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(catalogListing([catalogRepository(70000001, 'monoventech/atlas')]))]);
    $provider = catalogConnection();
    $other = catalogConnection();

    catalogCatalog()->for($provider);
    catalogCatalog()->find($provider, '70000002');
    catalogCatalog()->find($provider, '70000003');
    catalogCatalog()->find($provider, '70000004');

    Http::assertSentCount(2);

    catalogCatalog()->for($other);
    catalogCatalog()->find($other, '70000002');

    Http::assertSentCount(4);

    $this->travel(59)->seconds();
    catalogCatalog()->find($provider, '70000005');
    Http::assertSentCount(4);

    $this->travel(2)->seconds();
    catalogCatalog()->find($provider, '70000006');
    Http::assertSentCount(5);

    catalogCatalog()->find($provider, '70000007');
    Http::assertSentCount(5);
});

test('an id that cannot be a repository id is never looked up', function (string $id) {
    Http::fake();

    expect(catalogCatalog()->find(catalogConnection(), $id))->toBeNull();
    Http::assertNothingSent();
})->with(['', 'abc', '7000 0001', "70000001\n", '-1', '1.5', str_repeat('9', 21)]);

test('a truncated list says so', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::sequence()
        ->push(catalogListing([catalogRepository(70000001, 'monoventech/atlas'), catalogRepository(70000002, 'monoventech/forge')], 2345))
        ->push(catalogListing([], 2345))]);

    $catalog = catalogCatalog()->for(catalogConnection());

    expect($catalog['total'])->toBe(2345)
        ->and($catalog['truncated'])->toBeTrue()
        ->and($catalog['repositories'])->toHaveCount(2)
        ->and(array_keys($catalog))->toBe(['total', 'truncated', 'repositories']);
});

test('a list that holds every repository is not truncated', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(catalogListing([
        catalogRepository(70000001, 'monoventech/atlas'),
        catalogRepository(70000002, 'monoventech/forge'),
    ]))]);

    expect(catalogCatalog()->for(catalogConnection())['truncated'])->toBeFalse();
});

test('an installation that can see nothing has an empty list', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(catalogListing([]))]);

    expect(catalogCatalog()->for(catalogConnection()))->toBe(['total' => 0, 'truncated' => false, 'repositories' => []]);
});

test('at most ten pages of a long list are read', function () {
    $page = catalogListing(array_map(fn(int $i): array => catalogRepository($i, "monoventech/repo-{$i}"), range(1, 100)), 2345);
    Http::fake(['api.github.com/installation/repositories*' => Http::response($page)]);

    $catalog = catalogCatalog()->for(catalogConnection());

    expect(RepositoryCatalog::MAX_PAGES)->toBe(10)
        ->and($catalog['total'])->toBe(2345)
        ->and($catalog['truncated'])->toBeTrue()
        ->and($catalog['repositories'])->toHaveCount(1000);
    Http::assertSentCount(10);
    Http::assertNotSent(fn(Request $request) => str_ends_with($request->url(), 'page=11'));
});

test('a failed fetch is not cached and the next call tries again', function () {
    $healthy = false;
    Http::fake(['api.github.com/installation/repositories*' => function () use (&$healthy) {
        return $healthy
            ? Http::response(catalogListing([catalogRepository(70000001, 'monoventech/atlas')]))
            : Http::response(['message' => 'Server Error'], 500);
    }]);
    $provider = catalogConnection();

    expect(fn() => catalogCatalog()->for($provider))->toThrow(GitProviderException::class, 'Server Error')
        ->and(catalogCatalog()->cached($provider))->toBeNull();

    $healthy = true;

    expect(catalogCatalog()->for($provider)['total'])->toBe(1);
});

test('a refetch that fails leaves the list that was cached', function () {
    $healthy = true;
    Http::fake(['api.github.com/installation/repositories*' => function () use (&$healthy) {
        return $healthy
            ? Http::response(catalogListing([catalogRepository(70000001, 'monoventech/atlas')]))
            : Http::response(['message' => 'Server Error'], 500);
    }]);
    $provider = catalogConnection();

    catalogCatalog()->for($provider);
    $healthy = false;

    expect(fn() => catalogCatalog()->find($provider, '70000002'))->toThrow(GitProviderException::class)
        ->and(catalogCatalog()->cached($provider)['repositories'])->toHaveCount(1);
});

test('a connection whose app is gone cannot list anything', function () {
    $provider = catalogConnection();
    GitHubApp::query()->delete();

    expect(fn() => catalogCatalog()->for($provider))->toThrow(GitProviderException::class, 'no longer has');
    Http::assertNothingSent();
});

test('the cache is per connection and per environment', function () {
    $first = catalogConnection();
    $second = catalogConnection();

    expect(RepositoryCatalog::key($first))->toBe('github:repositories:testing:' . $first->id)
        ->and(RepositoryCatalog::key($first))->toContain(config('app.env'))
        ->and(RepositoryCatalog::key($first))->toContain($first->id)
        ->and(RepositoryCatalog::key($second))->not->toBe(RepositoryCatalog::key($first))
        ->and(RepositoryCatalog::branchesKey($first, '70000001'))->toBe(RepositoryCatalog::key($first) . ':branches:70000001')
        ->and(RepositoryCatalog::branchesKey($first, '70000002'))->not->toBe(RepositoryCatalog::branchesKey($first, '70000001'));

    config(['app.env' => 'production']);

    expect(RepositoryCatalog::key($first))->toBe('github:repositories:production:' . $first->id);
});

test('one connection never reads another connections list', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(catalogListing([catalogRepository(70000001, 'monoventech/atlas')]))]);
    $first = catalogConnection();
    $second = catalogConnection();

    catalogCatalog()->for($first);

    expect(catalogCatalog()->cached($first))->not->toBeNull()
        ->and(catalogCatalog()->cached($second))->toBeNull();

    catalogCatalog()->for($second);
    Http::assertSentCount(2);
});

test('the cached list is read without a request', function () {
    Http::fake();
    $provider = catalogConnection();

    expect(catalogCatalog()->cached($provider))->toBeNull();

    $catalog = ['total' => 1, 'truncated' => false, 'repositories' => [catalogEntry('70000001', 'monoventech/atlas')]];
    Cache::put(RepositoryCatalog::key($provider), $catalog, now()->addHour());

    expect(catalogCatalog()->cached($provider))->toBe($catalog);
    Http::assertNothingSent();
});

test('a cached value of the wrong shape counts as nothing cached', function (mixed $value) {
    Http::fake();
    $provider = catalogConnection();
    Cache::put(RepositoryCatalog::key($provider), $value, now()->addHour());

    expect(catalogCatalog()->cached($provider))->toBeNull();
    Http::assertNothingSent();
})->with(function () {
    $entry = ['id' => '70000001', 'full_name' => 'monoventech/atlas', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => null];

    return [
        'text' => ['junk'],
        'a number' => [42],
        'no total' => [['truncated' => false, 'repositories' => [$entry]]],
        'a total that is not a number' => [['total' => '1', 'truncated' => false, 'repositories' => [$entry]]],
        'no truncated flag' => [['total' => 1, 'repositories' => [$entry]]],
        'a truncated flag that is not a boolean' => [['total' => 1, 'truncated' => 'no', 'repositories' => [$entry]]],
        'no repositories' => [['total' => 1, 'truncated' => false]],
        'repositories that are not a list' => [['total' => 1, 'truncated' => false, 'repositories' => 'x']],
    ];
});

test('forgetting a list makes the next call fetch again, for that connection only', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(catalogListing([catalogRepository(70000001, 'monoventech/atlas')]))]);
    $first = catalogConnection();
    $second = catalogConnection();

    catalogCatalog()->for($first);
    catalogCatalog()->for($second);
    Http::assertSentCount(2);

    catalogCatalog()->forget($first);

    expect(catalogCatalog()->cached($first))->toBeNull()
        ->and(catalogCatalog()->cached($second))->not->toBeNull();

    catalogCatalog()->for($first);
    catalogCatalog()->for($second);
    Http::assertSentCount(3);
});

test('branches put the default branch first and are cached for two minutes', function () {
    Http::fake(['api.github.com/repos/monoventech/atlas/branches*' => Http::response([
        ['name' => 'main', 'protected' => true],
        ['name' => 'release/2.0', 'protected' => false],
        ['name' => 'trunk', 'protected' => false],
        ['name' => 'feature/login-form', 'protected' => false],
    ])]);
    $provider = catalogConnection();
    $repository = catalogEntry('70000001', 'monoventech/atlas', 'trunk');

    $branches = catalogCatalog()->branches($provider, $repository);

    expect($branches)->toBe(['trunk', 'main', 'release/2.0', 'feature/login-form'])
        ->and(catalogCatalog()->branches($provider, $repository))->toBe($branches)
        ->and(RepositoryCatalog::BRANCH_MINUTES)->toBe(2);
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->url() === 'https://api.github.com/repos/monoventech/atlas/branches?per_page=100'
        && $request->hasHeader('Authorization', 'Bearer ghs_live'));

    $this->travel(119)->seconds();
    catalogCatalog()->branches($provider, $repository);
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    catalogCatalog()->branches($provider, $repository);
    Http::assertSentCount(2);
});

test('the default branch is offered first even when github did not list it', function () {
    Http::fake(['api.github.com/repos/monoventech/atlas/branches*' => Http::response([['name' => 'a'], ['name' => 'b'], ['name' => 'a']])]);

    expect(catalogCatalog()->branches(catalogConnection(), catalogEntry('70000001', 'monoventech/atlas', 'trunk')))->toBe(['trunk', 'a', 'b']);
});

test('a repository with no other branches offers its default alone', function () {
    Http::fake(['api.github.com/repos/monoventech/atlas/branches*' => Http::response([])]);

    expect(catalogCatalog()->branches(catalogConnection(), catalogEntry('70000001', 'monoventech/atlas')))->toBe(['main']);
});

test('branches are cached for each repository separately', function () {
    Http::fake([
        'api.github.com/repos/monoventech/atlas/branches*' => Http::response([['name' => 'main'], ['name' => 'one']]),
        'api.github.com/repos/monoventech/forge/branches*' => Http::response([['name' => 'trunk'], ['name' => 'two']]),
    ]);
    $provider = catalogConnection();

    $atlas = catalogCatalog()->branches($provider, catalogEntry('70000001', 'monoventech/atlas'));
    $forge = catalogCatalog()->branches($provider, catalogEntry('70000002', 'monoventech/forge', 'trunk'));

    expect($atlas)->toBe(['main', 'one'])
        ->and($forge)->toBe(['trunk', 'two'])
        ->and(Cache::get(RepositoryCatalog::branchesKey($provider, '70000001')))->toBe($atlas)
        ->and(Cache::get(RepositoryCatalog::branchesKey($provider, '70000002')))->toBe($forge);
    Http::assertSentCount(2);
});

test('branches that github would not list are not cached and say why', function () {
    Http::fake(['api.github.com/repos/monoventech/atlas/branches*' => Http::response(['message' => 'Not Found'], 404)]);
    $provider = catalogConnection();

    expect(fn() => catalogCatalog()->branches($provider, catalogEntry('70000001', 'monoventech/atlas')))->toThrow(GitProviderException::class, 'Not Found')
        ->and(Cache::has(RepositoryCatalog::branchesKey($provider, '70000001')))->toBeFalse();
});

test('forgetting the list leaves branch lists to run out their own two minutes', function () {
    Http::fake(['api.github.com/repos/monoventech/atlas/branches*' => Http::response([['name' => 'main']])]);
    $provider = catalogConnection();

    catalogCatalog()->branches($provider, catalogEntry('70000001', 'monoventech/atlas'));
    catalogCatalog()->forget($provider);

    expect(Cache::has(RepositoryCatalog::branchesKey($provider, '70000001')))->toBeTrue();
});
