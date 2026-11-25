<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

function projectsUnfiledOwner(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $user->fresh();
}

function projectsUnfiledTeam(int $unfiled): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web']);
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    Application::factory()->for($team)->for($server)->count(2)->sequence(['name' => 'shop'], ['name' => 'shop-staging'])->create(['project_id' => $project->id]);
    Application::factory()->for($team)->for($server)->count($unfiled)->sequence(fn($sequence): array => ['name' => sprintf('app-%02d', $sequence->index + 1)])->create();

    return ['team' => $team, 'owner' => projectsUnfiledOwner($team)];
}

test('apps in no project come fifty a page by name, with the rest a page away', function () {
    $fleet = projectsUnfiledTeam(55);

    $this->actingAs($fleet['owner'])
        ->get(route('projects.index'))
        ->assertInertia(fn($page) => $page
            ->has('unfiled', 50)
            ->where('unfiled.0.name', 'app-01')
            ->where('unfiled.49.name', 'app-50')
            ->where('unfiled_pages.total', 55)
            ->where('unfiled_pages.newer', null)
            ->where('unfiled_pages.older', route('projects.index', ['page' => 2]) . '#no-project')
            ->where('counts.applications', 57)
            ->has('projects', 1));

    $this->actingAs($fleet['owner'])
        ->get(route('projects.index', ['page' => 2]))
        ->assertInertia(fn($page) => $page
            ->has('unfiled', 5)
            ->where('unfiled.0.name', 'app-51')
            ->where('unfiled_pages.from', 51)
            ->where('unfiled_pages.to', 55)
            ->where('unfiled_pages.newer', route('projects.index', ['page' => 1]) . '#no-project')
            ->where('unfiled_pages.older', null)
            ->where('counts.applications', 57)
            ->has('projects', 1));
});

test('a page of apps in no project past the end goes to the last page', function () {
    $fleet = projectsUnfiledTeam(55);

    $this->actingAs($fleet['owner'])
        ->get(route('projects.index', ['page' => 9]))
        ->assertRedirect(route('projects.index', ['page' => 2]) . '#no-project');
});
