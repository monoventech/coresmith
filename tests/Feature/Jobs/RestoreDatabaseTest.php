<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\RestoreDatabase;
use App\Models\Application;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\DatabaseRestoreNotification;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function restoreDatabaseFixture(array $attributes = []): ManagedDatabase
{
    $team = Team::factory()->create(['slug' => 'monoven']);
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Owner->value]);
    $server = Server::factory()->for($team)->create(['name' => 'anvil-01', 'host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'status' => ServerStatus::Active, 'last_connected_at' => now()]);

    return ManagedDatabase::factory()->for($team)->for($server)->create(['type' => DatabaseType::Mysql, 'name' => 'shop', 'db_user' => 'shop_user', 'db_password' => 'S3cretPassw0rd', ...$attributes]);
}

function restoreDatabaseApp(ManagedDatabase $database, string $name, PowerState $state = PowerState::Active): Application
{
    return Application::factory()->for($database->team)->for($database->server)->create(['name' => $name, 'power_state' => $state]);
}

function restoreDatabaseTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('mktemp', "/tmp/tmp.ReStOrE123\n")->respondTo('stat -c %s', "2048\n");
}

/**
 * @param  list<string>  $stop
 */
function restoreDatabaseRun(ManagedDatabase $database, FakeSSHTransport $transport, array $stop = [], ?string $key = null, int $size = 2048): FakeSSHTransport
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    $by = (string) $database->team->members()->first()?->getKey();

    app()->call([new RestoreDatabase($database, '20261110023000', $key, $size, $stop, $by), 'handle']);

    return $transport;
}

/**
 * @param  list<string>  $needles
 */
function restoreDatabaseOrder(FakeSSHTransport $transport, array $needles): array
{
    return array_map(function (string $needle) use ($transport): int {
        foreach ($transport->commands() as $index => $command) {
            if (str_contains($command, $needle)) {
                return $index;
            }
        }

        return -1;
    }, $needles);
}

beforeEach(function () {
    config(['app.timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 14:00:00', 'UTC'));
});

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test('a restore backs up first, works from its own copy, stops the ticked apps, restores, starts them again and cleans up', function () {
    Notification::fake();
    $database = restoreDatabaseFixture();
    $storefront = restoreDatabaseApp($database, 'storefront');
    $backoffice = restoreDatabaseApp($database, 'backoffice');

    $transport = restoreDatabaseRun($database, restoreDatabaseTransport(), stop: [$storefront->id]);
    $order = restoreDatabaseOrder($transport, [
        'shop-20261111140000.sql.gz',
        "cp -p '/var/coresmith/backups/shop/shop-20261110023000.sql.gz' '/var/coresmith/backups/shop/.restore-20261110023000.sql.gz'",
        'systemctl stop',
        'DROP DATABASE IF EXISTS `shop`',
        "gunzip -c '/var/coresmith/backups/shop/.restore-20261110023000.sql.gz'",
        'systemctl enable --now',
        "rm -f '/var/coresmith/backups/shop/.restore-20261110023000.sql.gz'",
    ]);

    expect($order)->not->toContain(-1)
        ->and($order)->toBe(collect($order)->sort()->values()->all())
        ->and($database->fresh()->restore)->toMatchArray(['state' => 'done', 'stamp' => '20261110023000', 'safety' => '20261111140000', 'error' => null])
        ->and($storefront->fresh()->power_state)->toBe(PowerState::Active)
        ->and($backoffice->fresh()->power_state)->toBe(PowerState::Active)
        ->and(implode("\n", $transport->commands()))->not->toContain($backoffice->name)
        ->and(Activity::query()->where('description', 'database_restored')->first()?->properties['from'] ?? null)->toBe('20261110023000');
});

test('a copy in S3 is downloaded to the server first', function () {
    Notification::fake();
    $database = restoreDatabaseFixture();
    BackupDestination::factory()->for($database->team)->create(['region' => 'eu-west-1', 'bucket' => 'my-bucket', 'prefix' => 'backups', 'access_key_id' => 'AKIAEXAMPLE0000000000', 'secret_access_key' => 'an-example-secret-access-key']);

    $transport = restoreDatabaseRun($database, restoreDatabaseTransport(), key: 'backups/monoven/anvil-01/shop/shop-20261110023000.sql.gz');
    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("-o '/var/coresmith/backups/shop/.restore-20261110023000.sql.gz'")
        ->and($commands)->not->toContain('cp -p')
        ->and($database->fresh()->restore['state'])->toBe('done');
});

