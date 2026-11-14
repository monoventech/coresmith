<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\AccessGrant;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ManagedDatabase;
use App\Models\Project;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ActivityFeed;
use App\Services\Server\SecurityHardening;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Notification::fake();
});

function holdBackPerson(Team $team, TeamRole $role, string $name = 'Ada Obi'): User
{
    $user = User::factory()->create(['name' => $name]);
    $team->members()->attach($user, ['role' => $role->value, 'sees_everything' => false]);

    return $user->fresh();
}

/**
 * @return array{team: Team, owner: User, web: Server, shop: Application, blog: Application, ledger: ManagedDatabase, cache: ManagedDatabase}
 */
function holdBackFleet(array $server = []): array
{
    $team = Team::factory()->create(['name' => 'Acme']);
    $owner = holdBackPerson($team, TeamRole::Owner, 'Maya Lindqvist');
    $web = Server::factory()->for($team)->create(['name' => 'web', ...$server]);

    return [
        'team' => $team,
        'owner' => $owner,
        'web' => $web,
        'shop' => Application::factory()->for($team)->for($web)->create(['name' => 'shop']),
        'blog' => Application::factory()->for($team)->for($web)->create(['name' => 'blog']),
        'ledger' => ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'ledger']),
        'cache' => ManagedDatabase::factory()->for($team)->for($web)->create(['name' => 'cache']),
    ];
}

/**
 * @param  array<string, mixed>  $access
 */
function holdBackSave(User $as, Team $team, User $member, array $access): TestResponse
{
    return test()->actingAs($as)->from(route('teams.index'))->put(route('teams.members.access.update', [$team, $member]), ['everything' => false, ...$access]);
}

test('a server shared with a Deployer brings its apps and databases, apart from the ones held back', function () {
    ['team' => $team, 'owner' => $owner, 'web' => $web, 'blog' => $blog, 'ledger' => $ledger] = holdBackFleet();
    $deployer = holdBackPerson($team, TeamRole::Member);

    holdBackSave($owner, $team, $deployer, ['servers' => [$web->id], 'withheld_applications' => [$blog->id], 'withheld_databases' => [$ledger->id]])->assertSessionHasNoErrors();

    $access = app(TeamAccess::class);

    expect($access->canSeeServer($deployer, $web))->toBeTrue()
        ->and($access->applications($deployer, $team)->pluck('name')->all())->toBe(['shop'])
        ->and($access->applicationsOn($deployer, $web)->pluck('name')->all())->toBe(['shop'])
        ->and($access->canSeeApplication($deployer, $blog))->toBeFalse()
        ->and($access->hidesApplication($deployer, $blog))->toBeTrue()
        ->and($access->databases($deployer, $team)->pluck('name')->all())->toBe(['cache'])
        ->and($access->databasesOn($deployer, $web)->pluck('name')->all())->toBe(['cache'])
        ->and($access->hidesDatabase($deployer, $ledger))->toBeTrue();
});

test('a held-back app and database answer as not found, and the server page leaves them out', function () {
    ['team' => $team, 'owner' => $owner, 'web' => $web, 'blog' => $blog, 'ledger' => $ledger] = holdBackFleet();
    $deployer = holdBackPerson($team, TeamRole::Member);
    holdBackSave($owner, $team, $deployer, ['servers' => [$web->id], 'withheld_applications' => [$blog->id], 'withheld_databases' => [$ledger->id]]);

    $this->actingAs($deployer)->get(route('servers.applications.show', [$web, $blog]))->assertNotFound();
    $this->actingAs($deployer)->get(route('servers.databases.show', [$web, $ledger]))->assertNotFound();
    $this->actingAs($deployer)->get(route('servers.show', $web))->assertOk()->assertDontSee('blog');
    $this->actingAs($deployer)->get(route('servers.databases.index', $web))->assertOk()->assertDontSee('ledger');
});

test('apps and databases added to a shared server later still come with it', function () {
    ['team' => $team, 'owner' => $owner, 'web' => $web, 'blog' => $blog] = holdBackFleet();
    $deployer = holdBackPerson($team, TeamRole::Member);
    holdBackSave($owner, $team, $deployer, ['servers' => [$web->id], 'withheld_applications' => [$blog->id]]);

    $notes = Application::factory()->for($team)->for($web)->create(['name' => 'notes']);

    expect(app(TeamAccess::class)->canSeeApplication($deployer, $notes))->toBeTrue();
});

test('an app shared on its own, or through its environment, stays visible though it is held back under its server', function () {
    ['team' => $team, 'owner' => $owner, 'web' => $web, 'shop' => $shop, 'blog' => $blog] = holdBackFleet();
    $project = Project::factory()->for($team)->create(['name' => 'storefront']);
    $production = Environment::factory()->for($project)->create(['name' => 'production']);
    $shop->forceFill(['environment_id' => $production->id])->save();
    $deployer = holdBackPerson($team, TeamRole::Member);

    holdBackSave($owner, $team, $deployer, ['servers' => [$web->id], 'applications' => [$blog->id], 'environments' => [$production->id], 'withheld_applications' => [$blog->id, $shop->id]])->assertSessionHasNoErrors();

    expect(app(TeamAccess::class)->applicationsOn($deployer, $web)->orderBy('name')->pluck('name')->all())->toBe(['blog', 'shop'])
        ->and(AccessGrant::query()->where('user_id', $deployer->id)->where('withheld', true)->pluck('application_id')->all())->toBe([$shop->id]);
});

