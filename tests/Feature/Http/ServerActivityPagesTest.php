<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;

function serverActivityPagesOwner(Server $server): User
{
    $user = User::factory()->create();
    $server->team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $user->fresh();
}

function serverActivityPagesReboots(Server $server, int $count): void
{
    foreach (range(1, $count) as $minute) {
        test()->travelTo(CarbonImmutable::parse('2026-09-13 10:00:00')->addMinutes($minute));
        activity()->performedOn($server)->log('server_rebooted');
    }

    test()->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
}

test("a server's activity comes a page at a time, with links to newer and older pages", function () {
    $server = Server::factory()->create(['name' => 'web-1']);
    $owner = serverActivityPagesOwner($server);
    serverActivityPagesReboots($server, 30);

    $this->actingAs($owner)
        ->get(route('servers.activity', $server))
        ->assertInertia(fn($page) => $page
            ->has('activity', 25)
            ->where('pages.current', 1)
            ->where('pages.newer', null)
            ->where('pages.older', route('servers.activity', [$server, 'page' => 2])));

    $this->actingAs($owner)
        ->get(route('servers.activity', [$server, 'page' => 2]))
        ->assertInertia(fn($page) => $page
            ->has('activity', 5)
            ->where('pages.newer', route('servers.activity', [$server, 'page' => 1]))
            ->where('pages.older', null));
});

test('the Alerts filter stays on as you page, and a page past the end goes back to the first', function () {
    $server = Server::factory()->create(['name' => 'web-1']);
    $owner = serverActivityPagesOwner($server);
    serverActivityPagesReboots($server, 3);

    $this->actingAs($owner)
        ->get(route('servers.activity', [$server, 'show' => 'alerts', 'page' => 7]))
        ->assertRedirect(route('servers.activity', [$server, 'show' => 'alerts', 'page' => 1]));

    $this->actingAs($owner)
        ->get(route('servers.activity', [$server, 'page' => 7]))
        ->assertRedirect(route('servers.activity', [$server, 'page' => 1]));
});
