<?php

declare(strict_types=1);

use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\SSHCommandTimedOut;
use App\Exceptions\UnsafeNameException;
use App\Jobs\SyncApplicationCron;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\CronSyncFailure;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function cronSyncApp(array $attributes = []): Application
{
    return Application::factory()->for(Server::factory()->create(['name' => 'web-1']))->create([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'php_version' => '8.5.5',
        'current_release' => '20261019-120000',
        'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']],
        'cron_pending_since' => now()->subSeconds(5),
        ...$attributes,
    ]);
}

function cronSyncRun(Application $application, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new SyncApplicationCron($application), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('the jobs are written to the server and the page stops waiting', function () {
    $application = cronSyncApp(['cron_error' => 'web-1 refused the change.']);
    $transport = new FakeSSHTransport;

    cronSyncRun($application, $transport);

    expect($transport->uploads())->toHaveKey('/etc/cron.d/.coresmith-app-shop.new')
        ->and($application->fresh()->cron_pending_since)->toBeNull()
        ->and($application->fresh()->cron_error)->toBeNull()
        ->and($transport->connected())->toBeFalse();
});

test('an app with nothing live is not touched on the server, and the page stops waiting', function () {
    $application = cronSyncApp(['current_release' => null]);
    $transport = new FakeSSHTransport;

    cronSyncRun($application, $transport);

    expect($transport->commands())->toBe([])
        ->and($transport->loginAttempted())->toBeFalse()
        ->and($application->fresh()->cron_pending_since)->toBeNull();
});

test('a removed app is left to the job that detaches it', function () {
    $application = cronSyncApp();
    $application->delete();
    $transport = new FakeSSHTransport;

    cronSyncRun($application, $transport);

    expect($transport->commands())->toBe([])
        ->and(Activity::query()->where('description', SyncApplicationCron::FAILED)->exists())->toBeFalse();
});

test('a write the server refuses is kept for the page in plain words, logged and rethrown', function () {
    $application = cronSyncApp();
    $transport = (new FakeSSHTransport)->respondTo("mv -f '/etc/cron.d/.coresmith-app-shop.new'", "mv: cannot create regular file: No space left on device.\n", 1);
    $reason = "web-1 refused the change: mv: cannot create regular file: No space left on device. Check the server's disk space, then try again.";

    expect(fn() => cronSyncRun($application, $transport))->toThrow(SSHCommandFailedException::class);

    expect($application->fresh()->cron_error)->toBe($reason)
        ->and($application->fresh()->cron_pending_since)->toBeNull()
        ->and(Activity::query()->where('description', SyncApplicationCron::FAILED)->sole()->properties['error'])->toBe($reason);
});

test('each kind of failure says what happened and what to do next', function (Closure $failure, string $reason) {
    expect(CronSyncFailure::reason($failure(), cronSyncApp()))->toBe($reason);
})->with([
    'a changed host key' => [fn() => HostKeyChangedException::forServer('203.0.113.9', 'SHA256:old', 'SHA256:new'), 'web-1 presented a different host key, so Coresmith stopped before changing anything. Review the key on the server page, then try again.'],
    'a refused sign-in' => [fn() => SSHAuthenticationException::forServer('203.0.113.9', 'root'), "Coresmith couldn't sign in to web-1 over SSH. Check the server's SSH key, then try again."],
    'a refusal with no output' => [fn() => new SSHCommandFailedException('', 1), "web-1 refused the change. Check the server's disk space, then try again."],
    'a timeout' => [fn() => new SSHCommandTimedOut('mv -f x y', 600, ''), 'web-1 took too long to answer. Try again in a minute.'],
    'a write already running' => [fn() => new LockTimeoutException, 'Another change to these jobs was still being written. Try again in a minute.'],
    'a schedule cron cannot read' => [fn() => UnsafeNameException::cronSchedule('61 * * * *'), "One of these jobs has a schedule cron can't read, so nothing was written. Fix that job, then try again."],
    'anything else' => [fn() => new RuntimeException('Cannot connect to 203.0.113.9:22. Error 113. No route to host'), "Couldn't reach web-1 over SSH. Check it's online, then try again."],
]);

test('what the server said is cleaned of secrets and kept short', function () {
    $application = cronSyncApp(['env_variables' => "DB_PASSWORD=s3cr3t-value-123\n"]);
    $reason = CronSyncFailure::reason(new SSHCommandFailedException('mv: failed near s3cr3t-value-123 ' . str_repeat('x', 400), 1), $application);

    expect($reason)->toStartWith('web-1 refused the change: mv: failed near ')
        ->and($reason)->not->toContain('s3cr3t-value-123')
        ->and(mb_strlen($reason))->toBe(300);
});

test('one sync per app waits in the queue at a time, and it never retries on its own', function () {
    $application = cronSyncApp();
    $job = new SyncApplicationCron($application);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe((string) $application->id)
        ->and($job->queue)->toBe('server-tasks')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(120);
});
