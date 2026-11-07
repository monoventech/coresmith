<?php

declare(strict_types=1);

use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdatePaths;
use App\Services\Updates\UpdateRequest;
use App\Services\Updates\UpdateState;
use App\Services\Updates\UpdateStatus;
use App\Services\Updates\Version;
use Illuminate\Support\Sleep;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    $this->paths = updatesUseTemporaryPaths();
    updatesInstall($this->paths['root'], '1.0.1', 'a1b2c3d4e5f6');
});

afterEach(function () {
    updatesCleanUp();
});

function updatesState(): UpdateState
{
    return app(UpdateState::class);
}

function updatesStatus(string $to = '1.0.2', string $trigger = 'cli', ?string $requestedBy = null): UpdateStatus
{
    return UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse($to), $trigger, $requestedBy);
}

test('nothing is read or created before the first update', function () {
    $state = updatesState();

    expect($state->isLocked())->toBeFalse()
        ->and($state->status())->toBeNull()
        ->and($state->interrupted())->toBeFalse()
        ->and($state->pendingRequest())->toBeNull()
        ->and($state->requestTaken())->toBeFalse()
        ->and($state->cancelRequest())->toBeFalse()
        ->and($state->takeRequest())->toBeNull()
        ->and($state->lastSucceeded())->toBeNull()
        ->and($state->log('01jbz0000000000000000000aa'))->toBe([])
        ->and(is_dir($this->paths['state']))->toBeFalse();
});

test('only one process holds the update lock', function () {
    $first = updatesState()->acquireLock();

    expect($first)->not->toBeNull()
        ->and(app(UpdateState::class)->acquireLock())->toBeNull()
        ->and(updatesState()->isLocked())->toBeTrue()
        ->and(trim((string) file_get_contents(app(UpdatePaths::class)->lockFile())))->toBe((string) getmypid())
        ->and(fileperms(app(UpdatePaths::class)->lockFile()) & 0777)->toBe(0600);

    $first->release();

    expect(updatesState()->isLocked())->toBeFalse();

    $again = updatesState()->acquireLock();

    expect($again)->not->toBeNull();

    $again->release();
});

function updatesProbeLock(): mixed
{
    if (!is_dir(app(UpdatePaths::class)->state())) {
        mkdir(app(UpdatePaths::class)->state(), 0700, true);
    }

    $probe = fopen(app(UpdatePaths::class)->lockFile(), 'c');
    flock($probe, LOCK_SH);

    return $probe;
}

test('a look at the lock never stops an update from taking it', function () {
    $probe = updatesProbeLock();
    Sleep::whenFakingSleep(function () use ($probe): void {
        flock($probe, LOCK_UN);
    });

    $lock = updatesState()->acquireLock();

    expect($lock)->not->toBeNull();
    Sleep::assertSleptTimes(1);

    $lock->release();
    fclose($probe);
});

test('an update gives up on a lock that stays taken', function () {
    $held = updatesState()->acquireLock();

    expect(updatesState()->acquireLock())->toBeNull()
        ->and(UpdateState::LOCK_ATTEMPTS)->toBe(10);
    Sleep::assertSleptTimes(9);

    $held->release();
});

test('two looks at the lock never read each other as an update', function () {
    $probe = updatesProbeLock();

    expect(updatesState()->isLocked())->toBeFalse();

    fclose($probe);
});

test('a status survives a round trip and is written atomically and privately', function () {
    $this->travelTo('2026-11-01 04:00:00');
    $status = updatesStatus(requestedBy: '01jbz7aaaaaaaaaaaaaaaaaaaa')->withStep('fetch')->withRelease('1.0.2-b1b2c3d')->withMarker('built', '1.0.2-b1b2c3d')->withSignature('unchecked');

    updatesState()->save($status);

    $written = json_decode((string) file_get_contents(app(UpdatePaths::class)->statusFile()), true);

    expect(updatesState()->status()->toArray())->toBe($status->toArray())
        ->and(array_keys($written))->toBe(['schema', 'id', 'state', 'trigger', 'requested_by', 'from', 'to', 'step', 'failed_step', 'message', 'signature', 'markers', 'started_at', 'updated_at', 'finished_at'])
        ->and($written['from'])->toBe(['version' => '1.0.1', 'release' => '1.0.1-a1b2c3d'])
        ->and($written['to'])->toBe(['version' => '1.0.2', 'release' => '1.0.2-b1b2c3d'])
        ->and($written['markers'])->toBe(['built' => '1.0.2-b1b2c3d', 'maintenance' => false, 'backup' => null, 'migrating' => false, 'switched' => false, 'healthy' => false])
        ->and($written['started_at'])->toBe('2026-11-01T04:00:00+00:00')
        ->and($written['requested_by'])->toBe('01jbz7aaaaaaaaaaaaaaaaaaaa')
        ->and(glob("{$this->paths['state']}/.*.json.*"))->toBe([])
        ->and(fileperms(app(UpdatePaths::class)->statusFile()) & 0777)->toBe(0600)
        ->and(fileperms($this->paths['state']) & 0777)->toBe(0700)
        ->and(fileperms(app(UpdatePaths::class)->runs()) & 0777)->toBe(0700)
        ->and(is_file(app(UpdatePaths::class)->runs() . "/{$status->id}.json"))->toBeTrue();
});

