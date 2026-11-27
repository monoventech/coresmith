<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\CommandPalette;
use App\Services\Teams\TeamAccess;
use Inertia\Testing\AssertableInertia;

function paletteMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function paletteItems(User $user, ?Team $team): array
{
    return (new CommandPalette($user, $team))->items();
}

function paletteLabels(User $user, ?Team $team): array
{
    return array_column(paletteItems($user, $team), 'label');
}

function paletteItem(User $user, ?Team $team, string $id): ?array
{
    return collect(paletteItems($user, $team))->firstWhere('id', $id);
}

test('a user without a team gets the theme, the deploys and their own account', function () {
    expect(paletteItems(User::factory()->create(), null))->toBe([
        ['id' => 'action:theme', 'kind' => 'action', 'label' => 'Switch theme', 'icon' => 'sun-moon', 'hint' => 'light / dark', 'action' => 'theme'],
        ['id' => 'page:deployments', 'kind' => 'page', 'label' => 'Deployments', 'icon' => 'rocket', 'hint' => 'fleet-wide history', 'href' => route('deployments.index')],
        ['id' => 'page:api-tokens', 'kind' => 'page', 'label' => 'API tokens', 'icon' => 'key-round', 'hint' => 'settings', 'href' => route('api-tokens.index'), 'keywords' => ['keys']],
        ['id' => 'page:profile', 'kind' => 'page', 'label' => 'Profile', 'icon' => 'user', 'hint' => 'your account', 'href' => route('profile.edit'), 'keywords' => ['name', 'email']],
        ['id' => 'page:security', 'kind' => 'page', 'label' => 'Security', 'icon' => 'shield', 'hint' => 'your account', 'href' => route('security.edit'), 'keywords' => ['password', 'two-factor', 'sessions']],
    ]);
});

test('a team member can jump to every section and settings tab', function () {
    $team = Team::factory()->create();
    $pages = collect(paletteItems(paletteMember($team, TeamRole::Member), $team))->where('kind', 'page');

    expect($pages->pluck('label')->all())->toBe(['Fleet', 'Deployments', 'Releases', 'Projects', 'Settings', 'Team', 'Git', 'Cloud providers', 'Notifications', 'API tokens', 'Profile', 'Security'])
        ->and($pages->firstWhere('label', 'Team')['href'])->toBe(route('teams.index'))
        ->and($pages->firstWhere('label', 'Team')['keywords'])->toContain('members');
});

test('billing is described as the cloud plan on coresmith cloud', function () {
    config(['coresmith.edition' => 'cloud']);

    $billing = collect(paletteItems(User::factory()->create(), null))->firstWhere('label', 'Billing');

    expect($billing['hint'])->toBe('Coresmith Cloud');
});

test('billing on cloud says what the team is on', function () {
    config(['coresmith.edition' => 'cloud']);
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $trial = Team::factory()->onTrial(9)->owned()->create();
    $hint = fn(Team $team): string => collect(paletteItems(User::query()->findOrFail($team->owner_id), $team))->firstWhere('label', 'Billing')['hint'];

    expect($hint($pro))->toBe('Pro · $19 / month')
        ->and($hint($trial))->toBe('trial · 9 days left');
});

test('billing on cloud says when a team is free', function () {
    config(['coresmith.edition' => 'cloud']);
    $forever = Team::factory()->free(Plan::Business)->owned()->create();
    $until = Team::factory()->free(Plan::Pro, now()->addMonths(2)->endOfDay())->owned()->create();
    $hint = fn(Team $team): string => collect(paletteItems(User::query()->findOrFail($team->owner_id), $team))->firstWhere('label', 'Billing')['hint'];

    expect($hint($forever))->toBe('Business · free')
        ->and($hint($until))->toBe('Pro · free until ' . now()->addMonths(2)->format(now()->addMonths(2)->year === now()->year ? 'M j' : 'M j, Y'));
});

test('billing on cloud shows what the team pays when stripe has said', function () {
    config(['coresmith.edition' => 'cloud']);
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $team->subscription('default')->forceFill(['unit_amount' => 1500, 'currency' => 'usd'])->save();

    expect(collect(paletteItems(User::query()->findOrFail($team->owner_id), $team->fresh()))->firstWhere('label', 'Billing')['hint'])->toBe('Pro · $15 / month');
});