test('a failed safety backup stops before anything changes', function () {
    Notification::fake();
    $database = restoreDatabaseFixture();
    $storefront = restoreDatabaseApp($database, 'storefront');

    $transport = restoreDatabaseRun($database, restoreDatabaseTransport()->respondTo('mysqldump', 'mysqldump: Got error: 1045', 2), stop: [$storefront->id]);
    $commands = implode("\n", $transport->commands());

    expect($commands)->not->toContain('DROP DATABASE')
        ->and($commands)->not->toContain('systemctl stop')
        ->and($database->fresh()->restore)->toMatchArray(['state' => 'failed', 'error' => "Coresmith couldn't back up shop as it is now, so nothing changed. mysqldump: Got error: 1045"])
        ->and(Activity::query()->where('description', 'database_restore_failed')->exists())->toBeTrue();
});

test('a failed restore puts back the copy taken first and says so', function () {
    Notification::fake();
    $database = restoreDatabaseFixture();
    $storefront = restoreDatabaseApp($database, 'storefront');

    $transport = restoreDatabaseRun($database, restoreDatabaseTransport()->respondToSequence('gunzip -c', [['ERROR 1064 (42000) at line 12', 1], ['', 0]]), stop: [$storefront->id]);
    $dumps = collect($transport->commands())->filter(fn(string $command): bool => str_starts_with($command, 'gunzip -c'))->values();

    expect($dumps)->toHaveCount(2)
        ->and($dumps[1])->toContain("'/var/coresmith/backups/shop/shop-20261111140000.sql.gz'")
        ->and($database->fresh()->restore)->toMatchArray(['state' => 'failed', 'error' => "Coresmith couldn't restore the backup from Nov 10, 02:30, so it put back the copy it took first. ERROR 1064 (42000) at line 12"])
        ->and($storefront->fresh()->power_state)->toBe(PowerState::Active);
});

test('a restore whose safety copy also fails says where that copy is', function () {
    Notification::fake();
    $database = restoreDatabaseFixture();

    restoreDatabaseRun($database, restoreDatabaseTransport()->respondTo('gunzip -c', 'ERROR 2002', 1));

    expect($database->fresh()->restore['error'])->toBe("Coresmith couldn't restore the backup from Nov 10, 02:30, or put back the copy it took first, so shop may be empty. Restore /var/coresmith/backups/shop/shop-20261111140000.sql.gz on anvil-01 by hand. ERROR 2002");
});

test('an app that was already stopped stays stopped', function () {
    Notification::fake();
    $database = restoreDatabaseFixture();
    $paused = restoreDatabaseApp($database, 'paused', PowerState::Stopped);

    $transport = restoreDatabaseRun($database, restoreDatabaseTransport(), stop: [$paused->id]);

    expect(implode("\n", $transport->commands()))->not->toContain('systemctl enable --now')
        ->and($paused->fresh()->power_state)->toBe(PowerState::Stopped);
});

test('an app on another server is never stopped', function () {
    Notification::fake();
    $database = restoreDatabaseFixture();
    $elsewhere = Application::factory()->for($database->team)->for(Server::factory()->for($database->team))->create(['power_state' => PowerState::Active]);

    $transport = restoreDatabaseRun($database, restoreDatabaseTransport(), stop: [$elsewhere->id]);

    expect(implode("\n", $transport->commands()))->not->toContain('systemctl stop')
        ->and($elsewhere->fresh()->power_state)->toBe(PowerState::Active);
});

test('a database busy with another task fails at once', function () {
    Notification::fake();
    $database = restoreDatabaseFixture();
    $database->credentialsLock()->get();

    $transport = restoreDatabaseRun($database, restoreDatabaseTransport());

    expect($transport->commands())->toBe([])
        ->and($database->fresh()->restore)->toMatchArray(['state' => 'failed', 'error' => 'Coresmith is still working on shop. Try again in a minute.']);
});

test('the team hears how it went', function () {
    Notification::fake();
    $database = restoreDatabaseFixture();
    $owner = $database->team->members()->first();

    restoreDatabaseRun($database, restoreDatabaseTransport());

    Notification::assertSentTo($owner, DatabaseRestoreNotification::class, fn(DatabaseRestoreNotification $notification): bool => $notification->succeeded);
});

test('a restore that dies part-way says what may be left', function () {
    $database = restoreDatabaseFixture(['restore' => ['state' => 'running', 'stamp' => '20261110023000', 'step' => 'restoring', 'safety' => '20261111140000', 'error' => null]]);

    (new RestoreDatabase($database, '20261110023000', null, 2048, [], ''))->failed(new RuntimeException('timed out'));

    expect($database->fresh()->restore)->toMatchArray(['state' => 'failed', 'error' => 'The restore of shop stopped before it finished, so shop may be empty and apps Coresmith stopped may still be stopped. The copy Coresmith took first is the backup from Nov 11, 14:00.']);
});

test('the restore job runs on the long queue and fits its supervisor', function () {
    $job = new RestoreDatabase(ManagedDatabase::factory()->make(), '20261110023000', null, 2048, [], '');

    expect($job->queue)->toBe('provisioning')
        ->and($job->timeout)->toBeLessThan((int) config('horizon.defaults.provisioning.timeout'))
        ->and($job->tries)->toBe(1);
});
