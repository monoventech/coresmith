<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Exceptions\DeploymentAborted;
use App\Jobs\DeployApplication;
use App\Jobs\ReservePortBlock;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function linkProofLost(): string
{
    return 'ada can no longer read monoventech/atlas on GitHub, so this connection cannot deploy it. Someone who can read it connects GitHub again from Settings, Git.';
}

function linkProofNoAnswer(): string
{
    return 'GitHub did not answer. Try again in a minute.';
}

function linkProofUnknown(): string
{
    return 'Coresmith has no record of who connected this GitHub account, so this connection cannot deploy monoventech/atlas. Someone who can read it connects GitHub again from Settings, Git.';
}

function linkProofAdaId(): int
{
    return 583231;
}

function linkProofPem(): string
{
    static $pem = null;

    if ($pem === null) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    }

    return $pem;
}

function linkProofEnv(): void
{
    config(['services.github' => [
        'app_id' => '1041207',
        'app_slug' => 'coresmith-cloud',
        'client_id' => 'Iv1.env',
        'client_secret' => 'env-secret',
        'private_key' => linkProofPem(),
        'webhook_secret' => 'env-hook-secret',
    ]]);
}

function linkProofTeam(): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);

    return [$team, $user->fresh()];
}

function linkProofConnection(Team $team, array $attributes = []): SourceProvider
{
    $connection = SourceProvider::factory()->for($team)->create([
        'type' => 'github_app',
        'github_app_id' => null,
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'access_token' => 'ghs_cached_installation_token',
        'token_expires_at' => now()->addHour(),
        'proof_github_id' => (string) linkProofAdaId(),
        'proof_github_login' => 'ada',
        ...$attributes,
    ]);

    Cache::put(RepositoryCatalog::key($connection), [
        'total' => 1,
        'truncated' => false,
        'repositories' => [
            ['id' => '70000001', 'full_name' => 'monoventech/atlas', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => '2026-10-30T09:00:00Z'],
        ],
    ], now()->addHour());

    return $connection;
}

function linkProofPermission(string $permission, int $id = 583231, string $login = 'ada'): array
{
    return ['permission' => $permission, 'role_name' => $permission, 'user' => ['login' => $login, 'id' => $id]];
}

function linkProofFake(array $routes): void
{
    Http::fake([
        ...$routes,
        'api.github.com/repos/monoventech/atlas/branches*' => Http::response([['name' => 'main']]),
        'api.github.com/app/installations/51842/access_tokens' => Http::response(['token' => 'ghs_clone_token', 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201),
        'api.github.com/installation/token' => Http::response(null, 204),
        'api.github.com/repos/*/statuses/*' => Http::response(['id' => 1], 201),
    ]);
}

function linkProofAsked(string $login): Closure
{
    return fn(Request $request): bool => $request->url() === "https://api.github.com/repos/monoventech/atlas/collaborators/{$login}/permission"
        && $request->header('Authorization') === ['Bearer ghs_cached_installation_token'];
}

function linkProofPick(SourceProvider $connection): string
{
    return route('source-providers.branches', [$connection, '70000001'], false);
}

function linkProofServer(Team $team): Server
{
    return Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
}

function linkProofStore(Server $server, SourceProvider $connection): string
{
    return route('servers.applications.store', $server, false);
}

function linkProofPayload(SourceProvider $connection): array
{
    return [
        'name' => 'Atlas',
        'type' => 'php',
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
        'source_provider_id' => $connection->id,
        'repository_id' => '70000001',
    ];
}

function linkProofApp(SourceProvider $connection): Application
{
    return Application::factory()->for($connection->team)->create([
        'source_provider_id' => $connection->id,
        'slug' => 'my-app',
        'domain' => null,
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_provider' => 'github',
        'repository_id' => '70000001',
        'repository_branch' => 'main',
        'base_port' => 8000,
    ]);
}

function linkProofDeploy(Application $application): Deployment
{
    $deployment = Deployment::factory()->for($application)->create(['commit_hash' => null]);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo('df /', "40\n"));

    try {
        (new DeployApplication($deployment, '20261019120000'))->handle(app(SSHConnectionManager::class), new DeploymentCommands(new AsdfManager));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    return $deployment;
}

function linkProofMinted(): Closure
{
    return fn(Request $request): bool => str_ends_with($request->url(), '/app/installations/51842/access_tokens');
}

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([ReservePortBlock::class]);
    linkProofEnv();
});

test('a member who still reads the repository can pick it', function (string $permission) {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(linkProofPermission($permission))]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))->assertOk()->assertJsonPath('default', 'main');

    Http::assertSent(linkProofAsked('ada'));
})->with(['admin', 'write', 'read']);

test('someone who left the organisation cannot pick its repository, and github is not asked for its branches', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(linkProofPermission('none'))]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))
        ->assertStatus(409)
        ->assertExactJson(['message' => linkProofLost()]);

    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), '/branches'));
});

