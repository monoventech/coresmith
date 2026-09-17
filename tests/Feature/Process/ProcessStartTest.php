<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\ProcessDidNotStart;
use App\Models\Application;
use App\Models\Server;
use App\Services\Process\FpmPoolBuilder;
use App\Services\Process\ProcessManager;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

function startManager(): ProcessManager
{
    $asdf = new AsdfManager;

    return new ProcessManager(new SystemdUnitBuilder($asdf), new FpmPoolBuilder($asdf));
}

function startConnection(FakeSSHTransport $transport): SSHConnection
{
    return (new SSHConnection(Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']), $transport))->connect();
}

function startApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'base_port' => 8100,
        'processes' => [['name' => 'queue', 'command' => '/opt/asdf-data/installs/php/8.5.5/bin/php artisan queue:work']],
    ], $attributes));
}

test('every unit is restarted under the name it was installed with', function () {
    $transport = new FakeSSHTransport;

    startManager()->refresh(startConnection($transport), startApp());

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("systemctl enable 'coresmith-app-shop-fpm'")
        ->toContain("systemctl reload-or-restart 'coresmith-app-shop-fpm'")
        ->toContain("systemctl enable 'coresmith-app-shop-queue'")
        ->toContain("systemctl restart 'coresmith-app-shop-queue'")
        ->not->toContain('php-fpm-coresmith-app-shop')
        ->not->toContain('coresmith-app-shop-worker');
});

test('a node app restarts its web unit and has no fpm unit', function () {
    $transport = new FakeSSHTransport;
    $application = startApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'php_version' => null, 'node_version' => '22.1.0', 'js_runtime' => 'node', 'processes' => null]);

    startManager()->refresh(startConnection($transport), $application);

    expect(implode("\n", $transport->commands()))->toContain("systemctl restart 'coresmith-app-shop-web'")
        ->not->toContain('-fpm');
});

test('the fpm unit can be reloaded gracefully', function () {
    expect((new FpmPoolBuilder(new AsdfManager))->unit(startApp()))->toContain('ExecReload=/bin/kill -USR2 $MAINPID');
});

test('units that come up active pass after a short grace period', function () {
    $transport = new FakeSSHTransport;

    startManager()->verifyStarted(startConnection($transport), startApp());

    Sleep::assertSequence([Sleep::for(ProcessManager::START_GRACE_SECONDS)->seconds()]);

    expect(implode("\n", $transport->commands()))->toContain("systemctl is-active 'coresmith-app-shop-fpm'")
        ->toContain("systemctl is-active 'coresmith-app-shop-queue'");
});

test('a unit that is not running fails with its journal and advice for what the journal says', function (string $journal, string $advice) {
    $transport = (new FakeSSHTransport)
        ->respondTo("systemctl is-active 'coresmith-app-shop-queue'", "failed\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-shop-queue'", $journal);

    try {
        startManager()->verifyStarted(startConnection($transport), startApp());
        $this->fail('A unit that was not running passed the start check.');
    } catch (ProcessDidNotStart $e) {
        expect($e->unit)->toBe('coresmith-app-shop-queue')
            ->and($e->state)->toBe('failed')
            ->and($e->journal)->toBe($journal)
            ->and($e->getMessage())->toContain('coresmith-app-shop-queue did not start')
            ->and($e->getMessage())->toContain($advice);
    }
})->with([
    'port taken' => ['Error: listen EADDRINUSE: address already in use :::8100', 'Port 8100 is already taken by another process.'],
    'cannot bind' => ['Error: listen EACCES: permission denied 0.0.0.0:8100', 'The process was not allowed to bind its port.'],
    'missing module' => ["Error: Cannot find module 'express'", 'A Node module is missing.'],
    'unreadable files' => ['PHP Warning: require(/var/coresmith/apps/shop/current/vendor/autoload.php): Failed to open stream: Permission denied', 'The process could not read its files.'],
    'missing entry point' => ['bun: No such file or directory: server.js', 'The entry point was not found.'],
    'syntax error' => ['PHP Parse error: syntax error, unexpected token "}"', 'The code has a syntax error.'],
    'anything else' => ['Killed', 'The process log says why.'],
]);
