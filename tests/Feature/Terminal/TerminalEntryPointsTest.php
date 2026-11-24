<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\CommandPalette;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

function terminalEntryMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function terminalEntryServer(array $attributes = []): Server
{
    return Server::factory()->create(array_merge(['name' => 'web-1', 'status' => ServerStatus::Active], $attributes));
}

test("the server's header offers a terminal to people who can open one", function (TeamRole $role, bool $added, bool $offered) {
    $server = terminalEntryServer();
    $person = terminalEntryMember($server->team, $role);

    if ($added) {
        $server->forceFill(['created_by' => $person->id])->save();
    }

    if ($role === TeamRole::Developer) {
        app(TeamAccess::class)->grant($server->team, $person, $server);
    }

    $this->actingAs($person)
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('server.terminal_href', $offered ? route('servers.terminal', $server) : null));
})->with([
    'an owner' => [TeamRole::Owner, false, true],
    'a developer on a server they added' => [TeamRole::Developer, true, true],
    'a developer on a server someone else added' => [TeamRole::Developer, false, false],
    'an admin' => [TeamRole::Admin, false, false],
    'a deployer' => [TeamRole::Member, false, false],
]);

test('a server that is not set up yet offers no terminal', function () {
    $server = terminalEntryServer(['status' => ServerStatus::Provisioning]);

    $this->actingAs(terminalEntryMember($server->team, TeamRole::Owner))
        ->get(route('servers.show', $server))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('server.terminal_href', null));
});

test("an app's page opens a terminal as that app once it has a live release", function () {
    $server = terminalEntryServer();
    $owner = terminalEntryMember($server->team, TeamRole::Owner);
    $live = Application::factory()->for($server)->create(['team_id' => $server->team_id, 'name' => 'shop', 'slug' => 'shop', 'current_release' => '20261124-110000']);
    $draft = Application::factory()->for($server)->create(['team_id' => $server->team_id, 'name' => 'draft', 'slug' => 'draft', 'current_release' => null]);

    $this->actingAs($owner)
        ->get(route('servers.applications.show', [$server, $live]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('application.terminal_href', route('servers.terminal', [$server, 'app' => 'shop'])));

    $this->actingAs($owner)
        ->get(route('servers.applications.show', [$server, $draft]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('application.terminal_href', null));
});

test('the command palette opens terminals for people who can open them', function () {
    $server = terminalEntryServer();
    $owner = terminalEntryMember($server->team, TeamRole::Owner);
    Application::factory()->for($server)->create(['team_id' => $server->team_id, 'name' => 'shop', 'slug' => 'shop', 'current_release' => '20261124-110000']);
    $labels = fn(User $user): array => array_column((new CommandPalette($user, $server->team))->commands(), 'hint', 'label');

    expect($labels($owner))->toHaveKey('Open a terminal on web-1')
        ->and($labels($owner)['Open a terminal on web-1'])->toBe("as root or an app's user")
        ->and($labels($owner))->toHaveKey('Open a terminal as shop')
        ->and(array_filter(array_keys($labels(terminalEntryMember($server->team, TeamRole::Member))), fn(string $label): bool => str_contains($label, 'terminal')))->toBe([]);
});
