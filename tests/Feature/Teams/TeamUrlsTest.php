<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\User;
use App\Services\Teams\TeamSlug;
use Illuminate\Support\Facades\Route;

function teamUrlsMember(Team $team, ?User $user = null, TeamRole $role = TeamRole::Owner): User
{
    $user ??= User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function teamUrlsTwoTeams(): array
{
    $monoven = Team::factory()->create(['name' => 'Monoven Ltd', 'slug' => 'monoven']);
    $acme = Team::factory()->create(['name' => 'Acme Labs', 'slug' => 'acme']);
    $user = teamUrlsMember($monoven);
    $user = teamUrlsMember($acme, $user, TeamRole::Admin);

    Server::factory()->for($monoven)->create(['name' => 'forge-01']);
    Server::factory()->for($acme)->create(['name' => 'acme-web']);

    return [$user, $monoven, $acme];
}

function teamUrlsInertia(): array
{
    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
}

test('a new team gets an address from its name, numbered when taken and never a reserved word', function () {
    expect(Team::factory()->create(['name' => 'Monoven Ltd'])->slug)->toBe('monoven-ltd')
        ->and(Team::factory()->create(['name' => 'Monoven Ltd'])->slug)->toBe('monoven-ltd-2')
        ->and(Team::factory()->create(['name' => 'Settings'])->slug)->toBe('settings-2')
        ->and(Team::factory()->create(['name' => '!!!'])->slug)->toBe('team');
});

test('an address that matches a route is reserved', function (string $slug) {
    expect(TeamSlug::isReserved($slug))->toBeTrue()
        ->and(TeamSlug::isAvailable($slug))->toBeFalse();
})->with(['dashboard', 'servers', 'deployments', 'settings', 'login', 'api', 'install', 'current-team']);

test('an address must be lowercase letters, numbers and single hyphens', function (string $slug, bool $valid) {
    expect(TeamSlug::isValid($slug))->toBe($valid);
})->with([
    ['monoven', true],
    ['monoven-labs', true],
    ['a', true],
    ['Monoven', false],
    ['-monoven', false],
    ['monoven-', false],
    ["monoven\n", false],
    ['mono--ven', false],
    ['mono_ven', false],
    ['mono.ven', false],
    [str_repeat('a', 41), false],
]);

test('without an address in the url the page follows the team the user last worked in', function () {
    [$user,, $acme] = teamUrlsTwoTeams();
    $user->forceFill(['current_team_id' => $acme->id])->save();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('Dashboard')
            ->where('panel.team.slug', 'acme')
            ->where('panel.base', '')
            ->has('servers', 1)
            ->where('servers.0.name', 'acme-web'));
});

test('an address in the path picks the team and every link keeps it', function () {
    [$user, $monoven, $acme] = teamUrlsTwoTeams();
    $user->forceFill(['current_team_id' => $monoven->id])->save();
    $server = $acme->servers()->first();

    $this->actingAs($user)
        ->get('/acme/dashboard')
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('Dashboard')
            ->url('/acme/dashboard')
            ->where('panel.team.slug', 'acme')
            ->where('panel.base', '/acme')
            ->where('servers.0.name', 'acme-web')
            ->where('servers.0.href', 'http://localhost/acme/servers/' . $server->id));

    expect($user->fresh()->current_team_id)->toBe($acme->id);
});

test('a team address on its own goes to that team fleet', function () {
    [$user] = teamUrlsTwoTeams();

    $this->actingAs($user)->get('/acme')->assertRedirect('http://localhost/acme/dashboard');
    $this->actingAs($user)->get('/acme/?tab=1')->assertRedirect('http://localhost/acme/dashboard?tab=1');
});

test('someone outside a team cannot tell its address exists', function () {
    [,, $acme] = teamUrlsTwoTeams();
    $stranger = teamUrlsMember(Team::factory()->create());

    $this->actingAs($stranger)->get('/acme/dashboard')->assertNotFound();
    $this->actingAs($stranger)->get('/no-such-team/dashboard')->assertNotFound();
});

