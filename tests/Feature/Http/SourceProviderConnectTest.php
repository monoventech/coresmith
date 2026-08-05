<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Http\Controllers\Settings\SourceProviderConnectController;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function connector(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function configureGitLab(): void
{
    config(['services.gitlab.client_id' => 'client-abc', 'services.gitlab.client_secret' => 'secret-xyz']);
}

beforeEach(function () {
    config(['services.gitlab.client_id' => null, 'services.bitbucket.client_id' => null, 'services.github.client_id' => null]);
});

test('connecting a provider the instance has no credentials for is a not found', function () {
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Owner))
        ->get(route('source-providers.connect', 'gitlab'))
        ->assertNotFound();
});

test('an unknown provider name is a not found', function (string $type) {
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Owner))
        ->get(route('source-providers.connect', $type))
        ->assertNotFound();
})->with(['github', 'gitea', 'custom', '../../etc/passwd']);

test('a member cannot start a connection', function () {
    configureGitLab();
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Member))
        ->get(route('source-providers.connect', 'gitlab'))
        ->assertForbidden();
});

test('starting a connection sends the user to the provider with the minimum scopes', function () {
    configureGitLab();
    $team = Team::factory()->create();

    $response = $this->actingAs(connector($team, TeamRole::Owner))
        ->get(route('source-providers.connect', 'gitlab'))
        ->assertRedirect();

    $target = $response->headers->get('Location');
    parse_str((string) parse_url((string) $target, PHP_URL_QUERY), $query);

    expect($target)->toStartWith('https://gitlab.com/oauth/authorize')
        ->and($query['scope'])->toBe('read_repository read_api api')
        ->and($query['client_id'])->toBe('client-abc')
        ->and($query['state'])->not->toBeEmpty()
        ->and(session(SourceProviderConnectController::STATE_KEY)['state'])->toBe($query['state']);
});

test('the client secret is never put in the redirect', function () {
    configureGitLab();
    $team = Team::factory()->create();

    $response = $this->actingAs(connector($team, TeamRole::Owner))
        ->get(route('source-providers.connect', 'gitlab'));

    expect($response->headers->get('Location'))->not->toContain('secret-xyz');
});

test('a callback without matching state is refused and nothing is stored', function () {
    configureGitLab();
    Http::fake();
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Owner))
        ->withSession([SourceProviderConnectController::STATE_KEY => ['state' => 'the-real-state', 'type' => 'gitlab', 'team' => $team->getKey()]])
        ->get(route('source-providers.callback', 'gitlab').'?code=abc&state=forged')
        ->assertRedirect(route('source-providers.index'))
        ->assertSessionHas('error');

    expect(SourceProvider::count())->toBe(0);
    Http::assertNothingSent();
});

test('a callback with no state in the session at all is refused', function () {
    configureGitLab();
    Http::fake();
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Owner))
        ->get(route('source-providers.callback', 'gitlab').'?code=abc&state=anything')
        ->assertSessionHas('error');

    expect(SourceProvider::count())->toBe(0);
});

test('state issued for one provider cannot be replayed against another', function () {
    configureGitLab();
    config(['services.bitbucket.client_id' => 'bb']);
    Http::fake();
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Owner))
        ->withSession([SourceProviderConnectController::STATE_KEY => ['state' => 'shared', 'type' => 'gitlab', 'team' => $team->getKey()]])
        ->get(route('source-providers.callback', 'bitbucket').'?code=abc&state=shared')
        ->assertSessionHas('error');

    expect(SourceProvider::count())->toBe(0);
});

