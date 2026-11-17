<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\ApplicationToggledNotification;
use App\Services\Teams\Handles;

beforeEach(fn() => config(['inertia.ssr.enabled' => false]));

test('servers, projects and apps get a handle from their name, unique within a team', function () {
    $team = Team::factory()->owned()->create();
    $other = Team::factory()->owned()->create();

    $first = Server::factory()->for($team)->create(['name' => 'Web 1']);
    $second = Server::factory()->for($team)->create(['name' => 'web-1']);
    $elsewhere = Server::factory()->for($other)->create(['name' => 'Web 1']);
    $project = Project::factory()->for($team)->create(['name' => 'Side Quests']);
    $application = Application::factory()->for($team)->for($first)->create(['name' => 'Notebook']);

    expect([$first->handle, $second->handle, $elsewhere->handle, $project->handle, $application->handle])
        ->toBe(['web-1', 'web-1-2', 'web-1', 'side-quests', 'notebook']);
});

test('a name that would clash with a page of its own gets a number', function (string $name, string $handle) {
    $team = Team::factory()->owned()->create();

    expect(Server::factory()->for($team)->create(['name' => $name])->handle)->toBe($handle);
})->with([
    'cloud' => ['cloud', 'cloud-2'],
    'this machine' => ['This machine', 'this-machine-2'],
    'nothing usable' => ['!!!', 'server'],
]);

test('renaming changes the handle and remembers the old one, which takes you to the new address', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);

    $server->update(['name' => 'Edge']);

    expect($server->fresh()->handle)->toBe('edge')
        ->and($server->fresh()->former_handles)->toBe(['web-1']);

    $this->actingAs($team->owner)->get('/servers/web-1/hardening')->assertRedirect('/servers/edge/hardening');
    $this->get('/servers/edge')->assertOk()->assertInertia(fn($page) => $page->where('server.id', $server->id));
});

test('addresses use handles, database names and release names', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'vps-1']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'ledger']);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'ledger_production']);
    $deployment = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20261117-030400']);
    $project = Project::factory()->for($team)->create(['name' => 'side quests']);

    expect(route('servers.show', $server, false))->toBe('/servers/vps-1')
        ->and(route('servers.applications.show', [$server, $application], false))->toBe('/servers/vps-1/applications/ledger')
        ->and(route('servers.databases.show', [$server, $database], false))->toBe('/servers/vps-1/databases/ledger_production')
        ->and(route('servers.applications.deployments.show', [$server, $application, $deployment], false))->toBe('/servers/vps-1/applications/ledger/deployments/20261117-030400')
        ->and(route('projects.show', $project, false))->toBe('/projects/side-quests');
});

test("an old address with an id, or an app's slug, takes you to the readable address", function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'vps-1']);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'ledger', 'slug' => 'ledger-x1y']);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'ledger_production']);
    $deployment = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20261117-030400']);

    $this->actingAs($team->owner);

    $this->get("/servers/{$server->id}")->assertRedirect('/servers/vps-1');
    $this->get("/servers/{$server->id}/activity?show=alerts")->assertRedirect('/servers/vps-1/activity?show=alerts');
    $this->get("/servers/{$server->id}/applications/{$application->id}")->assertRedirect('/servers/vps-1/applications/ledger');
    $this->get('/servers/vps-1/applications/ledger-x1y')->assertRedirect('/servers/vps-1/applications/ledger');
    $this->get("/servers/{$server->id}/databases/{$database->id}")->assertRedirect('/servers/vps-1/databases/ledger_production');
    $this->get("/servers/vps-1/applications/ledger/deployments/{$deployment->id}")->assertRedirect('/servers/vps-1/applications/ledger/deployments/20261117-030400');
    $this->get(route('servers.applications.show', [$server, $application]))->assertOk();
});

test('a form sent to an old address still works', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'vps-1']);

    $this->actingAs($team->owner)->put("/servers/{$server->id}", ['name' => 'vps-2'])->assertRedirect();

    expect($server->fresh()->name)->toBe('vps-2');
});

test("a handle from a team the person isn't on opens nothing", function () {
    $team = Team::factory()->owned()->create();
    $other = Team::factory()->owned()->create();
    Server::factory()->for($other)->create(['name' => 'secret-box']);

    $this->actingAs($team->owner)->get('/servers/secret-box')->assertNotFound();
});

test('the team the address names decides which of two same-named servers opens', function () {
    $team = Team::factory()->owned()->create(['slug' => 'alpha']);
    $second = Team::factory()->create(['slug' => 'beta']);
    $second->members()->attach($team->owner, ['role' => 'owner']);
    $mine = Server::factory()->for($team)->create(['name' => 'web']);
    $theirs = Server::factory()->for($second)->create(['name' => 'web']);

    $this->actingAs($team->owner)->get('/beta/servers/web')->assertOk()
        ->assertInertia(fn($page) => $page->where('server.id', $theirs->id));
    $this->actingAs($team->owner)->get('/alpha/servers/web')->assertOk()
        ->assertInertia(fn($page) => $page->where('server.id', $mine->id));
});