test('a healthy server is one row with its app count and the keys its pages are built from', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active, 'tags' => ['eu']]);
    Application::factory()->for($team)->for($server)->count(2)->create();

    expect(paletteItem(paletteMember($team, TeamRole::Member), $team, 'server:' . $server->id))->toBe([
        'id' => 'server:' . $server->id,
        'kind' => 'server',
        'label' => 'web-1',
        'hint' => 'eu · 2 apps',
        'icon' => 'server',
        'href' => route('servers.show', $server),
        'keywords' => ['eu'],
        'keys' => ['server' => (string) $server->getRouteKey()],
        'can' => ['logs' => false, 'terminal' => false, 'root' => false],
    ]);
});

test('an app is one row with its tags and server, so two apps of one name can be told apart', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    $project = Project::factory()->for($team)->create(['name' => 'Shopfront']);
    $live = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'tags' => ['production', 'eu'], 'project_id' => $project->id]);
    $trial = Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'tags' => ['staging']]);

    $shops = collect(paletteItems(paletteMember($team, TeamRole::Member), $team))->where('kind', 'app')->where('label', 'shop')->keyBy('id');

    expect($shops->get('app:' . $live->id)['hint'])->toBe('production · eu · on web-1')
        ->and($shops->get('app:' . $live->id)['keywords'])->toBe(['production', 'eu', 'web-1', 'Shopfront'])
        ->and($shops->get('app:' . $live->id)['keys'])->toBe(['server' => (string) $server->getRouteKey(), 'app' => (string) $live->getRouteKey()])
        ->and($shops->get('app:' . $live->id)['href'])->toBe(route('servers.applications.show', [$server, $live]))
        ->and($shops->get('app:' . $trial->id)['hint'])->toBe('staging · on web-1');
});

test('a server with a problem shows a warning and the problem as its hint', function (array $attributes, string $icon, string $hint) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', ...$attributes]);

    $row = paletteItem(paletteMember($team, TeamRole::Member), $team, 'server:' . $server->id);

    expect($row['icon'])->toBe($icon)->and($row['hint'])->toBe($hint);
})->with([
    'host key changed' => [['status' => ServerStatus::Active, 'pending_host_key_fingerprint' => 'SHA256:new'], 'triangle-alert', 'host key changed'],
    'unreachable' => [['status' => ServerStatus::Disconnected], 'triangle-alert', 'unreachable'],
    'not connected yet' => [['status' => ServerStatus::Pending], 'server', 'not connected'],
]);

test('projects say how many apps they hold, and databases say their engine and server', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    $project = Project::factory()->for($team)->create(['name' => 'Shopfront']);
    Application::factory()->for($team)->for($server)->count(2)->create(['project_id' => $project->id]);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'orders', 'type' => 'postgresql']);
    $owner = paletteMember($team, TeamRole::Owner);

    expect(paletteItem($owner, $team, 'project:' . $project->id))->toBe(['id' => 'project:' . $project->id, 'kind' => 'project', 'label' => 'Shopfront', 'hint' => '2 apps', 'icon' => 'folder', 'href' => route('projects.show', $project)])
        ->and(paletteItem($owner, $team, 'database:' . $database->id))->toBe([
            'id' => 'database:' . $database->id,
            'kind' => 'database',
            'label' => 'orders',
            'hint' => 'PostgreSQL on web-1',
            'icon' => 'database',
            'href' => route('servers.databases.show', [$server, $database]),
            'keywords' => ['PostgreSQL', 'web-1'],
            'keys' => ['server' => (string) $server->getRouteKey()],
        ]);
});

