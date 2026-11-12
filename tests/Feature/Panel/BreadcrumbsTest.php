<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{label: string, href: ?string, mono: bool}
 */
function breadcrumb(string $label, ?string $href = null, bool $mono = false): array
{
    return ['label' => $label, 'href' => $href, 'mono' => $mono];
}

/**
 * @return array<string, mixed>
 */
function breadcrumbsFleet(Team $team): array
{
    $web = Server::factory()->for($team)->create(['name' => 'web']);
    $storefront = Project::factory()->for($team)->withEnvironments(['Production'])->create(['name' => 'Storefront']);
    $production = $storefront->environments()->first();
    $shop = Application::factory()->for($team)->for($web)->create(['name' => 'shop', 'environment_id' => $production->id]);

    return [
        'web' => $web,
        'storefront' => $storefront,
        'shop' => $shop,
        'blog' => Application::factory()->for($team)->for($web)->create(['name' => 'blog']),
        'shopDb' => ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'shop_db', 'environment_id' => $production->id]),
        'blogDb' => ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'blog_db']),
        'deploy' => Deployment::factory()->for($shop, 'application')->succeeded()->create(['release' => '20261112091400']),
    ];
}

function breadcrumbsMember(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);

    return $user->fresh();
}

test('every page knows its trail', function (Closure $url, Closure $trail) {
    $team = Team::factory()->owned()->create(['name' => 'Monoven Ltd']);
    $fleet = breadcrumbsFleet($team);
    $root = breadcrumb('Monoven Ltd', route('dashboard'));

    $this->actingAs($team->owner)->withSession(['auth.password_confirmed_at' => time()])->get($url($fleet))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('crumbs', [$root, ...$trail($fleet)]));
})->with([
    'the fleet' => [fn() => route('dashboard'), fn() => [breadcrumb('Fleet')]],
    'the projects' => [fn() => route('projects.index'), fn() => [breadcrumb('Fleet', route('dashboard')), breadcrumb('Projects')]],
    'a project' => [fn($f) => route('projects.show', $f['storefront']), fn() => [breadcrumb('Projects', route('projects.index')), breadcrumb('Storefront')]],
    'provisioning a server' => [fn() => route('servers.create'), fn() => [breadcrumb('Fleet', route('dashboard')), breadcrumb('Provision a server')]],
    'a server' => [fn($f) => route('servers.show', $f['web']), fn() => [breadcrumb('Fleet', route('dashboard')), breadcrumb('web', null, true)]],
    'a server tab' => [fn($f) => route('servers.hardening', $f['web']), fn($f) => [breadcrumb('Fleet', route('dashboard')), breadcrumb('web', route('servers.show', $f['web']), true), breadcrumb('Hardening')]],
    "a server's activity" => [fn($f) => route('servers.activity', $f['web']), fn($f) => [breadcrumb('Fleet', route('dashboard')), breadcrumb('web', route('servers.show', $f['web']), true), breadcrumb('Activity')]],
    "a server's databases" => [fn($f) => route('servers.databases.index', $f['web']), fn($f) => [breadcrumb('Fleet', route('dashboard')), breadcrumb('web', route('servers.show', $f['web']), true), breadcrumb('Databases')]],
    'creating a database' => [fn($f) => route('servers.databases.create', $f['web']), fn($f) => [breadcrumb('Fleet', route('dashboard')), breadcrumb('web', route('servers.show', $f['web']), true), breadcrumb('Databases', route('servers.databases.index', $f['web'])), breadcrumb('Create a database')]],
    'adding an app' => [fn($f) => route('servers.applications.create', $f['web']), fn($f) => [breadcrumb('Fleet', route('dashboard')), breadcrumb('web', route('servers.show', $f['web']), true), breadcrumb('Add an app')]],
    'an app in a project' => [fn($f) => route('servers.applications.show', [$f['web'], $f['shop']]), fn($f) => [breadcrumb('Storefront', route('projects.show', $f['storefront'])), breadcrumb('Production'), breadcrumb('shop', null, true)]],
    'an app outside projects' => [fn($f) => route('servers.applications.show', [$f['web'], $f['blog']]), fn($f) => [breadcrumb('Fleet', route('dashboard')), breadcrumb('web', route('servers.show', $f['web']), true), breadcrumb('blog', null, true)]],
    "an app's releases" => [fn($f) => route('servers.applications.releases.index', [$f['web'], $f['shop']]), fn($f) => [breadcrumb('Storefront', route('projects.show', $f['storefront'])), breadcrumb('Production'), breadcrumb('shop', route('servers.applications.show', [$f['web'], $f['shop']]), true), breadcrumb('Releases')]],
    'a deploy' => [fn($f) => route('servers.applications.deployments.show', [$f['web'], $f['shop'], $f['deploy']]), fn($f) => [breadcrumb('Storefront', route('projects.show', $f['storefront'])), breadcrumb('Production'), breadcrumb('shop', route('servers.applications.show', [$f['web'], $f['shop']]), true), breadcrumb('Deploy'), breadcrumb('20261112-091400', null, true)]],
    'a database in a project' => [fn($f) => route('servers.databases.show', [$f['web'], $f['shopDb']]), fn($f) => [breadcrumb('Storefront', route('projects.show', $f['storefront'])), breadcrumb('Production'), breadcrumb('shop_db', null, true)]],
    'a database outside projects' => [fn($f) => route('servers.databases.show', [$f['web'], $f['blogDb']]), fn($f) => [breadcrumb('Fleet', route('dashboard')), breadcrumb('web', route('servers.show', $f['web']), true), breadcrumb('blog_db', null, true)]],
    'deployments' => [fn() => route('deployments.index'), fn() => [breadcrumb('Deployments')]],
    'general settings' => [fn() => route('settings.general'), fn() => [breadcrumb('Settings', route('settings.general')), breadcrumb('General')]],
    'the team tab' => [fn() => route('teams.index'), fn() => [breadcrumb('Settings', route('settings.general')), breadcrumb('Team')]],
    'git' => [fn() => route('source-providers.index'), fn() => [breadcrumb('Settings', route('settings.general')), breadcrumb('Git')]],
    'notifications' => [fn() => route('settings.notifications'), fn() => [breadcrumb('Settings', route('settings.general')), breadcrumb('Notifications')]],
    'api tokens' => [fn() => route('api-tokens.index'), fn() => [breadcrumb('Settings', route('settings.general')), breadcrumb('API tokens')]],
    'your profile' => [fn() => route('profile.edit'), fn() => [breadcrumb('Your account', route('profile.edit')), breadcrumb('Profile')]],
    'your security' => [fn() => route('security.edit'), fn() => [breadcrumb('Your account', route('profile.edit')), breadcrumb('Security')]],
]);

