<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Panel\FleetSnapshot;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/**
 * @param  array<string, mixed>  $values
 */
function fleetChartsReadings(Server $server, CarbonImmutable $from, int $minutes, array $values = []): void
{
    foreach (range(0, $minutes - 1) as $minute) {
        ServerMetric::factory()->for($server)->create(['recorded_at' => $from->addMinutes($minute), ...$values]);
    }
}

test('deploys per day counts the last seven days, today last', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 15:00:00'));
    $team = Team::factory()->owned()->create();
    $app = Application::factory()->for($team)->for(Server::factory()->for($team))->create();
    $deploy = fn(string $at, DeploymentStatus $status = DeploymentStatus::Succeeded) => Deployment::factory()->for($app, 'application')->create(['status' => $status, 'created_at' => $at]);
    $deploy('2026-11-12 09:00:00');
    $deploy('2026-11-12 14:00:00');
    $deploy('2026-11-09 12:00:00');
    $deploy('2026-11-05 23:00:00');
    $deploy('2026-11-12 10:00:00', DeploymentStatus::Cancelled);

    expect((new FleetSnapshot($team))->deploysPerDay())->toBe([0, 0, 0, 1, 0, 0, 2]);
});

test('uptime by day is one entry per day, empty before the first reading', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 00:30:00'));
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create();
    fleetChartsReadings($server, CarbonImmutable::parse('2026-11-11 23:00:00'), 30);
    fleetChartsReadings($server, CarbonImmutable::parse('2026-11-12 00:00:00'), 30);

    $days = (new FleetSnapshot($team))->uptimeByDay();

    expect($days)->toHaveCount(FleetSnapshot::UPTIME_DAYS)
        ->and(array_slice($days, -2))->toBe([50.0, 100.0])
        ->and(array_values(array_unique(array_slice($days, 0, -2))))->toBe([null]);
});

test("a server's trend is its last hour in twelve points", function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-12 15:00:00'));
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create();
    fleetChartsReadings($server, CarbonImmutable::parse('2026-11-12 14:00:00'), 55, ['cpu_percent' => 50]);
    fleetChartsReadings($server, CarbonImmutable::parse('2026-11-12 14:55:00'), 5, ['cpu_percent' => 90]);
    fleetChartsReadings($server, CarbonImmutable::parse('2026-11-12 13:00:00'), 10, ['cpu_percent' => 5]);

    expect((new FleetSnapshot($team))->trend($server, 'cpu_percent'))->toBe([50, 50, 50, 50, 50, 50, 50, 50, 50, 50, 50, 90]);
});

test('server cards carry trends and app states', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create();
    $app = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);
    ServerMetric::factory()->for($server)->create(['recorded_at' => now()->subMinutes(2), 'cpu_percent' => 40, 'memory_percent' => 60]);

    $fleet = new FleetSnapshot($team);
    $card = $fleet->card($fleet->servers()->first());

    expect($card['cpu_trend'])->toBe([40])
        ->and($card['memory_trend'])->toBe([60])
        ->and($card['applications'][0])->toMatchArray(['name' => 'shop', 'href' => route('servers.applications.show', [$server, $app])])
        ->and($card['applications'][0]['state'])->toBe(ApplicationPresenter::state($app));
});

test('the fleet page draws deploys per day and uptime by day, and never shows an em dash', function () {
    $team = Team::factory()->owned()->create();
    Server::factory()->for($team)->create();

    $this->actingAs($team->owner)->get(route('dashboard'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('tiles.1.id', 'deploys')
            ->has('tiles.1.bars', 7)
            ->where('tiles.3.id', 'uptime')
            ->has('tiles.3.strip', FleetSnapshot::UPTIME_DAYS)
            ->where('tiles.3.value', 'No readings')
            ->where('tiles.3.sub', 'collected every minute'));
});

test('the live deploy carries its phase states', function () {
    $team = Team::factory()->owned()->create();
    $app = Application::factory()->for($team)->for(Server::factory()->for($team))->create();
    Deployment::factory()->for($app, 'application')->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinute(),
        'log' => "[10:00:00] clone: cloning the release\n",
    ]);

    $this->actingAs($team->owner)->get(route('dashboard'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('live.states', ['running', 'pending', 'pending', 'pending', 'pending', 'pending']));
});
