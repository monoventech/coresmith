<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Jobs\StartApplication;
use App\Jobs\StopApplication;
use App\Models\Application;
use App\Services\SSH\SSHConnectionManager;
use Tests\Support\FakeSSHTransport;

function cronPowerApp(PowerState $state): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'php_version' => '8.5.5',
        'current_release' => '20261019-120000',
        'power_state' => $state,
        'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']],
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
