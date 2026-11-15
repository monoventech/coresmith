<?php

declare(strict_types=1);

use App\Enums\MonitorKind;
use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\ServerMonitor;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;

function monitorSettingsOwner(?Team $team = null): User
{
    $team ??= Team::factory()->owned()->create();
    $owner = User::query()->findOrFail($team->owner_id);
    $owner->forceFill(['current_team_id' => $team->getKey()])->save();

    return $owner->fresh();
}

function monitorSettingsMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create(['current_team_id' => $team->getKey()]);
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function monitorSettingsProps(User $user): array
{
    $props = [];

    test()->actingAs($user)->get(route('settings.notifications'))->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
        $props = $page->toArray()['props']['monitors'];
    });

    return $props;
}

test('the notifications page lists every monitor with its rule and its choices', function () {
    $owner = monitorSettingsOwner();
    $monitors = monitorSettingsProps($owner);

    expect($monitors['canChange'])->toBeTrue()
        ->and($monitors['plan'])->toBeTrue()
        ->and(array_column($monitors['monitors'], 'name'))->toBe(['Disk space', 'Memory', 'Processor', 'Load', 'Apps answering'])
        ->and(array_column($monitors['monitors'], 'rule'))->toBe([
            'Above 80% used, as soon as a reading shows it, and again at 95%, where deploys stop',
            'Above 95% used for 4 minutes',
            'Above 90% busy for 10 minutes',
            'Above 1.5 per core for 15 minutes',
            "An app's domain doesn't answer, or answers with a server error, two checks in a row",
        ])
        ->and(array_column($monitors['monitors'], 'enabled'))->toBe([true, true, true, true, true])
        ->and(array_column($monitors['monitors'][0]['levels'], 'label'))->toBe(['70%', '75%', '80%', '85%', '90%'])
        ->and(array_column($monitors['monitors'][0]['durations'], 'label'))->toBe(['As soon as seen', 'For 5 minutes', 'For 15 minutes'])
        ->and(array_column($monitors['monitors'][3]['levels'], 'label'))->toBe(['1 per core', '1.5 per core', '2 per core'])
        ->and(array_column($monitors['monitors'][4]['levels'], 'label'))->toBe(['Any error', 'Server errors'])
        ->and(array_column($monitors['monitors'][4]['durations'], 'label'))->toBe(['Two checks in a row', 'Three checks in a row'])
        ->and($monitors['overrides'])->toBe([])
        ->and($monitors['serverKinds'])->toBe(['disk', 'memory', 'cpu', 'load'])
        ->and($monitors['footnote'])->toBe('Owners and admins can change monitors. Everyone else sees them. A change applies from the next reading.');
});

test('a new limit is saved for the team and shown as its rule', function () {
    $owner = monitorSettingsOwner();

    $this->actingAs($owner)->from(route('settings.notifications'))
        ->put(route('settings.monitors.update', ['kind' => 'cpu']), ['threshold' => 80, 'duration' => 5])
        ->assertRedirect(route('settings.notifications'))
        ->assertSessionHas('success', 'Saved.');

    $monitor = Monitor::query()->sole();

    expect([$monitor->kind, $monitor->threshold, $monitor->duration, $monitor->enabled])->toBe([MonitorKind::Cpu, 80.0, 5, true])
        ->and(monitorSettingsProps($owner)['monitors'][2]['rule'])->toBe('Above 80% busy for 5 minutes')
        ->and(Activity::query()->where('description', 'monitor_changed')->sole()->properties->toArray())->toBeInAnyKeyOrder(['kind' => 'cpu', 'enabled' => true, 'rule' => 'Above 80% busy for 5 minutes']);
});

