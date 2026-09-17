<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\NoPreviousReleaseException;
use App\Exceptions\ProcessDidNotStart;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\RollbackApplication;
use App\Models\Application;
use App\Services\SSH\SSHConnectionManager;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function rollbackApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'my-app',
        'system_user' => 'coresmith-app-abcd',
        'type' => ApplicationType::Php,
        'php_version' => '8.5.5',
        'current_release' => '20260913-120000',
    ], $attributes));
}

function rollbackTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('ls -1dt', implode("\n", [
        '/var/coresmith/apps/my-app/releases/20260913-120000/',
        '/var/coresmith/apps/my-app/releases/20260912-090000/',
        '/var/coresmith/apps/my-app/releases/20260911-100000/',
        '',
    ]));
}

function rollbackRun(Application $application, FakeSSHTransport $transport, ?string $release = null): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new RollbackApplication($application, $release))->handle(app(SSHConnectionManager::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('a named release that is still on disk becomes the live release', function () {
    $application = rollbackApp();
    $transport = rollbackTransport();

    rollbackRun($application, $transport, '20260911-100000');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('ln -sfn /var/coresmith/apps/my-app/releases/20260911-100000 /var/coresmith/apps/my-app/current.tmp')
        ->and($commands)->toContain('mv -Tf /var/coresmith/apps/my-app/current.tmp /var/coresmith/apps/my-app/current')
        ->and($commands)->toContain("systemctl reload-or-restart 'coresmith-app-my-app-fpm'")
        ->and($application->fresh()->current_release)->toBe('20260911-100000');
});

test('without a named release it rolls back to the one before the newest', function () {
    $application = rollbackApp();
    $transport = rollbackTransport();

    rollbackRun($application, $transport);

    expect(implode("\n", $transport->commands()))->toContain('ln -sfn /var/coresmith/apps/my-app/releases/20260912-090000 /var/coresmith/apps/my-app/current.tmp')
        ->and($application->fresh()->current_release)->toBe('20260912-090000');
});

test('a rollback is logged with where it came from and where it went', function () {
    $application = rollbackApp(['current_release' => '20260913-120000']);

    rollbackRun($application, rollbackTransport(), '20260911-100000');

    $activity = Activity::query()->where('description', 'application_rolled_back')->sole();

    expect($activity->subject_id)->toBe($application->id)
        ->and($activity->properties->toArray())->toBe(['from' => '20260913-120000', 'to' => '20260911-100000']);
});

test('a named release that is no longer on disk is refused and nothing is swapped', function () {
    $application = rollbackApp();
    $transport = rollbackTransport();

    expect(fn() => rollbackRun($application, $transport, '20260801-080000'))->toThrow(NoPreviousReleaseException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('ln -sfn')
        ->and($application->fresh()->current_release)->toBe('20260913-120000')
        ->and(Activity::query()->where('description', 'application_rolled_back')->exists())->toBeFalse();
});

test('a release is matched by its full directory, not a prefix of it', function () {
    $application = rollbackApp();
    $transport = rollbackTransport();

    expect(fn() => rollbackRun($application, $transport, '20260911100000'))->toThrow(NoPreviousReleaseException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('ln -sfn');
});

test('a release name that is not a release timestamp is refused before the job exists', function (string $release) {
    expect(fn() => new RollbackApplication(rollbackApp(), $release))->toThrow(InvalidArgumentException::class);
})->with([
    'a traversal' => '../../etc',
    'a partial stamp' => '2026',
    'an injection' => '20260913-120000; rm -rf /',
    'an underscore' => '20260913_120000',
    'empty' => '',
]);

test('a release name carrying a trailing newline never reaches a shell command', function () {
    $application = rollbackApp();
    $transport = rollbackTransport();

    expect(fn() => rollbackRun($application, $transport, "20260911-100000\n"))->toThrow(NoPreviousReleaseException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('ln -sfn');
});

test('both release name formats are accepted', function (string $release) {
    expect(new RollbackApplication(rollbackApp(), $release))->toBeInstanceOf(RollbackApplication::class);
})->with(['20260913120000', '20260913-120000']);

test('the connection is released after a rollback, even a refused one', function () {
    $transport = rollbackTransport();

    rollbackRun(rollbackApp(), $transport);

    expect($transport->connected())->toBeFalse();

    $refused = rollbackTransport();

    expect(fn() => rollbackRun(rollbackApp(['slug' => 'other-app', 'system_user' => 'coresmith-app-efgh']), $refused, '20260101-000000'))
        ->toThrow(NoPreviousReleaseException::class);

    expect($refused->connected())->toBeFalse();
});

test('a restart failure after the swap still records the release that is now live', function () {
    $application = rollbackApp();
    $transport = rollbackTransport()->respondTo('systemctl reload', 'Job for php-fpm failed', 1);

    expect(fn() => rollbackRun($application, $transport, '20260911-100000'))->toThrow(SSHCommandFailedException::class);

    expect(implode("\n", $transport->commands()))->toContain('mv -Tf')
        ->and($application->fresh()->current_release)->toBe('20260911-100000');
});

test('a rollback whose processes do not come back up is recorded, not silent', function () {
    $application = rollbackApp();
    $transport = rollbackTransport()
        ->respondTo('systemctl is-active', "failed\n")
        ->respondTo('journalctl -u', "bind: address already in use\n");

    expect(fn() => rollbackRun($application, $transport, '20260911-100000'))->toThrow(ProcessDidNotStart::class);

    $activity = Activity::query()->where('description', 'rollback_processes_failed')->sole();

    expect($activity->subject_id)->toBe($application->id)
        ->and($activity->properties['from'])->toBe('20260913-120000')
        ->and($activity->properties['to'])->toBe('20260911-100000')
        ->and($activity->properties['error'])->toContain('did not start')
        ->and(Activity::query()->where('description', 'application_rolled_back')->exists())->toBeFalse();
});

test('rollbacks run on the deployments queue', function () {
    expect((new RollbackApplication(rollbackApp()))->queue)->toBe('deployments');
});
