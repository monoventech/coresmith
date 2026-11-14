<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\ReservePortBlock;
use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Git\GitHub\RepositoryCatalog;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function linkedStoreConnection(Team $team, array $attributes = [], bool $seed = true): SourceProvider
{
    $provider = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create(array_merge([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'access_token' => 'ghs_supersecret',
        'token_expires_at' => now()->addHour(),
    ], $attributes));

    if ($seed) {
        Cache::put(RepositoryCatalog::key($provider), [
            'total' => 1,
            'truncated' => false,
            'repositories' => [
                ['id' => '70000001', 'full_name' => 'monoventech/atlas', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => '2026-10-17T09:00:00Z'],
            ],
        ], now()->addHour());
    }

    return $provider;
}

function linkedStoreTeam(TeamRole $role = TeamRole::Owner): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);

    return [$user->fresh(), $server, linkedStoreConnection($team)];
}

function linkedStorePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Atlas',
        'type' => 'php',
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
    ], $overrides);
}

function linkedStoreUnavailable(): string
{
    return "That GitHub connection isn't available. Pick another one, or connect GitHub again in Git settings.";
}

function linkedStoreSuspended(): string
{
    return 'GitHub has suspended this installation. Unsuspend it on GitHub to deploy again.';
}

function linkedStoreNotPicked(): string
{
    return 'Pick a repository from the list.';
}

function linkedStoreErrors(string $field): array
{
    return session('errors')->get($field);
}

function linkedStoreFill(mixed $value, string $connection): mixed
{
    if ($value === ':connection') {
        return $connection;
    }

    return is_array($value) ? array_map(fn(mixed $item): mixed => linkedStoreFill($item, $connection), $value) : $value;
}

function linkedStoreListing(): array
{
    return ['total_count' => 1, 'repositories' => [
        ['id' => 70000001, 'full_name' => 'monoventech/atlas', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => '2026-10-17T09:00:00Z'],
    ]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    config(['inertia.ssr.enabled' => false]);
});

test('an app picked from github is stored with its link and a url coresmith built', function () {
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
            'repository_url' => 'https://evil.example/x.git',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $application = Application::query()->sole();

    expect($application->repository_url)->toBe('https://github.com/monoventech/atlas.git')
        ->and($application->repository_provider)->toBe('github')
        ->and($application->source_provider_id)->toBe($connection->id)
        ->and($application->repository_id)->toBe('70000001')
        ->and($application->repository_branch)->toBe('main')
        ->and($application->team_id)->toBe($server->team_id)
        ->and($application->server_id)->toBe($server->id)
        ->and($application->slug)->toBe('atlas')
        ->and($application->githubConnection()?->is($connection))->toBeTrue()
        ->and($application->repositoryFullName())->toBe('monoventech/atlas');
    Queue::assertPushed(ReservePortBlock::class);
    Http::assertNothingSent();
});

test('the url comes from github even when the browser sends none or a different one', function (?string $sent) {
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
            'repository_url' => $sent,
        ]))
        ->assertSessionHasNoErrors();

    expect(Application::query()->sole()->repository_url)->toBe('https://github.com/monoventech/atlas.git');
})->with([
    'no url' => [null],
    'another github repository' => ['https://github.com/someone-else/secrets.git'],
    'an ssh url' => ['git@github.com:someone-else/secrets.git'],
    'another host' => ['https://evil.example/x.git'],
]);

test('a repository the installation cannot see is refused', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(linkedStoreListing())]);
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000999',
        ]))
        ->assertSessionHasErrors(['repository_id' => 'GitHub does not give Coresmith access to that repository. Pick another, or add it under Manage repositories on GitHub.']);

    expect(Application::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertSentCount(1);
});

test('a repository granted since the list was cached is accepted after one more look', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(['total_count' => 2, 'repositories' => [
        ['id' => 70000002, 'full_name' => 'monoventech/forge', 'private' => false, 'default_branch' => 'trunk', 'archived' => false, 'pushed_at' => '2026-10-18T09:00:00Z'],
        ...linkedStoreListing()['repositories'],
    ]])]);
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000002',
            'repository_branch' => 'trunk',
        ]))
        ->assertSessionHasNoErrors();

    $application = Application::query()->sole();

    expect($application->repository_url)->toBe('https://github.com/monoventech/forge.git')
        ->and($application->repository_id)->toBe('70000002')
        ->and($application->repository_branch)->toBe('trunk');
    Http::assertSentCount(1);
});

