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
use App\Services\Panel\CommandPalette;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, web: Server, project: Project, shop: Application, shopStaging: Application, blog: Application}
 */
function projectFiltersFleet(): array
{
    $team = Team::factory()->owned()->create();
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $project = Project::factory()->for($team)->create(['name' => 'Storefront']);
    $shop = Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'project_id' => $project->id, 'tags' => ['production']]);
    $shopStaging = Application::factory()->for($team)->for($web)->create(['name' => 'shop-staging', 'project_id' => $project->id, 'tags' => ['staging']]);
    $blog = Application::factory()->for($team)->for($web)->create(['name' => 'blog']);

    foreach ([$shop, $shopStaging, $blog] as $index => $application) {
        Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '2026111110000' . $index, 'created_at' => now()->subMinutes(10 - $index), 'finished_at' => now()->subMinutes(9 - $index)]);
    }

    return ['team' => $team, 'owner' => $team->owner->fresh(), 'web' => $web, 'project' => $project, 'shop' => $shop, 'shopStaging' => $shopStaging, 'blog' => $blog];
}

/**
 * @return list<string>
 */
function projectFiltersNames(mixed $rows, string $key): array
{
    return collect($rows)->pluck($key)->sort()->values()->all();
}

test('deploys filter by project, and the status chips keep the filter', function () {
    $fleet = projectFiltersFleet();
    $id = $fleet['project']->handle;

    $this->actingAs($fleet['owner'])->get(route('deployments.index', ['project' => $id]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('deployments', fn($rows): bool => projectFiltersNames($rows, 'application') === ['shop', 'shop-staging'])
            ->where('scope', $id)
            ->where('filters.0.href', route('deployments.index', ['project' => $id]))
            ->where('filters.2.href', route('deployments.index', ['status' => 'deployed', 'project' => $id]))
            ->where('filters.0.count', 2));

    $this->actingAs($fleet['owner'])->get(route('deployments.index', ['project' => $id, 'environment' => 'anything']))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('deployments', fn($rows): bool => projectFiltersNames($rows, 'application') === ['shop', 'shop-staging'])
            ->where('scope', $id));

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

test("an app's releases find another app by its project's name", function () {
    $fleet = projectFiltersFleet();

    expect($this->actingAs($fleet['owner'])->getJson(route('releases.apps', ['q' => 'storefront']))->json('apps.*.name'))->toBe(['shop', 'shop-staging']);
});

test('search finds the projects a member can see', function () {
    $fleet = projectFiltersFleet();
    $member = User::factory()->create(['current_team_id' => $fleet['team']->id]);
    $fleet['team']->members()->attach($member, ['role' => TeamRole::Member->value, 'sees_everything' => false]);
    app(TeamAccess::class)->grant($fleet['team'], $member, $fleet['blog']);

    expect(collect((new CommandPalette($fleet['owner'], $fleet['team']))->items())->firstWhere('id', 'project:' . $fleet['project']->id))->toBe([
        'id' => 'project:' . $fleet['project']->id,
        'kind' => 'project',
        'label' => 'Storefront',
        'hint' => '2 apps',
        'icon' => 'folder',
        'href' => route('projects.show', $fleet['project']),
    ])->and(collect((new CommandPalette($member, $fleet['team']))->items())->firstWhere('id', 'project:' . $fleet['project']->id))->toBeNull();
});