test('the state is single use', function () {
    configureGitLab();
    Http::fake(['gitlab.com/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 7200])]);
    $team = Team::factory()->create();
    $user = connector($team, TeamRole::Owner);
    $session = [SourceProviderConnectController::STATE_KEY => ['state' => 'once', 'type' => 'gitlab', 'team' => $team->getKey()]];

    $this->actingAs($user)->withSession($session)
        ->get(route('source-providers.callback', 'gitlab').'?code=abc&state=once')
        ->assertSessionHas('success');

    $this->actingAs($user)
        ->get(route('source-providers.callback', 'gitlab').'?code=abc&state=once')
        ->assertSessionHas('error');

    expect(SourceProvider::count())->toBe(1);
});

test('a user who declined at the provider gets a plain message and nothing is stored', function () {
    configureGitLab();
    Http::fake();
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Owner))
        ->withSession([SourceProviderConnectController::STATE_KEY => ['state' => 'ok', 'type' => 'gitlab', 'team' => $team->getKey()]])
        ->get(route('source-providers.callback', 'gitlab').'?error=access_denied&state=ok')
        ->assertSessionHas('error');

    expect(SourceProvider::count())->toBe(0);
    Http::assertNothingSent();
});

test('a successful callback stores the tokens encrypted with the granted scopes', function () {
    configureGitLab();
    Http::fake([
        'gitlab.com/oauth/token' => Http::response(['access_token' => 'at-live', 'refresh_token' => 'rt-live', 'expires_in' => 7200]),
        'gitlab.com/api/v4/user' => Http::response(['username' => 'ose-ughu']),
    ]);
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Owner))
        ->withSession([SourceProviderConnectController::STATE_KEY => ['state' => 'ok', 'type' => 'gitlab', 'team' => $team->getKey()]])
        ->get(route('source-providers.callback', 'gitlab').'?code=the-code&state=ok')
        ->assertRedirect(route('source-providers.index'))
        ->assertSessionHas('success');

    $source = SourceProvider::firstOrFail();

    expect($source->name)->toBe('ose-ughu')
        ->and($source->access_token)->toBe('at-live')
        ->and($source->refresh_token)->toBe('rt-live')
        ->and($source->scopes)->toBe(['read_repository', 'read_api', 'api'])
        ->and($source->token_expires_at->isFuture())->toBeTrue()
        ->and($source->team_id)->toBe($team->getKey());
});

test('a provider that refuses the code exchange stores nothing', function () {
    configureGitLab();
    Http::fake(['gitlab.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Owner))
        ->withSession([SourceProviderConnectController::STATE_KEY => ['state' => 'ok', 'type' => 'gitlab', 'team' => $team->getKey()]])
        ->get(route('source-providers.callback', 'gitlab').'?code=bad&state=ok')
        ->assertSessionHas('error');

    expect(SourceProvider::count())->toBe(0);
});

test('reconnecting replaces the stored credentials rather than piling up connections', function () {
    configureGitLab();
    Http::fake([
        'gitlab.com/oauth/token' => Http::response(['access_token' => 'at-second', 'refresh_token' => 'rt-second', 'expires_in' => 7200]),
        'gitlab.com/api/v4/user' => Http::response(['username' => 'ose-ughu']),
    ]);
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'access_token' => 'at-first']);

    $this->actingAs(connector($team, TeamRole::Owner))
        ->withSession([SourceProviderConnectController::STATE_KEY => ['state' => 'ok', 'type' => 'gitlab', 'team' => $team->getKey()]])
        ->get(route('source-providers.callback', 'gitlab').'?code=c&state=ok');

    expect(SourceProvider::count())->toBe(1)
        ->and(SourceProvider::first()->access_token)->toBe('at-second');
});

test('a callback naming a team the user has left is refused', function () {
    configureGitLab();
    Http::fake();
    $other = Team::factory()->create();
    $team = Team::factory()->create();

    $this->actingAs(connector($team, TeamRole::Owner))
        ->withSession([SourceProviderConnectController::STATE_KEY => ['state' => 'ok', 'type' => 'gitlab', 'team' => $other->getKey()]])
        ->get(route('source-providers.callback', 'gitlab').'?code=c&state=ok')
        ->assertForbidden();

    expect(SourceProvider::count())->toBe(0);
});

test('an unauthenticated visitor cannot reach the connect or callback endpoints', function (string $name) {
    $this->get(route($name, 'gitlab'))->assertRedirect(route('login'));
})->with(['source-providers.connect', 'source-providers.callback']);
