<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\CommandPalette;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, web: Server, project: Project, production: Environment, staging: Environment, shop: Application, shopStaging: Application, blog: Application}
 */
function projectFiltersFleet(): array
{
    $team = Team::factory()->owned()->create();
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $project = Project::factory()->for($team)->withEnvironments(['Production', 'Staging'])->create(['name' => 'Storefront']);
    [$production, $staging] = $project->environments()->get()->all();
    $shop = Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'environment_id' => $production->id]);
    $shopStaging = Application::factory()->for($team)->for($web)->create(['name' => 'shop-staging', 'environment_id' => $staging->id]);
    $blog = Application::factory()->for($team)->for($web)->create(['name' => 'blog']);

    foreach ([$shop, $shopStaging, $blog] as $index => $application) {
        Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '2026111110000' . $index, 'created_at' => now()->subMinutes(10 - $index), 'finished_at' => now()->subMinutes(9 - $index)]);
    }

    return ['team' => $team, 'owner' => $team->owner->fresh(), 'web' => $web, 'project' => $project, 'production' => $production, 'staging' => $staging, 'shop' => $shop, 'shopStaging' => $shopStaging, 'blog' => $blog];
}

/**
 * @return list<string>
 */
function projectFiltersNames(mixed $rows, string $key): array
{
    return collect($rows)->pluck($key)->sort()->values()->all();
}

test('deploys filter by project and environment, and the status chips keep the filter', function () {
    $fleet = projectFiltersFleet();
    $id = $fleet['project']->id;

    $this->actingAs($fleet['owner'])->get(route('deployments.index', ['project' => $id]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('deployments', fn($rows): bool => projectFiltersNames($rows, 'application') === ['shop', 'shop-staging'])
            ->where('scope', $id)
            ->where('filters.0.href', route('deployments.index', ['project' => $id]))
            ->where('filters.2.href', route('deployments.index', ['status' => 'deployed', 'project' => $id]))
            ->where('filters.0.count', 2));

    $this->actingAs($fleet['owner'])->get(route('deployments.index', ['project' => $id, 'environment' => $fleet['staging']->id]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('deployments', fn($rows): bool => projectFiltersNames($rows, 'application') === ['shop-staging'])
            ->where('scope', $id . ':' . $fleet['staging']->id));

    $this->actingAs($fleet['owner'])->get(route('deployments.index', ['project' => 'none']))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('deployments', fn($rows): bool => projectFiltersNames($rows, 'application') === ['blog'])
            ->where('scope', 'none'));

    $this->actingAs($fleet['owner'])->get(route('deployments.index'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('deployments', fn($rows): bool => count($rows) === 3)
            ->where('scope', '')
            ->where('scopes', [
                ['value' => '', 'label' => 'All projects'],
                ['value' => 'none', 'label' => 'No project'],
                ['value' => $id, 'label' => 'Storefront'],
                ['value' => $id . ':' . $fleet['production']->id, 'label' => 'Storefront › Production'],
                ['value' => $id . ':' . $fleet['staging']->id, 'label' => 'Storefront › Staging'],
            ]));
});

test('a project the member cannot see is ignored, and no filter is offered', function () {
    $fleet = projectFiltersFleet();
    $member = User::factory()->create(['current_team_id' => $fleet['team']->id]);
    $fleet['team']->members()->attach($member, ['role' => TeamRole::Member->value, 'sees_everything' => false]);
    app(TeamAccess::class)->grant($fleet['team'], $member, $fleet['blog']);

    $this->actingAs($member)->get(route('deployments.index', ['project' => $fleet['project']->id]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('deployments', fn($rows): bool => projectFiltersNames($rows, 'application') === ['blog'])
            ->where('scope', '')
            ->where('scopes', []));
});

test('releases narrow the app list to a project, and switching apps keeps it', function () {
    $fleet = projectFiltersFleet();
    $id = $fleet['project']->id;

    $this->actingAs($fleet['owner'])->get(route('servers.applications.releases.index', [$fleet['web'], $fleet['shop'], 'project' => $id]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('applications', fn($rows): bool => projectFiltersNames($rows, 'name') === ['shop', 'shop-staging'])
            ->where('applications.1.href', route('servers.applications.releases.index', [$fleet['web'], $fleet['shopStaging'], 'project' => $id]))
            ->where('scope', $id)
            ->where('scopes.2', ['value' => $id, 'label' => 'Storefront']));
});

test('search finds the projects a member can see', function () {
    $fleet = projectFiltersFleet();
    $member = User::factory()->create(['current_team_id' => $fleet['team']->id]);
    $fleet['team']->members()->attach($member, ['role' => TeamRole::Member->value, 'sees_everything' => false]);
    app(TeamAccess::class)->grant($fleet['team'], $member, $fleet['blog']);

    expect(collect((new CommandPalette($fleet['owner'], $fleet['team']))->commands())->firstWhere('label', 'Open Storefront'))->toBe([
        'label' => 'Open Storefront',
        'icon' => 'folder',
        'hint' => '2 environments · 2 apps',
        'href' => route('projects.show', $fleet['project']),
    ])->and(collect((new CommandPalette($member, $fleet['team']))->commands())->firstWhere('label', 'Open Storefront'))->toBeNull();
});