test('a status that is not one is not read as one', function (string $contents) {
    mkdir($this->paths['state'], 0700, true);
    file_put_contents(app(UpdatePaths::class)->statusFile(), $contents);

    expect(updatesState()->status())->toBeNull();
})->with(['not json', '[]', '{"schema":1,"state":"running"}']);

test('a running status with nobody holding the lock is an interrupted update', function () {
    $status = updatesStatus();
    updatesState()->save($status);

    expect(updatesState()->interrupted())->toBeTrue();

    $lock = updatesState()->acquireLock();

    expect(updatesState()->interrupted())->toBeFalse();

    $lock->release();
    updatesState()->save($status->finished(UpdateStatus::SUCCEEDED));

    expect(updatesState()->interrupted())->toBeFalse();
});

test('history keeps the last twenty runs and the last success is found among them', function () {
    $newestSucceeded = null;

    foreach (range(1, 22) as $i) {
        $this->travelTo(now()->addMinute());
        $status = updatesStatus()->finished($i % 2 === 1 ? UpdateStatus::SUCCEEDED : UpdateStatus::ROLLED_BACK, "Run {$i}.");
        updatesState()->save($status);
        updatesState()->appendLog($status->id, "line of run {$i}");

        if ($i % 2 === 1) {
            $newestSucceeded = $status;
        }

        $first ??= $status;
    }

    expect(glob(app(UpdatePaths::class)->runs() . '/*.json'))->toHaveCount(20)
        ->and(glob(app(UpdatePaths::class)->runs() . '/*.log'))->toHaveCount(20)
        ->and(is_file(app(UpdatePaths::class)->runs() . "/{$first->id}.json"))->toBeFalse()
        ->and(updatesState()->lastSucceeded()->id)->toBe($newestSucceeded->id)
        ->and(updatesState()->lastSucceeded()->message)->toBe('Run 21.')
        ->and(updatesState()->status()->message)->toBe('Run 22.');
});

test('a request is written, cancelled, or taken exactly once', function () {
    $this->travelTo('2026-11-01 04:00:00');
    $request = new UpdateRequest(Version::parse('1.0.2'), 'panel', '01jbz3aaaaaaaaaaaaaaaaaaaa', now()->toImmutable());

    updatesState()->request($request);

    expect(updatesState()->pendingRequest()->toArray())->toBe($request->toArray())
        ->and($request->toArray())->toBe(['schema' => 1, 'to' => '1.0.2', 'trigger' => 'panel', 'requested_by' => '01jbz3aaaaaaaaaaaaaaaaaaaa', 'requested_at' => '2026-11-01T04:00:00+00:00'])
        ->and(fileperms(app(UpdatePaths::class)->requestFile()) & 0777)->toBe(0600)
        ->and(updatesState()->requestTaken())->toBeFalse()
        ->and(updatesState()->cancelRequest())->toBeTrue()
        ->and(updatesState()->pendingRequest())->toBeNull();

    updatesState()->request($request);
    rename(app(UpdatePaths::class)->requestFile(), app(UpdatePaths::class)->takenRequestFile());

    expect(updatesState()->requestTaken())->toBeTrue()
        ->and(updatesState()->pendingRequest()->toArray())->toBe($request->toArray())
        ->and(updatesState()->cancelRequest())->toBeFalse()
        ->and(updatesState()->takeRequest()->toArray())->toBe($request->toArray())
        ->and(is_file(app(UpdatePaths::class)->takenRequestFile()))->toBeFalse()
        ->and(updatesState()->takeRequest())->toBeNull();
});

test('a request names a recovery without a version, and nothing else without one', function () {
    $recover = new UpdateRequest(null, 'recover', null, now()->toImmutable());

    expect(UpdateRequest::fromArray($recover->toArray())->trigger)->toBe('recover')
        ->and(UpdateRequest::fromArray(['schema' => 1, 'to' => null, 'trigger' => 'panel', 'requested_by' => '01jbz1aaaaaaaaaaaaaaaaaaaa', 'requested_at' => now()->toIso8601String()]))->toBeNull()
        ->and(UpdateRequest::fromArray(['schema' => 1, 'to' => '1.0.2', 'trigger' => 'shell', 'requested_by' => '01jbz1aaaaaaaaaaaaaaaaaaaa', 'requested_at' => now()->toIso8601String()]))->toBeNull()
        ->and(UpdateRequest::fromArray(['schema' => 1, 'to' => '1.0.2', 'trigger' => 'auto', 'requested_by' => ['admin'], 'requested_at' => now()->toIso8601String()]))->toBeNull();
});

