<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{team: Team, owner: User, project: Project, production: Environment, staging: Environment, server: Server}
 */
function environmentContentsFixture(): array
{
    $team = Team::factory()->owned()->create();
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    [$production, $staging] = $project->environments()->get()->all();

    return [
        'team' => $team,
        'owner' => $team->owner->fresh(),
        'project' => $project,
        'production' => $production,
        'staging' => $staging,
        'server' => Server::factory()->for($team)->create(['name' => 'web']),
    ];
}

function environmentContentsPerson(Team $team, TeamRole $role, bool $everything = false): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

test('an owner files apps and databases into an environment, moving them from wherever they were', function () {
    ['team' => $team, 'owner' => $owner, 'project' => $project, 'production' => $production, 'staging' => $staging, 'server' => $server] = environmentContentsFixture();
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'environment_id' => $production->id]);
    $blog = Application::factory()->for($team)->for($server)->create(['name' => 'blog']);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_db']);

    $this->actingAs($owner)->post(route('projects.environments.contents.store', [$project, $staging]), [
        'applications' => [$shop->id, $blog->id],
        'databases' => [$database->id],
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Added to Staging.');

    expect($shop->fresh()->environment_id)->toBe($staging->id)
        ->and($blog->fresh()->environment_id)->toBe($staging->id)
        ->and($database->fresh()->environment_id)->toBe($staging->id)
        ->and(Activity::query()->where('description', 'application_filed')->where('subject_id', $blog->id)->sole()->properties->all())->toEqual(['project' => 'Storefront', 'environment' => 'Staging'])
        ->and(Activity::query()->where('description', 'database_filed')->where('subject_id', $database->id)->count())->toBe(1);
});

test("filing an app from another team or one the member can't see changes nothing", function () {
    ['team' => $team, 'project' => $project, 'staging' => $staging, 'server' => $server] = environmentContentsFixture();
    $admin = environmentContentsPerson($team, TeamRole::Admin);
    app(TeamAccess::class)->grant($team, $admin, $staging);
    $hidden = Application::factory()->for($team)->for($server)->create(['name' => 'billing']);
    $theirs = Application::factory()->create(['name' => 'theirs']);
    $hiddenDatabase = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'billing_db']);

    $this->actingAs($admin)->post(route('projects.environments.contents.store', [$project, $staging]), ['applications' => [$hidden->id, $theirs->id]])
        ->assertSessionHasErrors(['applications' => "One of those apps isn't one you can see. Reload the page and choose again."]);
    $this->actingAs($admin)->post(route('projects.environments.contents.store', [$project, $staging]), ['databases' => [$hiddenDatabase->id]])
        ->assertSessionHasErrors(['databases' => "One of those databases isn't one you can see. Reload the page and choose again."]);

    expect($hidden->fresh()->environment_id)->toBeNull()
        ->and($theirs->fresh()->environment_id)->toBeNull()
        ->and($hiddenDatabase->fresh()->environment_id)->toBeNull();
});

test('an environment-only admin files an app shared with them into their environment, and nowhere else', function () {
    ['team' => $team, 'project' => $project, 'production' => $production, 'staging' => $staging, 'server' => $server] = environmentContentsFixture();
    $admin = environmentContentsPerson($team, TeamRole::Admin);
    $shared = Application::factory()->for($team)->for($server)->create(['name' => 'shared']);
    app(TeamAccess::class)->grant($team, $admin, $staging);
    app(TeamAccess::class)->grant($team, $admin, $shared);

    $this->actingAs($admin)->post(route('projects.environments.contents.store', [$project, $production]), ['applications' => [$shared->id]])->assertNotFound();
    $this->actingAs($admin)->post(route('projects.environments.contents.store', [$project, $staging]), ['applications' => [$shared->id]])->assertSessionHasNoErrors();

    expect($shared->fresh()->environment_id)->toBe($staging->id);
});

test('taking an app or a database out leaves it in no project', function () {
    ['team' => $team, 'owner' => $owner, 'project' => $project, 'staging' => $staging, 'server' => $server] = environmentContentsFixture();
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'environment_id' => $staging->id]);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_db', 'environment_id' => $staging->id]);

    $this->actingAs($owner)->delete(route('projects.environments.applications.destroy', [$project, $staging, $shop]))
        ->assertSessionHas('success', 'Took shop out of Storefront.');
    $this->actingAs($owner)->delete(route('projects.environments.databases.destroy', [$project, $staging, $database]))
        ->assertSessionHas('success', 'Took shop_db out of Storefront.');

    expect($shop->fresh()->environment_id)->toBeNull()
        ->and($database->fresh()->environment_id)->toBeNull()
        ->and(Activity::query()->where('description', 'application_filed')->sole()->properties->all())->toEqual(['project' => null, 'environment' => null, 'from' => 'Storefront']);
});

test('an app in another environment is not found there', function () {
    ['team' => $team, 'owner' => $owner, 'project' => $project, 'production' => $production, 'staging' => $staging, 'server' => $server] = environmentContentsFixture();
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'environment_id' => $production->id]);

    $this->actingAs($owner)->delete(route('projects.environments.applications.destroy', [$project, $staging, $shop]))->assertNotFound();

    expect($shop->fresh()->environment_id)->toBe($production->id);
});

test('deployers cannot file or take out', function () {
    ['team' => $team, 'project' => $project, 'staging' => $staging, 'server' => $server] = environmentContentsFixture();
    $deployer = environmentContentsPerson($team, TeamRole::Member, everything: true);
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'environment_id' => $staging->id]);
    $blog = Application::factory()->for($team)->for($server)->create(['name' => 'blog']);

    $this->actingAs($deployer)->post(route('projects.environments.contents.store', [$project, $staging]), ['applications' => [$blog->id]])->assertForbidden();
    $this->actingAs($deployer)->delete(route('projects.environments.applications.destroy', [$project, $staging, $shop]))->assertForbidden();

    expect($blog->fresh()->environment_id)->toBeNull()
        ->and($shop->fresh()->environment_id)->toBe($staging->id);
});
