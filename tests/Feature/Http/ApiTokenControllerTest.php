<?php

declare(strict_types=1);

use App\Enums\Ability;
use App\Enums\TeamRole;
use App\Models\ApiToken;
use App\Models\Team;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function tokenPageUser(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('the page offers only abilities the role actually grants', function () {
    $team = Team::factory()->create();

    $this->actingAs(tokenPageUser($team, TeamRole::Member))
        ->get(route('api-tokens.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('settings/ApiTokens')
            ->where('abilities', fn($abilities) => collect($abilities)
                ->firstWhere('value', Ability::UpdateApplication->value)['granted'] === false
                && collect($abilities)->firstWhere('value', Ability::DeployApplication->value)['granted'] === true));
});

test('creating a token returns the plain text once and stores only a hash', function () {
    $team = Team::factory()->create();

    $response = $this->actingAs(tokenPageUser($team, TeamRole::Admin))
        ->post(route('api-tokens.store'), [
            'name' => 'CI',
            'abilities' => [Ability::DeployApplication->value],
        ])
        ->assertRedirect()
        ->assertSessionHas('plainTextToken');

    $plain = session('plainTextToken');
    $stored = ApiToken::first();

    expect($plain)->toContain('|')
        ->and($stored->name)->toBe('CI')
        ->and($stored->team_id)->toBe($team->getKey())
        ->and($stored->token)->not->toBe($plain)
        ->and($stored->token)->toBe(hash('sha256', explode('|', (string) $plain)[1]));
});

test('a token cannot be created with an ability the role does not grant', function () {
    $team = Team::factory()->create();

    $this->actingAs(tokenPageUser($team, TeamRole::Member))
        ->post(route('api-tokens.store'), [
            'name' => 'Sneaky',
            'abilities' => [Ability::UpdateApplication->value],
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(ApiToken::count())->toBe(0);
});

test('abilities the role does grant survive while the rest are dropped', function () {
    $team = Team::factory()->create();

    $this->actingAs(tokenPageUser($team, TeamRole::Member))
        ->post(route('api-tokens.store'), [
            'name' => 'Mixed',
            'abilities' => [Ability::DeployApplication->value, Ability::UpdateApplication->value],
        ])
        ->assertRedirect();

    expect((array) ApiToken::first()->abilities)->toBe([Ability::DeployApplication->value]);
});

test('an ability outside the offered set is rejected by validation', function () {
    $team = Team::factory()->create();

    $this->actingAs(tokenPageUser($team, TeamRole::Owner))
        ->post(route('api-tokens.store'), ['name' => 'X', 'abilities' => ['team.delete']])
        ->assertSessionHasErrors('abilities.0');

    expect(ApiToken::count())->toBe(0);
});

test('a token needs a name and at least one ability', function (array $payload, string $field) {
    $team = Team::factory()->create();

    $this->actingAs(tokenPageUser($team, TeamRole::Owner))
        ->post(route('api-tokens.store'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    [['abilities' => ['server.view']], 'name'],
    [['name' => 'X'], 'abilities'],
    [['name' => 'X', 'abilities' => []], 'abilities'],
]);

test('the page lists only your own tokens', function () {
    $team = Team::factory()->create();
    $mine = tokenPageUser($team, TeamRole::Owner);
    $theirs = tokenPageUser($team, TeamRole::Owner);

    $mine->createToken('mine', ['server.view']);
    $theirs->createToken('theirs', ['server.view']);

    $this->actingAs($mine)
        ->get(route('api-tokens.index'))
        ->assertInertia(fn($page) => $page->has('tokens', 1)->where('tokens.0.name', 'mine'));
});

test('a token can be revoked', function () {
    $team = Team::factory()->create();
    $user = tokenPageUser($team, TeamRole::Owner);
    $token = $user->createToken('CI', ['server.view'])->accessToken;

    $this->actingAs($user)
        ->delete(route('api-tokens.destroy', $token->getKey()))
        ->assertRedirect();

    expect(ApiToken::count())->toBe(0);
});

test('creating and revoking a token are recorded against its team', function () {
    $team = Team::factory()->create();
    $user = tokenPageUser($team, TeamRole::Owner);

    $this->actingAs($user);
    $this->post(route('api-tokens.store'), ['name' => 'CI', 'abilities' => [Ability::DeployApplication->value]])->assertRedirect();
    $plain = (string) session('plainTextToken');
    $token = ApiToken::query()->where('name', 'CI')->sole();
    $this->delete(route('api-tokens.destroy', $token->getKey()))->assertRedirect();

    $created = Activity::query()->where('description', 'api_token_created')->sole();
    $revoked = Activity::query()->where('description', 'api_token_revoked')->sole();

    expect($created->subject?->is($team))->toBeTrue()
        ->and($created->causer?->is($user))->toBeTrue()
        ->and($created->properties->toArray())->toBeInAnyKeyOrder(['name' => 'CI', 'abilities' => [Ability::DeployApplication->value]])
        ->and($revoked->subject?->is($team))->toBeTrue()
        ->and($revoked->causer?->is($user))->toBeTrue()
        ->and($revoked->properties->toArray())->toBe(['name' => 'CI'])
        ->and($plain)->not->toBe('')
        ->and(json_encode($created->getAttributes()))->not->toContain($plain);
});

test('a token that failed to revoke records nothing', function () {
    $team = Team::factory()->create();
    $mine = tokenPageUser($team, TeamRole::Owner);
    $theirs = tokenPageUser($team, TeamRole::Owner);
    $token = $theirs->createToken('theirs', ['server.view'])->accessToken;
    ApiToken::whereKey($token->getKey())->update(['team_id' => $team->id]);

    $this->actingAs($mine)->delete(route('api-tokens.destroy', $token->getKey()))->assertNotFound();

    expect(Activity::query()->where('description', 'api_token_revoked')->count())->toBe(0);
});

test('revoking a token with no team records nothing and still works', function () {
    $user = tokenPageUser(Team::factory()->create(), TeamRole::Owner);
    $token = $user->createToken('CI', ['server.view'])->accessToken;

    $this->actingAs($user)->delete(route('api-tokens.destroy', $token->getKey()))->assertRedirect()->assertSessionHas('success', 'Token revoked.');

    expect(ApiToken::count())->toBe(0)
        ->and(Activity::query()->where('description', 'api_token_revoked')->count())->toBe(0);
});

test('another users token cannot be revoked', function () {
    $team = Team::factory()->create();
    $mine = tokenPageUser($team, TeamRole::Owner);
    $theirs = tokenPageUser($team, TeamRole::Owner);
    $token = $theirs->createToken('theirs', ['server.view'])->accessToken;

    $this->actingAs($mine)
        ->delete(route('api-tokens.destroy', $token->getKey()))
        ->assertNotFound();

    expect(ApiToken::count())->toBe(1);
});

test('the token is shown once and never again', function () {
    $team = Team::factory()->create();
    $user = tokenPageUser($team, TeamRole::Owner);

    $this->actingAs($user)->post(route('api-tokens.store'), [
        'name' => 'CI',
        'abilities' => [Ability::ViewServer->value],
    ]);

    $secret = explode('|', (string) session('plainTextToken'))[1];

    expect($this->actingAs($user)->get(route('api-tokens.index'))->getContent())->toContain($secret)
        ->and($this->actingAs($user)->get(route('api-tokens.index'))->getContent())->not->toContain($secret);
});

test('the page is behind authentication', function () {
    $this->get(route('api-tokens.index'))->assertRedirect(route('login'));
});