test('the run log is plain, private and redacted', function () {
    $run = updatesState()->begin(updatesStatus());
    $heard = [];
    $run->listen(function (string $line) use (&$heard): void {
        $heard[] = $line;
    });

    $run->output("\e[32mok\e[0m\r\nfetching https://x-access-token:ghp_abcdefghijklmnopqrstuvwxyz0123@github.com/monoventech/coresmith.git\n\n");

    expect(updatesState()->log($run->id()))->toBe(['ok', 'fetching https://[REDACTED]@github.com/monoventech/coresmith.git'])
        ->and($heard)->toBe(['ok', 'fetching https://[REDACTED]@github.com/monoventech/coresmith.git'])
        ->and(fileperms(app(UpdatePaths::class)->runs() . "/{$run->id()}.log") & 0777)->toBe(0600);
});

test('a run records its steps, marks, warnings and outcome as it goes', function () {
    $run = updatesState()->begin(updatesStatus());

    $run->step('fetch');
    $run->release('1.0.2-b1b2c3d');
    $run->signature('unchecked');
    $run->mark('built', '1.0.2-b1b2c3d');
    $run->warn('Release signatures are not checked.');

    expect(updatesState()->status())
        ->step->toBe('fetch')
        ->toRelease->toBe('1.0.2-b1b2c3d')
        ->signature->toBe('unchecked')
        ->and(updatesState()->status()->markers['built'])->toBe('1.0.2-b1b2c3d');

    $final = $run->finish(UpdateStatus::ABORTED, 'Nothing was changed.', 'fetch');

    expect($final->state)->toBe(UpdateStatus::ABORTED)
        ->and($final->failedStep)->toBe('fetch')
        ->and($final->finishedAt)->not->toBeNull()
        ->and(updatesState()->status()->toArray())->toBe($final->toArray())
        ->and(updatesState()->log($run->id()))->toBe(['Fetching the release', 'Warning: Release signatures are not checked.', 'Nothing was changed.'])
        ->and(updatesState()->log($run->id(), 1))->toBe(['Nothing was changed.']);
});

test('a log id that is not a run id reads nothing', function (string $id) {
    mkdir(app(UpdatePaths::class)->runs(), 0700, true);
    file_put_contents("{$this->paths['state']}/secret.log", "do not show this\n");
    file_put_contents(app(UpdatePaths::class)->runs() . '/not-a-run.log', "nor this\n");

    expect(updatesState()->log($id))->toBe([])
        ->and(fn() => updatesState()->appendLog($id, 'x'))->toThrow(RuntimeException::class);
})->with(['../secret', 'not-a-run', '']);

test('a change moves the updated time and keeps the start', function () {
    $this->travelTo('2026-11-01 04:00:00');
    $status = updatesStatus();
    $this->travelTo('2026-11-01 04:02:00');
    $moved = $status->withStep('fetch');

    expect($moved->startedAt->toIso8601String())->toBe('2026-11-01T04:00:00+00:00')
        ->and($moved->updatedAt->toIso8601String())->toBe('2026-11-01T04:02:00+00:00')
        ->and($status->step)->toBe('preflight');
});

test('steps are numbered out of twelve and the rollback is not counted', function () {
    $status = updatesStatus();

    expect($status->withStep('dependencies')->stepNumber())->toBe(4)
        ->and($status->withStep('dependencies')->stepLabel())->toBe('Installing PHP dependencies')
        ->and($status->withStep('preflight')->stepNumber())->toBe(1)
        ->and($status->withStep('health')->stepNumber())->toBe(12)
        ->and($status->withStep('rollback')->stepNumber())->toBeNull()
        ->and($status->withStep('rollback')->stepLabel())->toBe('Rolling back')
        ->and(UpdateStatus::COUNTED)->toBe(12)
        ->and(UpdateStatus::COUNTED)->toBe(count(UpdateStatus::STEPS) - 1)
        ->and(fn() => $status->withStep('teleport'))->toThrow(InvalidArgumentException::class);
});

test('a new status starts at the pre-flight check with nothing done', function () {
    $status = updatesStatus(trigger: 'panel', requestedBy: '01jbz2aaaaaaaaaaaaaaaaaaaa');

    expect($status)
        ->state->toBe(UpdateStatus::RUNNING)
        ->step->toBe('preflight')
        ->trigger->toBe('panel')
        ->requestedBy->toBe('01jbz2aaaaaaaaaaaaaaaaaaaa')
        ->fromVersion->toBe('1.0.1')
        ->toVersion->toBe('1.0.2')
        ->toRelease->toBeNull()
        ->finishedAt->toBeNull()
        ->and($status->isRunning())->toBeTrue()
        ->and($status->id)->toMatch('/^[0-9a-z]{26}$/');
});
