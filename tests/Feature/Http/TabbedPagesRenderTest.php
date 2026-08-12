<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

/**
 * Every page carrying a tab strip reads `can` to decide which tabs to show.
 * A controller that forgets to send it renders a blank page in the browser
 * while every server-side assertion still passes, so each one is opened here.
 */
function tabbedOwner(Team $team): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $user->fresh();
}

test('every tabbed page sends the abilities its tab strip needs', function (string $name, string $kind) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $parameters = $kind === 'server' ? [$server] : [$server, $application];

    $this->actingAs(tabbedOwner($team))
        ->get(route($name, $parameters))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('can'));
})->with([
    ['servers.show', 'server'],
    ['servers.edit', 'server'],
    ['servers.firewall.index', 'server'],
    ['servers.applications.index', 'server'],
    ['servers.databases.index', 'server'],
    ['servers.applications.show', 'application'],
    ['servers.applications.edit', 'application'],
    ['servers.applications.env.show', 'application'],
    ['servers.applications.deployments.index', 'application'],
    ['servers.applications.features', 'application'],
]);
