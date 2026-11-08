<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function billingMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('billing requires authentication', function () {
    config(['coresmith.edition' => 'cloud']);

    $this->get(route('settings.billing'))->assertRedirect(route('login'));
});

test('the last owner of a team cannot close their account until someone else owns it', function () {
    $user = User::factory()->create();
    Team::factory()->create(['name' => 'Monoven', 'owner_id' => $user->id]);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['password' => 'Make someone else an owner of Monoven first. To remove Coresmith instead, run coresmith:uninstall on its server.']);

    expect(User::find($user->id))->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('a user who only belongs to a team can delete their account', function () {
    $user = billingMember(Team::factory()->create(), TeamRole::Admin);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull();

    $this->assertGuest();
});

test('deleting an account keeps the deployments it triggered and removes its api tokens', function () {
    $team = Team::factory()->create();
    $user = billingMember($team, TeamRole::Admin);
    $deployment = Deployment::factory()->for(Application::factory()->for($team))->create(['triggered_by' => $user->id]);
    $user->createToken('ci');

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull()
        ->and($deployment->fresh())->not->toBeNull()
        ->and($deployment->fresh()->triggered_by)->toBeNull()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
});

test('account deletion needs the current password', function (array $payload) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), $payload)
        ->assertSessionHasErrors('password');

    expect(User::find($user->id))->not->toBeNull();
})->with([
    'missing' => [[]],
    'wrong' => [['password' => 'not-the-password']],
]);