test('switching a monitor off keeps its limit and says so on the page', function () {
    $owner = monitorSettingsOwner();
    Monitor::query()->create(['team_id' => $owner->current_team_id, 'kind' => MonitorKind::Load, 'threshold' => 2, 'duration' => 30, 'enabled' => true]);

    $this->actingAs($owner)->put(route('settings.monitors.update', ['kind' => 'load']), ['enabled' => false])->assertSessionHas('success', 'Saved.');

    $row = monitorSettingsProps($owner)['monitors'][3];

    expect([$row['enabled'], $row['threshold'], $row['duration'], $row['rule']])->toBe([false, 2, 30, 'Above 2 per core for 30 minutes']);
});

test('a limit outside the choices is refused', function (string $kind, array $input, string $field) {
    $owner = monitorSettingsOwner();

    $this->actingAs($owner)->put(route('settings.monitors.update', ['kind' => $kind]), $input)->assertSessionHasErrors($field);

    expect(Monitor::query()->exists())->toBeFalse();
})->with([
    'a disk line not offered' => ['disk', ['threshold' => 42], 'threshold'],
    'a processor duration not offered' => ['cpu', ['duration' => 7], 'duration'],
    'a load line from another monitor' => ['load', ['threshold' => 90], 'threshold'],
    'an apps line that is not a status' => ['apps', ['threshold' => 1.5], 'threshold'],
    'a switch that is not a yes or no' => ['memory', ['enabled' => 'sometimes'], 'enabled'],
]);

test('a monitor Coresmith does not have is not found', function () {
    $this->actingAs(monitorSettingsOwner())->put('/settings/monitors/temperature', ['enabled' => false])->assertNotFound();
});

test('owners and admins change monitors, everyone else only sees them', function (TeamRole $role, bool $allowed) {
    $team = Team::factory()->owned()->create();
    $user = monitorSettingsMember($team, $role);

    $response = $this->actingAs($user)->put(route('settings.monitors.update', ['kind' => 'memory']), ['enabled' => false]);

    $allowed ? $response->assertSessionHas('success', 'Saved.') : $response->assertForbidden();
    expect(Monitor::query()->exists())->toBe($allowed)
        ->and(monitorSettingsProps($user)['canChange'])->toBe($allowed);
})->with([
    'an admin' => [TeamRole::Admin, true],
    'a member' => [TeamRole::Member, false],
]);

test('on Starter a monitor can be switched off, but its limit stays put', function () {
    CloudBilling::enable();
    $team = Team::factory()->subscribed(Plan::Starter)->owned()->create();
    $owner = monitorSettingsOwner($team);

    $this->actingAs($owner)->put(route('settings.monitors.update', ['kind' => 'cpu']), ['enabled' => false])->assertSessionHas('success', 'Saved.');
    $this->actingAs($owner)->from(route('settings.notifications'))
        ->put(route('settings.monitors.update', ['kind' => 'cpu']), ['threshold' => 70, 'duration' => 5])
        ->assertRedirect(route('settings.notifications'))
        ->assertSessionHas('error', 'Changing monitor limits is part of Pro and Business. Change the plan in Billing settings.');

    $monitor = Monitor::query()->sole();
    $props = monitorSettingsProps($owner);

    expect([$monitor->threshold, $monitor->duration, $monitor->enabled])->toBe([90.0, 10, false])
        ->and($props['plan'])->toBeFalse()
        ->and($props['footnote'])->toBe('These defaults run on every plan. Changing them, and limits for one server, are part of Pro and Business.');
});

test('a team that moved down to Starter sees the default rules, even with limits saved from before', function () {
    CloudBilling::enable();
    $team = Team::factory()->subscribed(Plan::Starter)->owned()->create();
    Monitor::query()->create(['team_id' => $team->id, 'kind' => MonitorKind::Memory, 'threshold' => 85, 'duration' => 10, 'enabled' => true]);

    expect(monitorSettingsProps(monitorSettingsOwner($team))['monitors'][1]['rule'])->toBe('Above 95% used for 4 minutes');
});

