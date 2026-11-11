<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;

function projectPolicyPerson(Team $team, TeamRole $role, bool $everything = false): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

/**
 * @return array{team: Team, server: Server, project: Project, staging: Environment, production: Environment}
 */
function projectPolicyFixture(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web']);
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    [$production, $staging] = $project->environments()->get()->all();

    return ['team' => $team, 'server' => $server, 'project' => $project, 'staging' => $staging, 'production' => $production];
}

test('project pages are 404 to members who see nothing of them', function () {
    ['team' => $team, 'project' => $project] = projectPolicyFixture();
    $member = projectPolicyPerson($team, TeamRole::Admin);

    expect(Gate::forUser($member)->inspect('view', $project)->status())->toBe(404)
        ->and(Gate::forUser($member)->inspect('manage', $project)->status())->toBe(404)
        ->and(Gate::forUser(User::factory()->create())->allows('view', $project))->toBeFalse();
});

test("an environment-only member can't rename or delete the project", function () {
    ['team' => $team, 'project' => $project, 'staging' => $staging, 'production' => $production] = projectPolicyFixture();
    $member = projectPolicyPerson($team, TeamRole::Admin);
    app(TeamAccess::class)->grant($team, $member, $staging);

    expect(Gate::forUser($member)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($member)->inspect('manage', $project)->status())->toBe(403)
        ->and(Gate::forUser($member)->allows('fill', $staging))->toBeTrue()
        ->and(Gate::forUser($member)->allows('fill', $production))->toBeFalse();
});

test('owners and admins who see a whole project manage it, deployers only look', function () {
    ['team' => $team, 'project' => $project, 'staging' => $staging] = projectPolicyFixture();
    $admin = projectPolicyPerson($team, TeamRole::Admin);
    $deployer = projectPolicyPerson($team, TeamRole::Member, everything: true);
    app(TeamAccess::class)->grant($team, $admin, $project);

    expect(Gate::forUser($admin)->allows('manage', $project))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', [Project::class, $team]))->toBeTrue()
        ->and(Gate::forUser($deployer)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($deployer)->allows('manage', $project))->toBeFalse()
        ->and(Gate::forUser($deployer)->allows('fill', $staging))->toBeFalse()
        ->and(Gate::forUser($deployer)->allows('create', [Project::class, $team]))->toBeFalse();
});

test('a database shared through its environment opens without its server', function () {
    ['team' => $team, 'server' => $server, 'staging' => $staging] = projectPolicyFixture();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'staging_db', 'environment_id' => $staging->id]);
    $hidden = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'other_db']);
    $member = projectPolicyPerson($team, TeamRole::Admin);
    app(TeamAccess::class)->grant($team, $member, $staging);

    $this->actingAs($member)->get(route('servers.databases.show', [$server, $database]))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('server.name', 'web')
            ->where('server.href', null));

    $this->actingAs($member)->get(route('servers.databases.show', [$server, $hidden]))->assertNotFound();
    $this->actingAs($member)->get(route('servers.databases.index', $server))->assertNotFound();
});

test('the restore dialog lists only apps the member can see', function () {
    ['team' => $team, 'server' => $server, 'staging' => $staging] = projectPolicyFixture();
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['type' => DatabaseType::Mysql, 'name' => 'staging_db', 'environment_id' => $staging->id]);
    Application::factory()->for($team)->for($server)->create(['name' => 'shop-staging', 'environment_id' => $staging->id]);
    Application::factory()->for($team)->for($server)->create(['name' => 'billing']);
    $member = projectPolicyPerson($team, TeamRole::Admin);
    app(TeamAccess::class)->grant($team, $member, $staging);

    $this->actingAs($member)->get(route('servers.databases.show', [$server, $database]))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('apps', fn($apps): bool => collect($apps)->pluck('name')->all() === ['shop-staging']));
});
