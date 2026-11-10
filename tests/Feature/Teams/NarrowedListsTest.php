<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\CommandPalette;
use App\Services\Panel\FleetSnapshot;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, web: Server, db: Server, shop: Application, api: Application, member: User}
 */
function narrowedListsSetup(): array
{
    $team = Team::factory()->create(['name' => 'Acme']);
    $web = Server::factory()->for($team)->create(['name' => 'web', 'status' => ServerStatus::Active]);
    $db = Server::factory()->for($team)->create(['name' => 'db', 'status' => ServerStatus::Active]);
    $shop = Application::factory()->for($team)->for($web)->create(['name' => 'shop']);
    $api = Application::factory()->for($team)->for($db)->create(['name' => 'api']);
    Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20261110120000', 'finished_at' => now()->subHour()]);
    Deployment::factory()->for($api)->create(['status' => DeploymentStatus::Failed, 'finished_at' => now()->subMinutes(5)]);
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value, 'sees_everything' => false]);
    $member->forceFill(['current_team_id' => $team->id])->save();

    return ['team' => $team, 'web' => $web, 'db' => $db, 'shop' => $shop, 'api' => $api, 'member' => $member->fresh()];
}

test('the fleet, its counts and the attention count show only what a narrowed member sees', function () {
    ['team' => $team, 'web' => $web, 'member' => $member] = narrowedListsSetup();
    app(TeamAccess::class)->grant($team, $member, $web);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('counts', ['servers' => 1, 'applications' => 1])
            ->where('servers', fn($servers) => collect($servers)->pluck('name')->all() === ['web'])
            ->where('shared', [])
            ->where('narrowed', true)
            ->where('panel.attention', 0));
});

test('search finds only shared servers and apps', function () {
    ['team' => $team, 'web' => $web, 'member' => $member] = narrowedListsSetup();
    app(TeamAccess::class)->grant($team, $member, $web);

    $labels = collect((new CommandPalette($member, $team))->commands())->pluck('label');

    expect($labels)->toContain('Open web')
        ->and($labels)->toContain('Open shop')
        ->and($labels)->not->toContain('Open db')
        ->and($labels)->not->toContain('Open api');
});

test('deployments and releases list only shared apps', function () {
    ['team' => $team, 'web' => $web, 'shop' => $shop, 'member' => $member] = narrowedListsSetup();
    app(TeamAccess::class)->grant($team, $member, $web);

    $this->actingAs($member)
        ->get(route('deployments.index'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('deployments', fn($rows) => collect($rows)->pluck('application')->unique()->values()->all() === ['shop']));

    $this->actingAs($member)
        ->get(route('servers.applications.releases.index', [$web, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('applications', fn($apps) => collect($apps)->pluck('name')->all() === ['shop']));
});

test('the activity feed leaves out unshared servers and apps', function () {
    ['team' => $team, 'web' => $web, 'db' => $db, 'member' => $member] = narrowedListsSetup();
    app(TeamAccess::class)->grant($team, $member, $web);
    activity()->performedOn($web)->log('server_renamed');
    activity()->performedOn($db)->log('server_renamed');

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('activity', fn($items) => collect($items)->contains(fn($item) => str_contains(json_encode($item), 'web'))
            && !collect($items)->contains(fn($item) => str_contains(json_encode($item), '"db"'))));
});

test('an app shared on its own is listed on the fleet and names its server without a link', function () {
    ['team' => $team, 'db' => $db, 'api' => $api, 'member' => $member] = narrowedListsSetup();
    app(TeamAccess::class)->grant($team, $member, $api);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('servers', [])
            ->where('shared', [['id' => $api->id, 'name' => 'api', 'server' => 'db', 'href' => route('servers.applications.show', [$db, $api])]])
            ->where('counts.applications', 1));

    $this->actingAs($member)
        ->get(route('servers.applications.show', [$db, $api]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('server.name', 'db')->where('server.href', null));
});

test('a stopped app shared on its own still needs their attention', function () {
    ['team' => $team, 'db' => $db, 'api' => $api, 'member' => $member] = narrowedListsSetup();
    $api->forceFill(['power_state' => PowerState::Stopped])->save();
    app(TeamAccess::class)->grant($team, $member, $api);

    expect(collect((new FleetSnapshot($team, $member))->attention())->where('summary', 'stopped')->values()->all())
        ->toBe([['severity' => 'warning', 'subject' => 'api', 'summary' => 'stopped', 'href' => route('servers.applications.show', [$db, $api])]]);
});

test('a narrowed member with nothing shared gets the empty fleet that says so', function () {
    ['member' => $member] = narrowedListsSetup();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('servers', [])->where('shared', [])->where('narrowed', true));
});
