<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Panel\PanelBackup;
use App\Services\Updates\PanelServices;
use App\Services\Updates\PanelUpdater;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdatePaths;
use App\Services\Updates\UpdateRefused;
use App\Services\Updates\UpdateState;
use App\Services\Updates\UpdateStatus;
use App\Services\Updates\Version;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->paths = updatesPanel();

    $this->commands = [];
    $kernel = app(Kernel::class);
    $proxy = Mockery::mock($kernel);
    $proxy->shouldReceive('call')->andReturnUsing(function (string $command, array $parameters = []) use ($kernel) {
        $this->commands[] = $command;

        return $kernel->call($command, $parameters);
    });
    Artisan::swap($proxy);
});

afterEach(function () {
    updatesCleanUp();
});

function updatesUpdater(): PanelUpdater
{
    return app(PanelUpdater::class);
}

function updatesReleaseNames(): array
{
    $names = array_map('basename', glob(config('coresmith.updates.root') . '/releases/{,.}[!.]*', GLOB_BRACE | GLOB_ONLYDIR) ?: []);
    sort($names);

    return $names;
}

function updatesArchives(): array
{
    return glob(storage_path('app/private/backups') . '/*.tar.gz') ?: [];
}

function updatesInterrupted(Closure $markers, string $step): UpdateStatus
{
    Carbon::setTestNow(now()->subMinutes(5));
    $archive = app(PanelBackup::class)->run()['path'];
    Carbon::setTestNow();

    DB::connection('csu_panel')->table('probe')->insert(['note' => 'migrated']);
    $status = UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse('0.1.1'), 'panel', null)->withStep($step);

    foreach ($markers($archive) as $key => $value) {
        $status = $status->withMarker($key, $value);
    }

    app(UpdateState::class)->save($status);

    return $status;
}

test('an update builds the new release beside the old one and switches to it', function () {
    $status = updatesUpdater()->run(Version::parse('0.1.1'));

    $layout = app(ReleaseLayout::class);

    expect($status->state)->toBe(UpdateStatus::SUCCEEDED)
        ->and($status->message)->toBe('Coresmith v0.1.1 is running. Backup taken, health check passed.')
        ->and($layout->current()->name)->toBe('0.1.1-a1b2c3d')
        ->and($layout->current()->builtBy)->toBe('update')
        ->and($layout->current()->signature)->toBe('unchecked')
        ->and($layout->find('0.1.0-8413dce'))->not->toBeNull()
        ->and($status->markers)->toMatchArray(['built' => '0.1.1-a1b2c3d', 'maintenance' => true, 'migrating' => true, 'switched' => true, 'healthy' => true])
        ->and(is_file($status->markers['backup']))->toBeTrue()
        ->and(app(PanelServices::class)->isDown())->toBeFalse()
        ->and(app(UpdateState::class)->isLocked())->toBeFalse()
        ->and(updatesProbeNotes())->toBe(['before', 'migrated'])
        ->and(Activity::query()->where('description', 'panel_updated')->sole()->properties->only(['from', 'to', 'trigger', 'signature'])->all())
        ->toBe(['from' => '0.1.0', 'to' => '0.1.1', 'trigger' => 'cli', 'signature' => 'unchecked']);
});

test('the steps run in the documented order', function () {
    $status = updatesUpdater()->run(Version::parse('0.1.1'));
    $labels = array_values(array_diff(UpdateStatus::STEPS, ['Rolling back']));
    $log = app(UpdateState::class)->log($status->id);

    expect(array_values(array_intersect($log, $labels)))->toBe($labels)
        ->and(collect(updatesProcesses())->firstWhere('command', PHP_BINARY . ' artisan migrate --force --no-interaction'))
        ->path->toBe(app(ReleaseLayout::class)->current()->path)
        ->timeout->toBe(1800);
});

