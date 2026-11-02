<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Exceptions\InvalidRuntimeException;
use App\Exceptions\RuntimeUnavailable;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\SSHCommandTimedOut;
use App\Jobs\DeployApplication;
use App\Jobs\InstallRuntimes;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use phpseclib3\Exception\ConnectionClosedException;
use Tests\Support\FakeSSHTransport;

const INSTALL_RELEASE = '20260925100000';

function installingDeployment(): Deployment
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'name' => 'web-1']);
    $application = Application::factory()->for($server)->create(['slug' => 'shop', 'type' => ApplicationType::Php, 'current_release' => null]);

    return Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now(),
        'release' => INSTALL_RELEASE,
        'log' => '[10:00:00] release ' . INSTALL_RELEASE,
    ]);
}

function installRun(Deployment $deployment, FakeSSHTransport $transport, array $runtimes, string $owner = 'owner-1'): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new InstallRuntimes($deployment, INSTALL_RELEASE, $runtimes, $owner), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function installTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('test -d', "no\n");
}

function installFailed(Deployment $deployment, FakeSSHTransport $transport, Throwable $e, string $owner = 'owner-1', array $runtimes = []): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new InstallRuntimes($deployment, INSTALL_RELEASE, $runtimes, $owner))->failed($e);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('each missing runtime is installed, checked and recorded, then the deploy resumes with its lock', function () {
    Queue::fake();
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();
    $transport = installTransport();

    installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10'], ['runtime' => 'bun', 'version' => '1.4.2']]);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('asdf plugin update')
        ->and($commands)->toContain('asdf install')
        ->and($commands)->toContain("test -x '/opt/asdf-data/installs/php/8.5.10/sbin/php-fpm'")
        ->and($commands)->toContain("test -x '/opt/asdf-data/installs/bun/1.4.2/bin/bun'")
        ->and($deployment->application->server->fresh()->installed_runtimes)->toBeInAnyKeyOrder(['php' => ['8.5.10'], 'bun' => ['1.4.2']])
        ->and($deployment->fresh()->log)->toContain('deps: installing PHP 8.5.10, compiling it, usually 5–15 minutes the first time')
        ->and($deployment->fresh()->log)->toContain('Bun 1.4.2 is installed')
        ->and(Cache::lock('deploy:' . $deployment->application_id, 10)->get())->toBeFalse();

    Queue::assertPushedOn('deployments', DeployApplication::class, fn(DeployApplication $job): bool => (fn(): array => [$this->timestamp, $this->lockOwner])->call($job) === [INSTALL_RELEASE, 'owner-1']);
});

test('the reclaim deadline is pushed out when the install starts, not when it was queued', function () {
    Queue::fake();
    $deployment = installingDeployment();
    $deployment->forceFill(['stale_after' => now()->addMinute()])->save();

    installRun($deployment, installTransport(), [['runtime' => 'bun', 'version' => '1.4.2']]);

    expect($deployment->fresh()->stale_after->greaterThanOrEqualTo(now()->addSeconds(InstallRuntimes::window() - 5)))->toBeTrue();
});

test('a deployment reclaimed while the install waited in the queue is left alone', function () {
    Queue::fake();
    $deployment = installingDeployment();
    $deployment->forceFill(['status' => DeploymentStatus::Failed])->save();
    $transport = installTransport();

    installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10']]);

    expect($transport->commands())->toBe([]);

    Queue::assertNothingPushed();
});

test('a compile that fails ends the deploy with the build output, removes the release and frees the lock', function () {
    Queue::fake();
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();
    $transport = installTransport()->respondTo('asdf install', "checking for libxml-2.0... no\nconfigure: error: Package requirements (libxml-2.0 >= 2.9.0) were not met\n", 1);

    expect(fn() => installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10']]))->toThrow(SSHCommandFailedException::class);

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Failed)
        ->and($fresh->failure_reason)->toBe('Installing PHP 8.5.10 failed, so nothing changed. The end of the build output is in the log: install what it says is missing, then deploy again.')
        ->and($fresh->log)->toContain('configure: error: Package requirements (libxml-2.0 >= 2.9.0) were not met')
        ->and(implode("\n", $transport->commands()))->toContain('rm -rf /var/coresmith/apps/shop/releases/' . INSTALL_RELEASE)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 10)->get())->toBeTrue();

    Queue::assertNotPushed(DeployApplication::class);
});

