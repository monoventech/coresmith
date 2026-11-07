<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\CommandPalette;

function paletteMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function paletteCommands(User $user, ?Team $team): array
{
    return (new CommandPalette($user, $team))->commands();
}

function paletteLabels(User $user, ?Team $team): array
{
    return array_column(paletteCommands($user, $team), 'label');
}

test('a user without a team still gets the global commands', function () {
    expect(paletteCommands(User::factory()->create(), null))->toBe([
        ['label' => 'Switch theme', 'icon' => 'sun-moon', 'hint' => 'light / dark', 'action' => 'theme'],
        ['label' => 'Deployments', 'icon' => 'rocket', 'hint' => 'fleet-wide history', 'href' => route('deployments.index')],
        ['label' => 'API tokens', 'icon' => 'key-round', 'hint' => 'settings', 'href' => route('api-tokens.index')],
    ]);
});

test('billing is described as the cloud plan on coresmith cloud', function () {
    config(['coresmith.edition' => 'cloud']);

    $billing = collect(paletteCommands(User::factory()->create(), null))->firstWhere('label', 'Billing');

    expect($billing['hint'])->toBe('Coresmith Cloud');
});

test('billing on cloud says what the team is on', function () {
    config(['coresmith.edition' => 'cloud']);
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $trial = Team::factory()->onTrial(9)->owned()->create();
    $hint = fn(Team $team): string => collect(paletteCommands(User::query()->findOrFail($team->owner_id), $team))->firstWhere('label', 'Billing')['hint'];

    expect($hint($pro))->toBe('Pro · $19 / month')
        ->and($hint($trial))->toBe('trial · 9 days left');
});

test('billing on cloud shows what the team pays when stripe has said', function () {
    config(['coresmith.edition' => 'cloud']);
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $team->subscription('default')->forceFill(['unit_amount' => 1500, 'currency' => 'usd'])->save();

    expect(collect(paletteCommands(User::query()->findOrFail($team->owner_id), $team->fresh()))->firstWhere('label', 'Billing')['hint'])->toBe('Pro · $15 / month');
});

test('a healthy server is opened with its environment and app count', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active, 'environment' => 'Production']);
    Application::factory()->for($team)->for($server)->count(2)->create();

    $open = collect(paletteCommands(paletteMember($team, TeamRole::Member), $team))->firstWhere('label', 'Open web-1');

    expect($open)->toBe(['label' => 'Open web-1', 'icon' => 'server', 'hint' => 'production · 2 apps', 'href' => route('servers.show', $server)]);
});

test('a healthy server without an environment just counts its apps', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active, 'environment' => null]);
    Application::factory()->for($team)->for($server)->create();

    $open = collect(paletteCommands(paletteMember($team, TeamRole::Member), $team))->firstWhere('label', 'Open web-1');

    expect($open['hint'])->toBe('1 app');
});

test('a server with a problem is opened with a warning and the problem as its hint', function (array $attributes, string $icon, string $hint) {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create(['name' => 'web-1', ...$attributes]);

    $open = collect(paletteCommands(paletteMember($team, TeamRole::Member), $team))->firstWhere('label', 'Open web-1');

    expect($open['icon'])->toBe($icon)->and($open['hint'])->toBe($hint);
})->with([
    'host key changed' => [['status' => ServerStatus::Active, 'pending_host_key_fingerprint' => 'SHA256:new'], 'triangle-alert', 'host key changed'],
    'unreachable' => [['status' => ServerStatus::Disconnected], 'triangle-alert', 'unreachable'],
    'not connected yet' => [['status' => ServerStatus::Pending], 'server', 'not connected'],
]);

test('only someone who can create servers is offered provisioning', function (TeamRole $role, bool $offered) {
    $team = Team::factory()->create();

    expect(in_array('Provision a server', paletteLabels(paletteMember($team, $role), $team), true))->toBe($offered);
})->with([
    [TeamRole::Owner, true],
    [TeamRole::Admin, true],
    [TeamRole::Member, false],
]);

test('a running deploy is offered first so it can be watched', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Shop']);
    $running = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'branch' => 'main',
        'commit_hash' => 'abc1234def5678',
        'started_at' => now(),
    ]);

    expect(paletteCommands(paletteMember($team, TeamRole::Member), $team)[0])->toBe([
        'label' => 'Watch the running deploy',
        'icon' => 'rocket',
        'hint' => 'Shop · main · abc1234',
        'href' => route('servers.applications.deployments.show', [$server, $application, $running]),
    ]);
});

test('the most recently deployed application can be rolled back to its previous release', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Shop', 'current_release' => '20260913090000']);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260912090000', 'finished_at' => now()->subDay()]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913090000', 'finished_at' => now()]);

    $rollback = collect(paletteCommands(paletteMember($team, TeamRole::Member), $team))->firstWhere('label', 'Roll back Shop');

    expect($rollback)->toBe([
        'label' => 'Roll back Shop',
        'icon' => 'undo-2',
        'hint' => 'to 20260912-090000',
        'href' => route('servers.applications.releases.index', [$server, $application, 'rollback' => '20260912090000']),
    ]);
});

test('no rollback is offered when there is no earlier successful release', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['name' => 'Shop', 'current_release' => '20260913090000']);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'release' => '20260912090000', 'finished_at' => now()->subDay()]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913090000', 'finished_at' => now()]);

    expect(paletteLabels(paletteMember($team, TeamRole::Member), $team))->not->toContain('Roll back Shop');
});

test('every application in the team can be opened, in name order', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    $zebra = Application::factory()->for($team)->for($server)->create(['name' => 'Zebra']);
    $apple = Application::factory()->for($team)->for($server)->create(['name' => 'Apple']);

    $opens = collect(paletteCommands(paletteMember($team, TeamRole::Member), $team))
        ->filter(fn(array $command): bool => $command['icon'] === 'layers')
        ->values()
        ->all();

    expect($opens)->toBe([
        ['label' => 'Open Apple', 'icon' => 'layers', 'hint' => 'on web-1', 'href' => route('servers.applications.show', [$server, $apple])],
        ['label' => 'Open Zebra', 'icon' => 'layers', 'hint' => 'on web-1', 'href' => route('servers.applications.show', [$server, $zebra])],
    ]);
});

test('another teams servers, applications and deploys never appear', function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $server = Server::factory()->for($other)->create(['name' => 'their-server']);
    $application = Application::factory()->for($other)->for($server)->create(['name' => 'Their App', 'current_release' => '2']);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '1', 'finished_at' => now()->subDay()]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '2', 'finished_at' => now()]);

    $labels = paletteLabels(paletteMember($team, TeamRole::Owner), $team);

    expect($labels)->not->toContain('Open their-server')
        ->and($labels)->not->toContain('Open Their App')
        ->and($labels)->not->toContain('Watch the running deploy')
        ->and($labels)->not->toContain('Roll back Their App');
});
