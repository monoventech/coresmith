<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\TrialHistory;
use Tests\Support\CloudBilling;

beforeEach(function () {
    CloudBilling::enable();
});

test('someone on cloud with no team is sent from the fleet to the page that offers a new one', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('start.show'));
});

test('a self-hosted panel keeps showing its empty fleet to someone with no team', function () {
    config(['coresmith.edition' => 'self-hosted']);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn($page) => $page->component('Dashboard')->where('hasTeam', false));
});

test('the page says the team was deleted only straight after a deletion', function (bool $deleted) {
    $this->actingAs(User::factory()->create())
        ->withSession($deleted ? ['team_deleted' => true] : [])
        ->get(route('start.show'))
        ->assertOk()
        ->assertInertia(fn($page) => $page->component('auth/NoTeam')->where('deleted', $deleted));
})->with([true, false]);

test('a new team starts on a trial only when the address never had one', function () {
    $fresh = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $this->actingAs($fresh)
        ->get(route('start.show'))
        ->assertInertia(fn($page) => $page->where('trial_days', 14));

    $this->actingAs($fresh)->post(route('start.store'))->assertRedirect();

    $team = $fresh->fresh()->teams()->sole();

    expect($team->name)->toBe("Ada's team")
        ->and($team->owner_id)->toBe($fresh->id)
        ->and($team->trial_ends_at?->isFuture())->toBeTrue()
        ->and($fresh->fresh()->current_team_id)->toBe($team->id);
});

test('a new team for an address that already had a trial starts without one', function () {
    $user = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace+cloud@example.com']);
    app(TrialHistory::class)->claim('grace@example.com', Team::factory()->create());

    $this->actingAs($user)
        ->get(route('start.show'))
        ->assertInertia(fn($page) => $page->where('trial_days', null));

    $this->actingAs($user)
        ->post(route('start.store'))
        ->assertSessionHas('success', "Started Grace's team.");

    expect($user->fresh()->teams()->sole()->trial_ends_at)->toBeNull();
});

test('starting a team lands on its fleet', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $response = $this->actingAs($user)->post(route('start.store'));

    $team = $user->fresh()->teams()->sole();
    $response->assertRedirect("/{$team->slug}/dashboard");
});

test('someone already on a team is sent to it, and starting does not make another', function () {
    $team = Team::factory()->owned()->create(['slug' => 'acme-labs']);
    $owner = CloudBilling::owner($team);

    $this->actingAs($owner)->get(route('start.show'))->assertRedirect('/acme-labs/dashboard');
    $this->actingAs($owner)->post(route('start.store'))->assertRedirect('/acme-labs/dashboard');

    expect($owner->fresh()->teams()->count())->toBe(1);
});

test('a member removed from their only team gets the same page', function () {
    $team = Team::factory()->owned()->create();
    $member = CloudBilling::member($team, TeamRole::Member);
    $team->members()->detach($member);

    $this->actingAs($member->fresh())
        ->get(route('dashboard'))
        ->assertRedirect(route('start.show'));

    $this->actingAs($member->fresh())
        ->get(route('start.show'))
        ->assertInertia(fn($page) => $page->component('auth/NoTeam')->where('deleted', false));
});

test('self-hosted panels have no such page', function () {
    config(['coresmith.edition' => 'self-hosted']);
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('start.show'))->assertNotFound();
    $this->actingAs($user)->post(route('start.store'))->assertNotFound();

    expect($user->fresh()->teams()->exists())->toBeFalse();
});

test('the page needs someone signed in', function () {
    $this->get(route('start.show'))->assertRedirect(route('login'));
    $this->post(route('start.store'))->assertRedirect(route('login'));
});