test('nothing is held back on a server that is not shared, and nothing from a Developer, whose server shares never bring others\' apps', function () {
    ['team' => $team, 'owner' => $owner, 'web' => $web, 'blog' => $blog, 'ledger' => $ledger] = holdBackFleet();
    $deployer = holdBackPerson($team, TeamRole::Member);
    $developer = holdBackPerson($team, TeamRole::Developer, 'Yomi');

    holdBackSave($owner, $team, $deployer, ['withheld_applications' => [$blog->id], 'withheld_databases' => [$ledger->id]])->assertSessionHasNoErrors();
    holdBackSave($owner, $team, $developer, ['servers' => [$web->id], 'withheld_applications' => [$blog->id]])->assertSessionHasNoErrors();

    expect(AccessGrant::query()->where('withheld', true)->count())->toBe(0);
});

test("a hold-back from an Admin, whose SSH key reaches every app on the server, is refused, except on This machine where nobody's key goes", function () {
    ['team' => $team, 'owner' => $owner, 'web' => $web, 'blog' => $blog] = holdBackFleet();
    $admin = holdBackPerson($team, TeamRole::Admin, 'Kofi Mensah');
    $machine = Server::factory()->for($team)->create(['name' => 'machine', 'runs_coresmith' => true]);
    $panel = Application::factory()->for($team)->for($machine)->create(['name' => 'panel']);

    holdBackSave($owner, $team, $admin, ['servers' => [$web->id], 'withheld_applications' => [$blog->id]])
        ->assertSessionHasErrors(['withheld_applications' => 'Kofi Mensah signs in to web over SSH, which reaches every app and database on it, so nothing there can be held back from them. Untick web and tick the apps to share one by one instead.']);

    holdBackSave($owner, $team, $admin, ['servers' => [$machine->id], 'withheld_applications' => [$panel->id]])->assertSessionHasNoErrors();

    expect(app(TeamAccess::class)->canSeeApplication($admin, $panel))->toBeFalse();
});

test('nobody gets an SSH key on a server where something is held back from them, so a later role change undoes nothing', function () {
    ['team' => $team, 'web' => $web, 'blog' => $blog] = holdBackFleet();
    $admin = holdBackPerson($team, TeamRole::Admin, 'Kofi Mensah');
    $other = holdBackPerson($team, TeamRole::Admin, 'Lena Brandt');
    SshKey::factory()->for($admin)->create();
    SshKey::factory()->for($other)->create();
    AccessGrant::query()->create(['team_id' => $team->id, 'user_id' => $admin->id, 'server_id' => $web->id]);
    AccessGrant::query()->create(['team_id' => $team->id, 'user_id' => $other->id, 'server_id' => $web->id]);
    AccessGrant::query()->create(['team_id' => $team->id, 'user_id' => $admin->id, 'application_id' => $blog->id, 'withheld' => true]);

    $people = array_column(app(SecurityHardening::class)->signInPeople($web), 'name');

    expect($people)->toContain('Lena Brandt')
        ->and($people)->not->toContain('Kofi Mensah');
});

test("the access summary lists what's held back, for the dialog", function () {
    ['team' => $team, 'owner' => $owner, 'web' => $web, 'blog' => $blog, 'ledger' => $ledger] = holdBackFleet();
    $deployer = holdBackPerson($team, TeamRole::Member);
    holdBackSave($owner, $team, $deployer, ['servers' => [$web->id], 'withheld_applications' => [$blog->id], 'withheld_databases' => [$ledger->id]]);

    expect(app(TeamAccess::class)->summary($team, $deployer))->toMatchArray([
        'everything' => false,
        'servers' => [$web->id],
        'applications' => [],
        'withheld_applications' => [$blog->id],
        'withheld_databases' => [$ledger->id],
    ]);
});

test("the server's activity leaves out what's held back, and what a Developer can't see", function () {
    ['team' => $team, 'owner' => $owner, 'web' => $web, 'shop' => $shop, 'blog' => $blog, 'ledger' => $ledger] = holdBackFleet();
    $deployer = holdBackPerson($team, TeamRole::Member);
    $developer = holdBackPerson($team, TeamRole::Developer, 'Yomi');
    holdBackSave($owner, $team, $deployer, ['servers' => [$web->id], 'withheld_applications' => [$blog->id], 'withheld_databases' => [$ledger->id]]);
    holdBackSave($owner, $team, $developer, ['servers' => [$web->id]]);
    activity()->performedOn($shop)->log('application_created');
    activity()->performedOn($blog)->log('application_created');
    activity()->performedOn($ledger)->log('database_created');

    $seen = fn(User $viewer): string => json_encode(app(ActivityFeed::class)->forServer($web, viewer: $viewer));

    expect($seen($owner))->toContain('blog')->toContain('ledger')
        ->and($seen($deployer))->toContain('shop')->not->toContain('blog')->not->toContain('ledger')
        ->and($seen($developer))->not->toContain('shop')->not->toContain('blog')->not->toContain('ledger');
});

test('the Team page offers each server with its apps and databases by name, and says which one is This machine', function () {
    ['team' => $team, 'owner' => $owner, 'ledger' => $ledger, 'cache' => $cache] = holdBackFleet();
    Server::factory()->for($team)->create(['name' => 'machine', 'runs_coresmith' => true]);

    $this->actingAs($owner)->get(route('teams.index'))->assertInertia(fn($page) => $page
        ->where('shareable.servers.0.name', 'machine')
        ->where('shareable.servers.0.this_machine', true)
        ->where('shareable.servers.1.name', 'web')
        ->where('shareable.servers.1.this_machine', false)
        ->where('shareable.servers.1.applications', [['id' => Application::query()->where('name', 'blog')->value('id'), 'name' => 'blog'], ['id' => Application::query()->where('name', 'shop')->value('id'), 'name' => 'shop']])
        ->where('shareable.servers.1.databases', [['id' => $cache->id, 'name' => 'cache'], ['id' => $ledger->id, 'name' => 'ledger']])
        ->etc());
});