test('a cached branch list is not a way around the check', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    Cache::put(RepositoryCatalog::branchesKey($connection, '70000001'), ['main'], now()->addHour());
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(linkProofPermission('none'))]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))->assertStatus(409);
});

test('a renamed login is followed from the stored id and remembered', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    linkProofFake([
        'api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/user/' . linkProofAdaId() => Http::response(['login' => 'ada-lovelace', 'id' => linkProofAdaId()]),
        'api.github.com/repos/monoventech/atlas/collaborators/ada-lovelace/permission' => Http::response(linkProofPermission('read', login: 'ada-lovelace')),
    ]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))->assertOk();

    Http::assertSent(linkProofAsked('ada-lovelace'));

    expect($connection->fresh()->proof_github_login)->toBe('ada-lovelace')
        ->and($connection->fresh()->proof_github_id)->toBe((string) linkProofAdaId());
});

test('a renamed login that lost access is refused under its new name', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    linkProofFake([
        'api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/user/' . linkProofAdaId() => Http::response(['login' => 'ada-lovelace', 'id' => linkProofAdaId()]),
        'api.github.com/repos/monoventech/atlas/collaborators/ada-lovelace/permission' => Http::response(linkProofPermission('none', login: 'ada-lovelace')),
    ]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))
        ->assertStatus(409)
        ->assertJsonPath('message', 'ada-lovelace can no longer read monoventech/atlas on GitHub, so this connection cannot deploy it. Someone who can read it connects GitHub again from Settings, Git.');
});

test('a login taken over by someone else is not trusted, the stored id decides', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    linkProofFake([
        'api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(linkProofPermission('admin', 999)),
        'api.github.com/user/' . linkProofAdaId() => Http::response(['login' => 'ada-lovelace', 'id' => linkProofAdaId()]),
        'api.github.com/repos/monoventech/atlas/collaborators/ada-lovelace/permission' => Http::response(linkProofPermission('none', login: 'ada-lovelace')),
    ]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))->assertStatus(409);

    Http::assertSent(linkProofAsked('ada-lovelace'));
});

test('an account github has deleted cannot read anything', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    linkProofFake([
        'api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/user/' . linkProofAdaId() => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))
        ->assertStatus(409)
        ->assertExactJson(['message' => linkProofLost()]);
});

test('a repository github cannot find for a known person says the repository is out of reach', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    linkProofFake([
        'api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/user/' . linkProofAdaId() => Http::response(['login' => 'ada', 'id' => linkProofAdaId()]),
    ]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))
        ->assertNotFound()
        ->assertExactJson(['message' => RepositoryCatalog::NOT_VISIBLE]);
});

test('a connection with nobody on record is refused', function (array $proof) {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team, $proof);
    linkProofFake([]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))
        ->assertStatus(409)
        ->assertExactJson(['message' => linkProofUnknown()]);
})->with([
    'no id' => [['proof_github_id' => null]],
    'no login' => [['proof_github_login' => null]],
]);

test('a check github cannot answer refuses the pick and logs no token', function (string|array $answer) {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    $logged = [];
    Log::shouldReceive('warning')->once()->andReturnUsing(function (string $message, array $context) use (&$logged): void {
        $logged = [$message, $context];
    });
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => $answer === 'timeout' ? fn() => throw new ConnectionException('timed out') : Http::response(...$answer)]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))
        ->assertStatus(409)
        ->assertExactJson(['message' => linkProofNoAnswer()]);

    expect(json_encode($logged))->not->toContain('ghs_')
        ->and($logged[1]['connection'])->toBe($connection->id)
        ->and($logged[1]['repository'])->toBe('monoventech/atlas');
})->with([
    'a server error' => [[['message' => 'Server Error'], 500]],
    'a rate limit' => [[['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0']]],
    'a secondary limit' => [[['message' => 'You have exceeded a secondary rate limit'], 429, ['Retry-After' => '60']]],
    'a timeout' => ['timeout'],
    'an answer without a permission' => [[['role_name' => 'read'], 200]],
]);

test('a registered app is private to its team and is not asked', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team, ['github_app_id' => GitHubApp::factory()->for($team)->create()->id, 'proof_github_id' => null, 'proof_github_login' => null]);
    Http::fake(['api.github.com/repos/monoventech/atlas/branches*' => Http::response([['name' => 'main']])]);

    $this->actingAs($owner)->getJson(linkProofPick($connection))->assertOk();

    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), '/collaborators/'));
});

test('someone who left the organisation cannot add an app from its repository', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    $server = linkProofServer($team);
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(linkProofPermission('none'))]);

    $this->actingAs($owner)->post(linkProofStore($server, $connection), linkProofPayload($connection))
        ->assertSessionHasErrors(['repository_id' => linkProofLost()]);

    expect(Application::query()->count())->toBe(0);
});

