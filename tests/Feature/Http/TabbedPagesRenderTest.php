<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

function tabbedOwner(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $user->fresh();
}

test('every tabbed page sends the abilities its tab strip needs', function (string $name, string $component) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(tabbedOwner($team))
        ->get(route($name, $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component($component)
            ->where('server.id', $server->id)
            ->where('server.can', [
                'update' => true,
                'delete' => true,
                'manageFirewall' => true,
                'createApplication' => true,
            ])
            ->where('server.tabs', [
                'apps' => route('servers.show', $server),
                'databases' => route('servers.databases.index', $server),
                'hardening' => route('servers.hardening', $server),
                'activity' => route('servers.activity', $server),
            ]));
})->with([
    ['servers.show', 'servers/Show'],
    ['servers.databases.index', 'servers/Databases'],
    ['servers.hardening', 'servers/Hardening'],
    ['servers.activity', 'servers/Activity'],
]);
