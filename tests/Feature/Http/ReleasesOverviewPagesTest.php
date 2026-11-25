<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

function overviewPagesOwner(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $user->fresh();
}

function overviewPagesApps(Team $team, Server $server, int $count, string $prefix, array $attributes = []): void
{
    Application::factory()->for($team)->for($server)->count($count)->sequence(fn($sequence): array => ['name' => sprintf('%s-%02d', $prefix, $sequence->index + 1)])->create($attributes);
}

function overviewPagesFound(User $owner, array $query): array
{
    $groups = test()->actingAs($owner)->get(route('releases.index', $query))->assertOk()->inertiaProps('groups');

    return collect($groups)->flatMap(fn(array $group): array => array_column($group['apps'], 'name'))->all();
}

test('the releases list comes fifty apps a page, projects first, with the rest a page away', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    overviewPagesApps($team, $server, 3, 'shop', ['project_id' => $project->id]);
    overviewPagesApps($team, $server, 58, 'app');
    $owner = overviewPagesOwner($team);

    $this->actingAs($owner)
        ->get(route('releases.index'))
        ->assertInertia(fn($page) => $page
            ->has('groups', 2)
            ->where('groups.0.name', 'Storefront')
            ->has('groups.0.apps', 3)
            ->where('groups.1.name', null)
            ->has('groups.1.apps', 47)
            ->where('groups.1.apps.0.name', 'app-01')
            ->where('groups.1.apps.46.name', 'app-47')
            ->where('total', 61)
            ->where('search', '')
            ->where('pages.total', 61)
            ->where('pages.newer', null)
            ->where('pages.older', route('releases.index', ['page' => 2])));

    $this->actingAs($owner)
        ->get(route('releases.index', ['page' => 2]))
        ->assertInertia(fn($page) => $page
            ->has('groups', 1)
            ->where('groups.0.name', null)
            ->has('groups.0.apps', 11)
            ->where('groups.0.apps.0.name', 'app-48')
            ->where('pages.from', 51)
            ->where('pages.to', 61)
            ->where('pages.newer', route('releases.index', ['page' => 1]))
            ->where('pages.older', null));
});

test('a search finds apps by name, tag, server or project whatever the case, on any page', function () {
    $team = Team::factory()->create();
    $web = Server::factory()->for($team)->create(['name' => 'web-1']);
    $worker = Server::factory()->for($team)->create(['name' => 'Worker-EU']);
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    overviewPagesApps($team, $web, 60, 'filler');
    Application::factory()->for($team)->for($web)->create(['name' => 'ledger']);
    Application::factory()->for($team)->for($web)->create(['name' => 'payments', 'tags' => ['Billing']]);
    Application::factory()->for($team)->for($worker)->create(['name' => 'queue']);
    Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'project_id' => $project->id]);
    Application::factory()->for($team)->for($web)->create(['name' => 'Écoute']);
    $owner = overviewPagesOwner($team);

    expect(overviewPagesFound($owner, ['q' => 'LEDG']))->toBe(['ledger'])
        ->and(overviewPagesFound($owner, ['q' => 'billing']))->toBe(['payments'])
        ->and(overviewPagesFound($owner, ['q' => 'worker-eu']))->toBe(['queue'])
        ->and(overviewPagesFound($owner, ['q' => 'storefront']))->toBe(['shop'])
        ->and(overviewPagesFound($owner, ['q' => 'écoute']))->toBe(['Écoute'])
        ->and(overviewPagesFound($owner, ['q' => '%']))->toBe([]);

    $this->actingAs($owner)
        ->get(route('releases.index', ['q' => '  ledg  ']))
        ->assertInertia(fn($page) => $page->where('search', 'ledg')->where('total', 65)->where('pages.total', 1));
});

test('a search that runs past one page keeps itself in the page links', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    overviewPagesApps($team, $server, 55, 'match');
    overviewPagesApps($team, $server, 5, 'other');
    $owner = overviewPagesOwner($team);

    $this->actingAs($owner)
        ->get(route('releases.index', ['q' => 'match']))
        ->assertInertia(fn($page) => $page
            ->where('total', 60)
            ->where('pages.total', 55)
            ->where('pages.older', route('releases.index', ['q' => 'match', 'page' => 2])));

    expect(overviewPagesFound($owner, ['q' => 'match', 'page' => 2]))->toBe(['match-51', 'match-52', 'match-53', 'match-54', 'match-55']);
});

test('a releases page past the end goes to the last page, keeping the search', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    overviewPagesApps($team, $server, 55, 'match');
    $owner = overviewPagesOwner($team);

    $this->actingAs($owner)
        ->get(route('releases.index', ['q' => 'match', 'page' => 9]))
        ->assertRedirect(route('releases.index', ['q' => 'match', 'page' => 2]));

    $this->actingAs($owner)
        ->get(route('releases.index', ['q' => 'nothing-like-it', 'page' => 3]))
        ->assertRedirect(route('releases.index', ['q' => 'nothing-like-it', 'page' => 1]));
});

test('an app on a later page still shows its live release', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    overviewPagesApps($team, $server, 50, 'app');
    $late = Application::factory()->for($team)->for($server)->create(['name' => 'zeta', 'current_release' => '20260913120000']);
    Deployment::factory()->for($late)->create([
        'status' => DeploymentStatus::Succeeded,
        'release' => '20260913120000',
        'tag' => 'v9.9.9',
        'started_at' => now()->subHours(2),
        'finished_at' => now()->subHours(2)->addMinutes(2),
    ]);

    $this->actingAs(overviewPagesOwner($team))
        ->get(route('releases.index', ['page' => 2]))
        ->assertInertia(fn($page) => $page
            ->where('groups.0.apps.0.name', 'zeta')
            ->where('groups.0.apps.0.release', 'releases/20260913-120000')
            ->where('groups.0.apps.0.tag', 'v9.9.9'));
});
