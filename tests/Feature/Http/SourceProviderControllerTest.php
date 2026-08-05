<?php

declare(strict_types=1);

use App\Enums\SourceProviderType;
use App\Enums\TeamRole;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function providerUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('the page lists the teams connections without leaking any credential', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create([
        'type' => SourceProviderType::GitHubApp->value,
        'name' => 'oseughu',
        'access_token' => 'ghs_supersecret',
        'refresh_token' => 'rt_supersecret',
        'app_private_key' => '-----BEGIN RSA PRIVATE KEY-----secret',
    ]);

    $response = $this->actingAs(providerUser($team, TeamRole::Owner))
        ->get(route('source-providers.index'))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('settings/SourceProviders')
        ->has('providers', 1)
        ->where('providers.0.name', 'oseughu')
        ->where('providers.0.label', 'GitHub')
        ->where('providers.0.connected', true));

    expect($response->getContent())->not->toContain('ghs_supersecret')
        ->and($response->getContent())->not->toContain('rt_supersecret')
        ->and($response->getContent())->not->toContain('BEGIN RSA PRIVATE KEY');
});

test('the page advertises the exact scopes each provider asks for', function () {
    $team = Team::factory()->create();

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->get(route('source-providers.index'))
        ->assertInertia(fn ($page) => $page
            ->has('connectable', 3)
            ->where('connectable.0.scopes', SourceProviderType::GitHubApp->scopes())
            ->where('connectable.1.scopes', ['read_repository', 'read_api', 'api'])
            ->where('connectable.2.scopes', ['repository', 'webhook', 'repository:admin']));
});

test('a provider whose token was cleared is shown as disconnected', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'access_token' => null]);

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->get(route('source-providers.index'))
        ->assertInertia(fn ($page) => $page->where('providers.0.connected', false));
});

test('a member sees the connections but gets no management controls', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create(['type' => 'gitlab']);

    $this->actingAs(providerUser($team, TeamRole::Member))
        ->get(route('source-providers.index'))
        ->assertInertia(fn ($page) => $page->where('can.manage', false)->has('providers', 1));
});

test('a member cannot disconnect a provider', function () {
    Http::fake();
    $team = Team::factory()->create();
    $source = SourceProvider::factory()->for($team)->create(['type' => 'gitlab']);

    $this->actingAs(providerUser($team, TeamRole::Member))
        ->delete(route('source-providers.destroy', $source))
        ->assertForbidden();

    expect(SourceProvider::find($source->getKey()))->not->toBeNull();
    Http::assertNothingSent();
});

test('another teams provider cannot be disconnected', function () {
    Http::fake();
    $source = SourceProvider::factory()->for(Team::factory()->create())->create(['type' => 'gitlab']);

    $this->actingAs(providerUser(Team::factory()->create(), TeamRole::Owner))
        ->delete(route('source-providers.destroy', $source))
        ->assertForbidden();

    expect(SourceProvider::find($source->getKey()))->not->toBeNull();
});

test('disconnecting revokes the token at the provider and erases it locally', function () {
    Http::fake(['gitlab.com/oauth/revoke' => Http::response([])]);
    $team = Team::factory()->create();
    $source = SourceProvider::factory()->for($team)->create([
        'type' => 'gitlab',
        'access_token' => 'to-be-revoked',
        'refresh_token' => 'also-gone',
    ]);

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->delete(route('source-providers.destroy', $source))
        ->assertRedirect();

    $trashed = SourceProvider::withTrashed()->find($source->getKey());

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->access_token)->toBeNull()
        ->and($trashed->refresh_token)->toBeNull()
        ->and($trashed->token_expires_at)->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth/revoke'));
});

test('a provider that refuses the revoke call is still disconnected locally', function () {
    Http::fake(['gitlab.com/oauth/revoke' => Http::response(['error' => 'invalid_token'], 401)]);
    $team = Team::factory()->create();
    $source = SourceProvider::factory()->for($team)->create(['type' => 'gitlab', 'access_token' => 'dead']);

    $this->actingAs(providerUser($team, TeamRole::Owner))
        ->delete(route('source-providers.destroy', $source))
        ->assertRedirect();

    expect(SourceProvider::withTrashed()->find($source->getKey())->access_token)->toBeNull();
});

test('a user with no team gets a not found rather than an error', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('source-providers.index'))
        ->assertNotFound();
});

test('the page is behind authentication', function () {
    $this->get(route('source-providers.index'))->assertRedirect(route('login'));
});
