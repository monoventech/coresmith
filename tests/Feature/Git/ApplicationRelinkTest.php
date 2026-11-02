<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Exceptions\DeploymentAborted;
use App\Jobs\AdoptStrandedApplications;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Git\GitHub\ApplicationRelinker;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\GitHubConnector;
use App\Services\Git\GitHub\GitHubEventHandler;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function relinkConnection(Team $team, ?GitHubApp $app, array $attributes = []): SourceProvider
{
    return SourceProvider::factory()->for($team)->create([
        'type' => 'github_app',
        'github_app_id' => $app?->id,
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'access_token' => null,
        'token_expires_at' => null,
        ...$attributes,
    ]);
}

function relinkApp(SourceProvider $connection, array $attributes = []): Application
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
        ...$attributes,
    ]);
}

function relinkRepository(int $id, string $fullName): array
{
    return ['id' => $id, 'full_name' => $fullName, 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => '2026-10-30T09:00:00Z'];
}

function relinkCatalog(SourceProvider $connection, array $repositories): void
{
    Cache::put(RepositoryCatalog::key($connection), [
        'total' => count($repositories),
        'truncated' => false,
        'repositories' => array_map(fn(array $repository): array => [...$repository, 'id' => (string) $repository['id']], $repositories),
    ], now()->addHour());
}

function relinkToken(string $installation): string
{
    return "ghs_{$installation}_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl";
}

function relinkFake(array $mints, array $listing = []): void
{
    $fakes = [];

    foreach ($mints as $installation => $status) {
        $fakes["api.github.com/app/installations/{$installation}/access_tokens"] = $status === 201
            ? Http::response(['token' => relinkToken((string) $installation), 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201)
            : Http::response(['message' => 'GitHub turned this request down'], $status);
    }

    Http::fake([
        ...$fakes,
        'api.github.com/installation/repositories*' => Http::response(['total_count' => count($listing), 'repositories' => $listing]),
        'api.github.com/installation/token' => Http::response(null, 204),
        'api.github.com/repos/*/statuses/*' => Http::response(['id' => 1], 201),
        'api.github.com/app/installations/*' => Http::response(['id' => 51842, 'suspended_at' => '2026-11-01T09:00:00Z']),
    ]);
}

function relinkDeploy(Application $application, ?FakeSSHTransport $transport = null): Deployment
{
    $deployment = Deployment::factory()->for($application)->create(['commit_hash' => null]);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport ?? (new FakeSSHTransport)->respondTo('df /', "40\n"));

    try {
        (new DeployApplication($deployment, '20261102120000'))->handle(app(SSHConnectionManager::class), new DeploymentCommands(new AsdfManager));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    return $deployment->fresh();
}

function relinkRefusedTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('df /', "40\n")
        ->respondTo('clone --bare', "fatal: could not read Username for 'https://github.com': terminal prompts disabled\n", 128);
}

function relinkMinted(string $installation): bool
{
    return Http::recorded(fn($request): bool => $request->url() === "https://api.github.com/app/installations/{$installation}/access_tokens"
        && ($request->data()['repository_ids'] ?? null) === [70000001]
        && ($request->data()['permissions'] ?? null) === ['contents' => 'read'])->isNotEmpty();
}

function relinkInstallation(string $id, string $account): array
{
    return ['id' => $id, 'account' => $account, 'account_type' => 'Organization', 'repository_selection' => 'selected', 'suspended_at' => null];
}

function relinkPush(string $installation, string $fullName, string $after = 'c', int $pushedAt = 1793620800): array
{
    return [
        'ref' => 'refs/heads/main',
        'after' => str_repeat($after, 40),
        'deleted' => false,
        'installation' => ['id' => (int) $installation],
        'repository' => ['id' => 70000001, 'full_name' => $fullName, 'pushed_at' => $pushedAt],
        'head_commit' => ['message' => 'Ship it'],
    ];
}

function relinkActivities(): array
{
    return Activity::query()->where('description', 'application_relinked')->get()
        ->map(fn(Activity $activity): array => [$activity->subject_id, collect($activity->properties->all())->sortKeys()->all()])
        ->all();
}

beforeEach(function () {
    Http::preventStrayRequests();
});

