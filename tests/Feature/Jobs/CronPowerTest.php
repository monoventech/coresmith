<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\StartApplication;
use App\Jobs\StopApplication;
use App\Models\Application;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

function cronPowerApp(PowerState $state, array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'php_version' => '8.5.5',
        'current_release' => '20261019-120000',
        'power_state' => $state,
        'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']],
        ...$attributes,
    ]);
}

function cronPowerRun(object $job, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([$job, 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('stopping an app takes its cron file away, so its jobs pause until it starts', function () {
    $transport = new FakeSSHTransport;

    cronPowerRun(new StopApplication(cronPowerApp(PowerState::Stopped)), $transport);

    expect(implode("\n", $transport->commands()))->toContain("rm -f '/etc/cron.d/coresmith-app-shop' '/etc/cron.d/.coresmith-app-shop.new'")
        ->and($transport->uploads())->not->toHaveKey('/etc/cron.d/.coresmith-app-shop.new');
});

test('starting an app puts its cron file back', function () {
    $transport = new FakeSSHTransport;

    cronPowerRun(new StartApplication(cronPowerApp(PowerState::Active)), $transport);

    expect($transport->uploads())->toHaveKey('/etc/cron.d/.coresmith-app-shop.new')
        ->and($transport->uploads())->toHaveKey('/etc/coresmith/cron/.shop.new/0.sh');
});

test('a stop or start that fails part-way still pauses or resumes the jobs', function (Closure $job, PowerState $state, string $refused, Closure $written) {
    $application = cronPowerApp($state, ['processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]);
    $transport = (new FakeSSHTransport)->respondTo($refused, 'Job for the unit failed.', 1);

    expect(fn() => cronPowerRun($job($application), $transport))->toThrow(SSHCommandFailedException::class);

    expect($written($transport))->toBeTrue()
        ->and($application->fresh()->cron_error)->toBeNull();
})->with([
    'a stop' => [fn(Application $application) => new StopApplication($application), PowerState::Stopped, 'systemctl stop', fn(FakeSSHTransport $transport): bool => str_contains(implode("\n", $transport->commands()), "rm -f '/etc/cron.d/coresmith-app-shop'")],
    'a start' => [fn(Application $application) => new StartApplication($application), PowerState::Active, 'systemctl enable --now', fn(FakeSSHTransport $transport): bool => array_key_exists('/etc/cron.d/.coresmith-app-shop.new', $transport->uploads())],
]);

test('a stop or start whose cron write fails says so on the card', function (Closure $job, PowerState $state, string $refused) {
    $application = cronPowerApp($state);
    $transport = (new FakeSSHTransport)->respondTo($refused, 'cannot remove: Read-only file system', 1);

    expect(fn() => cronPowerRun($job($application), $transport))->toThrow(SSHCommandFailedException::class);

    expect($application->fresh()->cron_error)->toBe("{$application->server->name} refused the change: cannot remove: Read-only file system. Check the server's disk space, then try again.");
})->with([
    'a stop' => [fn(Application $application) => new StopApplication($application), PowerState::Stopped, "rm -f '/etc/cron.d/coresmith-app-shop'"],
    'a start' => [fn(Application $application) => new StartApplication($application), PowerState::Active, 'mv -T'],
]);
