<?php

declare(strict_types=1);

use App\Services\Logs\LogEntryParser;
use App\Services\Logs\LogQuery;
use App\Services\Logs\ServerLogs;
use Carbon\CarbonImmutable;

function serverLogsRun(string $body): array
{
    $parser = app(LogEntryParser::class);

    return $parser->entries($parser->sections("==b0undary== meta\n1795500000\n+0000\n" . $body, 'b0undary'), null);
}

function serverLogsJournal(string $message, array $fields = []): string
{
    return json_encode(array_merge(['__REALTIME_TIMESTAMP' => '1795521601000000', 'PRIORITY' => '6', 'MESSAGE' => $message], $fields));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00'));
});

test('the server read covers every app\'s requests, the web server, sign-ins, blocked addresses, the apps and system warnings', function () {
    $script = app(ServerLogs::class)->script(new LogQuery('15m', "x'y"), 'b0undary');
    $since = now()->subMinutes(15)->getTimestamp();

    expect($script)->toContain("echo '==b0undary== requests'")
        ->not->toContain('"host":')
        ->toContain("journalctl -o json --no-pager -n 500 -u caddy --since=@{$since}")
        ->toContain('-u ssh -u sshd')
        ->toContain("'/var/log/fail2ban.log'")
        ->toContain("'--unit=coresmith-app-*'")
        ->toContain('-p warning')
        ->toContain(" | grep -F -i -e 'x'\\''y'");
});

test('on a server, a request names the host it was for', function () {
    $line = json_encode(['ts' => 1795521601.0, 'request' => ['host' => 'shop.example.com', 'method' => 'GET', 'uri' => '/cart'], 'status' => 200, 'duration' => 0.02], JSON_UNESCAPED_SLASHES);

    expect(serverLogsRun("==b0undary== requests\n0 0\n{$line}\n")[0]['text'])->toBe('GET shop.example.com/cart 200 20ms');
});

test('sign-ins, the web server and blocked addresses each keep their own source', function () {
    $entries = serverLogsRun(implode("\n", [
        '==b0undary== signins',
        serverLogsJournal('Accepted publickey for deploy from 198.51.100.7 port 51234 ssh2'),
        '==b0undary== web',
        serverLogsJournal('{"level":"error","msg":"could not get certificate from issuer"}', ['PRIORITY' => '3']),
        '==b0undary== firewall',
        '120 1795521700',
        '2026-11-24 12:00:05,123 fail2ban.actions [811]: NOTICE  [sshd] Ban 203.0.113.9',
        '',
    ]));

    expect(array_column($entries, 'source'))->toBe(['signins', 'web', 'firewall'])
        ->and($entries[1]['level'])->toBe('error')
        ->and($entries[2]['text'])->toBe('fail2ban.actions [811]: NOTICE  [sshd] Ban 203.0.113.9');
});

test('app events name the app and process they came from', function () {
    $entries = serverLogsRun(implode("\n", [
        '==b0undary== apps',
        serverLogsJournal('coresmith-app-shop-worker.service: Main process exited, code=exited, status=1/FAILURE', ['_PID' => '1', 'UNIT' => 'coresmith-app-shop-worker.service']),
        serverLogsJournal('PHP Warning:  Undefined variable $total in /var/www/cart.php on line 12', ['SYSLOG_IDENTIFIER' => 'coresmith-app-shop-php', '_SYSTEMD_UNIT' => 'coresmith-app-shop-fpm.service', 'PRIORITY' => '4']),
        '',
    ]));

    expect(array_column($entries, 'process'))->toBe(['shop-worker', 'shop-php'])
        ->and(array_column($entries, 'level'))->toBe(['error', 'warning']);
});

test('system warnings leave out what the other sources already show', function () {
    $entries = serverLogsRun(implode("\n", [
        '==b0undary== system',
        serverLogsJournal('Out of memory: Killed process 4242 (node)', ['PRIORITY' => '3', '_TRANSPORT' => 'kernel']),
        serverLogsJournal('error: could not load host key', ['_SYSTEMD_UNIT' => 'ssh.service', 'PRIORITY' => '3']),
        serverLogsJournal('coresmith-app-shop-web.service: Failed with result \'exit-code\'.', ['UNIT' => 'coresmith-app-shop-web.service', 'PRIORITY' => '4']),
        '',
    ]));

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->toMatchArray(['source' => 'system', 'level' => 'error', 'text' => 'Out of memory: Killed process 4242 (node)']);
});
