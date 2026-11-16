<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{team: Team, owner: User, project: Project, other: Project, server: Server}
 */
function projectContentsFixture(): array
{
    $team = Team::factory()->owned()->create();

    return [
        'team' => $team,
        'owner' => $team->owner->fresh(),
        'project' => Project::factory()->for($team)->create(['name' => 'Storefront']),
        'other' => Project::factory()->for($team)->create(['name' => 'Atlas']),
        'server' => Server::factory()->for($team)->create(['name' => 'web']),
    ];
}

function projectContentsPerson(Team $team, TeamRole $role, bool $everything = false): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

test('an owner adds apps and databases to a project, moving them from wherever they were', function () {
    ['team' => $team, 'owner' => $owner, 'project' => $project, 'other' => $other, 'server' => $server] = projectContentsFixture();
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'project_id' => $other->id]);
    $blog = Application::factory()->for($team)->for($server)->create(['name' => 'blog']);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_db']);

    $this->actingAs($owner)->post(route('projects.contents.store', $project), [
        'applications' => [$shop->id, $blog->id],
        'databases' => [$database->id],
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Added to Storefront.');

    expect($shop->fresh()->project_id)->toBe($project->id)
        ->and($blog->fresh()->project_id)->toBe($project->id)
        ->and($database->fresh()->project_id)->toBe($project->id)
        ->and(Activity::query()->where('description', 'application_filed')->where('subject_id', $blog->id)->sole()->properties->all())->toEqual(['project' => 'Storefront'])
        ->and(Activity::query()->where('description', 'database_filed')->where('subject_id', $database->id)->count())->toBe(1);
});

test("adding an app from another team or one the member can't see changes nothing", function () {
    ['team' => $team, 'project' => $project, 'server' => $server] = projectContentsFixture();
    $admin = projectContentsPerson($team, TeamRole::Admin);
    app(TeamAccess::class)->grant($team, $admin, $project);
    $hidden = Application::factory()->for($team)->for($server)->create(['name' => 'billing']);
    $theirs = Application::factory()->create(['name' => 'theirs']);
    $hiddenDatabase = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'billing_db']);

    $this->actingAs($admin)->post(route('projects.contents.store', $project), ['applications' => [$hidden->id, $theirs->id]])
        ->assertSessionHasErrors(['applications' => "One of those apps isn't one you can see. Reload the page and choose again."]);
    $this->actingAs($admin)->post(route('projects.contents.store', $project), ['databases' => [$hiddenDatabase->id]])
        ->assertSessionHasErrors(['databases' => "One of those databases isn't one you can see. Reload the page and choose again."]);

    expect($hidden->fresh()->project_id)->toBeNull()
        ->and($theirs->fresh()->project_id)->toBeNull()
        ->and($hiddenDatabase->fresh()->project_id)->toBeNull();
});

test('a member adds an app shared with them to a project shared with them, and to no project they cannot see', function () {
    ['team' => $team, 'project' => $project, 'other' => $other, 'server' => $server] = projectContentsFixture();
    $admin = projectContentsPerson($team, TeamRole::Admin);
    $shared = Application::factory()->for($team)->for($server)->create(['name' => 'shared']);
    app(TeamAccess::class)->grant($team, $admin, $project);
    app(TeamAccess::class)->grant($team, $admin, $shared);

    $this->actingAs($admin)->post(route('projects.contents.store', $other), ['applications' => [$shared->id]])->assertNotFound();
    $this->actingAs($admin)->post(route('projects.contents.store', $project), ['applications' => [$shared->id]])->assertSessionHasNoErrors();

    expect($shared->fresh()->project_id)->toBe($project->id);
});

test('taking an app or a database out leaves it in no project', function () {
    ['team' => $team, 'owner' => $owner, 'project' => $project, 'server' => $server] = projectContentsFixture();
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'project_id' => $project->id]);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'shop_db', 'project_id' => $project->id]);

    $this->actingAs($owner)->delete(route('projects.applications.destroy', [$project, $shop]))
        ->assertSessionHas('success', 'Took shop out of Storefront.');
    $this->actingAs($owner)->delete(route('projects.databases.destroy', [$project, $database]))
        ->assertSessionHas('success', 'Took shop_db out of Storefront.');

    expect($shop->fresh()->project_id)->toBeNull()
        ->and($database->fresh()->project_id)->toBeNull()
        ->and(Activity::query()->where('description', 'application_filed')->sole()->properties->all())->toEqual(['project' => null, 'from' => 'Storefront']);
});

test('an app in another project is not found there', function () {
    ['team' => $team, 'owner' => $owner, 'project' => $project, 'other' => $other, 'server' => $server] = projectContentsFixture();
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'project_id' => $other->id]);

    $this->actingAs($owner)->delete(route('projects.applications.destroy', [$project, $shop]))->assertNotFound();

    expect($shop->fresh()->project_id)->toBe($other->id);
});

test('deployers cannot add or take out', function () {
    ['team' => $team, 'project' => $project, 'server' => $server] = projectContentsFixture();
    $deployer = projectContentsPerson($team, TeamRole::Member, everything: true);
    $shop = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'project_id' => $project->id]);
    $blog = Application::factory()->for($team)->for($server)->create(['name' => 'blog']);

    $this->actingAs($deployer)->post(route('projects.contents.store', $project), ['applications' => [$blog->id]])->assertForbidden();
    $this->actingAs($deployer)->delete(route('projects.applications.destroy', [$project, $shop]))->assertForbidden();

    expect($blog->fresh()->project_id)->toBeNull()
        ->and($shop->fresh()->project_id)->toBe($project->id);
});
