<?php

declare(strict_types=1);

use App\Services\Updates\PanelServices;
use App\Services\Updates\UpdateFailed;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    updatesFakeHorizon([]);
});

afterEach(function () {
    updatesCleanUp();
});

function updatesServices(): PanelServices
{
    return app(PanelServices::class);
}

function updatesFailure(Closure $attempt): UpdateFailed
{
    try {
        $attempt();
    } catch (UpdateFailed $e) {
        return $e;
    }

    throw new RuntimeException('Expected the step to fail.');
}

function updatesSystemd(array $mainPids, array $inactive = []): stdClass
{
    $reads = (object) ['count' => []];

    Process::fake(['*' => function (PendingProcess $process) use ($mainPids, $inactive, $reads) {
        $command = (array) $process->command;
        $unit = (string) ($command[2] ?? '');

        if (($command[1] ?? null) === 'is-active') {
            $unit = (string) ($command[3] ?? '');

            return Process::result(exitCode: in_array($unit, $inactive, true) ? 3 : 0);
        }

        if (($command[1] ?? null) === 'show') {
            $reads->count[$unit] = ($reads->count[$unit] ?? 0) + 1;
            $answers = $mainPids[$unit] ?? [0];
            $answer = $answers[min($reads->count[$unit], count($answers)) - 1];

            return Process::result(output: "{$answer}\n");
        }

        return Process::result(exitCode: 1);
    }]);

    return $reads;
}

test('the services that are running are the ones restarted', function () {
    updatesSystemd([], inactive: ['coresmith-reverb']);

    expect(updatesServices()->active())->toBe(['coresmith-horizon']);

    Process::assertRan(fn(PendingProcess $process): bool => $process->command === ['systemctl', 'is-active', '--quiet', 'coresmith-horizon'] && $process->timeout === 10);
});

test('maintenance goes on and off', function () {
    $storage = realpath(sys_get_temp_dir()) . '/csu-storage-' . Str::lower(Str::random(8));
    mkdir("{$storage}/framework", 0700, true);
    $this->app->useStoragePath($storage);

    try {
        updatesServices()->down();

        expect(updatesServices()->isDown())->toBeTrue()
            ->and(json_decode((string) file_get_contents("{$storage}/framework/down"), true)['retry'] ?? null)->toBe(60);

        updatesServices()->up();

        expect(updatesServices()->isDown())->toBeFalse();
    } finally {
        File::deleteDirectory($storage);
    }
});

test('draining waits for horizon\'s workers to finish', function () {
    expect(posix_kill(999999, 0))->toBeFalse();

    updatesFakeHorizon([999999]);

    updatesServices()->drain(300);

    Sleep::assertNeverSlept();
});

test('draining asks horizon to stop and waits until its master has gone', function () {
    $master = updatesBusyMaster();
    updatesFakeHorizon([$master->getPid()]);
    Sleep::whenFakingSleep(function () use ($master): void {
        $master->signal(SIGKILL);
        $master->wait();
    });

    updatesServices()->drain(300);

    Sleep::assertSleptTimes(1);
});

test('masters on other hosts sharing the queue are not waited for', function () {
    $master = updatesBusyMaster();
    app()->instance(MasterSupervisorRepository::class, Mockery::mock(MasterSupervisorRepository::class, fn($mock) => $mock->shouldReceive('all')->andReturn([
        (object) ['name' => 'another-host-0', 'pid' => (string) $master->getPid(), 'status' => 'running', 'supervisors' => []],
    ])));

    updatesServices()->drain(3);

    Sleep::assertNeverSlept();
    expect($master->isRunning())->toBeTrue();
});

test('nothing is signalled when horizon is not running', function () {
    Artisan::spy();

    updatesServices()->drain(300);

    Artisan::shouldNotHaveReceived('call', ['horizon:terminate']);
    Sleep::assertNeverSlept();
});