test('handles are cut to length and keep a number when they clash', function () {
    expect(Handles::base(str_repeat('a', 80), 'server'))->toHaveLength(Handles::MAX)
        ->and(Handles::suffixed(str_repeat('a', 60), 12))->toBe(str_repeat('a', 57) . '-12')
        ->and(Handles::reserved('cloud'))->toBeTrue()
        ->and(Handles::reserved('web'))->toBeFalse();
});

test('the live deploy channel, which binds without a route, still finds a deploy by its id', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create();
    $deployment = Deployment::factory()->for(Application::factory()->for($team)->for($server))->create(['release' => '20261117-030400']);

    $found = call_user_func(app('router')->getBindingCallback('deployment'), $deployment->id);

    expect($found->is($deployment))->toBeTrue();
});

test("an email's link names the team, so it opens that team's app when another team has one with the same name", function () {
    $alpha = Team::factory()->owned()->create(['slug' => 'alpha']);
    $beta = Team::factory()->create(['slug' => 'beta']);
    $beta->members()->attach($alpha->owner, ['role' => 'owner']);
    Application::factory()->for($alpha)->for(Server::factory()->for($alpha)->create(['name' => 'web']))->create(['name' => 'shop']);
    $theirs = Application::factory()->for($beta)->for(Server::factory()->for($beta)->create(['name' => 'web']))->create(['name' => 'shop']);

    $link = (new ApplicationToggledNotification($theirs, 'The app was stopped', null))->toMail($alpha->owner)->actionUrl;

    expect($link)->toBe('http://localhost/beta/servers/web/applications/shop');

    $this->actingAs($alpha->owner)->get($link)->assertOk()
        ->assertInertia(fn($page) => $page->where('application.id', $theirs->id));
});

test('a removed app gives its address back to the next app with its name', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web']);
    $removed = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);

    $removed->delete();

    expect($removed->fresh()->handle)->toBeNull()
        ->and(Application::factory()->for($team)->for($server)->create(['name' => 'shop'])->handle)->toBe('shop');
});

test('a copy of an app gets an address of its own', function () {
    $team = Team::factory()->owned()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['name' => 'shop', 'slug' => 'shop']);

    $copy = $application->replicate()->forceFill(['slug' => 'shop-copy', 'system_user' => 'coresmith-app-shop-copy', 'domain' => 'copy.example.com', 'base_port' => $application->base_port + 10]);
    $copy->save();

    expect($copy->handle)->toBe('shop-2')
        ->and($copy->former_handles)->toBeNull();
});

test('a new app named like a renamed one opens at that name, and the renamed one keeps its new address', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web']);
    $renamed = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);
    $renamed->update(['name' => 'store']);
    $new = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);

    $this->actingAs($team->owner);

    $this->get('/servers/web/applications/shop')->assertOk()->assertInertia(fn($page) => $page->where('application.id', $new->id));
    $this->get('/servers/web/applications/store')->assertOk()->assertInertia(fn($page) => $page->where('application.id', $renamed->id));
});

test('renaming an app from its page lands on its new address', function () {
    $team = Team::factory()->owned()->create();
    Application::factory()->for($team)->for(Server::factory()->for($team)->create(['name' => 'web']))->create(['name' => 'shop']);

    $this->actingAs($team->owner)->from('http://localhost/servers/web/applications/shop')
        ->put('/servers/web/applications/shop', ['name' => 'store'])
        ->assertHeader('Location', 'http://localhost/servers/web/applications/store');
});

test('renaming a server keeps the tab and filter it was renamed from, under the team address', function () {
    $team = Team::factory()->owned()->create(['slug' => 'acme']);
    Server::factory()->for($team)->create(['name' => 'web']);

    $this->actingAs($team->owner)->from('http://localhost/acme/servers/web/activity?show=alerts')
        ->put('/acme/servers/web', ['name' => 'edge'])
        ->assertHeader('Location', 'http://localhost/acme/servers/edge/activity?show=alerts');
});

test('renaming a project lands on its new address', function () {
    $team = Team::factory()->owned()->create();
    Project::factory()->for($team)->create(['name' => 'side quests']);

    $this->actingAs($team->owner)->from('http://localhost/projects/side-quests')
        ->put('/projects/side-quests', ['name' => 'main quests'])
        ->assertHeader('Location', 'http://localhost/projects/main-quests');
});

test('a rename sent from another page, or one that keeps the address, goes back where it came from', function () {
    $team = Team::factory()->owned()->create();
    Server::factory()->for($team)->create(['name' => 'web']);

    $this->actingAs($team->owner);

    $this->from('http://localhost/dashboard')->put('/servers/web', ['name' => 'edge'])->assertHeader('Location', 'http://localhost/dashboard');
    $this->from('http://localhost/servers/edge/hardening')->put('/servers/edge', ['name' => 'Edge'])->assertHeader('Location', 'http://localhost/servers/edge/hardening');
});
