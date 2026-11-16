<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Http\Controllers\ApplicationTagsController;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ActivityFeed;
use App\Services\Projects\AppTags;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{team: Team, owner: User, server: Server, shop: Application}
 */
function appTagsFixture(): array
{
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web']);

    return [
        'team' => $team,
        'owner' => $team->owner->fresh(),
        'server' => $server,
        'shop' => Application::factory()->for($team)->for($server)->create(['name' => 'shop']),
    ];
}

function appTagsPerson(Team $team, TeamRole $role, bool $everything = true): User
{
    $user = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => $everything]);

    return $user->fresh();
}

test('tags are trimmed, kept as typed and told apart without regard to case', function () {
    expect(AppTags::normalize(['  Staging ', 'eu  west', 'staging', '', 7, null, 'EU West']))->toBe(['Staging', 'eu west'])
        ->and(AppTags::normalize('production'))->toBe([]);
});

test('the tags on an app change from its page, cleaned and saved, and the change is recorded', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = appTagsFixture();

    $this->actingAs($owner)->put(route('servers.applications.tags.update', [$server, $shop]), ['tags' => ['Staging ', 'eu', 'staging']])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved.');

    $activity = Activity::query()->where('description', ApplicationTagsController::CHANGED)->sole();

    expect($shop->fresh()->tags)->toBe(['Staging', 'eu'])
        ->and($activity->properties->all())->toBe(['from' => [], 'to' => ['Staging', 'eu']])
        ->and(collect(app(ActivityFeed::class)->forServer($server, viewer: $owner))->map(fn(array $entry): string => collect($entry['parts'])->pluck('text')->implode(''))->implode("\n"))->toContain('Tags on shop set to Staging, eu');
});

test('saving the same tags changes nothing and records nothing, and clearing them leaves none', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = appTagsFixture();
    $shop->update(['tags' => ['production']]);

    $this->actingAs($owner)->put(route('servers.applications.tags.update', [$server, $shop]), ['tags' => ['production']])->assertSessionMissing('success');

    expect(Activity::query()->where('description', ApplicationTagsController::CHANGED)->count())->toBe(0);

    $this->actingAs($owner)->put(route('servers.applications.tags.update', [$server, $shop]), ['tags' => []])->assertSessionHas('success', 'Saved.');

    expect($shop->fresh()->tags)->toBe([])
        ->and(Activity::query()->where('description', ApplicationTagsController::CHANGED)->sole()->properties->all())->toBe(['from' => ['production'], 'to' => []]);
});

test('tags are refused when there are too many, one is too long or one starts oddly', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = appTagsFixture();
    $route = route('servers.applications.tags.update', [$server, $shop]);

    $this->actingAs($owner)->put($route, ['tags' => ['a', 'b', 'c', 'd', 'e', 'f']])->assertSessionHasErrors(['tags' => 'An app can have up to 5 tags.']);
    $this->actingAs($owner)->put($route, ['tags' => [str_repeat('x', 25)]])->assertSessionHasErrors(['tags.0' => 'Keep each tag to 24 characters or fewer.']);
    $this->actingAs($owner)->put($route, ['tags' => ['#ops']])->assertSessionHasErrors(['tags.0' => 'Start each tag with a letter or number, then use letters, numbers, spaces, dots, dashes or underscores.']);

    expect($shop->fresh()->tags)->toBe([]);
});

test('people who cannot change the app cannot change its tags, and another server is not found', function () {
    ['team' => $team, 'server' => $server, 'shop' => $shop, 'owner' => $owner] = appTagsFixture();
    $deployer = appTagsPerson($team, TeamRole::Member);
    $elsewhere = Server::factory()->for($team)->create();

    $this->actingAs($deployer)->put(route('servers.applications.tags.update', [$server, $shop]), ['tags' => ['mine']])->assertForbidden();
    $this->actingAs($owner)->put(route('servers.applications.tags.update', [$elsewhere, $shop]), ['tags' => ['mine']])->assertNotFound();

    expect($shop->fresh()->tags)->toBe([]);
});

test('the app page, Add an app and the project page suggest the tags already in use on apps the person can see', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server, 'shop' => $shop] = appTagsFixture();
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    $shop->update(['tags' => ['production', 'eu'], 'project_id' => $project->id]);
    Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'tags' => ['Staging', 'Production'], 'project_id' => $project->id]);
    $hidden = Application::factory()->for($team)->for($server)->create(['name' => 'vault', 'tags' => ['secret']]);
    $admin = appTagsPerson($team, TeamRole::Admin, everything: false);
    app(TeamAccess::class)->sync($team, $admin, false, [$server->id], [], [$project->id], [], [$hidden->id]);

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('tag_suggestions', ['eu', 'production', 'secret', 'Staging']));
    $this->actingAs($owner)->get(route('servers.applications.create', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('tag_suggestions', ['eu', 'production', 'secret', 'Staging']));
    $this->actingAs($admin)->get(route('projects.show', $project))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('tag_suggestions', ['eu', 'production', 'Staging']));
});

test('the project page lets people who can change an app tag it in place', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server, 'shop' => $shop] = appTagsFixture();
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    $shop->update(['project_id' => $project->id]);
    $deployer = appTagsPerson($team, TeamRole::Member);

    $this->actingAs($owner)->get(route('projects.show', $project))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('applications.0.tags_href', route('servers.applications.tags.update', [$server, $shop])));
    $this->actingAs($deployer)->get(route('projects.show', $project))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('applications.0.tags_href', null));
});

test('the app page shows the tags to everyone and offers editing only to people who can change the app', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server, 'shop' => $shop] = appTagsFixture();
    $shop->update(['tags' => ['production']]);
    $deployer = appTagsPerson($team, TeamRole::Member);

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('application.tags', ['production'])
            ->where('application.tags_href', route('servers.applications.tags.update', [$server, $shop])));
    $this->actingAs($deployer)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('application.tags', ['production'])
            ->where('application.tags_href', null)
            ->where('tag_suggestions', []));
});