test('a failure while building changes nothing', function (string $key, array $failure, string $step) {
    if ($key === 'verify') {
        file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');
    }

    updatesMachine([$key => $failure]);

    $status = updatesUpdater()->run(Version::parse('0.1.1'));

    expect($status->state)->toBe(UpdateStatus::ABORTED)
        ->and($status->failedStep)->toBe($step)
        ->and($status->message)->toEndWith('Nothing changed.')
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.0-8413dce')
        ->and(updatesReleaseNames())->toBe(['0.1.0-8413dce'])
        ->and(updatesArchives())->toBe([])
        ->and($this->commands)->not->toContain('down');
})->with([
    'fetch' => ['fetch', [128, "fatal: unable to access the repository\n"], 'fetch'],
    'verify' => ['verify', [1, "error: no signature found\n"], 'verify'],
    'platform' => ['platform', [2, "ext-gmp * -> it is missing from your system.\n"], 'dependencies'],
    'composer' => ['composer', [1, "Your requirements could not be resolved.\n"], 'dependencies'],
    'bun build' => ['bun-build', [1, "error during build\n"], 'assets'],
    'config cache' => ['artisan:config:cache', [1, "Your configuration files are not serializable.\n"], 'cache'],
]);

test('a drain timeout aborts with nothing changed', function () {
    updatesFakeHorizon([updatesBusyMaster()->getPid()]);
    config(['coresmith.updates.drain_timeout' => 3]);

    $status = updatesUpdater()->run(Version::parse('0.1.1'));

    expect($status->state)->toBe(UpdateStatus::ABORTED)
        ->and($status->failedStep)->toBe('drain')
        ->and($status->message)->toBe('A queued job is still running after 3 seconds, so Coresmith stopped before changing anything. Let it finish, then update again.')
        ->and(app(PanelServices::class)->isDown())->toBeFalse()
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.0-8413dce')
        ->and(updatesArchives())->toBe([])
        ->and(updatesReleaseNames())->toBe(['0.1.0-8413dce']);
});

test('a failed backup aborts and brings the panel back', function () {
    $private = storage_path('app/private');
    mkdir($private, 0700, true);
    chmod($private, 0500);

    try {
        $status = updatesUpdater()->run(Version::parse('0.1.1'));
    } finally {
        chmod($private, 0700);
    }

    expect($status->state)->toBe(UpdateStatus::ABORTED)
        ->and($status->failedStep)->toBe('backup')
        ->and($status->message)->toStartWith("Coresmith couldn't back up the database: ")
        ->and($status->message)->toEndWith('. Nothing changed.')
        ->and($status->message)->not->toContain('.. Nothing')
        ->and(app(PanelServices::class)->isDown())->toBeFalse()
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.0-8413dce')
        ->and(updatesReleaseNames())->toBe(['0.1.0-8413dce']);
});

test('a failed migration restores the database and keeps the old release', function () {
    updatesMachine(['artisan:migrate' => [1, "SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.email\n"]]);

    $status = updatesUpdater()->run(Version::parse('0.1.1'));

    expect($status->state)->toBe(UpdateStatus::ROLLED_BACK)
        ->and($status->failedStep)->toBe('migrate')
        ->and($status->message)->toBe('Running migrations failed: SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.email. Coresmith rolled back to v0.1.0, and the panel is running as before.')
        ->and(updatesProbeNotes())->toBe(['before'])
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.0-8413dce')
        ->and(app(PanelServices::class)->isDown())->toBeFalse()
        ->and(array_count_values($this->commands)['horizon:terminate'] ?? 0)->toBe(2)
        ->and(Activity::query()->where('description', 'panel_update_rolled_back')->sole()->properties['step'])->toBe('migrate');
});

test('a failure after the switch swaps back, restores and restarts', function (array $failures, array $settings, string $step) {
    updatesMachine($failures);
    config($settings);

    if ($step === 'health') {
        updatesProbe('0.1.0-8413dce');
    }

    $status = updatesUpdater()->run(Version::parse('0.1.1'));

    expect($status->state)->toBe(UpdateStatus::ROLLED_BACK)
        ->and($status->failedStep)->toBe($step)
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.0-8413dce')
        ->and(updatesProbeNotes())->toBe(['before'])
        ->and(array_count_values($this->commands)['horizon:terminate'] ?? 0)->toBe(3)
        ->and(app(PanelServices::class)->isDown())->toBeFalse();
})->with([
    'a restart that never comes back' => [['main-pid' => fn(PendingProcess $process) => Process::result(output: (app(UpdateState::class)->status()?->step === 'rollback' ? 2000 + (updatesRegistry()->pidCounter = (updatesRegistry()->pidCounter ?? 0) + 1) : 1001) . "\n")], ['coresmith.updates.restart_timeout' => 4], 'restart'],
    'a health check still serving the old release' => [[], ['coresmith.updates.health_timeout' => 4], 'health'],
]);