test("another team's connection cannot be named", function () {
    [$owner, $server] = linkedStoreTeam();
    [, , $foreign] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $foreign->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors('source_provider_id')
        ->assertSessionDoesntHaveErrors('repository_id');

    expect(linkedStoreErrors('source_provider_id'))->toBe([linkedStoreUnavailable()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test("a connection of the person's other team cannot be named for this server", function () {
    [$owner, $server] = linkedStoreTeam();
    $elsewhere = Team::factory()->create();
    $elsewhere->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $theirs = linkedStoreConnection($elsewhere);

    $this->actingAs($owner->fresh())
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $theirs->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors('source_provider_id');

    expect(linkedStoreErrors('source_provider_id'))->toBe([linkedStoreUnavailable()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a disconnected connection cannot be named', function (string $how) {
    [$owner, $server, $connection] = linkedStoreTeam();

    match ($how) {
        'disconnected' => $connection->delete(),
        'uninstalled on github' => $connection->markUninstalled(),
    };

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors('source_provider_id');

    expect(linkedStoreErrors('source_provider_id'))->toBe([linkedStoreUnavailable()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['disconnected', 'uninstalled on github']);

test('a connection with no installation behind it cannot be named', function () {
    [$owner, $server, $connection] = linkedStoreTeam();
    $connection->forceFill(['installation_id' => null])->save();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors('source_provider_id')
        ->assertSessionDoesntHaveErrors('repository_id');

    expect(linkedStoreErrors('source_provider_id'))->toBe([linkedStoreUnavailable()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a suspended connection cannot be named and says why', function () {
    [$owner, $server, $connection] = linkedStoreTeam();
    $connection->markSuspended(now());

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors('source_provider_id')
        ->assertSessionDoesntHaveErrors('repository_id');

    expect(linkedStoreErrors('source_provider_id'))->toBe([linkedStoreSuspended()])
        ->and(Application::query()->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

test('a connection that is unsuspended can be named again', function () {
    [$owner, $server, $connection] = linkedStoreTeam();
    $connection->markSuspended(now());
    $connection->markSuspended(null);

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasNoErrors();

    expect(Application::query()->sole()->source_provider_id)->toBe($connection->id);
});

test('a connection that is not a github app cannot be named', function () {
    [$owner, $server] = linkedStoreTeam();
    $gitlab = linkedStoreConnection($server->team, ['type' => 'gitlab', 'access_token' => 'glpat']);

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $gitlab->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors('source_provider_id');

    expect(linkedStoreErrors('source_provider_id'))->toBe([linkedStoreUnavailable()])
        ->and(Application::query()->count())->toBe(0);
});

test('a connection that does not exist cannot be named', function (string $id) {
    [$owner, $server] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors('source_provider_id');

    expect(linkedStoreErrors('source_provider_id'))->toBe([linkedStoreUnavailable()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'an id nobody has' => ['01k0000000000000000000abcd'],
    'text' => ['nope'],
]);

test('a repository without a connection is refused and says the connection is the problem', function () {
    [$owner, $server] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'repository_id' => '70000001',
            'repository_url' => 'https://github.com/monoven/shop.git',
        ]))
        ->assertSessionHasErrors('source_provider_id')
        ->assertSessionDoesntHaveErrors('repository_id');

    expect(linkedStoreErrors('source_provider_id'))->toBe([linkedStoreUnavailable()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('github being unreachable while checking is said plainly, and only then is it worth trying again in a minute', function (Closure $fake) {
    Http::fake($fake());
    [$owner, $server] = linkedStoreTeam();
    $connection = linkedStoreConnection($server->team, ['name' => 'quiet', 'access_token' => null, 'token_expires_at' => null], seed: false);

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors(['repository_id' => 'Coresmith could not reach GitHub to check that repository. Try again in a minute.'])
        ->assertSessionDoesntHaveErrors('source_provider_id');

    expect(Application::query()->count())->toBe(0)
        ->and($connection->fresh()->connected())->toBeTrue();
    Queue::assertNothingPushed();
})->with([
    'the list fails' => [fn() => [
        'api.github.com/app/installations/51842/access_tokens' => Http::response(['token' => 'ghs_fresh', 'expires_at' => '2030-01-01T00:00:00Z'], 201),
        'api.github.com/installation/repositories*' => Http::response(['message' => 'Server Error'], 500),
    ]],
    'the token fails' => [fn() => ['api.github.com/app/installations/51842/access_tokens' => Http::response(['message' => 'Server Error'], 500)]],
    'github is rate limiting' => [fn() => ['api.github.com/app/installations/51842/access_tokens' => Http::response(['message' => 'API rate limit exceeded for installation ID 51842.'], 403)]],
    'github does not answer' => [fn() => ['api.github.com/app/installations/51842/access_tokens' => Http::failedConnection()]],
]);

test('a connection whose app is gone is said plainly, with nothing to try again', function () {
    [$owner, $server] = linkedStoreTeam();
    $connection = linkedStoreConnection($server->team, ['name' => 'orphan'], seed: false);
    GitHubApp::query()->delete();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors(['source_provider_id' => 'That GitHub connection was made through an app Coresmith no longer has. Pick another one, or connect GitHub again in Git settings.'])
        ->assertSessionDoesntHaveErrors('repository_id');

    expect(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('an installation github no longer has is said plainly and the connection is forgotten', function () {
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response(['message' => 'Not Found'], 404)]);
    [$owner, $server] = linkedStoreTeam();
    $connection = linkedStoreConnection($server->team, ['name' => 'monoven-labs', 'access_token' => null, 'token_expires_at' => null], seed: false);

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors(['source_provider_id' => "Coresmith's GitHub app is no longer installed on monoven-labs. Pick another connection, or connect GitHub again in Git settings."])
        ->assertSessionDoesntHaveErrors('repository_id');

    expect(Application::query()->count())->toBe(0)
        ->and(SourceProvider::withTrashed()->find($connection->id)->trashed())->toBeTrue();
});

test('a suspension github confirms while checking says to unsuspend it', function () {
    Http::fake([
        'api.github.com/app/installations/51842/access_tokens' => Http::response(['message' => 'This installation has been suspended'], 403),
        'api.github.com/app/installations/51842' => Http::response(['id' => 51842, 'suspended_at' => '2026-11-01T09:00:00Z']),
    ]);
    [$owner, $server] = linkedStoreTeam();
    $connection = linkedStoreConnection($server->team, ['name' => 'monoven-labs', 'access_token' => null, 'token_expires_at' => null], seed: false);

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors(['source_provider_id' => linkedStoreSuspended()])
        ->assertSessionDoesntHaveErrors('repository_id');

    expect(Application::query()->count())->toBe(0)
        ->and($connection->fresh()->suspended_at)->not->toBeNull();
});

test('a token github refuses for any other reason says what github said', function () {
    Http::fake([
        'api.github.com/app/installations/51842/access_tokens' => Http::response(['message' => 'Your IP address is not permitted to access this resource'], 403),
        'api.github.com/app/installations/51842' => Http::response(['id' => 51842, 'suspended_at' => null]),
    ]);
    [$owner, $server] = linkedStoreTeam();
    $connection = linkedStoreConnection($server->team, ['name' => 'monoven-labs', 'access_token' => null, 'token_expires_at' => null], seed: false);

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors(['source_provider_id' => 'GitHub would not give Coresmith a token for monoven-labs. It said: Your IP address is not permitted to access this resource. Fix that on GitHub, then try again.'])
        ->assertSessionDoesntHaveErrors('repository_id');

    expect(Application::query()->count())->toBe(0)
        ->and($connection->fresh()->connected())->toBeTrue();
});

test('an app from a git url is stored exactly as before', function () {
    [$owner, $server] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload(['repository_url' => 'https://github.com/monoven/shop.git']))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $application = Application::query()->sole();

    expect($application->source_provider_id)->toBeNull()
        ->and($application->repository_id)->toBeNull()
        ->and($application->repository_provider)->toBeNull()
        ->and($application->repository_url)->toBe('https://github.com/monoven/shop.git')
        ->and($application->githubConnection())->toBeNull();
    Http::assertNothingSent();
});

test('empty source fields mean a git url app, not a failed link', function () {
    [$owner, $server] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => '',
            'repository_id' => '',
            'repository_url' => 'git@github.com:monoven/shop.git',
        ]))
        ->assertSessionHasNoErrors();

    $application = Application::query()->sole();

    expect($application->source_provider_id)->toBeNull()
        ->and($application->repository_id)->toBeNull()
        ->and($application->repository_url)->toBe('git@github.com:monoven/shop.git');
    Http::assertNothingSent();
});

test('a connection needs a repository id', function () {
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload(['source_provider_id' => $connection->id, 'repository_id' => null]))
        ->assertSessionHasErrors('repository_id');

    expect(linkedStoreErrors('repository_id'))->toBe([linkedStoreNotPicked()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('the repository id must be a number', function (string $id) {
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload(['source_provider_id' => $connection->id, 'repository_id' => $id]))
        ->assertSessionHasErrors('repository_id');

    expect(linkedStoreErrors('repository_id'))->toBe([linkedStoreNotPicked()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'letters' => ['atlas'],
    'a name' => ['monoventech/atlas'],
    'a negative number' => ['-1'],
    'a decimal' => ['1.5'],
    'too long' => [str_repeat('9', 21)],
    'a number and a line' => ["70000001\nx"],
]);

test('a repository id with a trailing line break is refused when nothing trims it', function () {
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->withoutMiddleware(TrimStrings::class)
        ->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload(['source_provider_id' => $connection->id, 'repository_id' => "70000001\n"]))
        ->assertSessionHasErrors('repository_id');

    expect(linkedStoreErrors('repository_id'))->toBe([linkedStoreNotPicked()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a connection id that is not a single string is refused', function () {
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => [$connection->id],
            'repository_id' => '70000001',
        ]))
        ->assertSessionHasErrors('source_provider_id');

    expect(linkedStoreErrors('source_provider_id'))->toBe([linkedStoreUnavailable()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a repository id that is not a single string is refused', function () {
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => ['70000001'],
        ]))
        ->assertSessionHasErrors('repository_id');

    expect(linkedStoreErrors('repository_id'))->toBe([linkedStoreNotPicked()])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('no laravel default message reaches the person for the two source fields', function (array $source) {
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload(linkedStoreFill($source, $connection->id)))
        ->assertSessionHasErrors();

    $messages = [...linkedStoreErrors('source_provider_id'), ...linkedStoreErrors('repository_id')];

    expect($messages)->not->toBe([])
        ->and(array_values(array_diff($messages, [linkedStoreUnavailable(), linkedStoreNotPicked()])))->toBe([])
        ->and(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'a connection list' => [['source_provider_id' => [':connection'], 'repository_id' => '70000001']],
    'an empty connection list' => [['source_provider_id' => [], 'repository_id' => '70000001']],
    'a connection number' => [['source_provider_id' => 123, 'repository_id' => '70000001']],
    'a connection flag' => [['source_provider_id' => true, 'repository_id' => '70000001']],
    'a very long connection' => [['source_provider_id' => str_repeat('a', 300), 'repository_id' => '70000001']],
    'a connection keyed list' => [['source_provider_id' => ['id' => ':connection'], 'repository_id' => '70000001']],
    'a repository list' => [['source_provider_id' => ':connection', 'repository_id' => ['70000001']]],
    'an empty repository list' => [['source_provider_id' => ':connection', 'repository_id' => []]],
    'a repository number' => [['source_provider_id' => ':connection', 'repository_id' => 70000001]],
    'a repository flag' => [['source_provider_id' => ':connection', 'repository_id' => true]],
    'a repository keyed list' => [['source_provider_id' => ':connection', 'repository_id' => ['id' => '70000001']]],
    'a repository with too many digits' => [['source_provider_id' => ':connection', 'repository_id' => '7000000000000000000000000']],
    'a repository with a sign' => [['source_provider_id' => ':connection', 'repository_id' => '+70000001']],
    'a repository in hex' => [['source_provider_id' => ':connection', 'repository_id' => '0x42']],
    'both as lists' => [['source_provider_id' => ['x'], 'repository_id' => ['y']]],
    'only a repository' => [['source_provider_id' => null, 'repository_id' => '70000001']],
    'only a connection' => [['source_provider_id' => ':connection', 'repository_id' => null]],
]);

test('a member cannot link an app and github is never asked', function () {
    [$member, $server, $connection] = linkedStoreTeam(TeamRole::Member);

    $this->actingAs($member)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
        ]))
        ->assertForbidden();

    expect(Application::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('the rest of the form is still checked next to the repository', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(linkedStoreListing())]);
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000999',
            'repository_branch' => '--upload-pack=x',
        ]))
        ->assertSessionHasErrors([
            'repository_branch' => 'That is not a branch name Git accepts. Use something like main or release/2.0.',
            'repository_id' => 'GitHub does not give Coresmith access to that repository. Pick another, or add it under Manage repositories on GitHub.',
        ]);

    expect(Application::query()->count())->toBe(0);
});

test('a linked app that fails the branch check is not created either', function () {
    [$owner, $server, $connection] = linkedStoreTeam();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), linkedStorePayload([
            'source_provider_id' => $connection->id,
            'repository_id' => '70000001',
            'repository_branch' => '--upload-pack=x',
        ]))
        ->assertSessionHasErrors(['repository_branch' => 'That is not a branch name Git accepts. Use something like main or release/2.0.'])
        ->assertSessionDoesntHaveErrors('repository_id');

    expect(Application::query()->count())->toBe(0);
});

test("the add an app page lists the team's usable github connections", function () {
    [$owner, $server, $connection] = linkedStoreTeam();
    linkedStoreConnection($server->team, ['name' => 'gone'])->delete();
    linkedStoreConnection($server->team, ['name' => 'paused', 'suspended_at' => now()]);
    linkedStoreConnection($server->team, ['name' => 'gitlab-group', 'type' => 'gitlab', 'access_token' => 'glpat']);
    linkedStoreConnection(Team::factory()->create(), ['name' => 'someone-else']);

    $response = $this->actingAs($owner)->get(route('servers.applications.create', $server));

    $response->assertInertia(fn($page) => $page
        ->component('applications/Create')
        ->has('github.connections', 1)
        ->where('github.connections.0.id', $connection->id)
        ->where('github.connections.0.account', 'monoventech')
        ->where('github.connections.0.repositories_href', route('source-providers.repositories', $connection))
        ->where('github.connections.0.manage_url', 'https://github.com/organizations/monoventech/settings/installations/51842')
        ->missing('github.connections.0.branches_href_template')
        ->where('github.settings_href', route('source-providers.index')));

    expect($response->getContent())->not->toContain('ghs_supersecret')
        ->and($response->getContent())->not->toContain('someone-else')
        ->and($response->getContent())->not->toContain('paused');
});

test('the connections are listed by account and carry their own addresses', function () {
    [$owner, $server, $connection] = linkedStoreTeam();
    $first = linkedStoreConnection($server->team, ['name' => 'acme-labs', 'installation_id' => '51843', 'account_type' => 'User']);

    $this->actingAs($owner)->get(route('servers.applications.create', $server))
        ->assertInertia(fn($page) => $page
            ->has('github.connections', 2)
            ->where('github.connections.0.account', 'acme-labs')
            ->where('github.connections.0.repositories_href', route('source-providers.repositories', $first))
            ->where('github.connections.0.manage_url', 'https://github.com/settings/installations/51843')
            ->where('github.connections.1.account', 'monoventech')
            ->where('github.connections.1.repositories_href', route('source-providers.repositories', $connection)));
});

test('a team with no github connection gets an empty list and the way to make one', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $server = Server::factory()->for($team)->create();

    $this->actingAs($user->fresh())->get(route('servers.applications.create', $server))
        ->assertInertia(fn($page) => $page
            ->has('github.connections', 0)
            ->where('github.settings_href', route('source-providers.index')));
});

test('the links on the add an app page carry the team address', function () {
    [$owner, $server, $connection] = linkedStoreTeam();
    $server->team->forceFill(['slug' => 'monoven'])->save();

    $this->actingAs($owner)->get('/monoven/servers/' . $server->id . '/applications/create')
        ->assertInertia(fn($page) => $page
            ->where('github.connections.0.repositories_href', 'http://localhost/monoven/settings/source-providers/' . $connection->id . '/repositories')
            ->where('github.settings_href', 'http://localhost/monoven/settings/git'));
});

test('the add an app page offers the JavaScript frameworks the presets serve', function () {
    [$owner, $server] = linkedStoreTeam();

    $this->actingAs($owner)->get(route('servers.applications.create', $server))
        ->assertInertia(fn($page) => $page
            ->component('applications/Create')
            ->where('js_frameworks.0', ['value' => 'sveltekit', 'label' => 'SvelteKit'])
            ->where('js_frameworks.3', ['value' => 'astro', 'label' => 'Astro']));
});

test('the add an app page offers Python with its frameworks and versions', function () {
    [$owner, $server] = linkedStoreTeam();

    $this->actingAs($owner)->get(route('servers.applications.create', $server))
        ->assertInertia(fn($page) => $page
            ->component('applications/Create')
            ->where('python_frameworks', [['value' => 'django', 'label' => 'Django'], ['value' => 'fastapi', 'label' => 'FastAPI'], ['value' => 'starlette', 'label' => 'Starlette'], ['value' => 'flask', 'label' => 'Flask']])
            ->where('runtimes.python.label', 'Python')
            ->where('runtimes.python.recommended', '3.14')
            ->where('runtimes.python.sources', '.tool-versions, .python-version, pyproject.toml or runtime.txt'));
});
