<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\SmartToggles;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
    'added' => ['server_added', [], 'web-1 added to Coresmith, waiting for its key'],
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
    'creation started' => ['server_creation_started', ['provider' => 'Hetzner Cloud', 'size' => 'cx22', 'region' => 'fsn1'], 'web-1 is being created at Hetzner Cloud, cx22 in fsn1'],
    'created at the provider' => ['server_created_at_provider', ['provider' => 'Hetzner Cloud', 'seconds' => 48], 'web-1 created at Hetzner Cloud in 0m 48s, checking it before provisioning'],
    'created without a duration' => ['server_created_at_provider', ['provider' => 'Hetzner Cloud', 'seconds' => null], 'web-1 created at Hetzner Cloud, checking it before provisioning'],
    'creation failed' => ['server_creation_failed', ['provider' => 'Hetzner Cloud', 'step' => 'ssh', 'reason' => 'SSH authentication failed.'], 'Creating web-1 at Hetzner Cloud failed'],
    'destroy failed' => ['server_destroy_failed', ['provider' => 'Hetzner Cloud', 'reason' => 'Hetzner Cloud refused the request.'], 'Destroying web-1 at Hetzner Cloud failed'],
    'resized' => ['server_resized', ['from' => 'cx22', 'to' => 'cx32', 'provider' => 'Hetzner Cloud'], 'web-1 resized from cx22 to cx32'],
    'resize failed' => ['server_resize_failed', ['from' => 'cx22', 'to' => 'cx32', 'reason' => 'Hetzner Cloud refused the request.'], 'Resizing web-1 failed'],
    'reboot failed' => ['server_reboot_failed', ['reason' => 'Hetzner Cloud refused the request.'], 'web-1 did not reboot. Hetzner Cloud refused the request.'],
    'reboot accepted by the provider' => ['server_reboot_requested', ['via' => 'Hetzner Cloud'], 'Hetzner Cloud accepted a reboot of web-1'],
    'reboot accepted by a provider it cannot name' => ['server_reboot_requested', [], 'Its provider accepted a reboot of web-1'],
    'removed apps cleared' => ['removed_apps_cleared', ['apps' => 3], 'Folders cleared on web-1 for 3 apps removed over 7 days ago'],
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
    'rollback worker did not start' => ['rollback_worker_failed', ['from' => '20260913140000', 'to' => '20260912090000', 'worker' => 'Sidekiq', 'error' => 'coresmith-app-shop-sidekiq did not start.'], 'shop rolled back to 20260912-090000, but Sidekiq did not start. Open the app to see why.'],
    'worker did not start' => ['toggle_start_failed', ['toggle' => 'solid_queue', 'label' => 'Solid Queue', 'error' => 'coresmith-app-shop-jobs did not start, systemd reports it as activating.'], 'Solid Queue did not start on shop, so its toggle is off again'],
    'ssr did not start' => ['toggle_start_failed', ['toggle' => 'inertia_ssr', 'label' => 'Inertia SSR', 'error' => 'The SSR server did not start on port 13714.'], 'Inertia SSR did not start on shop, pages render in the browser until the next deploy'],
    'domain added' => ['domain_added', ['domain' => 'www.shop.test'], 'www.shop.test added to shop'],
    'port block moved' => ['port_block_moved', ['from' => 8000, 'to' => 8010, 'port' => 8005, 'process' => 'java'], 'shop moved to ports 8010–8019, java already listens on 8005'],
    'killed for memory' => ['app_oom_killed', ['process' => 'queue', 'at' => '2026-09-13T14:03:00+00:00', 'limit_mb' => 256], 'shop queue process killed for going over its 256 MB memory limit, systemd started it again'],
    'end of life chosen' => ['runtime_eol_chosen', ['runtime' => 'php', 'line' => '8.1'], 'shop was added on PHP 8.1, which no longer gets security fixes'],
    'cron sync failed' => ['cron_sync_failed', ['error' => "Couldn't reach web-1 over SSH. Check it's online, then try again."], "Couldn't write the cron jobs for shop. Couldn't reach web-1 over SSH. Check it's online, then try again."],
    'cron job added' => ['cron_job_added', ['schedule' => '0 3 * * *', 'description' => null, 'command' => 'php artisan backup:run'], 'Cron job added to shop, daily at 3:00 AM UTC'],
    'cron job changed' => ['cron_job_updated', ['schedule' => '*/5 * * * *', 'from' => '* * * * *', 'description' => null, 'command' => 'php artisan schedule:run'], 'Cron job changed on shop, runs every 5 minutes'],
    'cron job on a custom schedule' => ['cron_job_added', ['schedule' => '15 9-17 * * 1-5', 'description' => null, 'command' => 'php artisan prices:sync'], 'Cron job added to shop, on a custom schedule'],
    'auto deploy on' => ['toggle_changed', ['toggle' => SmartToggles::AUTO_DEPLOY, 'label' => 'Auto deploy', 'on' => true], 'Auto deploy turned on for shop, it deploys the branch if the live release is behind'],
    'auto deploy off' => ['toggle_changed', ['toggle' => SmartToggles::AUTO_DEPLOY, 'label' => 'Auto deploy', 'on' => false], 'Auto deploy turned off for shop'],
    'one rejected webhook' => ['webhook_signature_rejected', ['count' => 1], 'A webhook with a bad signature was rejected'],
    'a rejected webhook from before the count' => ['webhook_signature_rejected', [], 'A webhook with a bad signature was rejected'],
    'a burst of rejected webhooks' => ['webhook_signature_rejected', ['count' => 42], '42 webhooks with a bad signature were rejected'],
    'cron job removed' => ['cron_job_removed', ['schedule' => '0 3 * * *', 'description' => null, 'command' => 'php artisan backup:run'], 'Cron job removed from shop'],
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

