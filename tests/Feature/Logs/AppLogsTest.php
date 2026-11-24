<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Server;
use App\Services\Logs\AppLogs;
use App\Services\Logs\LogQuery;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;
use Tests\Support\FakeSSHTransport;

function appLogsApp(array $attributes = []): Application
{
    return Application::factory()->for(Server::factory()->create(['ssh_user' => 'coresmith']))->create(array_merge([
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'current_release' => '20261124-110000',
        'processes' => [['name' => 'worker', 'command' => 'php artisan queue:work']],
    ], $attributes));
}

function appLogsBoundary(FakeSSHTransport $transport): string
{
    preg_match('/==([0-9a-f]{16})==/', (string) last($transport->commands()), $found);

    return $found[1];
}

function appLogsRead(Application $application, LogQuery $query, string $root, string $own = ''): array
{
    $transport = new FakeSSHTransport;
    $transport->onCommand('date +%z', fn() => $transport->respondTo('date +%z', str_replace('BOUNDARY', appLogsBoundary($transport), $root)));
    $transport->onCommand('logs/scheduler.log', fn() => $transport->respondTo('logs/scheduler.log', str_replace('BOUNDARY', appLogsBoundary($transport), $own)));

    return [app(AppLogs::class)->read((new SSHConnection($application->server, $transport))->connect(), $application, $query), $transport];
}

function appLogsAccess(float $at, string $uri = '/', int $status = 200): string
{
    return json_encode(['ts' => $at, 'request' => ['host' => 'shop.example.com', 'method' => 'GET', 'uri' => $uri], 'status' => $status, 'duration' => 0.05], JSON_UNESCAPED_SLASHES);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
});

test('the root read covers requests for the app\'s hosts, each process\'s file, PHP errors and the units\' events for the window', function () {
    $script = app(AppLogs::class)->rootScript(appLogsApp(), new LogQuery('15m'), 'b0undary');
    $since = now()->subMinutes(15)->getTimestamp();

    expect($script)->toContain("echo '==b0undary== requests'")
        ->toContain("grep -F -i -e '\"host\":\"shop.example.com\"'")
        ->toContain("echo '==b0undary== process worker'")
        ->toContain("'/var/coresmith/logs/shop/worker.log'")
        ->toContain("-t 'coresmith-app-shop-php' -t 'coresmith-app-shop-fpm' --since=@{$since}")
        ->toContain("_PID=1 'UNIT=coresmith-app-shop-fpm.service' 'UNIT=coresmith-app-shop-worker.service' --since=@{$since}")
        ->toContain('tail -c ' . LogQuery::SHORT_BYTES)
        ->not->toContain('grep -F -i -e \'x');
});

test('a search is passed to grep as a fixed string, so nothing in it runs', function () {
    $script = app(AppLogs::class)->rootScript(appLogsApp(), new LogQuery('1h', "'; rm -rf / #\$(reboot)"), 'b0undary');

    expect($script)->toContain(" | grep -F -i -e ''\\''; rm -rf / #\$(reboot)'")
        ->not->toContain("-e '; rm");
});

test('a static site has no PHP errors or processes to read, only its requests', function () {
    $script = app(AppLogs::class)->rootScript(appLogsApp(['type' => ApplicationType::Static, 'framework' => null, 'php_version' => null, 'processes' => []]), new LogQuery, 'b0undary');

    expect($script)->not->toContain('coresmith-app-shop-php')
        ->not->toContain("echo '==b0undary== units'")
        ->toContain("echo '==b0undary== requests'");
});

test('the framework and scheduler logs are read as the app\'s own user, never as root', function () {
    $app = appLogsApp();
    $script = (string) app(AppLogs::class)->appScript($app, new LogQuery, 'b0undary');

    expect($script)->toContain('systemd-run')
        ->toContain('--uid=' . $app->system_user)
        ->toContain('storage/logs/laravel*.log')
        ->toContain('logs/scheduler.log');
});

test('an app that was never deployed has no files of its own to read', function () {
    expect(app(AppLogs::class)->appScript(appLogsApp(['current_release' => null]), new LogQuery, 'b0undary'))->toBeNull();
});

test('a read puts every source in time order, drops what is outside the window, and measures the requests', function () {
    $now = now()->getTimestamp();
    $root = implode("\n", [
        '==BOUNDARY== meta', (string) $now, '+0000',
        '==BOUNDARY== requests', '0 0',
        appLogsAccess($now - 30, '/checkout'),
        appLogsAccess($now - 7200, '/too-old'),
        appLogsAccess($now - 10, '/api/pay', 500),
        '==BOUNDARY== process worker', '120 ' . $now,
        now()->subSeconds(20)->format('Y-m-d H:i:s') . ' Processing SendReceipt',
        '',
    ]);
    $own = implode("\n", [
        '==BOUNDARY== scheduler',
        '==BOUNDARY== framework', '200 ' . $now,
        '[' . now()->subSeconds(5)->format('Y-m-d H:i:s') . '] production.ERROR: Payment declined',
        '',
    ]);

    [$read] = appLogsRead(appLogsApp(), new LogQuery('1h'), $root, $own);

    expect(array_column($read['entries'], 'text'))->toBe(['GET /checkout 200 50ms', 'Processing SendReceipt', 'GET /api/pay 500 50ms', 'production.ERROR: Payment declined'])
        ->and($read['stats']['requests'])->toBe(2)
        ->and($read['stats']['server_errors'])->toBe(1)
        ->and($read['capped'])->toBeFalse();
});