test('reinstalling the app moves the apps of the removed installation onto the new one and they clone with its token again', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $removed = relinkConnection($team, $githubApp);
    $application = relinkApp($removed);
    $removed->markUninstalled();
    relinkFake(['61842' => 201], [relinkRepository(70000001, 'monoventech/atlas'), relinkRepository(70000002, 'monoventech/ledger')]);

    $linked = app(GitHubConnector::class)->link($team, app(GitHubApps::class)->fromModel($githubApp), relinkInstallation('61842', 'monoventech'));

    expect($linked->id)->not->toBe($removed->id)
        ->and($application->fresh()->source_provider_id)->toBe($linked->id)
        ->and($application->fresh()->repository_url)->toBe('https://github.com/monoventech/atlas.git')
        ->and(relinkActivities())->toBe([[$application->id, ['from' => $removed->id, 'repository' => 'monoventech/atlas', 'to' => $linked->id]]])
        ->and(json_encode(Activity::query()->get()))->not->toContain('ghs_');

    $deployment = relinkDeploy($application->fresh());

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->log)->toContain('clone: fetching with a one-hour GitHub token (read-only, monoventech/atlas)')
        ->and(relinkMinted('61842'))->toBeTrue();
});

test('a new link leaves alone apps whose connection still works and apps it cannot see', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $working = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '51843']);
    $removed = relinkConnection($team, $githubApp);
    $kept = relinkApp($working, ['slug' => 'kept-app', 'base_port' => 8010]);
    $unseen = relinkApp($removed, ['slug' => 'unseen-app', 'base_port' => 8020, 'repository_id' => '70000009', 'repository_url' => 'https://github.com/monoventech/vault.git']);
    $removed->markUninstalled();
    relinkFake(['61842' => 201], [relinkRepository(70000001, 'monoventech/atlas')]);

    app(GitHubConnector::class)->link($team, app(GitHubApps::class)->fromModel($githubApp), relinkInstallation('61842', 'monoventech'));

    expect($kept->fresh()->source_provider_id)->toBe($working->id)
        ->and($unseen->fresh()->source_provider_id)->toBe($removed->id)
        ->and(relinkActivities())->toBe([]);
});

test('a suspended installation linked again takes no apps over and asks github nothing', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $removed = relinkConnection($team, $githubApp);
    $application = relinkApp($removed);
    $removed->markUninstalled();
    Http::fake();

    app(GitHubConnector::class)->link($team, app(GitHubApps::class)->fromModel($githubApp), [...relinkInstallation('61842', 'monoventech'), 'suspended_at' => '2026-11-01T09:00:00Z']);

    expect($application->fresh()->source_provider_id)->toBe($removed->id)
        ->and(relinkActivities())->toBe([]);
    Http::assertNothingSent();
});

test('a link asks github nothing when no app of the team is stranded', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    relinkApp(relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '51843']));
    Http::fake();

    app(GitHubConnector::class)->link($team, app(GitHubApps::class)->fromModel($githubApp), relinkInstallation('61842', 'monoventech'));

    Http::assertNothingSent();
});

test('a link github will not list its repositories for still links, and the app follows on its next deploy', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $removed = relinkConnection($team, $githubApp);
    $application = relinkApp($removed);
    $removed->markUninstalled();
    Http::fake([
        'api.github.com/app/installations/61842/access_tokens' => Http::response(['token' => relinkToken('61842'), 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201),
        'api.github.com/installation/repositories*' => Http::sequence()
            ->push(['message' => 'Server Error'], 500)
            ->push(['message' => 'Server Error'], 500)
            ->push(['total_count' => 1, 'repositories' => [relinkRepository(70000001, 'monoventech/atlas')]]),
        'api.github.com/installation/token' => Http::response(null, 204),
        'api.github.com/repos/*/statuses/*' => Http::response(['id' => 1], 201),
    ]);

    $linked = app(GitHubConnector::class)->link($team, app(GitHubApps::class)->fromModel($githubApp), relinkInstallation('61842', 'monoventech'));

    expect(SourceProvider::query()->find($linked->id)?->connected())->toBeTrue()
        ->and($application->fresh()->source_provider_id)->toBe($removed->id);

    $deployment = relinkDeploy($application->fresh());

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($application->fresh()->source_provider_id)->toBe($linked->id)
        ->and(relinkMinted('61842'))->toBeTrue()
        ->and(relinkActivities())->toBe([[$application->id, ['from' => $removed->id, 'repository' => 'monoventech/atlas', 'to' => $linked->id]]]);
});