test('a worker that started is bookkeeping, and stays out of the feed without taking a place in it', function () {
    [$team,, $application] = feedFixture();

    feedLog($application, 'application_started');
    feedLog($application, 'toggle_started', ['toggle' => 'queue_worker', 'label' => 'Queue worker']);

    expect(array_map(feedSentence(...), (new ActivityFeed)->forTeam($team, 1)))->toBe(['shop started again']);
});

test('database events are described without a link', function () {
    [$team, $server] = feedFixture();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_production']);

    feedLog($database, 'database_backed_up');

    $row = (new ActivityFeed)->forTeam($team)[0];

    expect(feedSentence($row))->toBe('Backup taken of shop_production')
        ->and($row['href'])->toBeNull();
});

test('showing and rotating a database password are described, never with the password', function (string $description, string $sentence) {
    [$team, $server] = feedFixture();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_production', 'db_password' => 'Sealed5ecretValue']);

    feedLog($database, $description);

    $row = (new ActivityFeed)->forTeam($team)[0];

    expect(feedSentence($row))->toBe($sentence)
        ->and(json_encode($row))->not->toContain('Sealed5ecretValue');
})->with([
    'shown' => ['database_password_shown', 'Password of shop_production shown'],
    'rotated' => ['database_password_rotated', 'Password of shop_production rotated'],
]);

test('a deleted database is still named in the activity', function () {
    [$team, $server] = feedFixture();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_production']);

    feedLog($database, 'database_deleted');
    $database->delete();

    expect(feedSentence((new ActivityFeed)->forTeam($team)[0]))->toBe('Database shop_production deleted');
});

