<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{team: Team, owner: User, developer: User, shared: Server, own: Server, theirs: Application, mine: Application, theirsDb: ManagedDatabase, mineDb: ManagedDatabase}
 */
function developerOwnershipSetup(): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $developer = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'sees_everything' => true]);
    $team->members()->attach($developer, ['role' => TeamRole::Developer->value, 'sees_everything' => false]);

    $shared = Server::factory()->for($team)->create(['name' => 'beelink', 'created_by' => $owner->id]);
    $own = Server::factory()->for($team)->create(['name' => 'devbox', 'created_by' => $developer->id]);
    $theirs = Application::factory()->for($team)->for($shared)->create(['name' => 'miagraph', 'created_by' => $owner->id]);
    $mine = Application::factory()->for($team)->for($shared)->create(['name' => 'notes', 'created_by' => $developer->id]);
    $theirsDb = ManagedDatabase::factory()->for($team)->for($shared)->create(['name' => 'miagraph_db', 'created_by' => $owner->id]);
    $mineDb = ManagedDatabase::factory()->for($team)->for($shared)->create(['name' => 'notes_db', 'created_by' => $developer->id]);
    app(TeamAccess::class)->grant($team, $developer, $shared);

    return compact('team', 'owner', 'shared', 'own', 'theirs', 'mine', 'theirsDb', 'mineDb') + ['developer' => $developer->fresh()];
}

test('what a member adds records them as the one who added it', function () {
    ['team' => $team, 'developer' => $developer, 'shared' => $shared] = developerOwnershipSetup();

    $this->actingAs($developer);

    expect(Server::factory()->for($team)->create()->created_by)->toBe($developer->id)
        ->and(Application::factory()->for($team)->for($shared)->create()->created_by)->toBe($developer->id)
        ->and(ManagedDatabase::factory()->for($team)->for($shared)->create()->created_by)->toBe($developer->id);
});

test('a developer sees the servers it added and the ones shared with it', function () {
    ['team' => $team, 'developer' => $developer] = developerOwnershipSetup();
    Server::factory()->for($team)->create(['name' => 'vault']);

    expect(app(TeamAccess::class)->servers($developer, $team)->orderBy('name')->pluck('name')->all())->toBe(['beelink', 'devbox']);
});

test('a developer sees the apps and databases it added on a shared server, not the ones others put there', function () {
    ['team' => $team, 'developer' => $developer, 'theirs' => $theirs, 'mine' => $mine, 'theirsDb' => $theirsDb] = developerOwnershipSetup();
    $access = app(TeamAccess::class);

    expect($access->applications($developer, $team)->pluck('name')->all())->toBe(['notes'])
        ->and($access->databases($developer, $team)->pluck('name')->all())->toBe(['notes_db'])
        ->and($access->canSeeApplication($developer, $theirs))->toBeFalse()
        ->and($access->canSeeApplication($developer, $mine))->toBeTrue()
        ->and($access->canSeeDatabase($developer, $theirsDb))->toBeFalse();
});

test('an app shared with a developer by name is one it sees and deploys, but cannot change or remove', function () {
    ['team' => $team, 'developer' => $developer, 'theirs' => $theirs] = developerOwnershipSetup();
    app(TeamAccess::class)->grant($team, $developer, $theirs);

    expect(app(TeamAccess::class)->canSeeApplication($developer, $theirs))->toBeTrue()
        ->and($developer->can('deploy', $theirs))->toBeTrue()
        ->and($developer->can('viewLogs', $theirs))->toBeTrue()
        ->and($developer->can('update', $theirs))->toBeFalse()
        ->and($developer->can('delete', $theirs))->toBeFalse();
});

test('a developer changes and removes only the servers it added', function () {
    ['developer' => $developer, 'shared' => $shared, 'own' => $own] = developerOwnershipSetup();

    expect([$developer->can('update', $own), $developer->can('delete', $own), $developer->can('manageFirewall', $own)])->toBe([true, true, true])
        ->and([$developer->can('update', $shared), $developer->can('delete', $shared), $developer->can('manageFirewall', $shared)])->toBe([false, false, false])
        ->and($developer->can('view', $shared))->toBeTrue();
});

test('a developer changes and removes only the apps it added', function () {
    ['developer' => $developer, 'mine' => $mine] = developerOwnershipSetup();

    expect([$developer->can('update', $mine), $developer->can('delete', $mine), $developer->can('deploy', $mine), $developer->can('manageEnvironment', $mine)])->toBe([true, true, true, true]);
});

