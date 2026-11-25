<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\RemoveServerRuntime;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ActivityFeed;
use App\Services\Server\ServerRuntimes;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    config(['coresmith.runtimes.php.versions' => [
        ['line' => '8.4', 'latest' => '8.4.9', 'active_until' => '2030-12-31', 'eol_at' => '2032-12-31'],
        ['line' => '8.3', 'latest' => '8.3.14', 'active_until' => '2025-12-31', 'eol_at' => '2027-12-31'],
    ]]);
    Queue::fake();
});

/**
 * @return array{Team, Server}
 */
function runtimeInventoryFleet(): array
{
    $team = Team::factory()->owned()->create();

    return [$team, Server::factory()->for($team)->create([
        'name' => 'web-1',
        'status' => ServerStatus::Active,
        'installed_runtimes' => ['php' => ['8.3.14', '8.4.1', '8.4.9']],
    ])];
}

function runtimeInventoryApp(Server $server, string $name, string $php): Application
{
    return Application::factory()->for($server->team)->for($server)->create(['name' => $name, 'type' => ApplicationType::Php, 'php_version' => $php]);
}

function runtimeInventoryRelease(Application $application, string $php, int $hoursAgo): Deployment
{
    return Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Succeeded,
        'release' => now()->subHours($hoursAgo)->format('YmdHis'),
        'finished_at' => now()->subHours($hoursAgo),
        'stack' => ['runtimes' => ['php' => ['runtime' => 'php', 'version' => $php]]],
    ]);
}

/**
 * @return array<string, array<string, mixed>>
 */
function runtimeInventoryRows(Server $server, User $user): array
{
    return collect(app(ServerRuntimes::class)->inventory($server, $user))
        ->keyBy(fn(array $row): string => $row['label'])
        ->map(fn(array $row): array => ['apps' => array_column($row['apps'], 'name'), 'hidden' => $row['hidden'], 'kept' => $row['kept'], 'removable' => $row['remove_href'] !== null])
        ->all();
}

test('a server lists every runtime version on it with the apps on each, and only an unused one can be removed', function () {
    [$team, $server] = runtimeInventoryFleet();
    runtimeInventoryApp($server, 'shop', '8.4.1');
    runtimeInventoryApp($server, 'blog', '8.4.9');

    expect(runtimeInventoryRows($server, $team->owner))->toBe([
        'PHP 8.4.9' => ['apps' => ['blog'], 'hidden' => 0, 'kept' => [], 'removable' => false],
        'PHP 8.4.1' => ['apps' => ['shop'], 'hidden' => 0, 'kept' => [], 'removable' => false],
        'PHP 8.3.14' => ['apps' => [], 'hidden' => 0, 'kept' => [], 'removable' => true],
    ]);
});

test('a version a kept release was built on stays, and says which app keeps it', function () {
    [$team, $server] = runtimeInventoryFleet();
    $shop = runtimeInventoryApp($server, 'shop', '8.4.9');
    runtimeInventoryRelease($shop, '8.4.9', 1);
    runtimeInventoryRelease($shop, '8.4.1', 30);

    expect(runtimeInventoryRows($server, $team->owner)['PHP 8.4.1'])->toBe(['apps' => [], 'hidden' => 0, 'kept' => ['shop'], 'removable' => false]);
});

test('apps the person cannot see still keep their versions, counted but not named', function () {
    [$team, $server] = runtimeInventoryFleet();
    runtimeInventoryApp($server, 'shop', '8.4.1');
    $blog = runtimeInventoryApp($server, 'blog', '8.4.9');
    $narrowed = User::factory()->create();
    $team->members()->attach($narrowed, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);
    app(TeamAccess::class)->grant($team, $narrowed, $blog);

    expect(runtimeInventoryRows($server, $narrowed->fresh())['PHP 8.4.1'])->toBe(['apps' => [], 'hidden' => 1, 'kept' => [], 'removable' => false]);
});

