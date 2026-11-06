<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\SyncStripeCustomerEmail;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

function accountClosingTeam(User $owner, array $attributes = []): Team
{
    $team = Team::factory()->create(['name' => 'Monoven', 'owner_id' => $owner->id, ...$attributes]);
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value, 'created_at' => now()->subYears(2)]);

    return $team;
}

function accountClosingJoin(Team $team, TeamRole $role, string $joined): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value, 'created_at' => now()->modify($joined), 'updated_at' => now()]);

    return $user;
}

function accountClosingAsk(User $user): TestResponse
{
    return test()->actingAs($user)
        ->from(route('settings.general'))
        ->delete(route('profile.destroy'), ['password' => 'password']);
}

test('the last owner of a team with other members is told what to do first', function (string $edition, string $refusal) {
    config(['coresmith.edition' => $edition]);
    $owner = User::factory()->create();
    $team = accountClosingTeam($owner);
    accountClosingJoin($team, TeamRole::Admin, '-1 year');

    accountClosingAsk($owner)
        ->assertRedirect(route('settings.general'))
        ->assertSessionHasErrors(['password' => $refusal]);

    expect(User::find($owner->id))->not->toBeNull();
    $this->assertAuthenticatedAs($owner);
})->with([
    'on cloud' => ['cloud', 'Make someone else an owner of Monoven first, remove the other members, or delete the team.'],
    'on a self-hosted panel' => ['self-hosted', 'Make someone else an owner of Monoven first. To remove the panel instead, run coresmith:uninstall on its server.'],
]);

test('a cloud owner alone on their team is told to delete it first', function () {
    config(['coresmith.edition' => 'cloud']);
    $owner = User::factory()->create();
    accountClosingTeam($owner);

    accountClosingAsk($owner)->assertSessionHasErrors(['password' => 'Delete Monoven from Settings, General first, then close your account.']);

    expect(User::find($owner->id))->not->toBeNull();
});

test('the only person on a self-hosted panel keeps their account, so the panel is never left with nobody to manage it', function () {
    config(['coresmith.edition' => 'self-hosted']);
    $owner = User::factory()->create();
    accountClosingTeam($owner);

    accountClosingAsk($owner)->assertSessionHasErrors(['password' => 'Make someone else an owner of Monoven first. To remove the panel instead, run coresmith:uninstall on its server.']);

    expect(User::find($owner->id))->not->toBeNull();
});

test('an owner closes their account while another owner remains, and the team passes to the longest-standing one', function (string $edition) {
    config(['coresmith.edition' => $edition]);
    Queue::fake();
    $owner = User::factory()->create();
    $team = accountClosingTeam($owner);
    accountClosingJoin($team, TeamRole::Owner, '-1 month');
    $first = accountClosingJoin($team, TeamRole::Owner, '-1 year');
    accountClosingJoin($team, TeamRole::Admin, '-18 months');

    accountClosingAsk($owner)->assertRedirect('/');

    expect(User::find($owner->id))->toBeNull()
        ->and($team->fresh()->owner_id)->toBe($first->id)
        ->and($team->fresh()->owners()->pluck('users.id')->all())->not->toContain($owner->id);
    $this->assertGuest();
})->with(['cloud', 'self-hosted']);

test('on cloud the stripe customer follows the new owner', function () {
    config(['coresmith.edition' => 'cloud']);
    Queue::fake();
    $owner = User::factory()->create();
    $team = accountClosingTeam($owner, ['stripe_id' => 'cus_monoven']);
    $next = accountClosingJoin($team, TeamRole::Owner, '-1 year');

    accountClosingAsk($owner)->assertRedirect('/');

    Queue::assertPushed(SyncStripeCustomerEmail::class, fn(SyncStripeCustomerEmail $job): bool => $job->team->is($team) && $job->team->owner_id === $next->id);
});

test('a team with no stripe customer, or a self-hosted panel, has nobody to tell', function (string $edition, ?string $customer) {
    config(['coresmith.edition' => $edition]);
    Queue::fake();
    $owner = User::factory()->create();
    $team = accountClosingTeam($owner, ['stripe_id' => $customer]);
    accountClosingJoin($team, TeamRole::Owner, '-1 year');

    accountClosingAsk($owner)->assertRedirect('/');

    Queue::assertNotPushed(SyncStripeCustomerEmail::class);
})->with([
    'no customer on cloud' => ['cloud', null],
    'a self-hosted panel' => ['self-hosted', 'cus_leftover'],
]);

test('a co-owner closes their account and the recorded owner stays', function () {
    $founder = User::factory()->create();
    $team = accountClosingTeam($founder);
    $partner = accountClosingJoin($team, TeamRole::Owner, '-1 year');

    accountClosingAsk($partner)->assertRedirect('/');

    expect(User::find($partner->id))->toBeNull()
        ->and($team->fresh()->owner_id)->toBe($founder->id);
});

test('a founder who was made an admin passes the recorded ownership to an owner', function () {
    $founder = User::factory()->create();
    $team = accountClosingTeam($founder);
    $owner = accountClosingJoin($team, TeamRole::Owner, '-1 year');
    $team->members()->updateExistingPivot($founder->id, ['role' => TeamRole::Admin->value]);

    accountClosingAsk($founder)->assertRedirect('/');

    expect($team->fresh()->owner_id)->toBe($owner->id);
});

test('the last owner of a team whose founder is now an admin is told what to do first', function () {
    config(['coresmith.edition' => 'cloud']);
    $founder = User::factory()->create();
    $team = accountClosingTeam($founder);
    $owner = accountClosingJoin($team, TeamRole::Owner, '-1 year');
    $team->members()->updateExistingPivot($founder->id, ['role' => TeamRole::Admin->value]);

    accountClosingAsk($owner)->assertSessionHasErrors(['password' => 'Make someone else an owner of Monoven first, remove the other members, or delete the team.']);
});

test('settings say what has to happen before the account can close', function (string $edition, bool $alone, ?string $refusal) {
    config(['coresmith.edition' => $edition]);
    $owner = User::factory()->create();
    $team = accountClosingTeam($owner);
    $owner->forceFill(['current_team_id' => $team->id])->save();

    if (!$alone) {
        accountClosingJoin($team, TeamRole::Owner, '-1 year');
    }

    $this->actingAs($owner)
        ->get(route('settings.general'))
        ->assertInertia(fn($page) => $page->where('account', ['refusal' => $refusal]));
})->with([
    'alone on cloud' => ['cloud', true, 'Delete Monoven from Settings, General first, then close your account.'],
    'another owner on cloud' => ['cloud', false, null],
    'alone on a self-hosted panel' => ['self-hosted', true, 'Make someone else an owner of Monoven first. To remove the panel instead, run coresmith:uninstall on its server.'],
]);
