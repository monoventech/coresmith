<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Logs\LogEntryParser;

function logParserApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'env_variables' => "STRIPE_SECRET=sk_live_abcdefghijklmnop\n",
    ], $attributes));
}

function logParserRun(string $body, ?Application $application = null, array $units = []): array
{
    $application ??= logParserApp();
    $parser = app(LogEntryParser::class);

    return $parser->entries($parser->sections("==b0undary== meta\n1795500000\n+0000\n" . $body, 'b0undary'), $application, $units);
}

function logParserAccess(array $overrides = []): string
{
    return json_encode(array_replace_recursive([
        'ts' => 1795499990.5,
        'request' => ['host' => 'shop.example.com', 'method' => 'GET', 'uri' => '/checkout', 'client_ip' => '203.0.113.42'],
        'status' => 200,
        'duration' => 0.084,
        'size' => 21100,
    ], $overrides), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

test('output is split into sections by the boundary, and anything before the first one is ignored', function () {
    $parser = app(LogEntryParser::class);

    expect($parser->sections("noise\n==b0undary== process web\none\ntwo\n==b0undary== php\n{}", 'b0undary'))->toBe([
        ['name' => 'process', 'arg' => 'web', 'lines' => ['one', 'two']],
        ['name' => 'php', 'arg' => '', 'lines' => ['{}']],
    ]);
});

test('a request becomes an entry with its details, and only the app\'s own hosts count', function () {
    $entries = logParserRun("==b0undary== requests\n" . logParserAccess() . "\n" . logParserAccess(['request' => ['host' => 'other.example.com']]) . "\n");

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->toMatchArray(['source' => 'requests', 'level' => 'info', 'text' => 'GET /checkout 200 84ms', 'at' => 1795499990.5])
        ->and($entries[0]['request'])->toMatchArray(['ip' => '203.0.113.42', 'bytes' => 21100, 'status' => 200]);
});

test('a request that failed on the server is an error, and one the visitor got wrong is a warning', function () {
    $entries = logParserRun("==b0undary== requests\n" . logParserAccess(['status' => 502]) . "\n" . logParserAccess(['status' => 404, 'ts' => 1795499991]) . "\n");

    expect(array_column($entries, 'level'))->toBe(['error', 'warning']);
});

test('process output takes its time from the line, in the forms apps commonly print', function (string $line, float $at) {
    $entries = logParserRun("==b0undary== process web\n100 1795499999\n{$line}\n");

    expect($entries[0]['at'])->toBe($at)
        ->and($entries[0]['source'])->toBe('process:web')
        ->and($entries[0]['process'])->toBe('web');
})->with([
    'ISO with a zone' => ['2026-11-24T12:00:01.250Z Listening on :3000', 1795521601.25],
    'ISO in the server\'s zone' => ['2026-11-24 12:00:01 Processing SendReceipt', 1795521601.0],
    'Rails' => ['I, [2026-11-24T12:00:01.500000 #1234]  INFO -- : Started GET "/"', 1795521601.5],
    'gunicorn with an offset' => ['[2026-11-24 13:00:01 +0100] [12] [INFO] Booting worker', 1795521601.0],
    'Go' => ['2026/11/24 12:00:01 serving on :8080', 1795521601.0],
    'common log' => ['127.0.0.1 - - [24/Nov/2026:12:00:01 +0000] "GET / HTTP/1.1" 200', 1795521601.0],
]);

test('a stack trace is kept with the line it belongs to, and makes it an error', function () {
    $entries = logParserRun(implode("\n", [
        '==b0undary== process worker',
        '400 1795499999',
        '2026-11-24 12:00:01 Processing SyncInventory',
        'Something went sideways',
        '    at App\\Jobs\\SyncInventory->handle() (SyncInventory.php:42)',
        '#0 /var/www/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(120): handle()',
        'Caused by: RuntimeException: The warehouse did not answer',
        '',
    ]));

    expect($entries)->toHaveCount(2)
        ->and($entries[1]['text'])->toBe('Something went sideways')
        ->and($entries[1]['more'])->toHaveCount(3)
        ->and($entries[1]['level'])->toBe('error')
        ->and($entries[1]['at'])->toBeNull()
        ->and($entries[1]['sort'])->toBe(1795521601.0);
});

test('output with no times of its own has none, and sorts at the time its file last changed', function () {
    $entries = logParserRun("==b0undary== process web\n50 1795499999\nOrder 1042 created\nOrder 1043 created\n");

    expect(array_column($entries, 'at'))->toBe([null, null])
        ->and(array_column($entries, 'sort'))->toBe([1795499999.0, 1795499999.0]);
});

test('a missing file gives no entries', function () {
    expect(logParserRun("==b0undary== process web\n==b0undary== scheduler\n"))->toBe([]);
});

test('each Laravel log entry keeps its stack trace, and its level comes from the channel', function () {
    $entries = logParserRun(implode("\n", [
        '==b0undary== framework',
        '900 1795499999',
        '[2026-11-24 12:00:01] production.ERROR: SQLSTATE[23000]: Integrity constraint violation {"exception":"[object] (PDOException(code: 23000))"}',
        '[stacktrace]',
        '#0 /var/www/app/Payments/Charge.php(88): PDO->execute()',
        '"}',
        '[2026-11-24 12:00:02] production.WARNING: Cache miss for pricing',
        '',
    ]));

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->toMatchArray(['source' => 'framework', 'level' => 'error'])
        ->and($entries[0]['more'])->toBe(['[stacktrace]', '#0 /var/www/app/Payments/Charge.php(88): PDO->execute()', '"}'])
        ->and($entries[1]['level'])->toBe('warning');
});

test('PHP errors come from the journal with their priority, even when the message is not plain text', function () {
    $entries = logParserRun(implode("\n", [
        '==b0undary== php',
        json_encode(['__REALTIME_TIMESTAMP' => '1795521601000000', 'PRIORITY' => '3', 'MESSAGE' => 'PHP Fatal error:  Uncaught Error: Call to undefined function in /var/www/app.php:12']),
        json_encode(['__REALTIME_TIMESTAMP' => '1795521602000000', 'PRIORITY' => '6', 'MESSAGE' => array_map('ord', str_split("pool shop: caf\xE9 child 12 started"))]),
        '',
    ]));

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->toMatchArray(['source' => 'php', 'level' => 'error', 'at' => 1795521601.0])
        ->and($entries[1]['text'])->toBe('pool shop: caf? child 12 started')
        ->and($entries[1]['level'])->toBe('info');
});

test('a process\'s restarts and out-of-memory kills belong to that process', function () {
    $units = ['coresmith-app-shop-worker.service' => ['process:worker', 'worker']];
    $entries = logParserRun(implode("\n", [
        '==b0undary== units',
        json_encode(['__REALTIME_TIMESTAMP' => '1795521601000000', 'PRIORITY' => '6', 'UNIT' => 'coresmith-app-shop-worker.service', 'MESSAGE' => 'coresmith-app-shop-worker.service: A process of this unit has been killed by the OOM killer.']),
        json_encode(['__REALTIME_TIMESTAMP' => '1795521602000000', 'PRIORITY' => '6', 'UNIT' => 'coresmith-app-shop-worker.service', 'MESSAGE' => 'coresmith-app-shop-worker.service: Scheduled restart job, restart counter is at 3.']),
        json_encode(['__REALTIME_TIMESTAMP' => '1795521603000000', 'PRIORITY' => '6', 'UNIT' => 'coresmith-app-shop-worker.service', 'MESSAGE' => 'Started coresmith-app-shop-worker.service - Coresmith: shop - worker.']),
        '',
    ]), units: $units);

    expect(array_column($entries, 'source'))->toBe(['process:worker', 'process:worker', 'process:worker'])
        ->and(array_column($entries, 'level'))->toBe(['error', 'warning', 'info'])
        ->and($entries[0]['text'])->toBe('A process of this unit has been killed by the OOM killer.');
});

test('secrets are masked and terminal codes are stripped before a line reaches the page', function () {
    $entries = logParserRun("==b0undary== process web\n80 1795499999\n\e[31mcharging with sk_live_abcdefghijklmnop\e[0m\n");

    expect($entries[0]['text'])->toBe('charging with [REDACTED:STRIPE_SECRET]');
});

test('the same line twice in the same second still gets two ids', function () {
    $entries = logParserRun("==b0undary== process web\n80 1795499999\n2026-11-24 12:00:01 tick\n2026-11-24 12:00:01 tick\n");

    expect($entries[0]['id'])->not->toBe($entries[1]['id']);
});

test('a stack trace keeps at most 30 lines of 500 characters, so one entry cannot swamp a read', function () {
    $trace = array_map(fn(int $frame): string => "#{$frame} " . str_repeat('x', 900), range(0, 59));
    $entries = logParserRun("==b0undary== process worker\n100 1795499999\n2026-11-24 12:00:01 Processing SyncInventory\n" . implode("\n", $trace) . "\n");

    expect($entries[0]['more'])->toHaveCount(LogEntryParser::MORE_LINES)
        ->and(mb_strlen($entries[0]['more'][0]))->toBe(LogEntryParser::MORE_LENGTH);
});