test('a compile that runs out of time explains what timed out, not the raw command', function () {
    Queue::fake();
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();
    $transport = installTransport()->timeOutOn('asdf install');

    expect(fn() => installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10']]))->toThrow(SSHCommandTimedOut::class);

    expect($deployment->fresh()->failure_reason)->toBe(
        'Installing PHP 8.5.10 ran for ' . SSHCommandTimedOut::duration(1800) . " without finishing, so Coresmith stopped it. Deploy again to retry; if it happens again, check the server's network, memory and CPU."
    );
});

test('a download that runs out of time uses the same wording as a compile', function () {
    Queue::fake();
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();
    $transport = installTransport()->timeOutOn('asdf install');

    expect(fn() => installRun($deployment, $transport, [['runtime' => 'bun', 'version' => '1.4.2']]))->toThrow(SSHCommandTimedOut::class);

    expect($deployment->fresh()->failure_reason)->toBe(
        'Installing Bun 1.4.2 ran for ' . SSHCommandTimedOut::duration(1800) . " without finishing, so Coresmith stopped it. Deploy again to retry; if it happens again, check the server's network, memory and CPU."
    );
});

test('a connection dropped mid install names the server, not the raw phpseclib error', function () {
    Queue::fake();
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();
    $transport = installTransport()->dropConnectionOn('asdf install');

    expect(fn() => installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10']]))->toThrow(ConnectionClosedException::class);

    expect($deployment->fresh()->failure_reason)->toBe('Coresmith lost the connection to web-1 while installing PHP 8.5.10. Check the server is reachable, then deploy again.');
});

test('an install that leaves php-fpm missing is refused, because the app could not serve requests', function () {
    Queue::fake();
    $deployment = installingDeployment();
    $transport = installTransport()->respondTo("test -x '/opt/asdf-data/installs/php/8.5.10/sbin/php-fpm'", '', 1);

    expect(fn() => installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10']]))->toThrow(RuntimeUnavailable::class);

    expect($deployment->fresh()->failure_reason)->toBe('PHP 8.5.10 finished installing, but php-fpm is missing. Read the install log above, then deploy again.');
});

test('a version whose directory exists but whose binaries are incomplete is removed, installed again and checked', function () {
    Queue::fake();
    $deployment = installingDeployment();
    $transport = (new FakeSSHTransport)
        ->respondToSequence('test -d', [["yes\n"], ["no\n"]])
        ->respondToSequence("test -x '/opt/asdf-data/installs/php/8.5.10/sbin/php-fpm'", [['', 1], ['', 0]]);

    installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10']]);

    $commands = $transport->commands();
    $position = fn(string $needle): ?int => array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, $needle)));
    $uninstall = $position('asdf uninstall "$CORESMITH_RUNTIME" "$CORESMITH_VERSION"');
    $install = $position('asdf install "$CORESMITH_RUNTIME" "$CORESMITH_VERSION"');
    $checked = array_key_last(array_filter($commands, fn(string $command): bool => $command === "test -x '/opt/asdf-data/installs/php/8.5.10/sbin/php-fpm'"));

    expect($uninstall)->not->toBeNull()
        ->and($commands[$uninstall])->toContain("CORESMITH_RUNTIME='php'")
        ->and($commands[$uninstall])->toContain("CORESMITH_VERSION='8.5.10'")
        ->and($install)->toBeGreaterThan($uninstall)
        ->and($checked)->toBeGreaterThan($install)
        ->and($deployment->fresh()->log)->toContain('PHP 8.5.10 was left partly installed, so it is removed and installed again')
        ->and($deployment->fresh()->log)->toContain('PHP 8.5.10 is installed');

    Queue::assertPushed(DeployApplication::class);
});

test('a php version missing only composer is not recompiled, and composer is installed alongside it', function () {
    Queue::fake();
    $deployment = installingDeployment();
    $transport = (new FakeSSHTransport)
        ->respondTo('test -d', "yes\n")
        ->respondTo("test -x '/opt/asdf-data/installs/php/8.5.10/bin/composer'", '', 1);

    installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10']]);

    $commands = implode("\n", $transport->commands());

    expect($commands)->not->toContain('asdf uninstall')
        ->and($commands)->not->toContain('asdf install')
        ->and($commands)->toContain('composer-setup.php')
        ->and($deployment->fresh()->log)->not->toContain('was left partly installed')
        ->and($deployment->fresh()->log)->toContain('composer: installed beside PHP 8.5.10');

    Queue::assertPushed(DeployApplication::class);
});

test('a version another deploy finished installing is used as it is', function () {
    Queue::fake();
    $deployment = installingDeployment();
    $transport = (new FakeSSHTransport)->respondTo('test -d', "yes\n");

    installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10']]);

    $commands = implode("\n", $transport->commands());

    expect($commands)->not->toContain('asdf uninstall')
        ->and($commands)->not->toContain('asdf install')
        ->and($commands)->toContain("test -x '/opt/asdf-data/installs/php/8.5.10/bin/php' && test -x '/opt/asdf-data/installs/php/8.5.10/sbin/php-fpm'")
        ->and($commands)->toContain("'/opt/asdf-data/installs/php/8.5.10/bin/php' -m");

    Queue::assertPushed(DeployApplication::class);
});

