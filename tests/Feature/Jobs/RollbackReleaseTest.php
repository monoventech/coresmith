<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Exceptions\NoPreviousReleaseException;
use App\Exceptions\ProcessDidNotStart;
use App\Jobs\RollbackApplication;
use App\Models\Application;
use App\Models\Deployment;
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

test('without a named release it rolls back to the newest succeeded release other than the current one', function () {
    $application = rollbackApp();
    Deployment::factory()->for($application)->create(['release' => '20260912-090000', 'status' => DeploymentStatus::Succeeded]);
    $transport = rollbackTransport();

    rollbackRun($application, $transport);

    expect(implode("\n", $transport->commands()))->toContain('ln -sfn /var/coresmith/apps/my-app/releases/20260912-090000 /var/coresmith/apps/my-app/current.tmp')
        ->and($application->fresh()->current_release)->toBe('20260912-090000');
});

test('a default rollback skips a newer directory on the server that never became a succeeded release', function () {
    $application = rollbackApp();
    Deployment::factory()->for($application)->create(['release' => '20260912-090000', 'status' => DeploymentStatus::Succeeded]);
    Deployment::factory()->for($application)->create(['release' => '20260913-120000', 'status' => DeploymentStatus::Running]);
    $transport = (new FakeSSHTransport)->respondTo('ls -1dt', implode("\n", [
        '/var/coresmith/apps/my-app/releases/20260914-080000/',
        '/var/coresmith/apps/my-app/releases/20260913-120000/',
        '/var/coresmith/apps/my-app/releases/20260912-090000/',
        '/var/coresmith/apps/my-app/releases/20260911-100000/',
        '',
    ]));

    rollbackRun($application, $transport);

    expect(implode("\n", $transport->commands()))->toContain('ln -sfn /var/coresmith/apps/my-app/releases/20260912-090000 /var/coresmith/apps/my-app/current.tmp')
        ->and($application->fresh()->current_release)->toBe('20260912-090000');
});