test('a deploy whose connection is gone clones through the team connection that can see the repository', function (array $gone) {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $old = relinkConnection($team, $githubApp);
    $other = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '61842']);
    $application = relinkApp($old);
    relinkCatalog($other, [relinkRepository(70000001, 'monoventech/atlas')]);
    $old->forceFill($gone)->save();
    relinkFake(['61842' => 201]);

    $deployment = relinkDeploy($application->fresh());

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($application->fresh()->source_provider_id)->toBe($other->id)
        ->and($application->fresh()->repository_url)->toBe('https://github.com/monoventech/atlas.git')
        ->and(relinkMinted('61842'))->toBeTrue()
        ->and(relinkActivities())->toBe([[$application->id, ['from' => $old->id, 'repository' => 'monoventech/atlas', 'to' => $other->id]]]);
})->with([
    'removed' => [['deleted_at' => now()]],
    'suspended' => [['suspended_at' => now()]],
]);

test('a deploy whose suspension github will not confirm or deny relinks as before', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $old = relinkConnection($team, $githubApp, ['suspended_at' => now()]);
    $other = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '61842']);
    $application = relinkApp($old);
    relinkCatalog($other, [relinkRepository(70000001, 'monoventech/atlas')]);
    Http::fake(['api.github.com/app/installations/51842' => Http::response(['message' => 'Server Error'], 500)]);
    relinkFake(['61842' => 201]);

    expect(relinkDeploy($application->fresh())->status)->toBe(DeploymentStatus::Succeeded)
        ->and($old->fresh()->suspended_at)->not->toBeNull()
        ->and($application->fresh()->source_provider_id)->toBe($other->id)
        ->and(relinkMinted('61842'))->toBeTrue();
});

test('a deploy whose app was removed from the panel clones through the team connection that can see the repository', function () {
    $team = Team::factory()->create();
    $gone = GitHubApp::factory()->for($team)->create();
    $current = GitHubApp::factory()->for($team)->create();
    $old = relinkConnection($team, $gone);
    $other = relinkConnection($team, $current, ['installation_id' => '61842']);
    $application = relinkApp($old);
    relinkCatalog($other, [relinkRepository(70000001, 'monoventech/atlas')]);
    $gone->delete();
    relinkFake(['61842' => 201]);

    expect(relinkDeploy($application->fresh())->status)->toBe(DeploymentStatus::Succeeded)
        ->and($application->fresh()->source_provider_id)->toBe($other->id);
});

test('a token refused because the installation is gone is minted again through the connection that can see the repository', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $old = relinkConnection($team, $githubApp);
    $other = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '61842']);
    $application = relinkApp($old);
    relinkCatalog($other, [relinkRepository(70000001, 'monoventech/atlas')]);
    relinkFake(['51842' => 404, '61842' => 201]);

    $deployment = relinkDeploy($application->fresh());

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and(SourceProvider::withTrashed()->find($old->id)->trashed())->toBeTrue()
        ->and($application->fresh()->source_provider_id)->toBe($other->id)
        ->and(relinkMinted('61842'))->toBeTrue();
});

test('after a transfer between two linked accounts the clone is minted through the account the repository moved to', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $from = relinkConnection($team, $githubApp);
    $to = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '61842']);
    $application = relinkApp($from);
    relinkCatalog($from, [relinkRepository(70000001, 'monoventech/atlas')]);
    relinkCatalog($to, [relinkRepository(70000001, 'monoven-labs/atlas')]);
    relinkFake(['51842' => 422, '61842' => 201]);

    $deployment = relinkDeploy($application->fresh());

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->log)->toContain('clone: fetching with a one-hour GitHub token')
        ->and($application->fresh()->source_provider_id)->toBe($to->id)
        ->and($from->fresh()->connected())->toBeTrue()
        ->and(relinkMinted('51842'))->toBeTrue()
        ->and(relinkMinted('61842'))->toBeTrue()
        ->and(relinkActivities())->toBe([[$application->id, ['from' => $from->id, 'repository' => 'monoven-labs/atlas', 'to' => $to->id]]]);
});

