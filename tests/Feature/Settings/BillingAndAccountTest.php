<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function billingMember(Team $team, TeamRole $role = TeamRole::Member): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('a self hosted panel shows free billing with the teams server count', function () {
    config(['coresmith.billing_enabled' => false]);
    $team = Team::factory()->create(['name' => 'Monoven']);
    Server::factory()->for($team)->count(3)->create();
    Server::factory()->for(Team::factory())->create();

    $this->actingAs(billingMember($team))
        ->get(route('settings.billing'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('settings/Billing')
            ->where('cloud', false)
            ->where('servers', 3)
            ->where('team', 'Monoven'));
});

test('a cloud panel says billing is on', function () {
    config(['coresmith.billing_enabled' => true]);

    $this->actingAs(billingMember(Team::factory()->create()))
        ->get(route('settings.billing'))
        ->assertInertia(fn($page) => $page->where('cloud', true));
});

test('billing for a user without a team counts nothing', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.billing'))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('servers', 0)->where('team', null));
});

test('billing requires authentication', function () {
    $this->get(route('settings.billing'))->assertRedirect(route('login'));
});

test('a user who owns a team cannot delete their account until they hand it over', function () {
    $user = User::factory()->create();
    Team::factory()->create(['name' => 'Monoven', 'owner_id' => $user->id]);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['password' => 'Hand Monoven to another owner before deleting your account.']);

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