test('a rollback that cannot restore the database leaves the panel in maintenance and says what to do', function () {
    updatesMachine(['artisan:migrate' => function (PendingProcess $process) {
        @unlink(app(UpdateState::class)->status()->markers['backup']);

        return Process::result(errorOutput: "Migration failed\n", exitCode: 1);
    }]);

    $status = updatesUpdater()->run(Version::parse('0.1.1'));

    expect($status->state)->toBe(UpdateStatus::FAILED)
        ->and($status->failedStep)->toBe('migrate')
        ->and(app(PanelServices::class)->isDown())->toBeTrue()
        ->and($status->message)->toContain('The rollback did not finish:')
        ->and($status->message)->toContain('The panel is in maintenance mode.')
        ->and($status->message)->toContain('Restore ' . $status->markers['backup'])
        ->and($status->message)->toContain('sudo -u coresmith php artisan up')
        ->and(Activity::query()->where('description', 'panel_update_failed')->count())->toBe(1);
});

test('an interrupted update is rolled back by the next run', function () {
    updatesInterrupted(fn(string $archive): array => ['built' => '0.1.1-a1b2c3d', 'maintenance' => true, 'backup' => $archive, 'migrating' => true, 'switched' => true], 'switch');
    $new = updatesRelease($this->paths['root'], '0.1.1', 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0');
    app(ReleaseLayout::class)->activate($new);
    app(PanelServices::class)->down();

    $status = updatesUpdater()->recover();

    expect($status->state)->toBe(UpdateStatus::ROLLED_BACK)
        ->and($status->message)->toStartWith('The update was interrupted while switching to the new release. Coresmith rolled back to v0.1.0')
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.0-8413dce')
        ->and(updatesProbeNotes())->toBe(['before'])
        ->and(app(PanelServices::class)->isDown())->toBeFalse()
        ->and(updatesUpdater()->recover())->toBeNull();
});

test('an interrupted update that had passed its health check is finished, not undone', function () {
    updatesInterrupted(fn(string $archive): array => ['built' => '0.1.1-a1b2c3d', 'maintenance' => true, 'backup' => $archive, 'migrating' => true, 'switched' => true, 'healthy' => true], 'health');
    app(PanelServices::class)->down();

    $status = updatesUpdater()->recover();

    expect($status->state)->toBe(UpdateStatus::SUCCEEDED)
        ->and($status->message)->toBe('The update finished, but its last step was interrupted.')
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.0-8413dce')
        ->and(updatesProbeNotes())->toBe(['before', 'migrated'])
        ->and(app(PanelServices::class)->isDown())->toBeFalse();
});

test('an interruption before maintenance only clears up', function () {
    updatesInterrupted(fn(string $archive): array => ['built' => '0.1.1-a1b2c3d'], 'dependencies');
    mkdir("{$this->paths['root']}/releases/0.1.1-a1b2c3d", 0755, true);

    $status = updatesUpdater()->recover();

    expect($status->state)->toBe(UpdateStatus::ABORTED)
        ->and($status->message)->toBe('The update was interrupted while installing PHP dependencies. Nothing changed.')
        ->and(is_dir("{$this->paths['root']}/releases/0.1.1-a1b2c3d"))->toBeFalse()
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.0-8413dce');
});

test('nothing is recovered when nothing was interrupted', function () {
    expect(updatesUpdater()->recover())->toBeNull();

    app(UpdateState::class)->save(UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse('0.1.1'), 'cli', null)->finished(UpdateStatus::SUCCEEDED));

    expect(updatesUpdater()->recover())->toBeNull();
});