test('after a transfer between two linked accounts a push from the new account deploys the app and keeps it there', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $from = relinkConnection($team, $githubApp);
    $to = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '61842']);
    $application = relinkApp($from, ['quick_deploy' => true]);
    $events = app(GitHubEventHandler::class);
    $credentials = app(GitHubApps::class)->fromModel($githubApp);

    expect($events->push($credentials, relinkPush('61842', 'monoven-labs/atlas'), 'delivery-1'))->toBe(1);

    expect($application->fresh()->source_provider_id)->toBe($to->id)
        ->and($application->fresh()->repository_url)->toBe('https://github.com/monoven-labs/atlas.git')
        ->and($application->fresh()->repository_head)->toBe(str_repeat('c', 40))
        ->and(Deployment::query()->where('application_id', $application->id)->sole()->requested_commit)->toBe(str_repeat('c', 40))
        ->and(relinkActivities())->toBe([[$application->id, ['from' => $from->id, 'repository' => 'monoven-labs/atlas', 'to' => $to->id]]]);

    Queue::assertPushed(DeployApplication::class, 1);

    Deployment::query()->update(['status' => DeploymentStatus::Succeeded->value, 'commit_hash' => str_repeat('c', 40)]);

    expect($events->push($credentials, relinkPush('61842', 'monoven-labs/atlas', 'd', 1793620900), 'delivery-2'))->toBe(1)
        ->and($application->fresh()->source_provider_id)->toBe($to->id)
        ->and($application->fresh()->repository_head)->toBe(str_repeat('d', 40))
        ->and(relinkActivities())->toHaveCount(1);

    Queue::assertPushed(DeployApplication::class, 2);
});

test('a push through a second app on the same account leaves an app on a working link where it is', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $first = GitHubApp::factory()->for($team)->create();
    $second = GitHubApp::factory()->for($team)->create();
    $current = relinkConnection($team, $first);
    relinkConnection($team, $second, ['installation_id' => '61842']);
    $application = relinkApp($current, ['quick_deploy' => true]);

    app(GitHubEventHandler::class)->push(app(GitHubApps::class)->fromModel($second), relinkPush('61842', 'monoventech/atlas'), 'delivery-1');

    expect($application->fresh()->source_provider_id)->toBe($current->id)
        ->and(relinkActivities())->toBe([]);
});

test('a push moves an app off a connection that is gone even on the same account', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $removed = relinkConnection($team, $githubApp);
    $linked = relinkConnection($team, $githubApp, ['installation_id' => '61842']);
    $application = relinkApp($removed, ['quick_deploy' => true]);
    $removed->markUninstalled();

    app(GitHubEventHandler::class)->push(app(GitHubApps::class)->fromModel($githubApp), relinkPush('61842', 'monoventech/atlas'), 'delivery-1');

    expect($application->fresh()->source_provider_id)->toBe($linked->id)
        ->and($application->fresh()->repository_head)->toBe(str_repeat('c', 40));

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a connection of another team is never used', function () {
    Queue::fake([DeployApplication::class]);
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $removed = relinkConnection($team, $githubApp);
    $application = relinkApp($removed, ['quick_deploy' => true]);
    $removed->markUninstalled();
    $elsewhere = Team::factory()->create();
    $theirApp = GitHubApp::factory()->for($elsewhere)->create();
    $theirs = relinkConnection($elsewhere, $theirApp, ['installation_id' => '61842']);
    relinkCatalog($theirs, [relinkRepository(70000001, 'monoventech/atlas')]);
    relinkFake(['61842' => 201], [relinkRepository(70000001, 'monoventech/atlas')]);

    app(GitHubEventHandler::class)->push(app(GitHubApps::class)->fromModel($theirApp), relinkPush('61842', 'monoventech/atlas'), 'delivery-1');
    app(GitHubConnector::class)->link($elsewhere, app(GitHubApps::class)->fromModel($theirApp), relinkInstallation('61842', 'monoventech'));

    expect(fn() => relinkDeploy($application->fresh(), relinkRefusedTransport()))->toThrow(DeploymentAborted::class);

    expect($application->fresh()->source_provider_id)->toBe($removed->id)
        ->and($application->fresh()->repository_head)->toBeNull()
        ->and(Deployment::query()->where('application_id', $application->id)->sole()->failure_reason)->toBe("This app's GitHub connection was removed, so the private repository monoventech/atlas could not be read. Connect GitHub again from Settings, Git, then deploy.")
        ->and(relinkActivities())->toBe([])
        ->and(relinkMinted('61842'))->toBeFalse();
});

