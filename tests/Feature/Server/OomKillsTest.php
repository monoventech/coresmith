<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\Server;
use App\Services\Server\MetricsCollector;
use App\Services\Server\OomKillReader;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function sinceTimestamp(string $command): int
{
    preg_match("/--since '@(\d+)'/", $command, $matches);

    return (int) ($matches[1] ?? 0);
}

function oomLine(string $unit, string $message, int $secondsAgo = 30): string
{
    return json_encode([
        'UNIT' => $unit,
        'MESSAGE' => $message,
        '__REALTIME_TIMESTAMP' => (string) (now()->subSeconds($secondsAgo)->getTimestamp() * 1000000),
    ], JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00:00'));
});

test('journal lines about coresmith units killed for memory are read, anything else is ignored', function () {
    $output = implode("\n", [
        oomLine('coresmith-app-shop-queue-worker.service', 'coresmith-app-shop-queue-worker.service: A process of this unit has been killed by the OOM killer.'),
        oomLine('nginx.service', 'nginx.service: A process of this unit has been killed by the OOM killer.'),
        oomLine('coresmith-app-shop-web.service', 'coresmith-app-shop-web.service: Scheduled restart job, restart counter is at 2.'),
        '{not json',
    ]);

    $kills = (new OomKillReader)->parse($output);

    expect($kills)->toHaveCount(1)
        ->and($kills[0]['unit'])->toBe('coresmith-app-shop-queue-worker')
        ->and($kills[0]['at']->toIso8601String())->toBe('2026-09-14T09:59:30+00:00');
});

test('a kill is logged once against the app it belongs to, with its process and memory limit', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    $shop = Application::factory()->for($server)->create(['slug' => 'shop', 'app_size' => 'medium']);
    Application::factory()->for($server)->create(['slug' => 'shop-admin']);
    $line = oomLine('coresmith-app-shop-admin-web.service', 'coresmith-app-shop-admin-web.service: A process of this unit has been killed by the OOM killer.')
        . "\n" . oomLine('coresmith-app-shop-web.service', 'coresmith-app-shop-web.service: A process of this unit has been killed by the OOM killer.', 50);
    $transport = (new FakeSSHTransport)->respondTo('journalctl --since', $line);
    $connection = (new SSHConnection($server, $transport))->connect();

    app(MetricsCollector::class)->recordOomKills($connection);
    app(MetricsCollector::class)->recordOomKills($connection);

    $kills = Activity::query()->where('description', 'app_oom_killed')->get();

    expect($kills)->toHaveCount(2)
        ->and($kills->firstWhere('subject_id', $shop->id)->properties->toArray())->toBe(['process' => 'web', 'at' => '2026-09-14T09:59:10+00:00', 'limit_mb' => 512])
        ->and($kills->where('subject_id', '!=', $shop->id)->first()->properties['process'])->toBe('web');
});

test('the journal is read from where the last pass stopped', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;
    $connection = (new SSHConnection($server, $transport))->connect();

    app(MetricsCollector::class)->recordOomKills($connection);
    $this->travel(1)->minutes();
    app(MetricsCollector::class)->recordOomKills($connection);

    $sinces = array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'journalctl --since')));

    expect($sinces[0])->toContain("--since '@" . CarbonImmutable::parse('2026-09-14 09:58:00')->getTimestamp() . "'")
        ->and(sinceTimestamp($sinces[1]))->toBeLessThanOrEqual(CarbonImmutable::parse('2026-09-14 10:00:00')->getTimestamp());
});

test('a cursor in the future still reads the last lookback window', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;
    $connection = (new SSHConnection($server, $transport))->connect();

    Cache::put('oom-since:' . $server->getKey(), now()->addHour()->getTimestamp(), now()->addDay());

    app(MetricsCollector::class)->recordOomKills($connection);

    $sinces = array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'journalctl --since')));

    expect(sinceTimestamp($sinces[0]))->toBe(now()->subSeconds(OomKillReader::LOOKBACK_SECONDS)->getTimestamp());
});

test('a pass further apart than the lookback still reads from the cursor, not just the last two minutes', function () {
    $server = Server::factory()->create(['status' => ServerStatus::Active, 'host_key_fingerprint' => null]);
    $transport = new FakeSSHTransport;
    $connection = (new SSHConnection($server, $transport))->connect();

    app(MetricsCollector::class)->recordOomKills($connection);
    $stopped = now()->getTimestamp();

    $this->travel(5)->minutes();
    app(MetricsCollector::class)->recordOomKills($connection);

    $sinces = array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'journalctl --since')));

    expect(sinceTimestamp($sinces[1]))->toBe($stopped)
        ->and(sinceTimestamp($sinces[1]))->toBeLessThan(now()->subSeconds(OomKillReader::LOOKBACK_SECONDS)->getTimestamp());
});
