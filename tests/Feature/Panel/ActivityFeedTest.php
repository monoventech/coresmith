<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Services\Panel\ActivityFeed;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

function feedSentence(array $row): string
{
    return implode('', array_column($row['parts'], 'text'));
}

function feedLog(Model $subject, string $description, array $properties = []): void
{
    activity()->performedOn($subject)->withProperties($properties)->log($description);
}

function feedFixture(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);

    return [$team, $server, $application];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
});

test('server events are described in plain words', function (string $description, array $properties, string $sentence) {
    [$team, $server] = feedFixture();

    feedLog($server, $description, $properties);

    $row = (new ActivityFeed)->forTeam($team)[0];

    expect(feedSentence($row))->toBe($sentence)
        ->and($row['href'])->toBe(route('servers.show', $server));
})->with([
    'added' => ['server_added', [], 'web-1 added to the panel, waiting for its key'],
    'provisioned' => ['server_provisioned', ['duration_seconds' => 372], 'web-1 provisioned and hardened in 6m 12s'],
    'provisioned without a duration' => ['server_provisioned', [], 'web-1 provisioned and hardened'],
    'renamed' => ['server_renamed', [], 'Server renamed to web-1'],
    'rebooted' => ['server_rebooted', [], 'web-1 rebooted, apps came back via systemd'],
    'pruned' => ['releases_pruned', ['removed' => 7, 'kept' => 5], 'Old releases pruned on web-1, 7 removed'],
    'disk pressure' => ['disk_pressure', ['percent' => 86], 'Disk warning, web-1 at 86%, consider pruning old releases'],
    'firewall' => ['firewall_rule_changed', ['action' => 'allow', 'port' => 6379], 'Firewall allow port 6379 on web-1'],
    'host key changed' => ['ssh_host_key_changed', [], 'web-1 presented a different host key, operations paused'],
    'apparmor' => ['apparmor_profiles_in_complain_mode', ['profiles_in_complain_mode' => 3], 'AppArmor has 3 profiles in complain mode on web-1'],
    'audited' => ['server_audited', ['blockers' => 1, 'warnings' => 2], 'Pre-provisioning check on web-1, 1 blocking, 2 to review'],
]);

test('application events are described in plain words and link to the application', function (string $description, array $properties, string $sentence) {
    [$team, $server, $application] = feedFixture();

    feedLog($application, $description, $properties);

    $row = (new ActivityFeed)->forTeam($team)[0];

    expect(feedSentence($row))->toBe($sentence)
        ->and($row['href'])->toBe(route('servers.applications.show', [$server, $application]));
})->with([
    'created' => ['application_created', [], 'shop created on web-1'],
    'rolled back' => ['application_rolled_back', ['from' => '20260913140000', 'to' => '20260912090000'], 'shop rolled back to 20260912-090000, no rebuild'],
    'stopped' => ['application_stopped', [], 'shop stopped, visitors see the maintenance page'],
    'started' => ['application_started', [], 'shop started again'],
    'maintenance on' => ['maintenance_enabled', [], 'Maintenance page turned on for shop'],
    'toggle enabled' => ['toggle_changed', ['toggle' => 'queue_worker', 'label' => 'Queue worker', 'on' => true], 'Smart toggle enabled, Queue worker on shop, no deployment needed'],
    'toggle disabled' => ['toggle_changed', ['toggle' => 'scheduler', 'label' => 'Scheduler', 'on' => false], 'Smart toggle disabled, Scheduler on shop, no deployment needed'],
    'process sync failed' => ['processes_sync_failed', ['error' => 'SSH command failed with exit code 1.'], "Couldn't apply the process change on shop, SSH command failed with exit code 1."],
    'process sync failed without detail' => ['processes_sync_failed', [], "Couldn't apply the process change on shop, the server did not respond"],
    'rollback processes failed' => ['rollback_processes_failed', ['from' => '20260913140000', 'to' => '20260912090000', 'error' => 'coresmith-app-shop-web did not start.'], 'shop rolled back to 20260912-090000, but its processes did not start. Open the app to see why, then deploy a release that starts.'],
    'domain added' => ['domain_added', ['domain' => 'www.shop.test'], 'www.shop.test added to shop'],
    'port block moved' => ['port_block_moved', ['from' => 8000, 'to' => 8010, 'port' => 8005, 'process' => 'java'], 'shop moved to ports 8010–8019, java already listens on 8005'],
    'killed for memory' => ['app_oom_killed', ['process' => 'queue', 'at' => '2026-09-13T14:03:00+00:00', 'limit_mb' => 256], 'shop queue process killed for going over its 256 MB memory limit, systemd started it again'],
]);

test('names are marked as monospace and empty fragments are dropped', function () {
    [$team,, $application] = feedFixture();

    feedLog($application, 'application_started');

    expect((new ActivityFeed)->forTeam($team)[0]['parts'])->toBe([
        ['text' => 'shop', 'mono' => true],
        ['text' => ' started again', 'mono' => false],
    ]);
});

test('each row carries a clock time for today and the exact moment', function () {
    [$team, $server] = feedFixture();

    feedLog($server, 'server_rebooted');

    $row = (new ActivityFeed)->forTeam($team)[0];

    expect($row['time'])->toBe('14:05')
        ->and($row['at'])->toBe('2026-09-13T14:05:09+00:00');
});

