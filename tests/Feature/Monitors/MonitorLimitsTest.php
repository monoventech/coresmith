<?php

declare(strict_types=1);

use App\Enums\MonitorKind;
use App\Enums\Plan;
use App\Models\Monitor;
use App\Models\MonitorAlert;
use App\Models\Server;
use App\Models\ServerMonitor;
use App\Models\Team;
use App\Services\Monitors\MonitorLimits;
use Illuminate\Database\UniqueConstraintViolationException;

function monitorLimitsServer(?Team $team = null): Server
{
    return Server::factory()->for($team ?? Team::factory()->create())->create();
}

test('every monitor starts on its default limit', function (MonitorKind $kind, float $threshold, int $duration) {
    $limit = app(MonitorLimits::class)->for(monitorLimitsServer(), $kind);

    expect([$limit->threshold, $limit->duration, $limit->enabled, $limit->source])->toBe([$threshold, $duration, true, 'default']);
})->with([
    'disk, at once' => [MonitorKind::Disk, 80.0, 0],
    'memory, 4 minutes' => [MonitorKind::Memory, 95.0, 4],
    'processor, 10 minutes' => [MonitorKind::Cpu, 90.0, 10],
    'load per core, 15 minutes' => [MonitorKind::Load, 1.5, 15],
    'apps, two checks' => [MonitorKind::Apps, 500.0, 2],
]);

test('a team limit applies to every server, and a server limit wins over it', function () {
    $team = Team::factory()->create();
    $plain = monitorLimitsServer($team);
    $busy = monitorLimitsServer($team);
    Monitor::create(['team_id' => $team->id, 'kind' => MonitorKind::Cpu, 'threshold' => 80, 'duration' => 5, 'enabled' => true]);
    ServerMonitor::create(['team_id' => $team->id, 'server_id' => $busy->id, 'kind' => MonitorKind::Cpu, 'threshold' => 95, 'duration' => 15]);

    $limits = app(MonitorLimits::class);

    expect([$limits->for($plain, MonitorKind::Cpu)->threshold, $limits->for($plain, MonitorKind::Cpu)->source])->toBe([80.0, 'team'])
        ->and([$limits->for($busy, MonitorKind::Cpu)->threshold, $limits->for($busy, MonitorKind::Cpu)->duration, $limits->for($busy, MonitorKind::Cpu)->source])->toBe([95.0, 15, 'server'])
        ->and($limits->for($busy, MonitorKind::Memory)->source)->toBe('default');
});

test('without the plan feature the limits stay at their defaults, but a monitor can still be switched off', function () {
    config(['coresmith.edition' => 'cloud']);
    $team = Team::factory()->subscribed(Plan::Starter)->create();
    $server = monitorLimitsServer($team);
    Monitor::create(['team_id' => $team->id, 'kind' => MonitorKind::Cpu, 'threshold' => 50, 'duration' => 1, 'enabled' => false]);
    ServerMonitor::create(['team_id' => $team->id, 'server_id' => $server->id, 'kind' => MonitorKind::Memory, 'threshold' => 70, 'duration' => 1]);

    $limits = app(MonitorLimits::class);

    expect([$limits->for($server, MonitorKind::Cpu)->threshold, $limits->for($server, MonitorKind::Cpu)->duration, $limits->for($server, MonitorKind::Cpu)->enabled])->toBe([90.0, 10, false])
        ->and([$limits->for($server, MonitorKind::Memory)->threshold, $limits->for($server, MonitorKind::Memory)->source])->toBe([95.0, 'default']);
});

test('switching a monitor off for the team switches it off on servers with their own limits too', function () {
    $team = Team::factory()->create();
    $server = monitorLimitsServer($team);
    Monitor::create(['team_id' => $team->id, 'kind' => MonitorKind::Load, 'threshold' => 2, 'duration' => 5, 'enabled' => false]);
    ServerMonitor::create(['team_id' => $team->id, 'server_id' => $server->id, 'kind' => MonitorKind::Load, 'threshold' => 3, 'duration' => 30]);

    $limit = app(MonitorLimits::class)->for($server, MonitorKind::Load);

    expect([$limit->threshold, $limit->duration, $limit->enabled, $limit->source])->toBe([3.0, 30, false, 'server']);
});

test('a server and kind can have only one open alert', function () {
    $server = monitorLimitsServer();
    MonitorAlert::open($server, MonitorKind::Cpu, peak: 97);

    MonitorAlert::query()->create(['team_id' => $server->team_id, 'server_id' => $server->id, 'kind' => MonitorKind::Cpu, 'opened_at' => now(), 'open_key' => MonitorAlert::keyFor($server, MonitorKind::Cpu)]);
})->throws(UniqueConstraintViolationException::class);

test('a closed alert frees its server and kind for the next one', function () {
    $server = monitorLimitsServer();
    $first = MonitorAlert::open($server, MonitorKind::Cpu, peak: 97);
    $first->close();
    $second = MonitorAlert::open($server, MonitorKind::Cpu, peak: 93);

    expect($first->fresh()->closed_at)->not->toBeNull()
        ->and($first->fresh()->open_key)->toBeNull()
        ->and($second->closed_at)->toBeNull()
        ->and(MonitorAlert::query()->where('server_id', $server->id)->count())->toBe(2);
});
