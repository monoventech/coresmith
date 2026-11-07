<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Server;
use App\Services\Server\AccessLogReader;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function accessLogLine(array $overrides = []): string
{
    $entry = array_replace_recursive([
        'ts' => 1789308309.482,
        'request' => ['host' => 'shop.example.com', 'method' => 'GET', 'uri' => '/'],
        'status' => 200,
        'duration' => 0.0123,
    ], $overrides);

    return json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function accessLogParse(array $lines, array $domains = ['shop.example.com']): array
{
    return (new AccessLogReader)->parse(implode("\n", $lines), $domains);
}

test('a caddy access log entry is summarised as method, path, status and time taken', function () {
    $parsed = accessLogParse([accessLogLine([
        'request' => ['method' => 'POST', 'uri' => '/checkout'],
        'status' => 302,
        'duration' => 0.0427,
    ])]);

    expect($parsed)->toBe([['time' => '14:05:09', 'text' => 'POST /checkout 302 43ms']]);
});

test('requests for other sites on the same server are left out', function () {
    $parsed = accessLogParse([
        accessLogLine(['request' => ['host' => 'other.example.com', 'uri' => '/theirs']]),
        accessLogLine(['request' => ['uri' => '/ours']]),
    ]);

    expect(array_column($parsed, 'text'))->toBe(['GET /ours 200 12ms']);
});

test('the host is matched regardless of case and every domain of the application counts', function () {
    $parsed = accessLogParse([
        accessLogLine(['request' => ['host' => 'Shop.Example.COM', 'uri' => '/a']]),
        accessLogLine(['request' => ['host' => 'www.shop.example.com', 'uri' => '/b']]),
    ], ['shop.example.com', 'www.shop.example.com']);

    expect(array_column($parsed, 'text'))->toBe(['GET /a 200 12ms', 'GET /b 200 12ms']);
});

test('an application without domains shows no requests', function () {
    expect(accessLogParse([accessLogLine()], []))->toBe([]);
});

test('lines that are not access log entries are skipped', function () {
    $parsed = accessLogParse([
        'not json at all',
        '',
        '{"level":"info","msg":"server running"}',
        '{"request":"GET /"}',
        accessLogLine(['request' => ['uri' => '/kept']]),
    ]);

    expect(array_column($parsed, 'text'))->toBe(['GET /kept 200 12ms']);
});

test('secret query values are masked before they reach the panel', function (string $uri, string $expected) {
    $parsed = accessLogParse([accessLogLine(['request' => ['uri' => $uri]])]);

    expect($parsed[0]['text'])->toBe("GET {$expected} 200 12ms");
})->with([
    'token' => ['/reset?token=abc123', '/reset?token=•••'],
    'key' => ['/feed?key=k-999&page=2', '/feed?key=•••&page=2'],
    'secret' => ['/hook?id=4&secret=s3cr3t', '/hook?id=4&secret=•••'],
    'signature' => ['/download?file=a.zip&signature=deadbeef&expires=1', '/download?file=a.zip&signature=•••&expires=1'],
    'password' => ['/login?user=ada&PASSWORD=hunter2', '/login?user=ada&PASSWORD=•••'],
    'several at once' => ['/x?token=a&key=b', '/x?token=•••&key=•••'],
    'a prefixed token' => ['/cb?access_token=ya29&state=1', '/cb?access_token=•••&state=1'],
    'an api key' => ['/v1?api_key=live_123', '/v1?api_key=•••'],
    'a client secret' => ['/oauth?client_id=9&client_secret=shh', '/oauth?client_id=9&client_secret=•••'],
    'a session id' => ['/app?sessionid=abc', '/app?sessionid=•••'],
    'ordinary parameters' => ['/search?q=shoes&page=2', '/search?q=shoes&page=2'],
]);

test('the method keeps only capital letters and falls back to GET', function (string $method, string $expected) {
    $parsed = accessLogParse([accessLogLine(['request' => ['method' => $method]])]);

    expect($parsed[0]['text'])->toStartWith($expected . ' /');
})->with([
    'lowercase' => ['get', 'GET'],
    'empty' => ['', 'GET'],
    'injected' => ["PO\nST<b>", 'POST'],
]);

test('control characters are stripped from the path and long paths are cut short', function () {
    $parsed = accessLogParse([
        accessLogLine(['request' => ['uri' => "/\x1b[31mred\x1b[0m"]]),
        accessLogLine(['request' => ['uri' => '/' . str_repeat('a', 300)]]),
    ]);

    expect($parsed[0]['text'])->toBe('GET /[31mred[0m 200 12ms')
        ->and($parsed[1]['text'])->toBe('GET /' . str_repeat('a', 119) . ' 200 12ms');
});

test('missing fields read as placeholders rather than failing', function () {
    $line = json_encode(['request' => ['host' => 'shop.example.com']], JSON_THROW_ON_ERROR);

    expect(accessLogParse([$line]))->toBe([['time' => '--:--:--', 'text' => 'GET / 0 0ms']]);
});

test('only the twenty most recent requests are kept, oldest first', function () {
    $lines = array_map(fn(int $i): string => accessLogLine(['request' => ['uri' => '/page-' . $i]]), range(1, 25));

    $parsed = accessLogParse($lines);

    expect($parsed)->toHaveCount(AccessLogReader::LINES)
        ->and($parsed[0]['text'])->toBe('GET /page-6 200 12ms')
        ->and($parsed[19]['text'])->toBe('GET /page-25 200 12ms');
});

test('recent requests are read from the tail of the caddy access log for the application domains', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $application = Application::factory()->for($server)->create(['domain' => 'shop.example.com', 'additional_domains' => ['www.shop.example.com']]);
    $transport = (new FakeSSHTransport)->respondTo('access.log', implode("\n", [
        accessLogLine(['request' => ['host' => 'www.shop.example.com', 'uri' => '/www']]),
        accessLogLine(['request' => ['host' => 'intruder.example.com', 'uri' => '/nope']]),
    ]));

    $lines = (new AccessLogReader)->recent((new SSHConnection($server, $transport))->connect(), $application);

    expect($transport->commands())->toBe(["test -f '/var/log/caddy/access.log' && tail -n 2000 '/var/log/caddy/access.log' || true"])
        ->and(array_column($lines, 'text'))->toBe(['GET /www 200 12ms']);
});