test('an app whose repository no usable connection can see keeps the failure and the advice it had', function (array $connection, string $reason) {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $old = relinkConnection($team, $githubApp, $connection);
    $application = relinkApp($old);
    $other = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '61842']);
    $paused = relinkConnection($team, $githubApp, ['name' => 'monoven-paused', 'installation_id' => '61843', 'suspended_at' => now()]);
    relinkCatalog($other, [relinkRepository(70000002, 'monoven-labs/ledger')]);
    relinkCatalog($paused, [relinkRepository(70000001, 'monoventech/atlas')]);
    relinkFake(['51842' => 422, '61842' => 201]);

    expect(fn() => relinkDeploy($application->fresh(), relinkRefusedTransport()))->toThrow(DeploymentAborted::class);

    expect($application->fresh()->source_provider_id)->toBe($old->id)
        ->and(Deployment::query()->where('application_id', $application->id)->sole()->failure_reason)->toBe($reason)
        ->and(relinkActivities())->toBe([])
        ->and(relinkMinted('61842'))->toBeFalse()
        ->and(relinkMinted('61843'))->toBeFalse();
})->with([
    'its connection was removed' => [['deleted_at' => now()], "This app's GitHub connection was removed, so the private repository monoventech/atlas could not be read. Connect GitHub again from Settings, Git, then deploy."],
    'its installation lost the repository' => [[], "GitHub no longer gives Coresmith access to monoventech/atlas. Add it under Manage repositories on GitHub, or if it moved to another account, connect that account's installation from Settings, Git. Then deploy again."],
]);

test('a token github could not issue is never a reason to move the app', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $current = relinkConnection($team, $githubApp);
    $application = relinkApp($current);
    $other = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '61842']);
    relinkCatalog($other, [relinkRepository(70000001, 'monoventech/atlas')]);
    relinkFake(['51842' => 500, '61842' => 201]);

    expect(fn() => relinkDeploy($application->fresh(), relinkRefusedTransport()))->toThrow(DeploymentAborted::class);

    expect($application->fresh()->source_provider_id)->toBe($current->id)
        ->and(relinkMinted('61842'))->toBeFalse();
});

test('an app cloned with a deploy key is never moved', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $removed = relinkConnection($team, $githubApp);
    $application = relinkApp($removed, ['repository_url' => 'git@github.com:monoventech/atlas.git']);
    $removed->markUninstalled();
    relinkFake(['61842' => 201], [relinkRepository(70000001, 'monoventech/atlas')]);

    app(GitHubConnector::class)->link($team, app(GitHubApps::class)->fromModel($githubApp), relinkInstallation('61842', 'monoventech'));

    $deployment = relinkDeploy($application->fresh());

    expect($application->fresh()->source_provider_id)->toBe($removed->id)
        ->and($application->fresh()->repository_url)->toBe('git@github.com:monoventech/atlas.git')
        ->and($deployment->log)->toContain('clone: fetching via deploy key (ed25519, read-only)')
        ->and(relinkMinted('61842'))->toBeFalse()
        ->and(relinkActivities())->toBe([]);
});

test('an app another check moved meanwhile is neither moved again nor logged', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $removed = relinkConnection($team, $githubApp);
    $application = relinkApp($removed);
    $removed->markUninstalled();
    $elsewhere = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '71842']);
    Http::fake([
        'api.github.com/app/installations/61842/access_tokens' => Http::response(['token' => relinkToken('61842'), 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201),
        'api.github.com/installation/repositories*' => function () use ($application, $elsewhere) {
            Application::query()->whereKey($application->id)->update(['source_provider_id' => $elsewhere->id]);

            return Http::response(['total_count' => 1, 'repositories' => [relinkRepository(70000001, 'monoventech/atlas')]]);
        },
    ]);

    app(GitHubConnector::class)->link($team, app(GitHubApps::class)->fromModel($githubApp), relinkInstallation('61842', 'monoventech'));

    expect($application->fresh()->source_provider_id)->toBe($elsewhere->id)
        ->and(relinkActivities())->toBe([]);
});

test('a deploy whose app another check moved meanwhile clones through the connection it was moved to', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $removed = relinkConnection($team, $githubApp);
    $candidate = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '61842']);
    $movedTo = relinkConnection($team, $githubApp, ['name' => 'monoven-ops', 'installation_id' => '71842']);
    $application = relinkApp($removed);
    $removed->markUninstalled();
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => fn($request) => Http::response(['token' => relinkToken((string) preg_replace('#^.*/installations/([0-9]+)/access_tokens$#', '$1', $request->url())), 'expires_at' => now()->addHour()->toIso8601ZuluString()], 201),
        'api.github.com/installation/repositories*' => function () use ($application, $movedTo) {
            Application::query()->whereKey($application->id)->update(['source_provider_id' => $movedTo->id]);

            return Http::response(['total_count' => 1, 'repositories' => [relinkRepository(70000001, 'monoventech/atlas')]]);
        },
        'api.github.com/installation/token' => Http::response(null, 204),
        'api.github.com/repos/*/statuses/*' => Http::response(['id' => 1], 201),
    ]);

    $deployment = relinkDeploy($application->fresh());

    expect($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($application->fresh()->source_provider_id)->toBe($movedTo->id)
        ->and(relinkMinted('71842'))->toBeTrue()
        ->and(relinkMinted('61842'))->toBeFalse()
        ->and(relinkActivities())->toBe([]);
});