test('a guest opening a team address is sent to sign in and comes back to it', function () {
    [$user] = teamUrlsTwoTeams();

    $this->get('/acme/deployments')->assertRedirect('http://localhost/acme/login');

    $this->post('/acme/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('http://localhost/acme/deployments');
});

test('signing in on another team address signs the account straight back out and says why', function () {
    teamUrlsTwoTeams();
    $stranger = teamUrlsMember(Team::factory()->create());

    $this->post('/acme/login', ['email' => $stranger->email, 'password' => 'password'])
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHasErrors(['email' => "That account isn't a member of Acme Labs. Sign in with one that is, or ask an owner to invite you."]);

    $this->assertGuest();
});

test('the refusal waits until two factor is passed, so only the account holder learns of it', function () {
    teamUrlsTwoTeams();
    $stranger = teamUrlsMember(Team::factory()->create());
    $stranger->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post('/acme/login', ['email' => $stranger->email, 'password' => 'password'])
        ->assertRedirect('http://localhost/acme/two-factor-challenge')
        ->assertSessionDoesntHaveErrors();

    $this->post('/acme/two-factor-challenge', ['recovery_code' => 'code1'])
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('signing in on a team domain as someone outside the team is refused the same way', function () {
    [,, $acme] = teamUrlsTwoTeams();
    TeamDomain::factory()->for($acme)->verified()->create(['hostname' => 'panel.acme.test']);
    $stranger = teamUrlsMember(Team::factory()->create());

    $this->post('http://panel.acme.test/login', ['email' => $stranger->email, 'password' => 'password'])
        ->assertRedirect('http://panel.acme.test/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('someone no longer in a team can still sign out on its address', function () {
    [,, $acme] = teamUrlsTwoTeams();
    TeamDomain::factory()->for($acme)->verified()->create(['hostname' => 'panel.acme.test']);
    $stranger = teamUrlsMember(Team::factory()->create());

    $this->actingAs($stranger)->get('http://panel.acme.test/dashboard')->assertNotFound();
    $this->actingAs($stranger)->post('http://panel.acme.test/logout')->assertRedirect();

    $this->assertGuest();
});

test('a server from another team is not found under a team address, even for someone in both', function () {
    [$user, $monoven] = teamUrlsTwoTeams();
    $server = $monoven->servers()->first();

    $this->actingAs($user)->get('/acme/servers/' . $server->id)->assertNotFound();
    $this->actingAs($user)->get('/monoven/servers/' . $server->id)->assertOk();
});

test('without an address a server opens in its own team and that team becomes current', function () {
    [$user, $monoven, $acme] = teamUrlsTwoTeams();
    $user->forceFill(['current_team_id' => $monoven->id])->save();

    $this->actingAs($user)
        ->get('/servers/' . $acme->servers()->first()->id)
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('panel.team.slug', 'acme'));

    expect($user->fresh()->current_team_id)->toBe($acme->id);
});

test('switching team remembers it and sends the browser to that team', function () {
    [$user, $monoven, $acme] = teamUrlsTwoTeams();
    $user->forceFill(['current_team_id' => $monoven->id])->save();

    $this->actingAs($user)
        ->withHeaders(teamUrlsInertia())
        ->put(route('current-team.update'), ['team' => $acme->id])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'http://localhost/acme/dashboard');

    expect($user->fresh()->current_team_id)->toBe($acme->id);
});

test('nobody can switch into a team they are not in', function () {
    [$user] = teamUrlsTwoTeams();
    $other = Team::factory()->create();

    $this->actingAs($user)->put(route('current-team.update'), ['team' => $other->id])->assertNotFound();
    $this->actingAs($user)->put(route('current-team.update'), [])->assertSessionHasErrors('team');

    expect($user->fresh()->current_team_id)->not->toBe($other->id);
});

test('signing in lands on the current team fleet unless a page was waiting', function () {
    [$user,, $acme] = teamUrlsTwoTeams();
    $user->forceFill(['current_team_id' => $acme->id])->save();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('http://localhost/acme/dashboard');
});

test('the switcher lists every team with its address and marks the current one', function () {
    [$user,, $acme] = teamUrlsTwoTeams();

    $this->actingAs($user)
        ->get('/acme/dashboard')
        ->assertInertia(fn($page) => $page
            ->has('panel.teams', 2)
            ->where('panel.teams.0.slug', 'acme')
            ->where('panel.teams.0.current', true)
            ->where('panel.teams.1.slug', 'monoven')
            ->where('panel.teams.1.current', false));
});

test('a team subdomain picks the team when the instance has a tenant domain', function () {
    config(['coresmith.tenant_domain' => 'coresmith.test']);
    [$user,, $acme] = teamUrlsTwoTeams();
    $server = $acme->servers()->first();

    $this->actingAs($user)
        ->get('http://acme.coresmith.test/dashboard')
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('panel.team.slug', 'acme')
            ->where('panel.base', '')
            ->where('panel.viaHost', true)
            ->where('servers.0.href', 'http://acme.coresmith.test/servers/' . $server->id));
});

test('a subdomain means nothing when no tenant domain is configured', function () {
    config(['coresmith.tenant_domain' => null]);
    [$user, $monoven] = teamUrlsTwoTeams();
    $user->forceFill(['current_team_id' => $monoven->id])->save();

    $this->actingAs($user)
        ->get('http://acme.coresmith.test/dashboard')
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('panel.team.slug', 'monoven')->where('panel.viaHost', false));
});