test('two deploys needing the same version never compile it at once', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    $deployment = installingDeployment();
    Cache::lock('runtime-install:' . $deployment->application->server_id . ':php:8.5.10', 3600)->get();
    $transport = installTransport();

    expect(fn() => installRun($deployment, $transport, [['runtime' => 'php', 'version' => '8.5.10']]))->toThrow(LockTimeoutException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('asdf install')
        ->and($deployment->fresh()->failure_reason)->toBe('Another deploy was still installing PHP 8.5.10. Deploy again once it finishes.');
});

test('an install whose worker times out still ends the deploy and frees the lock', function () {
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();

    installFailed($deployment, new FakeSSHTransport, new MaxAttemptsExceededException('Job has timed out.'));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toBe('Installing the runtime ran for 45 minutes without finishing, so Coresmith stopped it. Deploy again, and check the server\'s memory and disk if it happens twice.')
        ->and(Cache::lock('deploy:' . $deployment->application_id, 10)->get())->toBeTrue();
});

test('a worker failure with nothing recorded yet names the runtime it was given', function () {
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();

    installFailed($deployment, new FakeSSHTransport, new MaxAttemptsExceededException('Job has timed out.'), runtimes: [['runtime' => 'php', 'version' => '8.5.10']]);

    expect($deployment->fresh()->failure_reason)->toBe('Installing PHP 8.5.10 ran for 45 minutes without finishing, so Coresmith stopped it. Deploy again, and check the server\'s memory and disk if it happens twice.');
});

test('a worker failure after the deploy already failed writes nothing twice', function () {
    $deployment = installingDeployment();
    $deployment->forceFill(['status' => DeploymentStatus::Failed, 'failure_reason' => 'first'])->save();

    installFailed($deployment, new FakeSSHTransport, new RuntimeException('late'));

    expect($deployment->fresh()->failure_reason)->toBe('first');
});

test('a failed install removes its half-built release from the server', function () {
    $deployment = installingDeployment();
    $transport = new FakeSSHTransport;

    installFailed($deployment, $transport, new RuntimeException('boom'));

    expect(implode("\n", $transport->commands()))->toContain('rm -rf /var/coresmith/apps/shop/releases/' . INSTALL_RELEASE)
        ->and($deployment->fresh()->log)->not->toContain('Could not remove');
});

test('a release that cannot be removed from failed() is logged, not thrown', function () {
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();
    $transport = (new FakeSSHTransport)->dropConnectionOn('rm -rf');

    installFailed($deployment, $transport, new RuntimeException('boom'));

    expect($deployment->fresh()->log)->toContain('Could not remove the half-built release. Remove releases/' . INSTALL_RELEASE . ' by hand if it is still there.')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 10)->get())->toBeTrue();
});

test('a failed() after the deployment already succeeded leaves the release alone', function () {
    $deployment = installingDeployment();
    $deployment->application->forceFill(['current_release' => '20260101000000'])->save();
    $deployment->forceFill(['status' => DeploymentStatus::Succeeded, 'finished_at' => now()])->save();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();
    $transport = new FakeSSHTransport;

    installFailed($deployment, $transport, new MaxAttemptsExceededException('Job has timed out.'));

    expect($transport->commands())->toBe([])
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);
});

test('a removed app and a detached server both still end Failed with the lock released', function (bool $removeApplication) {
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();
    $transport = new FakeSSHTransport;

    if ($removeApplication) {
        $deployment->application->delete();
    } else {
        $deployment->application->server->delete();
    }

    installFailed($deployment, $transport, new MaxAttemptsExceededException('Job has timed out.'));

    expect($transport->commands())->toBe([])
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 10)->get())->toBeTrue();
})->with([
    'a removed application' => [true],
    'a detached server' => [false],
]);

test('a timeout handler running inside the same worker reconnects instead of reusing a busy connection', function () {
    $deployment = installingDeployment();
    Cache::lock('deploy:' . $deployment->application_id, 3600, 'owner-1')->get();
    $transport = installTransport();
    $server = $deployment->application->server;

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    app(SSHConnectionManager::class)->for($server)->connect();

    installFailed($deployment, $transport, new MaxAttemptsExceededException('Job has timed out.'));

    expect($transport->calls())->toBe(['connect', 'disconnect', 'connect', 'disconnect']);
});

test('a runtime or version that could reach the shell is refused before the job exists', function () {
    new InstallRuntimes(installingDeployment(), INSTALL_RELEASE, [['runtime' => 'php', 'version' => '8.5.5; touch /tmp/pwned']], 'owner-1');
})->throws(InvalidRuntimeException::class);

test('installs run on the provisioning queue with a timeout long enough to compile', function () {
    $job = new InstallRuntimes(installingDeployment(), INSTALL_RELEASE, [], 'owner-1');

    expect($job->queue)->toBe('provisioning')
        ->and($job->timeout)->toBe(2700)
        ->and($job->tries)->toBe(1)
        ->and(InstallRuntimes::window())->toBe(2700 + 780 + 120);
});
