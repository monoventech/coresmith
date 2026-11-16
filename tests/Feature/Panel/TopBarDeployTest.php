<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, server: Server, shop: Application}
 */
function topBarDeployFixture(): array
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

test('the app page and its releases carry Deploy for the top bar, each going back where it started', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = topBarDeployFixture();
    $href = route('servers.applications.deploy', [$server, $shop]);

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('app_deploy', ['name' => 'shop', 'href' => $href, 'from' => 'app', 'running_href' => null, 'blocked' => false]));
    $this->actingAs($owner)->get(route('servers.applications.releases.index', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('app_deploy', ['name' => 'shop', 'href' => $href, 'from' => 'releases', 'running_href' => null, 'blocked' => false]));
});

test('a running deploy turns it into a way to watch, and a pending repository check blocks it on the app page', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = topBarDeployFixture();
    $running = Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::Running]);

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('app_deploy.running_href', route('servers.applications.deployments.show', [$server, $shop, $running, 'from' => 'app'])));

    $running->update(['status' => DeploymentStatus::Succeeded]);
    $shop->forceFill(['repository_check_pending' => true])->save();

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('app_deploy.running_href', null)->where('app_deploy.blocked', true));
    $this->actingAs($owner)->get(route('servers.applications.releases.index', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('app_deploy.blocked', false));
});

test('other pages and the page of a single deploy carry nothing', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = topBarDeployFixture();
    $deployment = Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::Succeeded]);

    $this->actingAs($owner)->get(route('servers.show', $server))->assertInertia(fn(AssertableInertia $page) => $page->where('app_deploy', null));
    $this->actingAs($owner)->get(route('servers.applications.deployments.show', [$server, $shop, $deployment]))->assertInertia(fn(AssertableInertia $page) => $page->where('app_deploy', null));
    $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn(AssertableInertia $page) => $page->where('app_deploy', null));
});
