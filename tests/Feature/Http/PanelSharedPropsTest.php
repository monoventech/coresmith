<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Application;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

function panelPropsUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function panelPropsPartialHeaders(string $only): array
{
    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Dashboard',
        'X-Inertia-Partial-Data' => $only,
    ];
}

test('the panel carries the current team, the users role in it and setup progress', function () {
    $team = Team::factory()->create(['name' => 'Monoven']);
    SourceProvider::factory()->for($team)->create();

    $this->actingAs(panelPropsUser($team, TeamRole::Member))
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->where('panel.team', ['id' => $team->id, 'name' => 'Monoven', 'slug' => $team->slug, 'role' => 'Deployer'])
            ->where('panel.setup', ['complete' => false, 'current' => 1, 'total' => 4])
            ->where('panel.attention', 0)
            ->where('panel.can', ['createServer' => false]));
});

test('every team the user belongs to is listed with its role and size', function () {
    $user = User::factory()->create();
    $first = Team::factory()->create(['name' => 'First']);
    $second = Team::factory()->create(['name' => 'Second']);
    $first->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $second->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $first->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);
    Server::factory()->for($first)->count(2)->create();
    Server::factory()->for($second)->create();

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->where('panel.team.id', $first->id)
            ->where('panel.team.role', 'Owner')
            ->where('panel.teams', [
                ['id' => $first->id, 'name' => 'First', 'slug' => 'first', 'role' => 'Owner', 'servers' => 2, 'members' => 2, 'current' => true],
                ['id' => $second->id, 'name' => 'Second', 'slug' => 'second', 'role' => 'Admin', 'servers' => 1, 'members' => 1, 'current' => false],
            ])
            ->where('panel.can.createServer', true));
});

test('the current team is listed first and the rest follow by name', function () {
    $user = User::factory()->create();
    $alpha = Team::factory()->create(['name' => 'Alpha']);
    $beta = Team::factory()->create(['name' => 'Beta']);
    $gamma = Team::factory()->create(['name' => 'Gamma']);

    foreach ([$gamma, $alpha, $beta] as $team) {
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    }

    $user->forceFill(['current_team_id' => $gamma->id])->save();

    $this->actingAs($user->fresh())
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page
            ->where('panel.teams.0.name', 'Gamma')
            ->where('panel.teams.0.current', true)
            ->where('panel.teams.1.name', 'Alpha')
            ->where('panel.teams.2.name', 'Beta'));
});

test('the panel counts what needs attention in the current team', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Disconnected]);
    Application::factory()->for($team)->for($server)->create(['power_state' => PowerState::Stopped]);
    Server::factory()->for(Team::factory())->create(['status' => ServerStatus::Failed]);

    $this->actingAs(panelPropsUser($team, TeamRole::Admin))
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('panel.attention', 2));
});

test('a user without a team gets an empty panel', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('panel', [
            'team' => null,
            'teams' => [],
            'base' => '',
            'viaHost' => false,
            'setup' => null,
            'attention' => 0,
            'can' => ['createServer' => false],
            'live' => ['streaming' => false, 'refresh_seconds' => 60],
        ]));
});

test('a guest page carries no panel', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('panel', null)->missing('palette'));
});

test('the command palette is not sent on a normal visit', function () {
    $team = Team::factory()->create();

    $this->actingAs(panelPropsUser($team, TeamRole::Owner))
        ->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->has('panel')->missing('palette'));
});

test('a partial reload asking for the palette receives the users commands and nothing else', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    Server::factory()->for(Team::factory())->create(['name' => 'theirs']);

    $response = $this->actingAs(panelPropsUser($team, TeamRole::Owner))
        ->get(route('dashboard'), panelPropsPartialHeaders('palette'))
        ->assertOk()
        ->assertHeader('X-Inertia', 'true');

    $labels = array_column($response->json('props.palette'), 'label');

    expect($response->json('component'))->toBe('Dashboard')
        ->and($labels)->toContain('Open web-1')
        ->and($labels)->toContain('Provision a server')
        ->and($labels)->toContain('Switch theme')
        ->and($labels)->not->toContain('Open theirs')
        ->and($response->json('props'))->not->toHaveKey('panel')
        ->and($response->json('props'))->not->toHaveKey('servers');
});

test('a partial reload for other props still leaves the palette out', function () {
    $team = Team::factory()->create();

    $response = $this->actingAs(panelPropsUser($team, TeamRole::Owner))
        ->get(route('dashboard'), panelPropsPartialHeaders('panel'))
        ->assertOk();

    expect($response->json('props'))->toHaveKey('panel')
        ->and($response->json('props'))->not->toHaveKey('palette');
});

test('a user without a team still gets the global palette commands', function () {
    $response = $this->actingAs(User::factory()->create())
        ->get(route('dashboard'), panelPropsPartialHeaders('palette'))
        ->assertOk();

    expect(array_column($response->json('props.palette'), 'label'))->toBe(['Switch theme', 'Deployments', 'API tokens', 'Billing']);
});