test('a job that outlasts the drain timeout stops the update', function (int $timeout, string $duration) {
    updatesFakeHorizon([updatesBusyMaster()->getPid()]);

    $failed = updatesFailure(fn() => updatesServices()->drain($timeout));

    expect($failed->step)->toBe('drain')
        ->and($failed->getMessage())->toBe("A queued job is still running after {$duration}, so Coresmith stopped before changing anything. Let it finish, then update again.");

    Sleep::assertSleptTimes($timeout);
})->with([
    'seconds' => [3, '3 seconds'],
    'one second' => [1, '1 second'],
    'one minute' => [60, '1 minute'],
    'minutes' => [300, '5 minutes'],
]);

test('a restart is proved by a new main pid', function () {
    updatesSystemd(['coresmith-horizon' => [100, 100, 100, 200]]);
    Artisan::spy();

    updatesServices()->restart(['coresmith-horizon'], 90);

    Sleep::assertSleptTimes(2);
    Artisan::shouldHaveReceived('call')->with('horizon:terminate')->once();
    Artisan::shouldNotHaveReceived('call', ['reverb:restart']);
});

test('a main pid of zero is a unit that has not come back yet', function () {
    updatesSystemd(['coresmith-horizon' => [100, 0, 0, 300]]);
    Artisan::spy();

    updatesServices()->restart(['coresmith-horizon'], 90);

    Sleep::assertSleptTimes(2);
});

test('a new main pid on a unit that is not active is not a restart', function () {
    updatesSystemd(['coresmith-horizon' => [100, 200]], inactive: ['coresmith-horizon']);
    Artisan::spy();

    $failed = updatesFailure(fn() => updatesServices()->restart(['coresmith-horizon'], 4));

    expect($failed->step)->toBe('restart');
});

test('a unit that does not come back fails the restart with how to look', function () {
    updatesSystemd(['coresmith-horizon' => [100]]);
    Artisan::spy();

    $failed = updatesFailure(fn() => updatesServices()->restart(['coresmith-horizon'], 6));

    expect($failed->step)->toBe('restart')
        ->and($failed->getMessage())->toBe("coresmith-horizon didn't come back within 6 seconds. Inspect it with journalctl -u coresmith-horizon -n 50.");

    Sleep::assertSleptTimes(3);
});

test('reverb is asked to restart only when it was running', function () {
    updatesSystemd(['coresmith-reverb' => [500, 501]]);
    Artisan::spy();

    updatesServices()->restart(['coresmith-reverb'], 90);

    Artisan::shouldHaveReceived('call')->with('reverb:restart')->once();
    Artisan::shouldNotHaveReceived('call', ['horizon:terminate']);
});

test('both units must come back before a restart is done', function () {
    updatesSystemd(['coresmith-horizon' => [100, 200], 'coresmith-reverb' => [500, 500, 501]]);
    Artisan::spy();

    updatesServices()->restart(['coresmith-horizon', 'coresmith-reverb'], 90);

    Sleep::assertSleptTimes(1);
});

test('the unit that did not come back is the one named', function () {
    updatesSystemd(['coresmith-horizon' => [100, 200], 'coresmith-reverb' => [500]]);
    Artisan::spy();

    $failed = updatesFailure(fn() => updatesServices()->restart(['coresmith-horizon', 'coresmith-reverb'], 4));

    expect($failed->getMessage())->toBe("coresmith-reverb didn't come back within 4 seconds. Inspect it with journalctl -u coresmith-reverb -n 50.");
});

test('the main pid is read from systemd with a short timeout', function () {
    updatesSystemd(['coresmith-horizon' => [4242]]);

    expect(updatesServices()->mainPid('coresmith-horizon'))->toBe(4242);

    Process::assertRan(fn(PendingProcess $process): bool => $process->command === ['systemctl', 'show', 'coresmith-horizon', '--property=MainPID', '--value'] && $process->timeout === 10);
});