test('a verified custom domain picks its team and an unverified one does not', function () {
    [$user, $monoven, $acme] = teamUrlsTwoTeams();
    $user->forceFill(['current_team_id' => $monoven->id])->save();
    TeamDomain::factory()->for($acme)->verified()->create(['hostname' => 'panel.acme.test']);
    TeamDomain::factory()->for($monoven)->create(['hostname' => 'panel.monoven.test']);

    $this->actingAs($user)
        ->get('http://panel.acme.test/deployments')
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('panel.team.slug', 'acme')->where('panel.viaHost', true));

    $this->actingAs($user)
        ->get('http://panel.monoven.test/deployments')
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('panel.viaHost', false));
});

test('a team host does not serve another team resources', function () {
    [$user, $monoven, $acme] = teamUrlsTwoTeams();
    TeamDomain::factory()->for($acme)->verified()->create(['hostname' => 'panel.acme.test']);

    $this->actingAs($user)->get('http://panel.acme.test/servers/' . $monoven->servers()->first()->id)->assertNotFound();
});

test('assets are served from the host root, never under a team address', function () {
    Route::middleware('web')->get('asset-probe', fn() => asset('build/app.js'));
    TeamSlug::forgetRouteSegments();
    [$user] = teamUrlsTwoTeams();

    $this->actingAs($user)->get('/acme/asset-probe')->assertOk()->assertSee('http://localhost/build/app.js', false);
});

test('oauth callbacks stay on the canonical address when the connection starts under a team address', function () {
    config(['services.gitlab.client_id' => 'gitlab-client', 'services.gitlab.client_secret' => 'secret']);
    [$user] = teamUrlsTwoTeams();

    $location = $this->actingAs($user)->get('/acme/settings/source-providers/gitlab/connect')->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($query['redirect_uri'])->toBe('http://localhost/settings/source-providers/gitlab/callback');
});

test('a git connection attaches to the team being worked in, not the first one joined', function () {
    config(['services.gitlab.client_id' => 'gitlab-client', 'services.gitlab.client_secret' => 'secret']);
    $first = Team::factory()->create(['slug' => 'first']);
    $second = Team::factory()->create(['slug' => 'second']);
    $user = teamUrlsMember($first, null, TeamRole::Member);
    $user = teamUrlsMember($second, $user, TeamRole::Owner);

    $this->actingAs($user)->get('/second/settings/source-providers/gitlab/connect')->assertRedirect();

    expect(session('source_provider_state.team'))->toBe($second->id);

    $this->actingAs($user)->get('/first/settings/source-providers/gitlab/connect')->assertForbidden();
});

test('an api token is issued for the team being worked in', function () {
    [$user, $monoven, $acme] = teamUrlsTwoTeams();

    $this->actingAs($user)
        ->post('/acme/settings/api-tokens', ['name' => 'ci', 'abilities' => ['server.view']])
        ->assertRedirect();

    expect($user->tokens()->latest('id')->first()->team_id)->toBe($acme->id)
        ->and($user->tokens()->where('team_id', $monoven->id)->exists())->toBeFalse();
});

test('an owner changes the team address and the old one stops working', function () {
    [$user,, $acme] = teamUrlsTwoTeams();

    $this->actingAs($user)
        ->withHeaders(teamUrlsInertia())
        ->put('/acme/settings/team/url', ['slug' => 'Acme-Labs'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'http://localhost/acme-labs/settings/team');

    expect($acme->fresh()->slug)->toBe('acme-labs');

    $this->actingAs($user)->get('/acme/dashboard')->assertNotFound();
    $this->actingAs($user)->get('/acme-labs/dashboard')->assertOk();
});

test('a team address that is invalid, reserved or taken is refused with a reason', function (string $slug, string $message) {
    [$user] = teamUrlsTwoTeams();

    $this->actingAs($user)
        ->put('/acme/settings/team/url', ['slug' => $slug])
        ->assertSessionHasErrors(['slug' => $message]);
})->with([
    'invalid' => ['acme labs', 'Use lowercase letters, numbers and single hyphens, like monoven-labs.'],
    'reserved' => ['dashboard', 'That address is reserved. Try another one.'],
    'taken' => ['monoven', 'Another team already uses that address.'],
]);

test('a deployer cannot change the team address', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    $member = teamUrlsMember($team, null, TeamRole::Member);

    $this->actingAs($member)->put('/acme/settings/team/url', ['slug' => 'elsewhere'])->assertForbidden();

    expect($team->fresh()->slug)->toBe('acme');
});