test('removing an unused version queues its removal, and one in use or kept is refused', function () {
    [$team, $server] = runtimeInventoryFleet();
    $shop = runtimeInventoryApp($server, 'shop', '8.4.9');
    runtimeInventoryRelease($shop, '8.4.1', 30);

    $this->actingAs($team->owner)
        ->delete(route('servers.runtimes.destroy', [$server, 'php', '8.3.14']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Removing PHP 8.3.14 from web-1.');

    $this->actingAs($team->owner)
        ->delete(route('servers.runtimes.destroy', [$server, 'php', '8.4.9']))
        ->assertSessionHas('error', 'shop runs on PHP 8.4.9, so it stays.');

    $this->actingAs($team->owner)
        ->delete(route('servers.runtimes.destroy', [$server, 'php', '8.4.1']))
        ->assertSessionHas('error', 'A kept release of shop was built on PHP 8.4.1, so it stays until that release is gone.');

    $this->actingAs($team->owner)
        ->delete(route('servers.runtimes.destroy', [$server, 'php', '8.2.1']))
        ->assertNotFound();

    Queue::assertPushed(RemoveServerRuntime::class, 1);
    Queue::assertPushed(RemoveServerRuntime::class, fn(RemoveServerRuntime $job): bool => $job->server->is($server) && [$job->runtime, $job->version] === ['php', '8.3.14']);
});

test('a refusal over apps the person cannot see names them in lower case mid-sentence and capitalised at the start', function () {
    [$team, $server] = runtimeInventoryFleet();
    $shop = runtimeInventoryApp($server, 'shop', '8.4.9');
    runtimeInventoryRelease($shop, '8.4.1', 30);
    $blog = runtimeInventoryApp($server, 'blog', '8.3.14');
    $developer = User::factory()->create();
    $team->members()->attach($developer, ['role' => TeamRole::Developer->value]);
    $server->forceFill(['created_by' => $developer->id])->save();
    app(TeamAccess::class)->grant($team, $developer, $blog);

    $this->actingAs($developer->fresh())
        ->delete(route('servers.runtimes.destroy', [$server, 'php', '8.4.1']))
        ->assertSessionHas('error', "A kept release of an app you can't see was built on PHP 8.4.1, so it stays until that release is gone.");

    $this->actingAs($developer->fresh())
        ->delete(route('servers.runtimes.destroy', [$server, 'php', '8.4.9']))
        ->assertSessionHas('error', "An app you can't see runs on PHP 8.4.9, so it stays.");

    Queue::assertNotPushed(RemoveServerRuntime::class);
});

test('only people who can change the server can remove a runtime', function () {
    [$team, $server] = runtimeInventoryFleet();
    $deployer = User::factory()->create();
    $team->members()->attach($deployer, ['role' => TeamRole::Member->value]);

    $this->actingAs($deployer)
        ->delete(route('servers.runtimes.destroy', [$server, 'php', '8.3.14']))
        ->assertForbidden();

    Queue::assertNotPushed(RemoveServerRuntime::class);
});

test('the removal job uninstalls the version, forgets it and logs it, unless an app moved onto it meanwhile', function (bool $moved) {
    [$team, $server] = runtimeInventoryFleet();

    if ($moved) {
        runtimeInventoryApp($server, 'shop', '8.3.14');
    }

    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new RemoveServerRuntime($server, 'php', '8.3.14', $team->owner), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    if ($moved) {
        expect(implode("\n", $transport->commands()))->not->toContain('uninstall')
            ->and($server->fresh()->installed_runtimes)->toBe(['php' => ['8.3.14', '8.4.1', '8.4.9']])
            ->and(Activity::query()->where('description', 'runtime_removed')->exists())->toBeFalse();

        return;
    }

    expect(implode("\n", $transport->commands()))->toContain('asdf uninstall')
        ->and($server->fresh()->installed_runtimes)->toBe(['php' => ['8.4.1', '8.4.9']])
        ->and(Activity::query()->where('description', 'runtime_removed')->sole()->properties->all())->toBe(['runtime' => 'PHP', 'version' => '8.3.14']);
})->with(['unused' => false, 'an app moved onto it' => true]);

test('the activity feed says which runtime was removed', function () {
    [$team, $server] = runtimeInventoryFleet();

    activity()->performedOn($server)->withProperties(['runtime' => 'PHP', 'version' => '8.3.14'])->log('runtime_removed');

    $sentences = collect((new ActivityFeed)->forTeam($team))->map(fn(array $row): string => implode('', array_column($row['parts'], 'text')))->all();

    expect($sentences)->toContain('PHP 8.3.14 removed from web-1');
});
