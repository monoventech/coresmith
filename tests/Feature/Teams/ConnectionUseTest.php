<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, server: Server, connection: SourceProvider, owner: User, admin: User, narrowed: User, developer: User}
 */
function connectionUseSetup(): array
{
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web']);
    $connection = SourceProvider::factory()->githubApp(GitHubApp::factory()->create(['team_id' => $team->id]))->create(['team_id' => $team->id, 'name' => 'monoventech']);
    $people = [];

    foreach (['owner' => [TeamRole::Owner, true], 'admin' => [TeamRole::Admin, true], 'narrowed' => [TeamRole::Admin, false], 'developer' => [TeamRole::Developer, false]] as $key => [$role, $everything]) {
        $people[$key] = User::factory()->create();
        $team->members()->attach($people[$key], ['role' => $role->value, 'sees_everything' => $everything]);
        $people[$key] = $people[$key]->fresh();
    }

    app(TeamAccess::class)->grant($team, $people['narrowed'], $server);
    app(TeamAccess::class)->grant($team, $people['developer'], $server);

    return ['team' => $team, 'server' => $server, 'connection' => $connection, ...$people];
}

test('a team GitHub connection is offered on Add an app only to members who see everything', function (string $who, bool $offered) {
    $setup = connectionUseSetup();

    $this->actingAs($setup[$who])->get(route('servers.applications.create', $setup['server']))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('github.connections', fn($connections): bool => collect($connections)->pluck('id')->contains($setup['connection']->id) === $offered));
})->with([
    'the owner' => ['owner', true],
    'a full admin' => ['admin', true],
    'a narrowed admin' => ['narrowed', false],
    'a developer' => ['developer', false],
]);

test("a team connection's repositories are not listed to a narrowed admin or a developer", function (string $who) {
    Http::preventStrayRequests();
    $setup = connectionUseSetup();

    $this->actingAs($setup[$who])->getJson(route('source-providers.repositories', $setup['connection']))->assertNotFound();
})->with(['narrowed', 'developer']);

test('a narrowed admin or a developer cannot add an app from a team connection', function (string $who) {
    Http::preventStrayRequests();
    $setup = connectionUseSetup();

    $this->actingAs($setup[$who])->from(route('servers.applications.create', $setup['server']))->post(route('servers.applications.store', $setup['server']), [
        'name' => 'shop',
        'type' => 'php',
        'framework' => 'laravel',
        'source_provider_id' => $setup['connection']->id,
        'repository_id' => '123456',
        'repository_branch' => 'main',
        'directory' => '/',
    ])->assertSessionHasErrors(['source_provider_id' => "That GitHub connection isn't available. Pick another one, or connect GitHub again in Git settings."]);
})->with(['narrowed', 'developer']);

test('a developer cannot read a repository through a team connection to fill in its stack', function () {
    Http::preventStrayRequests();
    $setup = connectionUseSetup();

    $this->actingAs($setup['developer'])->getJson(route('servers.repository-stack', ['server' => $setup['server'], 'source_provider_id' => $setup['connection']->id, 'repository_id' => '123456', 'repository_branch' => 'main']))
        ->assertOk()
        ->assertJsonPath('state', 'failed')
        ->assertJsonPath('failure', "That GitHub connection isn't available. Pick another one, or connect GitHub again in Git settings.");
});