test("each app's and server's pages follow what the person may do there", function (TeamRole $role) {
    $team = Team::factory()->create();
    $user = paletteMember($team, $role);
    $mine = Server::factory()->for($team)->create(['name' => 'mine', 'status' => ServerStatus::Active, 'created_by' => $user->id]);
    $theirs = Server::factory()->for($team)->create(['name' => 'theirs', 'status' => ServerStatus::Active]);
    $applications = [
        Application::factory()->for($team)->for($mine)->create(['name' => 'added', 'current_release' => '20261127-090000', 'created_by' => $user->id]),
        Application::factory()->for($team)->for($mine)->create(['name' => 'other', 'current_release' => '20261127-090000']),
        Application::factory()->for($team)->for($theirs)->create(['name' => 'elsewhere', 'current_release' => '20261127-090000']),
        Application::factory()->for($team)->for($mine)->create(['name' => 'draft', 'current_release' => null]),
    ];
    $rows = collect(paletteItems($user, $team))->keyBy('id');

    foreach ($applications as $application) {
        $row = $rows->get('app:' . $application->id);

        if ($row === null) {
            expect($user->can('view', $application))->toBeFalse();

            continue;
        }

        expect($row['can'])->toBe([
            'logs' => $user->can('viewLogs', $application),
            'setup' => $user->can('update', $application),
            'terminal' => $application->current_release !== null && $user->can('openTerminal', $application->server),
        ]);
    }

    foreach ([$mine, $theirs] as $server) {
        $row = $rows->get('server:' . $server->id);

        if ($row === null) {
            expect($user->can('view', $server))->toBeFalse();

            continue;
        }

        expect($row['can'])->toBe([
            'logs' => $user->can('update', $server),
            'terminal' => $user->can('openTerminal', $server) || $user->can('openRootTerminal', $server),
            'root' => $user->can('openRootTerminal', $server),
        ]);
    }
})->with([
    'an owner' => [TeamRole::Owner],
    'an admin' => [TeamRole::Admin],
    'a developer' => [TeamRole::Developer],
    'a member' => [TeamRole::Member],
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

    expect(paletteItems(paletteMember($team, TeamRole::Member), $team)[0])->toBe([
        'id' => 'action:watch',
        'kind' => 'action',
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

    expect(collect(paletteItems(paletteMember($team, TeamRole::Member), $team))->firstWhere('label', 'Roll back Shop'))->toBe([
        'id' => 'action:rollback',
        'kind' => 'action',
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

test('every app in the team is one row, in name order', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);
    Application::factory()->for($team)->for($server)->create(['name' => 'Zebra']);
    Application::factory()->for($team)->for($server)->create(['name' => 'Apple']);

    expect(collect(paletteItems(paletteMember($team, TeamRole::Member), $team))->where('kind', 'app')->pluck('label')->all())->toBe(['Apple', 'Zebra']);
});

test("another team's servers, apps, databases and deploys never appear", function () {
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $server = Server::factory()->for($other)->create(['name' => 'their-server']);
    $application = Application::factory()->for($other)->for($server)->create(['name' => 'Their App', 'current_release' => '2']);
    ManagedDatabase::factory()->for($other)->for($server)->create(['name' => 'their-db']);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '1', 'finished_at' => now()->subDay()]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '2', 'finished_at' => now()]);

    $labels = paletteLabels(paletteMember($team, TeamRole::Owner), $team);

    expect($labels)->not->toContain('their-server')
        ->and($labels)->not->toContain('Their App')
        ->and($labels)->not->toContain('their-db')
        ->and($labels)->not->toContain('Watch the running deploy')
        ->and($labels)->not->toContain('Roll back Their App');
});

test('a narrowed developer sees only the apps they may see, and their projects', function () {
    $team = Team::factory()->create();
    $developer = paletteMember($team, TeamRole::Developer);
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    $given = Application::factory()->for($team)->for($server)->create(['name' => 'given']);
    Application::factory()->for($team)->for($server)->create(['name' => 'hidden']);
    app(TeamAccess::class)->grant($team, $developer, $given);

    $apps = collect(paletteItems($developer->fresh(), $team))->where('kind', 'app')->pluck('label')->all();

    expect($apps)->toBe(collect(app(TeamAccess::class)->applications($developer->fresh(), $team)->orderBy('name')->pluck('name'))->all());
});

test('every page names the thing it is about, so the palette can offer that first', function () {
    $team = Team::factory()->create();
    $owner = paletteMember($team, TeamRole::Owner);
    $owner->forceFill(['current_team_id' => $team->id])->save();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'shop']);
    $database = ManagedDatabase::factory()->for($team)->for($server)->create(['name' => 'orders']);
    $project = Project::factory()->for($team)->create(['name' => 'Shopfront']);
    $subject = fn(string $url): ?string => $this->actingAs($owner)->get($url)->viewData('page')['props']['subject'] ?? null;

    expect($subject(route('servers.applications.show', [$server, $application])))->toBe('app:' . $application->id)
        ->and($subject(route('servers.applications.releases.index', [$server, $application])))->toBe('app:' . $application->id)
        ->and($subject(route('servers.show', $server)))->toBe('server:' . $server->id)
        ->and($subject(route('servers.hardening', $server)))->toBe('server:' . $server->id)
        ->and($subject(route('servers.databases.show', [$server, $database])))->toBe('database:' . $database->id)
        ->and($subject(route('projects.show', $project)))->toBe('project:' . $project->id)
        ->and($subject(route('dashboard')))->toBeNull();
});