test('a developer removes and restores only the databases it added', function () {
    ['team' => $team, 'developer' => $developer, 'mineDb' => $mineDb, 'theirsDb' => $theirsDb] = developerOwnershipSetup();
    $theirsDb->forceFill(['environment_id' => null])->save();

    expect([$developer->can('delete', $mineDb), $developer->can('restore', $mineDb), $developer->can('backup', $mineDb)])->toBe([true, true, true])
        ->and([$developer->can('delete', $theirsDb), $developer->can('restore', $theirsDb)])->toBe([false, false]);
});

test('an admin is not limited to what it added', function () {
    ['team' => $team, 'shared' => $shared, 'theirs' => $theirs] = developerOwnershipSetup();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value, 'sees_everything' => true]);

    expect([$admin->can('update', $shared), $admin->can('delete', $theirs)])->toBe([true, true]);
});

test("a developer's token puts into maintenance only the apps it added", function () {
    Queue::fake();
    ['team' => $team, 'developer' => $developer, 'theirs' => $theirs, 'mine' => $mine] = developerOwnershipSetup();
    app(TeamAccess::class)->grant($team, $developer, $theirs);
    $token = $developer->createToken('dev', [Ability::UpdateApplication->value]);
    ApiToken::whereKey($token->accessToken->getKey())->update(['team_id' => $team->getKey()]);
    $headers = ['Authorization' => 'Bearer ' . $token->plainTextToken, 'Accept' => 'application/json'];

    $this->withHeaders($headers)->putJson("/api/v1/applications/{$theirs->id}/maintenance", ['enabled' => true])->assertForbidden();
    $this->withHeaders($headers)->putJson("/api/v1/applications/{$mine->id}/maintenance", ['enabled' => true])->assertOk();
});

test("a developer may create servers on the team's cloud accounts, as an admin may", function () {
    ['team' => $team, 'developer' => $developer] = developerOwnershipSetup();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value, 'sees_everything' => true]);

    $this->actingAs($developer)->post(route('servers.cloud.store'), [])->assertSessionHasErrors('cloud_provider_id');
    $this->actingAs($admin)->post(route('servers.cloud.store'), [])->assertSessionHasErrors('cloud_provider_id');
});

test('a developer creates a project, as an admin can', function () {
    ['team' => $team, 'developer' => $developer] = developerOwnershipSetup();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value, 'sees_everything' => true]);

    $this->actingAs($developer)->post(route('projects.store'), ['name' => 'Side project'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(route('projects.store'), ['name' => 'Launch'])->assertSessionHasNoErrors();
});

test("a developer adding a server is offered the team's cloud accounts, but cannot connect new ones", function () {
    ['team' => $team, 'developer' => $developer] = developerOwnershipSetup();
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner Cloud']);

    $this->actingAs($developer)->get(route('servers.create'))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('cloud.accounts', fn($accounts): bool => collect($accounts)->pluck('name')->all() === ['Hetzner Cloud'])
            ->where('cloud.types', [])
            ->where('cloud.selected', 'account:' . $account->id)
            ->where('cloud.can_connect', false));
});

test('a shared server\'s page lists only the apps and databases the developer can see, and counts only those', function () {
    ['developer' => $developer, 'shared' => $shared] = developerOwnershipSetup();

    $this->actingAs($developer)->get(route('servers.show', $shared))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('applications', fn($apps): bool => collect($apps)->pluck('name')->all() === ['notes']));

    $this->actingAs($developer)->get(route('servers.databases.index', $shared))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('databases', fn($databases): bool => collect($databases)->pluck('name')->all() === ['notes_db']));
});

test('the fleet, explorer and command palette show a developer only its own apps on a shared server', function () {
    ['developer' => $developer] = developerOwnershipSetup();

    $this->actingAs($developer)->get(route('dashboard'))
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];
            $names = collect(data_get($props, 'servers'))->flatMap(fn(array $server): array => collect($server['applications'] ?? [])->pluck('name')->all())->all();
            $counts = collect(data_get($props, 'explorer.servers'))->pluck('apps', 'name')->all();

            expect($names)->not->toContain('miagraph')
                ->and(json_encode($props))->not->toContain('miagraph')
                ->and($counts['beelink'] ?? null)->not->toBe(2);
        });
});