test('a database leaving the shared Redis, and the shared Redis turned off, are described', function () {
    [$team, $server] = feedFixture();
    $database = ManagedDatabase::factory()->redis()->for($team)->for($server)->create(['name' => 'cache']);

    feedLog($database, 'database_moved_off_shared_redis', ['port' => 6380]);
    $this->travel(1)->second();
    feedLog($server, 'shared_redis_turned_off');

    expect(array_map(feedSentence(...), (new ActivityFeed)->forTeam($team)))->toBe([
        "Shared Redis turned off on {$server->name}",
        'cache moved to a Redis of its own on port 6380',
    ]);
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

test('cloud account events are described and link to the team page', function (string $description, string $sentence) {
    [$team] = feedFixture();
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner production']);

    feedLog($account, $description, ['provider' => 'Hetzner Cloud', 'name' => 'Hetzner production']);

    $row = (new ActivityFeed)->forTeam($team)[0];

    expect(feedSentence($row))->toBe($sentence)
        ->and($row['href'])->toBe(route('teams.index'))
        ->and(array_column(array_values(array_filter($row['parts'], fn(array $part): bool => $part['mono'])), 'text'))->toBe(['Hetzner production']);
})->with([
    'connected' => ['cloud_provider_connected', 'Hetzner Cloud connected as Hetzner production'],
    'token replaced' => ['cloud_provider_token_replaced', 'Token replaced for Hetzner production'],
    'removed' => ['cloud_provider_removed', 'Hetzner Cloud account Hetzner production removed'],
]);

test("a removed account keeps its history, and another team's accounts never show", function () {
    [$team] = feedFixture();
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner production']);
    $theirs = CloudProvider::factory()->create(['name' => 'Their Hetzner']);

    feedLog($account, 'cloud_provider_removed', ['provider' => 'Hetzner Cloud', 'name' => 'Hetzner production']);
    feedLog($theirs, 'cloud_provider_connected', ['provider' => 'Hetzner Cloud', 'name' => 'Their Hetzner']);
    $account->delete();

    expect(array_map(feedSentence(...), (new ActivityFeed)->forTeam($team)))->toBe(['Hetzner Cloud account Hetzner production removed']);
});

test('account events stay off a server feed', function () {
    [$team, $server] = feedFixture();
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner production']);

    feedLog($account, 'cloud_provider_connected', ['provider' => 'Hetzner Cloud', 'name' => 'Hetzner production']);

    expect((new ActivityFeed)->forServer($server))->toBe([]);
});

test('a destroyed server says its billing stopped and links nowhere', function () {
    [$team, $server] = feedFixture();
    feedLog($server, 'server_destroyed', ['provider' => 'Hetzner Cloud', 'size' => 'cx22']);
    $server->delete();

    $row = (new ActivityFeed)->forTeam($team)[0];

    expect(feedSentence($row))->toBe('web-1 destroyed at Hetzner Cloud, billing for it stopped')
        ->and($row['href'])->toBeNull();
});

test('panel updates appear in every team\'s activity and lead to settings', function () {
    [$team, $server] = feedFixture();
    $other = Team::factory()->create();

    activity()->withProperties(['from' => '0.1.0', 'to' => '0.1.1', 'trigger' => 'auto'])->log('panel_updated');
    $this->travel(1)->minutes();
    activity()->withProperties(['from' => '0.1.1', 'to' => '0.1.2', 'trigger' => 'panel'])->log('panel_updated');
    $this->travel(1)->minutes();
    activity()->withProperties(['from' => '0.1.2', 'to' => '0.1.3', 'trigger' => 'panel', 'step' => 'migrate'])->log('panel_update_rolled_back');
    $this->travel(1)->minutes();
    activity()->withProperties(['from' => '0.1.2', 'to' => '0.1.4', 'trigger' => 'cli', 'step' => 'migrate'])->log('panel_update_failed');

    $rows = app(ActivityFeed::class)->forTeam($team);

    expect(array_map(feedSentence(...), $rows))->toBe([
        'Coresmith update to v0.1.4 failed and needs attention',
        'Coresmith update to v0.1.3 failed while running migrations, rolled back to v0.1.2',
        'Coresmith updated to v0.1.2, backup taken, health check passed',
        'Auto-update applied, Coresmith v0.1.1, backup taken, health check passed',
    ])
        ->and(array_unique(array_column($rows, 'href')))->toBe([route('settings.general')])
        ->and(app(ActivityFeed::class)->forTeam($other))->toHaveCount(4)
        ->and(app(ActivityFeed::class)->forServer($server))->toBe([]);
});

test('other events without a subject stay out of the feed and take no place in it', function () {
    [$team, $server] = feedFixture();
    feedLog($server, 'server_provisioned');
    $this->travel(1)->minutes();

    foreach (range(1, 5) as $ignored) {
        activity()->log('something_else');
    }

    expect(array_map(feedSentence(...), app(ActivityFeed::class)->forTeam($team)))->toHaveCount(1);
});

test('an app that moved to another github connection says what it clones and through which account', function (bool $disconnected) {
    [$team, $server, $application] = feedFixture();
    $connection = SourceProvider::factory()->for($team)->create(['type' => 'github_app', 'name' => 'monoven-labs', 'installation_id' => '61842']);

    if ($disconnected) {
        $connection->disconnect();
    }

    feedLog($application, 'application_relinked', ['from' => (string) Str::ulid(), 'to' => $connection->id, 'repository' => 'monoven-labs/shop']);

    $row = (new ActivityFeed)->forTeam($team)[0];

    expect($row['parts'])->toBe([
        ['text' => 'shop', 'mono' => true],
        ['text' => ' now clones ', 'mono' => false],
        ['text' => 'monoven-labs/shop', 'mono' => true],
        ['text' => ' through GitHub on ', 'mono' => false],
        ['text' => 'monoven-labs', 'mono' => true],
    ])
        ->and($row['href'])->toBe(route('servers.applications.show', [$server, $application]));
})->with([
    'a connected account' => [false],
    'an account disconnected since' => [true],
]);

test('an app that moved to a github connection that no longer exists still says what it clones', function () {
    [$team,, $application] = feedFixture();

    feedLog($application, 'application_relinked', ['from' => (string) Str::ulid(), 'to' => (string) Str::ulid(), 'repository' => 'monoven-labs/shop']);

    expect(feedSentence((new ActivityFeed)->forTeam($team)[0]))->toBe('shop now clones monoven-labs/shop through another GitHub connection');
});