test('after an account rename two apps on that account settle the app on the link named for the current owner', function (string $startsOn) {
    Queue::fake();
    $team = Team::factory()->create();
    $before = GitHubApp::factory()->for($team)->create();
    $after = GitHubApp::factory()->for($team)->create();
    $linkedBeforeRename = relinkConnection($team, $before, ['name' => 'monoventech-old']);
    $linkedAfterRename = relinkConnection($team, $after, ['installation_id' => '61842']);
    $application = relinkApp($startsOn === 'the stale name' ? $linkedBeforeRename : $linkedAfterRename, ['quick_deploy' => true]);
    $events = app(GitHubEventHandler::class);
    $pushes = [
        [$after, '61842'], [$before, '51842'], [$before, '51842'], [$after, '61842'], [$before, '51842'],
    ];

    foreach ($pushes as $index => [$githubApp, $installation]) {
        $events->push(app(GitHubApps::class)->fromModel($githubApp), relinkPush($installation, 'monoventech/atlas', 'c', 1793620800 + $index), "delivery-{$index}");
    }

    expect($application->fresh()->source_provider_id)->toBe($linkedAfterRename->id)
        ->and(relinkActivities())->toHaveCount($startsOn === 'the stale name' ? 1 : 0);
})->with([
    'the app starts on the link with the stale name' => ['the stale name'],
    'the app starts on the link with the current name' => ['the current name'],
]);

test('a token github refuses for a reason other than a suspension never moves the app', function () {
    $team = Team::factory()->create();
    $githubApp = GitHubApp::factory()->for($team)->create();
    $current = relinkConnection($team, $githubApp);
    $application = relinkApp($current);
    $other = relinkConnection($team, $githubApp, ['name' => 'monoven-labs', 'installation_id' => '61842']);
    relinkCatalog($other, [relinkRepository(70000001, 'monoventech/atlas')]);
    Http::fake(['api.github.com/app/installations/51842' => Http::response(['id' => 51842, 'suspended_at' => null])]);
    relinkFake(['51842' => 403, '61842' => 201]);

    expect(fn() => relinkDeploy($application->fresh(), relinkRefusedTransport()))->toThrow(DeploymentAborted::class);

    expect($application->fresh()->source_provider_id)->toBe($current->id)
        ->and($current->fresh()->connected())->toBeTrue()
        ->and(relinkMinted('61842'))->toBeFalse()
        ->and(relinkActivities())->toBe([]);
});

test('linking an installation takes over the apps of a connection whose app was removed from the panel', function () {
    $team = Team::factory()->create();
    $gone = GitHubApp::factory()->for($team)->create();
    $current = GitHubApp::factory()->for($team)->create();
    $appLess = relinkConnection($team, $gone);
    $application = relinkApp($appLess);
    $gone->delete();
    relinkFake(['61842' => 201], [relinkRepository(70000001, 'monoventech/atlas')]);

    $linked = app(GitHubConnector::class)->link($team, app(GitHubApps::class)->fromModel($current), relinkInstallation('61842', 'monoventech'));

    expect($application->fresh()->source_provider_id)->toBe($linked->id)
        ->and(relinkActivities())->toBe([[$application->id, ['from' => $appLess->id, 'repository' => 'monoventech/atlas', 'to' => $linked->id]]]);
});

test('an adoption that runs after its connection was disconnected or suspended moves nothing', function (string $state) {
    $team = Team::factory()->create();
    $app = GitHubApp::factory()->for($team)->create();
    $removed = relinkConnection($team, $app, ['installation_id' => '40000', 'name' => 'old']);
    $application = relinkApp($removed);
    $removed->delete();
    $connection = relinkConnection($team, $app);
    relinkCatalog($connection, [relinkRepository(70000001, 'monoventech/atlas')]);

    expect(app(ApplicationRelinker::class)->stranded($connection))->toHaveCount(1);

    match ($state) {
        'disconnected' => $connection->disconnect(),
        'suspended' => $connection->markSuspended(now()),
    };

    (new AdoptStrandedApplications($connection))->handle(app(ApplicationRelinker::class));

    expect($application->fresh()->source_provider_id)->toBe($removed->id);
})->with(['disconnected', 'suspended']);
