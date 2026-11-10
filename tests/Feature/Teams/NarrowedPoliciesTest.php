<?php

declare(strict_types=1);

use App\Broadcasting\DeploymentChannel;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;

/**
 * @return array{team: Team, web: Server, db: Server, shop: Application, api: Application, deployment: Deployment, member: User}
 */
function narrowedPoliciesSetup(bool $everything = false, TeamRole $role = TeamRole::Member): array
{
    $team = Team::factory()->create();
    $web = Server::factory()->for($team)->create(['name' => 'web', 'status' => ServerStatus::Active]);
    $db = Server::factory()->for($team)->create(['name' => 'db', 'status' => ServerStatus::Active]);
    $shop = Application::factory()->for($team)->for($web)->create(['name' => 'shop']);
    $api = Application::factory()->for($team)->for($db)->create(['name' => 'api']);
    $deployment = Deployment::factory()->for($api)->create();
    ManagedDatabase::factory()->for($team)->for($db)->create();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => $role->value, 'sees_everything' => $everything]);

    return ['team' => $team, 'web' => $web, 'db' => $db, 'shop' => $shop, 'api' => $api, 'deployment' => $deployment, 'member' => $member->fresh()];
}

test('a narrowed member gets 404 for an unshared server, its tabs, its databases, its apps and their deployments', function () {
    ['db' => $db, 'api' => $api, 'deployment' => $deployment, 'member' => $member] = narrowedPoliciesSetup();

    $this->actingAs($member);

    $this->get(route('servers.show', $db))->assertNotFound();
    $this->get(route('servers.hardening', $db))->assertNotFound();
    $this->get(route('servers.databases.index', $db))->assertNotFound();
    $this->get(route('servers.applications.show', [$db, $api]))->assertNotFound();
    $this->get(route('servers.applications.deployments.show', [$db, $api, $deployment]))->assertNotFound();
});

test('a narrowed member reaches a shared server and every app on it', function () {
    ['team' => $team, 'web' => $web, 'shop' => $shop, 'member' => $member] = narrowedPoliciesSetup();
    app(TeamAccess::class)->grant($team, $member, $web);

    $this->actingAs($member);

    $this->get(route('servers.show', $web))->assertOk();
    $this->get(route('servers.applications.show', [$web, $shop]))->assertOk();
});

test('an app shared on its own opens with its deployments, while its server still answers 404', function () {
    ['team' => $team, 'db' => $db, 'api' => $api, 'deployment' => $deployment, 'member' => $member] = narrowedPoliciesSetup();
    app(TeamAccess::class)->grant($team, $member, $api);

    $this->actingAs($member);

    $this->get(route('servers.applications.show', [$db, $api]))->assertOk();
    $this->get(route('servers.applications.deployments.show', [$db, $api, $deployment]))->assertOk();
    $this->get(route('servers.show', $db))->assertNotFound();
    $this->get(route('servers.databases.index', $db))->assertNotFound();
});

test('a narrowed admin cannot add an app or a database to a server not shared with them', function () {
    ['db' => $db, 'member' => $admin] = narrowedPoliciesSetup(role: TeamRole::Admin);

    $this->actingAs($admin);

    $this->post(route('servers.applications.store', $db), ['name' => 'sneaky'])->assertForbidden();
    $this->post(route('servers.databases.store', $db), ['name' => 'sneaky'])->assertForbidden();

    expect(Application::query()->where('server_id', $db->id)->count())->toBe(1)
        ->and(ManagedDatabase::query()->where('server_id', $db->id)->count())->toBe(1);
});

test('members who see everything, and owners, are unaffected', function (bool $everything, TeamRole $role) {
    ['db' => $db, 'api' => $api, 'member' => $member] = narrowedPoliciesSetup($everything, $role);

    $this->actingAs($member);

    $this->get(route('servers.show', $db))->assertOk();
    $this->get(route('servers.applications.show', [$db, $api]))->assertOk();
})->with([
    'a deployer who sees everything' => [true, TeamRole::Member],
    'an owner, whatever the flag says' => [false, TeamRole::Owner],
]);

test('the deployment channel refuses a narrowed member for an app not shared with them', function () {
    ['team' => $team, 'web' => $web, 'deployment' => $deployment, 'member' => $member] = narrowedPoliciesSetup();
    app(TeamAccess::class)->grant($team, $member, $web);

    expect((new DeploymentChannel)->join($member, (string) $deployment->id))->toBeFalse();
});