test('ssh command noise and events with no description are left out', function () {
    [$team, $server] = feedFixture();

    feedLog($server, 'ssh_command_executed', ['command' => 'uptime']);
    feedLog($server, 'something_the_feed_does_not_know');

    expect((new ActivityFeed)->forTeam($team))->toBe([]);
});

test('database events are described without a link', function () {
    [$team, $server] = feedFixture();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_production']);

    feedLog($database, 'database_backed_up');

    $row = (new ActivityFeed)->forTeam($team)[0];

    expect(feedSentence($row))->toBe('Backup taken of shop_production')
        ->and($row['href'])->toBeNull();
});

test('another teams activity is never shown', function () {
    [$team] = feedFixture();
    [, $theirServer, $theirApplication] = feedFixture();

    feedLog($theirServer, 'server_rebooted');
    feedLog($theirApplication, 'application_started');
    Deployment::factory()->for($theirApplication)->create(['status' => DeploymentStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]);

    expect((new ActivityFeed)->forTeam($team))->toBe([]);
});

test('a detached server and a removed application keep their history but lose their links', function () {
    [$team, $server, $application] = feedFixture();

    feedLog($application, 'application_removed');
    $application->delete();
    feedLog($server, 'server_detached');
    $server->delete();

    $rows = (new ActivityFeed)->forTeam($team);

    expect(array_map(feedSentence(...), $rows))->toBe([
        'web-1 detached, the VM itself is untouched',
        'shop stopped and detached from web-1',
    ])->and(array_column($rows, 'href'))->toBe([null, null]);
});

test('deployments of an application on a detached server still name the server', function () {
    [$team, $server, $application] = feedFixture();

    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913120000', 'started_at' => now(), 'finished_at' => now()]);
    $server->delete();

    expect(feedSentence((new ActivityFeed)->forTeam($team)[0]))->toBe('shop deployed to web-1, 20260913-120000, zero downtime');
});

test('deployments are merged into the feed with their outcome', function () {
    [$team, $server, $application] = feedFixture();

    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'release' => '20260913100000', 'started_at' => now()->subHours(4), 'finished_at' => now()->subHours(4)]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913120000', 'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2)]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913130000', 'tag' => 'v2.4.0', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
    $running = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running, 'release' => '20260913140500', 'branch' => 'main', 'started_at' => now()]);

    $rows = (new ActivityFeed)->forTeam($team, 10);

    expect(array_map(feedSentence(...), $rows))->toBe([
        'Deploy started, shop release 20260913-140500, branch main',
        'shop deployed to web-1, v2.4.0, zero downtime',
        'shop deployed to web-1, 20260913-120000, zero downtime',
        'Deploy failed, shop, the current release kept serving',
    ])->and($rows[0]['href'])->toBe(route('servers.applications.deployments.show', [$server, $application, $running]));
});

test('cancelled deployments and deployments that never started are left out', function () {
    [$team,, $application] = feedFixture();

    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Cancelled, 'started_at' => now()]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Pending, 'started_at' => null]);

    expect((new ActivityFeed)->forTeam($team))->toBe([]);
});

test('activity and deployments are interleaved newest first and cut to the limit', function () {
    [$team, $server, $application] = feedFixture();

    $this->travel(-50)->minutes();
    feedLog($server, 'server_added');
    $this->travel(10)->minutes();
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913132000', 'started_at' => now(), 'finished_at' => now()]);
    $this->travel(10)->minutes();
    feedLog($server, 'releases_pruned', ['removed' => 2]);
    $this->travel(10)->minutes();
    feedLog($application, 'application_stopped');
    $this->travel(10)->minutes();
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'started_at' => now(), 'finished_at' => now()]);

    $rows = (new ActivityFeed)->forTeam($team, 3);

    expect(array_map(feedSentence(...), $rows))->toBe([
        'Deploy failed, shop, the current release kept serving',
        'shop stopped, visitors see the maintenance page',
        'Old releases pruned on web-1, 2 removed',
    ]);
});

test('the team feed shows five entries by default', function () {
    [$team, $server] = feedFixture();

    Collection::times(7, fn() => feedLog($server, 'server_rebooted'));

    expect((new ActivityFeed)->forTeam($team))->toHaveCount(5);
});

test('a server feed covers only that server, its applications and its databases', function () {
    [$team, $server, $application] = feedFixture();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_db']);
    $sibling = Server::factory()->for($team)->create(['name' => 'web-2']);
    $siblingApplication = Application::factory()->for($team)->for($sibling)->create(['name' => 'blog']);

    $this->travel(-3)->minutes();
    feedLog($server, 'server_rebooted');
    $this->travel(1)->minutes();
    feedLog($application, 'application_started');
    $this->travel(1)->minutes();
    feedLog($database, 'database_created');
    feedLog($sibling, 'server_rebooted');
    feedLog($siblingApplication, 'application_started');

    expect(array_map(feedSentence(...), (new ActivityFeed)->forServer($server)))->toBe([
        'Database shop_db created',
        'shop started again',
        'web-1 rebooted, apps came back via systemd',
    ]);
});

test('a server feed shows up to twenty five entries by default', function () {
    [, $server] = feedFixture();

    Collection::times(30, fn() => feedLog($server, 'server_rebooted'));

    expect((new ActivityFeed)->forServer($server))->toHaveCount(25);
});