test('a new run recovers an interrupted one before it starts', function () {
    $interrupted = updatesInterrupted(fn(string $archive): array => ['built' => '0.1.1-a1b2c3d', 'maintenance' => true, 'backup' => $archive, 'migrating' => true, 'switched' => true], 'switch');
    app(ReleaseLayout::class)->activate(updatesRelease($this->paths['root'], '0.1.1', 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0'));

    $status = updatesUpdater()->run(Version::parse('0.1.1'));

    $runs = collect(glob(app(UpdatePaths::class)->runs() . '/*.json'))
        ->map(fn(string $file): array => json_decode((string) file_get_contents($file), true))
        ->sortBy('id')
        ->values();

    expect($runs)->toHaveCount(2)
        ->and($runs[0]['id'])->toBe($interrupted->id)
        ->and($runs[0]['state'])->toBe(UpdateStatus::ROLLED_BACK)
        ->and($runs[1]['state'])->toBe(UpdateStatus::SUCCEEDED)
        ->and($status->state)->toBe(UpdateStatus::SUCCEEDED)
        ->and(app(ReleaseLayout::class)->current()->name)->toBe('0.1.1-a1b2c3d');
});

test('refusals that leave nothing to record are thrown', function (Closure $setUp, string $message) {
    $message = $setUp($this->paths) ?? $message;

    expect(fn() => updatesUpdater()->run(Version::parse('0.1.1')))->toThrow(UpdateRefused::class, $message)
        ->and(app(UpdateState::class)->status())->toBeNull();
})->with([
    'updates are off' => [fn() => config(['coresmith.updates.enabled' => false]), 'Self-updates are off on this install. Installs made by the Coresmith installer turn them on with CORESMITH_UPDATES_ENABLED=true.'],
    'the wrong user' => [function () {
        config(['coresmith.updates.root' => '/']);

        return posix_geteuid() === 0 ? 'release directories' : 'Run updates as root: sudo -u root php artisan coresmith:update';
    }, ''],
    'no release directories' => [function (array $paths) {
        unlink("{$paths['root']}/app");
        mkdir("{$paths['root']}/app");
    }, "This install doesn't use release directories, so it can't update itself. Run the installer for the version you want instead."],
]);

test('another update in progress is refused without touching its status', function () {
    $lock = app(UpdateState::class)->acquireLock();
    $this->travelTo(now()->subMinutes(3));
    $status = UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse('0.1.1'), 'panel', null);
    app(UpdateState::class)->save($status);
    $this->travelBack();

    try {
        expect(fn() => updatesUpdater()->run(Version::parse('0.1.1')))->toThrow(UpdateRefused::class, 'Another update is running (to v0.1.1, started 3 minutes ago).')
            ->and(app(UpdateState::class)->status()->toArray())->toBe($status->toArray());
    } finally {
        $lock->release();
    }
});

test('pre-flight refusals are recorded so the panel can show them', function (Closure $setUp, string $message, string $target = '0.1.1') {
    $setUp($this->paths);

    $status = updatesUpdater()->run(Version::parse($target));

    expect($status->state)->toBe(UpdateStatus::ABORTED)
        ->and($status->failedStep)->toBe('preflight')
        ->and($status->message)->toMatch('/^' . $message . '$/')
        ->and(updatesReleaseNames())->toBe(['0.1.0-8413dce'])
        ->and(app(UpdateState::class)->status()->toArray())->toBe($status->toArray());
})->with([
    'a running deploy' => [fn() => Deployment::factory()->for(Application::factory()->state(['name' => 'atlas']))->create(['status' => DeploymentStatus::Running]), preg_quote('A deploy of atlas is running. Wait for it to finish, then update.', '/')],
    'a pending deploy' => [fn() => Deployment::factory()->for(Application::factory()->state(['name' => 'atlas']))->create(['status' => DeploymentStatus::Pending]), preg_quote('A deploy of atlas is running. Wait for it to finish, then update.', '/')],
    'a server provisioning' => [fn() => Server::factory()->create(['name' => 'forge-03', 'status' => ServerStatus::Provisioning]), preg_quote('forge-03 is provisioning. Wait for it to finish, then update.', '/')],
    'a cloud task running' => [function () {
        $server = Server::factory()->fromProvider(CloudProvider::factory()->create())->create(['name' => 'kiln-02']);
        CloudTask::start($server, CloudTask::RESIZE, 'shutdown');
    }, preg_quote('Coresmith is still working on kiln-02 at Hetzner Cloud. Wait for it to finish, then update.', '/')],
    'too little disk' => [fn() => config(['coresmith.updates.min_free_bytes' => 10 ** 15]), 'Only [\d.]+ GB is free on .+; an update needs 931322\.6 GB\. Free some space, then try again\.'],
    'bun missing' => [fn() => updatesMachine(['tool:bun' => [127, "bun: command not found\n"]]), preg_quote("bun isn't installed, and the update needs it. Install it, then try again.", '/')],
    'an older version' => [fn() => null, preg_quote('v0.0.9 is not newer than the running v0.1.0. Coresmith only updates forward.', '/'), '0.0.9'],
    'a withdrawn version' => [fn() => updatesFeed(['latest' => '0.1.2', 'withdrawn' => ['0.1.1']]), preg_quote("v0.1.1 was withdrawn, so Coresmith won't install it.", '/')],
]);

test('a feed that cannot be reached does not stop a named update', function () {
    updatesFeed('nope', 500);

    expect(updatesUpdater()->run(Version::parse('0.1.1'))->state)->toBe(UpdateStatus::SUCCEEDED);
});

test('an unsigned release is installed with a warning while no keys ship', function () {
    $status = updatesUpdater()->run(Version::parse('0.1.1'));

    expect(app(UpdateState::class)->log($status->id))->toContain("Warning: Release signatures aren't checked: this install has no Coresmith release keys yet.")
        ->and($status->signature)->toBe('unchecked');
});

test('the target comes from the feed unless one is named', function () {
    expect((string) updatesUpdater()->target(null))->toBe('0.1.1');

    updatesFeed(['latest' => '0.1.0']);

    expect(updatesUpdater()->target(null))->toBeNull();

    updatesFeed('nope', 500);

    expect(fn() => updatesUpdater()->target(null))->toThrow(UpdateRefused::class, 'The release feed at get.coresmith.io answered 500. Try again later. Pass --to=<version> to update without the feed.')
        ->and(fn() => updatesUpdater()->target('0.0.9'))->toThrow(UpdateRefused::class, 'v0.0.9 is not newer than the running v0.1.0. Coresmith only updates forward.')
        ->and(fn() => updatesUpdater()->target('soon'))->toThrow(UpdateRefused::class, '"soon" is not a version like 1.2.3.')
        ->and((string) updatesUpdater()->target('0.1.1'))->toBe('0.1.1')
        ->and(app(UpdateState::class)->readCheck()->error)->toBe('The release feed at get.coresmith.io answered 500. Try again later.');

    updatesFeed(['latest' => '0.1.2', 'withdrawn' => ['0.1.1']]);

    expect(fn() => updatesUpdater()->target('0.1.1'))->toThrow(UpdateRefused::class, "v0.1.1 was withdrawn, so Coresmith won't install it.");
});

test('old releases are pruned to the running one and the one before it', function () {
    $this->travelTo(now()->subDays(2));
    updatesRelease($this->paths['root'], '0.0.9', '0000009aaaaaaa');
    $this->travelBack();
    mkdir("{$this->paths['root']}/releases/.incoming-leftover");

    updatesUpdater()->run(Version::parse('0.1.1'));

    expect(updatesReleaseNames())->toBe(['0.1.0-8413dce', '0.1.1-a1b2c3d']);
});

test('a finished run is not undone when the activity log cannot be written', function () {
    Activity::creating(fn() => throw new RuntimeException('database is locked'));

    $status = updatesUpdater()->run(Version::parse('0.1.1'));

    expect($status->state)->toBe(UpdateStatus::SUCCEEDED)
        ->and(app(UpdateState::class)->log($status->id))->toContain("Warning: Coresmith couldn't record this run in the activity log.");
});