test('a member who still reads the repository can add an app from it', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    $server = linkProofServer($team);
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(linkProofPermission('read'))]);

    $this->actingAs($owner)->post(linkProofStore($server, $connection), linkProofPayload($connection))
        ->assertSessionHasNoErrors();

    expect(Application::query()->sole()->repository_id)->toBe('70000001');
    Http::assertSent(linkProofAsked('ada'));
});

test('adding an app is refused when github cannot answer the check', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    $server = linkProofServer($team);
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(['message' => 'Server Error'], 502)]);

    $this->actingAs($owner)->post(linkProofStore($server, $connection), linkProofPayload($connection))
        ->assertSessionHasErrors(['repository_id' => linkProofNoAnswer()]);

    expect(Application::query()->count())->toBe(0);
});

test('adding an app the installation stopped covering says so', function () {
    [$team, $owner] = linkProofTeam();
    $connection = linkProofConnection($team);
    $server = linkProofServer($team);
    linkProofFake([
        'api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/user/' . linkProofAdaId() => Http::response(['login' => 'ada', 'id' => linkProofAdaId()]),
    ]);

    $this->actingAs($owner)->post(linkProofStore($server, $connection), linkProofPayload($connection))
        ->assertSessionHasErrors(['repository_id' => RepositoryCatalog::NOT_VISIBLE]);
});

test('someone who left the organisation cannot deploy its repository, and no clone token is minted', function () {
    [$team] = linkProofTeam();
    $application = linkProofApp(linkProofConnection($team));
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(linkProofPermission('none'))]);

    expect(fn() => linkProofDeploy($application))->toThrow(DeploymentAborted::class, linkProofLost());

    $deployment = Deployment::query()->sole();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->failure_reason)->toBe(linkProofLost());

    Http::assertNotSent(linkProofMinted());
});

test('a member who still reads the repository deploys it, checked before the token is minted', function () {
    [$team] = linkProofTeam();
    $application = linkProofApp(linkProofConnection($team));
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(linkProofPermission('read'))]);

    $deployment = linkProofDeploy($application);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);

    $sent = collect(Http::recorded())->map(fn(array $pair): string => $pair[0]->url())->values();
    $checked = $sent->search(fn(string $url): bool => str_ends_with($url, '/collaborators/ada/permission'));
    $minted = $sent->search(fn(string $url): bool => str_ends_with($url, '/app/installations/51842/access_tokens'));

    expect($checked)->not->toBeFalse()
        ->and($minted)->not->toBeFalse()
        ->and($checked)->toBeLessThan($minted);
});

test('a deploy is refused without a fetch when github cannot answer the check', function () {
    [$team] = linkProofTeam();
    $application = linkProofApp(linkProofConnection($team));
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(['message' => 'Server Error'], 500)]);

    expect(fn() => linkProofDeploy($application))->toThrow(DeploymentAborted::class, linkProofNoAnswer());

    expect(Deployment::query()->sole()->failure_reason)->toBe(linkProofNoAnswer());
    Http::assertNotSent(linkProofMinted());
});

test('a repository the installation stopped covering moves to another connection, whose own proof decides', function () {
    [$team] = linkProofTeam();
    $application = linkProofApp(linkProofConnection($team));
    $other = linkProofConnection($team, [
        'installation_id' => '51843',
        'name' => 'other',
        'access_token' => 'ghs_other_cached_token',
        'proof_github_id' => '77',
        'proof_github_login' => 'grace',
    ]);
    linkProofFake([
        'api.github.com/repos/monoventech/atlas/collaborators/ada/permission' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/user/' . linkProofAdaId() => Http::response(['login' => 'ada', 'id' => linkProofAdaId()]),
        'api.github.com/repos/monoventech/atlas/collaborators/grace/permission' => Http::response(linkProofPermission('none', 77, 'grace')),
    ]);

    expect(fn() => linkProofDeploy($application))->toThrow(DeploymentAborted::class, 'grace can no longer read monoventech/atlas');

    expect($application->fresh()->source_provider_id)->toBe($other->id);
    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), '/access_tokens'));
});

test('an app relinked before its deploy is checked against the connection it moved to', function () {
    [$team] = linkProofTeam();
    $application = linkProofApp(linkProofConnection($team, ['deleted_at' => now()]));
    $other = linkProofConnection($team, [
        'installation_id' => '51843',
        'name' => 'other',
        'access_token' => 'ghs_other_cached_token',
        'proof_github_id' => '77',
        'proof_github_login' => 'grace',
    ]);
    linkProofFake(['api.github.com/repos/monoventech/atlas/collaborators/grace/permission' => Http::response(linkProofPermission('none', 77, 'grace'))]);

    expect(fn() => linkProofDeploy($application))->toThrow(DeploymentAborted::class, 'grace can no longer read monoventech/atlas');

    expect($application->fresh()->source_provider_id)->toBe($other->id);
    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), '/access_tokens'));
});
