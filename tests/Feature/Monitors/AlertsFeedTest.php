<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ActivityFeed;
use Inertia\Testing\AssertableInertia;

function alertsFeedServer(): Server
{
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'Shop', 'slug' => 'shop']);

    activity()->performedOn($server)->withProperties(['percent' => 93, 'minutes' => 10])->log('cpu_pressure');
    activity()->performedOn($server)->withProperties(['kind' => 'cpu', 'minutes' => 12])->log('monitor_recovered');
    activity()->performedOn($shop)->withProperties(['domain' => 'shop.example.com', 'status' => null, 'checks' => 2])->log('app_not_answering');
    activity()->performedOn($server)->withProperties(['action' => 'allow', 'port' => 9090])->log('firewall_rule_changed');
    Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::Succeeded, 'started_at' => now()->subMinute()]);

    return $server;
}

function alertsFeedSentences(array $rows): array
{
    return array_map(fn(array $row): string => implode('', array_column($row['parts'], 'text')), $rows);
}

test('a server feed narrowed to alerts keeps alerts and recoveries, and leaves out deploys and changes', function () {
    $server = alertsFeedServer();

    $everything = (new ActivityFeed)->forServer($server);
    $alerts = (new ActivityFeed)->forServer($server, alerts: true);

    expect($everything)->toHaveCount(5)
        ->and(alertsFeedSentences($alerts))->toEqualCanonicalizing([
            'Processor warning, web-1 at 93% for 10 minutes',
            'Processor back to normal on web-1 after 12 minutes',
            'Shop stopped answering at shop.example.com',
        ]);
});

test('the Activity tab narrows to alerts from its address, and shows everything otherwise', function (array $query, string $show, int $rows) {
    $server = alertsFeedServer();
    $owner = User::query()->findOrFail($server->team->owner_id);
    $owner->forceFill(['current_team_id' => $server->team_id])->save();

    $this->actingAs($owner->fresh())
        ->get(route('servers.activity', [$server, ...$query]))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('show', $show)->has('activity', $rows));
})->with([
    'everything' => [[], 'everything', 5],
    'alerts' => [['show' => 'alerts'], 'alerts', 3],
    'anything else' => [['show' => 'deploys'], 'everything', 5],
]);

test('every kind of alert and recovery is part of the alerts filter', function () {
    expect(ActivityFeed::ALERTS)->toContain('disk_pressure', 'memory_pressure', 'cpu_pressure', 'load_pressure', 'monitor_recovered', 'app_not_answering', 'app_answering', 'app_oom_killed', 'server_unreachable', 'server_reachable', 'certificate_expiring');
});
