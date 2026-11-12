<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Team;
use Inertia\Testing\AssertableInertia;

test('a server added without an environment has none', function () {
    $team = Team::factory()->owned()->create();

    $this->actingAs($team->owner)->post(route('servers.store'), [
        'team_id' => $team->id,
        'name' => 'web-1',
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
        'environment' => '',
    ])->assertSessionHasNoErrors();

    expect(Server::query()->sole()->environment)->toBeNull();
});

test('renaming can set and clear the environment', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'environment' => null]);

    $this->actingAs($team->owner)->put(route('servers.update', $server), ['name' => 'web-1', 'environment' => 'Staging'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved.');

    expect($server->fresh()->environment)->toBe('Staging');

    $this->actingAs($team->owner)->put(route('servers.update', $server), ['name' => 'web-1', 'environment' => ''])->assertSessionHasNoErrors();

    expect($server->fresh()->environment)->toBeNull();
});

test('an unknown environment is refused', function (string $route) {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'environment' => null]);

    $response = $route === 'update'
        ? $this->actingAs($team->owner)->put(route('servers.update', $server), ['name' => 'web-1', 'environment' => 'QA'])
        : $this->actingAs($team->owner)->post(route('servers.store'), ['team_id' => $team->id, 'name' => 'web-2', 'ip_address' => '203.0.113.11', 'ssh_port' => 22, 'ssh_user' => 'root', 'environment' => 'QA']);

    $response->assertSessionHasErrors(['environment' => 'Pick Production, Staging, Development or No environment.']);

    expect($server->fresh()->environment)->toBeNull();
})->with(['update', 'store']);

test('the server page offers the environments to pick from', function () {
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['environment' => 'Production']);

    $this->actingAs($team->owner)->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('server.environment', 'Production')
            ->where('server.environments', ['Production', 'Staging', 'Development']));
});