test('a server gets its own limit, shown under the monitors, and loses it again', function () {
    $owner = monitorSettingsOwner();
    $server = Server::factory()->create(['team_id' => $owner->current_team_id, 'name' => 'forge-01']);

    $this->actingAs($owner)->put(route('settings.monitors.servers.update', ['kind' => 'cpu', 'server' => $server]), ['threshold' => 95, 'duration' => 30])
        ->assertSessionHas('success', 'Saved.');

    $override = monitorSettingsProps($owner)['overrides'];

    expect($override)->toBe([[
        'kind' => 'cpu',
        'server' => ['id' => $server->id, 'name' => 'forge-01'],
        'rule' => 'Processor above 95% busy for 30 minutes',
        'threshold' => 95,
        'duration' => 30,
    ]]);

    $this->actingAs($owner)->delete(route('settings.monitors.servers.destroy', ['kind' => 'cpu', 'server' => $server]))
        ->assertSessionHas('success', "Removed. forge-01 follows the team's limit again.");

    expect(ServerMonitor::query()->exists())->toBeFalse()
        ->and(Activity::query()->whereIn('description', ['monitor_server_changed', 'monitor_server_removed'])->pluck('description')->all())->toBe(['monitor_server_changed', 'monitor_server_removed']);
});

test('limits for one server need Pro or Business', function () {
    CloudBilling::enable();
    $team = Team::factory()->subscribed(Plan::Starter)->owned()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(monitorSettingsOwner($team))
        ->put(route('settings.monitors.servers.update', ['kind' => 'disk', 'server' => $server]), ['threshold' => 85, 'duration' => 0])
        ->assertSessionHas('error', 'Changing monitor limits is part of Pro and Business. Change the plan in Billing settings.');

    expect(ServerMonitor::query()->exists())->toBeFalse();
});

test("another team's server, and the apps monitor, can't have limits for one server", function (Closure $server, string $kind) {
    $owner = monitorSettingsOwner();
    $target = $server($owner);

    $this->actingAs($owner)->put(route('settings.monitors.servers.update', ['kind' => $kind, 'server' => $target]), ['threshold' => 500, 'duration' => 2])->assertNotFound();
    $this->actingAs($owner)->delete(route('settings.monitors.servers.destroy', ['kind' => $kind, 'server' => $target]))->assertNotFound();
})->with([
    "another team's server" => [fn(User $owner) => Server::factory()->create(), 'cpu'],
    'the apps monitor' => [fn(User $owner) => Server::factory()->create(['team_id' => $owner->current_team_id]), 'apps'],
]);

test('a member who sees only some servers sees only their names and their limits', function () {
    $team = Team::factory()->owned()->create();
    $seen = Server::factory()->for($team)->create(['name' => 'seen-1']);
    $hidden = Server::factory()->for($team)->create(['name' => 'hidden-1']);

    foreach ([$seen, $hidden] as $server) {
        ServerMonitor::query()->create(['team_id' => $team->id, 'server_id' => $server->id, 'kind' => MonitorKind::Cpu, 'threshold' => 95, 'duration' => 30]);
    }

    $member = monitorSettingsMember($team, TeamRole::Member);
    app(TeamAccess::class)->sync($team, $member, false, [$seen->id], []);

    $monitors = monitorSettingsProps($member);

    expect(array_column($monitors['servers'], 'name'))->toBe(['seen-1'])
        ->and(array_column(array_column($monitors['overrides'], 'server'), 'name'))->toBe(['seen-1']);
});

test('members cannot set or remove limits for one server', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create();
    ServerMonitor::query()->create(['team_id' => $team->id, 'server_id' => $server->id, 'kind' => MonitorKind::Disk, 'threshold' => 85, 'duration' => 0]);
    $member = monitorSettingsMember($team, TeamRole::Member);

    $this->actingAs($member)->put(route('settings.monitors.servers.update', ['kind' => 'disk', 'server' => $server]), ['threshold' => 90, 'duration' => 5])->assertForbidden();
    $this->actingAs($member)->delete(route('settings.monitors.servers.destroy', ['kind' => 'disk', 'server' => $server]))->assertForbidden();

    expect(ServerMonitor::query()->sole()->threshold)->toBe(85.0);
});