test('an app whose server the member cannot see names the server without linking it', function () {
    $team = Team::factory()->owned()->create(['name' => 'Monoven Ltd']);
    $fleet = breadcrumbsFleet($team);
    $member = breadcrumbsMember($team);
    app(TeamAccess::class)->grant($team, $member, $fleet['blog']);

    $this->actingAs($member)->get(route('servers.applications.show', [$fleet['web'], $fleet['blog']]))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('crumbs', [
            breadcrumb('Monoven Ltd', route('dashboard')),
            breadcrumb('Fleet', route('dashboard')),
            breadcrumb('web', null, true),
            breadcrumb('blog', null, true),
        ]));
});

test('an app in a project the member cannot open goes by its server instead', function () {
    $team = Team::factory()->owned()->create(['name' => 'Monoven Ltd']);
    $fleet = breadcrumbsFleet($team);
    $member = breadcrumbsMember($team);
    app(TeamAccess::class)->grant($team, $member, $fleet['web']);

    $this->actingAs($member)->get(route('servers.applications.show', [$fleet['web'], $fleet['shop']]))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('crumbs', [
            breadcrumb('Monoven Ltd', route('dashboard')),
            breadcrumb('Fleet', route('dashboard')),
            breadcrumb('web', route('servers.show', $fleet['web']), true),
            breadcrumb('shop', null, true),
        ]));
});

test('releases before the first deploy read team, then Releases', function () {
    $team = Team::factory()->owned()->create(['name' => 'Monoven Ltd']);

    $this->actingAs($team->owner)->get(route('releases.index'))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('crumbs', [breadcrumb('Monoven Ltd', route('dashboard')), breadcrumb('Releases')]));
});

test('a guest has no trail', function () {
    $this->get(route('login'))->assertInertia(fn(AssertableInertia $page) => $page->where('crumbs', []));
});