test('a default rollback with no succeeded release to go back to is refused', function () {
    $application = rollbackApp();
    $transport = rollbackTransport();

    expect(fn() => rollbackRun($application, $transport))->toThrow(NoPreviousReleaseException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('ln -sfn')
        ->and($application->fresh()->current_release)->toBe('20260913-120000');
});

test('a default rollback whose chosen release is no longer on the server fails with a clear message', function () {
    $application = rollbackApp();
    Deployment::factory()->for($application)->create(['release' => '20260910-070000', 'status' => DeploymentStatus::Succeeded]);
    $transport = rollbackTransport();

    expect(fn() => rollbackRun($application, $transport))->toThrow(NoPreviousReleaseException::class, 'Release 20260910-070000 is no longer on the server, so there is nothing to roll back to. Deploy again instead.');

    expect(implode("\n", $transport->commands()))->not->toContain('ln -sfn');
});

test('a rollback is logged with where it came from and where it went', function () {
    $application = rollbackApp(['current_release' => '20260913-120000']);

    rollbackRun($application, rollbackTransport(), '20260911-100000');

    $activity = Activity::query()->where('description', 'application_rolled_back')->sole();

    expect($activity->subject_id)->toBe($application->id)
        ->and($activity->properties->toArray())->toBe(['from' => '20260913-120000', 'to' => '20260911-100000', 'runtimes' => 'unchanged']);
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
    'a trailing newline' => "20261019-120000\n",
]);

test('a release name carrying a trailing newline never reaches a shell command', function () {
    $application = rollbackApp();
    $transport = rollbackTransport();

    expect(fn() => rollbackRun($application, $transport, "20260911-100000\n"))->toThrow(InvalidArgumentException::class);

    expect($transport->commands())->toBe([]);
});

test('both release name formats are accepted', function (string $release) {
    expect(new RollbackApplication(rollbackApp(), $release))->toBeInstanceOf(RollbackApplication::class);
})->with(['20260913120000', '20260913-120000']);

test('the connection is released after a rollback, even a refused one', function () {
    $application = rollbackApp();
    Deployment::factory()->for($application)->create(['release' => '20260912-090000', 'status' => DeploymentStatus::Succeeded]);
    $transport = rollbackTransport();

    rollbackRun($application, $transport);

    expect($transport->connected())->toBeFalse();

    $refused = rollbackTransport();

    expect(fn() => rollbackRun(rollbackApp(['slug' => 'other-app', 'system_user' => 'coresmith-app-efgh']), $refused, '20260101-000000'))
        ->toThrow(NoPreviousReleaseException::class);

    expect($refused->connected())->toBeFalse();
});

test('a restart failure after the swap still records the release that is now live', function () {
    $application = rollbackApp();
    $transport = rollbackTransport()
        ->respondTo('systemctl reload', 'Job for php-fpm failed', 1)
        ->respondTo("systemctl is-active 'coresmith-app-my-app-fpm'", "failed\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-my-app-fpm'", 'ERROR: failed to open configuration file');

    expect(fn() => rollbackRun($application, $transport, '20260911-100000'))->toThrow(ProcessDidNotStart::class);

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

test('a rollback whose processes do not start leaves the app failed', function () {
    $application = rollbackApp(['status' => ApplicationStatus::Active]);
    $transport = rollbackTransport()
        ->respondTo('systemctl is-active', "activating\n", 3)
        ->respondTo('journalctl -u', "coresmith-app-my-app-fpm.service: Main process exited, code=exited, status=78/CONFIG\n");

    expect(fn() => rollbackRun($application, $transport, '20260911-100000'))->toThrow(ProcessDidNotStart::class);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Failed)
        ->and($application->fresh()->current_release)->toBe('20260911-100000');
});

test('a rollback where only a worker does not start keeps the app live and names the worker', function () {
    $application = rollbackApp(['status' => ApplicationStatus::Failed, 'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]);
    $transport = rollbackTransport()
        ->respondTo("systemctl is-active 'coresmith-app-my-app-queue'", "activating\n", 3)
        ->respondTo("tail -n 200 '/var/coresmith/logs/my-app/queue.log'", "Could not open input file: artisan\nNo such file or directory\n");

    expect(fn() => rollbackRun($application, $transport, '20260911-100000'))->toThrow(ProcessDidNotStart::class);

    $activity = Activity::query()->where('description', 'rollback_worker_failed')->sole();

    expect($application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and($application->fresh()->current_release)->toBe('20260911-100000')
        ->and($activity->properties->toArray())->toBe([
            'from' => '20260913-120000',
            'to' => '20260911-100000',
            'worker' => 'Queue worker',
            'error' => 'coresmith-app-my-app-queue did not start, systemd reports it as activating. The entry point was not found. Check the build produced the file the process starts, then deploy again.',
        ])
        ->and(Activity::query()->whereIn('description', ['rollback_processes_failed', 'application_rolled_back'])->exists())->toBeFalse();
});

test('a rollback whose processes start leaves a failed app live again', function () {
    $application = rollbackApp(['status' => ApplicationStatus::Failed]);

    rollbackRun($application, rollbackTransport(), '20260911-100000');

    expect($application->fresh()->status)->toBe(ApplicationStatus::Active);
});

test('a rollback never overwrites the status of a deploy that is running', function (bool $starts) {
    $application = rollbackApp(['status' => ApplicationStatus::Deploying]);
    $transport = $starts ? rollbackTransport() : rollbackTransport()->respondTo('systemctl is-active', "failed\n", 3);
    $run = fn() => rollbackRun($application, $transport, '20260911-100000');

    $starts ? $run() : expect($run)->toThrow(ProcessDidNotStart::class);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Deploying);
})->with(['processes start' => true, 'processes do not start' => false]);

test('rolling back writes the current environment before the processes restart, so a fixed variable takes effect', function () {
    $application = rollbackApp(['env_variables' => "RAILS_ENV=production\nSECRET_KEY_BASE=abc\n"]);
    $transport = rollbackTransport();

    rollbackRun($application, $transport, '20260911-100000');

    $commands = $transport->commands();
    $written = array_search("chown root:root '/etc/coresmith/env/my-app.env' && chmod 600 '/etc/coresmith/env/my-app.env'", $commands, true);
    $restarted = array_search("systemctl reload-or-restart 'coresmith-app-my-app-fpm'", $commands, true);

    expect($transport->uploads()['/etc/coresmith/env/my-app.env'] ?? null)->toBe("RAILS_ENV=\"production\"\nSECRET_KEY_BASE=\"abc\"\n")
        ->and($written)->not->toBeFalse()
        ->and($restarted)->not->toBeFalse()
        ->and($written)->toBeLessThan($restarted);
});

test('a stopped app rolled back still gets its current environment, so it starts with it', function () {
    $application = rollbackApp(['power_state' => PowerState::Stopped, 'env_variables' => "RAILS_ENV=production\n"]);
    $transport = rollbackTransport();

    rollbackRun($application, $transport, '20260911-100000');

    expect($transport->uploads()['/etc/coresmith/env/my-app.env'] ?? null)->toBe("RAILS_ENV=\"production\"\n")
        ->and(implode("\n", $transport->commands()))->not->toContain('systemctl restart')
        ->and(implode("\n", $transport->commands()))->not->toContain('systemctl reload-or-restart');
});

test('rollbacks run on the deployments queue', function () {
    expect((new RollbackApplication(rollbackApp()))->queue)->toBe('deployments');
});

function rollbackStack(string $php): array
{
    return [
        'runtimes' => ['php' => ['runtime' => 'php', 'version' => $php, 'source' => 'composer.json', 'detail' => '^8.4']],
        'framework' => ['name' => 'laravel', 'major' => 13],
        'tooling' => null,
    ];
}

test('rolling back restores the versions the target release was built with and restarts php-fpm onto them', function () {
    $application = rollbackApp(['php_version' => '8.5.10']);
    Deployment::factory()->for($application)->create(['release' => '20260912-090000', 'status' => DeploymentStatus::Succeeded, 'stack' => rollbackStack('8.4.20')]);
    $transport = rollbackTransport();

    rollbackRun($application, $transport);

    $commands = implode("\n", $transport->commands());

    expect($application->fresh()->php_version)->toBe('8.4.20')
        ->and($application->fresh()->stack['framework'])->toBe(['name' => 'laravel', 'major' => 13])
        ->and($transport->uploads()['/etc/systemd/system/coresmith-app-my-app-fpm.service'] ?? '')->toContain('/opt/asdf-data/installs/php/8.4.20/sbin/php-fpm')
        ->and($commands)->toContain("systemctl restart 'coresmith-app-my-app-fpm'")
        ->and($commands)->not->toContain("systemctl reload-or-restart 'coresmith-app-my-app-fpm'")
        ->and(Activity::query()->where('description', 'application_rolled_back')->sole()->properties['runtimes'])->toBe('restored');
});

test('rolling back to a release with no recorded versions leaves the runtimes as they are and says so', function () {
    $application = rollbackApp(['php_version' => '8.5.10']);
    Deployment::factory()->for($application)->create(['release' => '20260912-090000', 'status' => DeploymentStatus::Succeeded]);
    $transport = rollbackTransport();

    rollbackRun($application, $transport);

    expect($application->fresh()->php_version)->toBe('8.5.10')
        ->and(array_keys($transport->uploads()))->toBe(['/etc/coresmith/env/my-app.env'])
        ->and(implode("\n", $transport->commands()))->toContain("rm -f '/etc/cron.d/coresmith-app-my-app' '/etc/cron.d/.coresmith-app-my-app.new' && rm -rf '/etc/coresmith/cron/my-app' '/etc/coresmith/cron/.my-app.new'")
        ->and(implode("\n", $transport->commands()))->toContain("systemctl reload-or-restart 'coresmith-app-my-app-fpm'")
        ->and(Activity::query()->where('description', 'application_rolled_back')->sole()->properties['runtimes'])->toBe('unchanged');
});

test('rolling back to a release on another php version installs a cron file whose PATH names that version', function () {
    $application = rollbackApp(['php_version' => '8.5.10', 'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);
    Deployment::factory()->for($application)->create(['release' => '20260912-090000', 'status' => DeploymentStatus::Succeeded, 'stack' => rollbackStack('8.4.20')]);
    $transport = rollbackTransport();

    rollbackRun($application, $transport, '20260912-090000');

    $crontab = $transport->uploads()['/etc/cron.d/.coresmith-app-my-app.new'] ?? '';

    expect($crontab)->toContain(" -E 'PATH=/opt/asdf-data/installs/php/8.4.20/bin:/usr/local/bin:/usr/bin:/bin' ")
        ->and($crontab)->toContain("* * * * * root systemd-run --quiet --wait --pipe --collect --service-type=exec --uid=coresmith-app-abcd --gid=coresmith-app-abcd -p 'WorkingDirectory=/var/coresmith/apps/my-app/current'")
        ->and($crontab)->toContain("-- /bin/bash -s < '/etc/coresmith/cron/my-app/0.sh'")
        ->and($transport->uploads()['/etc/coresmith/cron/.my-app.new/0.sh'] ?? '')->toContain("\nphp artisan schedule:run\n")
        ->and($crontab)->not->toContain('8.5.10');
});

test('a stopped app still gets its units rewritten, so it starts on the right versions', function () {
    $application = rollbackApp(['php_version' => '8.5.10', 'power_state' => PowerState::Stopped]);
    Deployment::factory()->for($application)->create(['release' => '20260912-090000', 'status' => DeploymentStatus::Succeeded, 'stack' => rollbackStack('8.4.20')]);
    $transport = rollbackTransport();

    rollbackRun($application, $transport);

    expect($transport->uploads()['/etc/systemd/system/coresmith-app-my-app-fpm.service'] ?? '')->toContain('/opt/asdf-data/installs/php/8.4.20/sbin/php-fpm')
        ->and(implode("\n", $transport->commands()))->not->toContain('systemctl restart');
});
