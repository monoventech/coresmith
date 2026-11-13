<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Server;
use App\Services\Process\CrashLoop;
use App\Services\Process\CrashLoopWatch;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

function watchApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'type' => ApplicationType::Php,
        'php_version' => '8.5.5',
        'processes' => [['name' => 'queue', 'command' => '/usr/bin/php artisan queue:work']],
    ], $attributes));
}

function watchRun(FakeSSHTransport $transport, Application $application): ?CrashLoop
{
    $connection = (new SSHConnection(Server::factory()->create(['host_key_fingerprint' => null]), $transport))->connect();

    return app(CrashLoopWatch::class)->watch($connection, $application);
}

test('processes that stay up pass a full minute of checks', function () {
    expect(watchRun(new FakeSSHTransport, watchApp()))->toBeNull();

    Sleep::assertSleptTimes(CrashLoopWatch::CHECKS);
});

test('a unit down on three checks in a row is a crash loop, with the end of its log', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo("systemctl is-active 'coresmith-app-shop-queue'", "activating\n", 3)
        ->respondTo("tail -n 20 '/var/coresmith/logs/shop/queue.log'", "SQLSTATE[HY000] [2002] Connection refused\n");

    $loop = watchRun($transport, watchApp());

    expect($loop)->toBeInstanceOf(CrashLoop::class)
        ->and($loop->unit)->toBe('coresmith-app-shop-queue')
        ->and($loop->output)->toContain('Connection refused');

    Sleep::assertSleptTimes(3);
});

test('a unit the memory limit keeps killing is a crash loop that says it ran out of memory', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo("systemctl is-active 'coresmith-app-shop-queue'", "activating\n", 3)
        ->respondTo("show -p Result --value 'coresmith-app-shop-queue'", "oom-kill\n");

    $loop = watchRun($transport, watchApp());

    expect($loop?->unit)->toBe('coresmith-app-shop-queue')
        ->and($loop?->outOfMemory)->toBeTrue();
});

test('a unit that stops for any other reason is not called out of memory', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo("systemctl is-active 'coresmith-app-shop-queue'", "activating\n", 3)
        ->respondTo("show -p Result --value 'coresmith-app-shop-queue'", "exit-code\n");

    expect(watchRun($transport, watchApp())?->outOfMemory)->toBeFalse();
});

test('a unit systemd keeps restarting is a crash loop even when it looks active', function () {
    $transport = (new FakeSSHTransport)
        ->respondToSequence("NRestarts --value 'coresmith-app-shop-fpm'", [["0\n", 0], ["2\n", 0], ["5\n", 0]])
        ->respondTo("journalctl -u 'coresmith-app-shop-fpm'", "ERROR: failed to open configuration file\n");

    $loop = watchRun($transport, watchApp());

    expect($loop?->unit)->toBe('coresmith-app-shop-fpm')
        ->and($loop?->output)->toContain('failed to open configuration file');
});

test('restarts are counted from the baseline, not from zero', function () {
    $steady = (new FakeSSHTransport)->respondTo("NRestarts --value 'coresmith-app-shop-fpm'", "9\n");

    expect(watchRun($steady, watchApp()))->toBeNull();

    $climbing = (new FakeSSHTransport)
        ->respondToSequence("NRestarts --value 'coresmith-app-shop2-fpm'", [["9\n", 0], ["10\n", 0], ["11\n", 0], ["12\n", 0]])
        ->respondTo("journalctl -u 'coresmith-app-shop2-fpm'", "ERROR: failed to open configuration file\n");

    expect(watchRun($climbing, watchApp(['slug' => 'shop2', 'system_user' => 'coresmith-app-shop2']))?->unit)->toBe('coresmith-app-shop2-fpm');
});

test('a unit that recovers before the third failed check is left alone', function () {
    $transport = (new FakeSSHTransport)->respondToSequence("systemctl is-active 'coresmith-app-shop-queue'", [["failed\n", 3], ["failed\n", 3], ["active\n", 0]]);

    expect(watchRun($transport, watchApp()))->toBeNull();
});

test('a static site has nothing to watch', function () {
    $transport = new FakeSSHTransport;

    expect(watchRun($transport, watchApp(['type' => ApplicationType::Static, 'php_version' => null, 'processes' => null])))->toBeNull()
        ->and($transport->commands())->toBe([]);

    Sleep::assertNeverSlept();
});
