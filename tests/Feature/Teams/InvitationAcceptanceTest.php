<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Features;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;

/**
 * @return array{TeamInvitation, string}
 */
function invitationAcceptanceInvite(array $attributes = [], ?Team $team = null): array
{
    $team ??= Team::factory()->create(['name' => 'Acme Labs', 'slug' => 'acme']);
    $inviter = User::factory()->create(['name' => 'Maya Lindqvist']);
    $team->members()->attach($inviter, ['role' => TeamRole::Owner->value]);
    $token = Str::random(40);

    $invitation = TeamInvitation::factory()->for($team)->withToken($token)->create([
        'email' => 'newcomer@example.com',
        'role' => TeamRole::Admin,
        'invited_by' => $inviter->id,
        ...$attributes,
    ]);

    return [$invitation, $token];
}

function invitationAcceptanceAccount(string $token, array $overrides = []): TestResponse
{
    return test()->from(route('invitations.show', $token))->post(route('invitations.register', $token), [
        'name' => 'Noor Haddad',
        'password' => 'a-long-enough-passphrase',
        'password_confirmation' => 'a-long-enough-passphrase',
        ...$overrides,
    ]);
}

test('an invitation that cannot be used says so and names no team', function (Closure $make) {
    $token = $make();

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('auth/AcceptInvitation')
            ->where('state', 'expired')
            ->where('invitation', null)
            ->where('action', null))
        ->assertDontSee('Acme Labs');
})->with([
    'unknown' => [fn() => 'no-invitation-was-ever-sent-with-this-one'],
    'expired' => [fn() => invitationAcceptanceInvite(['expires_at' => now()->subMinute()])[1]],
    'used' => [fn() => invitationAcceptanceInvite(['accepted_at' => now()->subHour()])[1]],
    'revoked' => [function () {
        [$invitation, $token] = invitationAcceptanceInvite();
        $invitation->delete();

        return $token;
    }],
    'for a team that was deleted' => [function () {
        [$invitation, $token] = invitationAcceptanceInvite();
        $invitation->team->delete();

        return $token;
    }],
]);

test('someone with no account is asked for a name and a password', function () {
    [, $token] = invitationAcceptanceInvite();

    $this->get(route('invitations.show', $token))->assertOk()->assertInertia(fn($page) => $page
        ->component('auth/AcceptInvitation')
        ->where('state', 'register')
        ->where('invitation', ['team' => 'Acme Labs', 'email' => 'newcomer@example.com', 'role' => 'Admin', 'inviter' => 'Maya Lindqvist'])
        ->where('action', route('invitations.register', $token))
        ->where('trust_note', true));
});

test('the trust note is for self-hosted panels only', function () {
    CloudBilling::enable();
    [, $token] = invitationAcceptanceInvite([], Team::factory()->subscribed(Plan::Pro)->create(['name' => 'Acme Labs', 'slug' => 'acme']));

    $this->get(route('invitations.show', $token))->assertInertia(fn($page) => $page->where('state', 'register')->where('trust_note', false));
});

test('creating the account joins the team with the invited role and opens its fleet', function () {
    Queue::fake();
    [$invitation, $token] = invitationAcceptanceInvite();
    $server = Server::factory()->for($invitation->team)->active()->create();

    invitationAcceptanceAccount($token)
        ->assertRedirect('http://localhost/acme/dashboard')
        ->assertSessionHas('success', 'Welcome to Acme Labs.');

    $user = User::query()->where('email', 'newcomer@example.com')->sole();
    $entry = Activity::query()->where('description', 'member_added')->sole();

    $this->assertAuthenticatedAs($user);

    expect($user->name)->toBe('Noor Haddad')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('a-long-enough-passphrase', $user->password))->toBeTrue()
        ->and($user->roleIn($invitation->team))->toBe(TeamRole::Admin)
        ->and($user->current_team_id)->toBe($invitation->team_id)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($entry->causer_id)->toBe($user->id)
        ->and($entry->subject_id)->toBe($invitation->team_id)
        ->and($entry->properties->all())->toBe(['email' => 'newcomer@example.com', 'role' => 'admin'])
        ->and(Queue::pushed(SyncAuthorizedKeys::class)->map->uniqueId()->all())->toBe([$server->id]);
});

test('the team opens in a full page load, so its links carry the team address', function () {
    [, $token] = invitationAcceptanceInvite();

    invitationAcceptanceAccount($token)->assertRedirect('http://localhost/acme/dashboard');

    $this->post(route('logout'));
    [, $second] = invitationAcceptanceInvite(['email' => 'second@example.com'], Team::query()->where('slug', 'acme')->sole());

    $this->withHeader('X-Inertia', 'true')
        ->from(route('invitations.show', $second))
        ->post(route('invitations.register', $second), ['name' => 'Sam Okafor', 'password' => 'a-long-enough-passphrase', 'password_confirmation' => 'a-long-enough-passphrase'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'http://localhost/acme/dashboard');
});

test('the account takes the invited address, whatever the form sends', function () {
    [, $token] = invitationAcceptanceInvite();

    invitationAcceptanceAccount($token, ['email' => 'someone-else@example.com'])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'someone-else@example.com')->exists())->toBeFalse()
        ->and(User::query()->where('email', 'newcomer@example.com')->exists())->toBeTrue();
});

test('the account needs a name and a confirmed password', function (array $overrides, string $field) {
    [$invitation, $token] = invitationAcceptanceInvite();

    invitationAcceptanceAccount($token, $overrides)->assertSessionHasErrors($field);

    expect(User::query()->where('email', 'newcomer@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();

    $this->assertGuest();
})->with([
    'no name' => [['name' => ''], 'name'],
    'a short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'a confirmation that does not match' => [['password_confirmation' => 'something-else-entirely'], 'password'],
]);

test('an invitation works with public sign up turned off', function () {
    config(['fortify.features' => [Features::resetPasswords(), Features::emailVerification()]]);
    [, $token] = invitationAcceptanceInvite();

    expect(Features::enabled(Features::registration()))->toBeFalse();

    $this->get(route('invitations.show', $token))->assertOk()->assertInertia(fn($page) => $page->where('state', 'register'));

    invitationAcceptanceAccount($token)->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticated();
});

test('an invited person on cloud gets no trial team of their own', function () {
    CloudBilling::enable();
    Notification::fake();
    $team = Team::factory()->subscribed(Plan::Pro)->create(['name' => 'Acme Labs', 'slug' => 'acme']);
    [, $token] = invitationAcceptanceInvite([], $team);

    invitationAcceptanceAccount($token)->assertRedirect('http://localhost/acme/dashboard');

    $user = User::query()->where('email', 'newcomer@example.com')->sole();

    expect($user->teams()->pluck('teams.id')->all())->toBe([$team->id])
        ->and(Team::query()->count())->toBe(1)
        ->and(DB::table('trial_history')->count())->toBe(0);

    Notification::assertNotSentTo($user, VerifyEmail::class);

    $this->get('/acme/dashboard')->assertOk();
});

test('someone who already has an account signs in and comes back to the invitation', function () {
    [, $token] = invitationAcceptanceInvite();
    User::factory()->create(['email' => 'newcomer@example.com', 'password' => 'the-real-password']);

    $this->get(route('invitations.show', $token))->assertInertia(fn($page) => $page->where('state', 'sign-in')->where('action', null));

    invitationAcceptanceAccount($token)->assertRedirect(route('invitations.show', $token));

    expect(User::query()->where('email', 'newcomer@example.com')->count())->toBe(1);

    $this->post(route('login.store'), ['email' => 'newcomer@example.com', 'password' => 'the-real-password'])
        ->assertRedirect(route('invitations.show', $token));

    $this->get(route('invitations.show', $token))->assertInertia(fn($page) => $page
        ->where('state', 'join')
        ->where('action', route('invitations.accept', $token)));
});

test('the invited person, signed in, joins with one click and only once', function () {
    Queue::fake();
    [$invitation, $token] = invitationAcceptanceInvite();
    $user = User::factory()->create(['email' => 'NewComer@example.com']);

    $this->actingAs($user)->get(route('invitations.show', $token))->assertInertia(fn($page) => $page
        ->where('state', 'join')
        ->where('invitation.email', 'newcomer@example.com')
        ->where('action', route('invitations.accept', $token)));

    $this->actingAs($user)->post(route('invitations.accept', $token))
        ->assertRedirect('http://localhost/acme/dashboard')
        ->assertSessionHas('success', 'Welcome to Acme Labs.');

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect(route('invitations.show', $token));

    expect($user->fresh()->roleIn($invitation->team))->toBe(TeamRole::Admin)
        ->and($user->fresh()->current_team_id)->toBe($invitation->team_id)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'member_added')->sole()->causer_id)->toBe($user->id);
});

test('someone signed in as another account is told to sign out first', function () {
    [$invitation, $token] = invitationAcceptanceInvite();
    $other = User::factory()->create(['email' => 'other@example.com']);

    $this->actingAs($other)->get(route('invitations.show', $token))->assertInertia(fn($page) => $page
        ->where('state', 'other')
        ->where('invitation.email', 'newcomer@example.com')
        ->where('action', null));

    $this->actingAs($other)->post(route('invitations.accept', $token))->assertRedirect(route('invitations.show', $token));
    $this->actingAs($other)->post(route('invitations.register', $token), [
        'name' => 'Noor Haddad',
        'password' => 'a-long-enough-passphrase',
        'password_confirmation' => 'a-long-enough-passphrase',
    ])->assertRedirect();

    expect($invitation->team->members()->whereKey($other->id)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'newcomer@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('an invitation creates one account only', function () {
    [, $token] = invitationAcceptanceInvite();

    invitationAcceptanceAccount($token)->assertRedirect('http://localhost/acme/dashboard');

    $this->post(route('logout'));

    invitationAcceptanceAccount($token, ['name' => 'Someone Else'])->assertRedirect(route('invitations.show', $token));

    $this->get(route('invitations.show', $token))->assertInertia(fn($page) => $page->where('state', 'expired'));

    expect(User::query()->where('email', 'newcomer@example.com')->sole()->name)->toBe('Noor Haddad')
        ->and(Activity::query()->where('description', 'member_added')->count())->toBe(1);
});

test('an invitation stops working after seven days', function () {
    [, $token] = invitationAcceptanceInvite(['expires_at' => now()->addDays(7)]);

    $this->travel(6)->days();

    $this->get(route('invitations.show', $token))->assertInertia(fn($page) => $page->where('state', 'register'));

    $this->travel(1)->days();
    $this->travel(1)->minutes();

    $this->get(route('invitations.show', $token))->assertInertia(fn($page) => $page->where('state', 'expired'));

    invitationAcceptanceAccount($token)->assertRedirect(route('invitations.show', $token));

    expect(User::query()->where('email', 'newcomer@example.com')->exists())->toBeFalse();

    $this->assertGuest();
});

test('on cloud the plan is checked again when the invitation is accepted', function (Closure $team, string $message) {
    CloudBilling::enable();
    [$invitation, $token] = invitationAcceptanceInvite([], $team());

    invitationAcceptanceAccount($token)
        ->assertRedirect(route('invitations.show', $token))
        ->assertSessionHas('error', $message);

    expect(User::query()->where('email', 'newcomer@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();

    $this->assertGuest();
})->with([
    'starter, which is for one person' => [fn() => Team::factory()->subscribed(Plan::Starter)->create(['name' => 'Acme Labs', 'slug' => 'acme']), 'Starter is for one person. Ask an owner to change the plan.'],
    'a team that is read-only' => [fn() => Team::factory()->trialEnded()->create(['name' => 'Acme Labs', 'slug' => 'acme']), "This team's trial has ended, so it is read-only. Servers and apps keep running. Ask an owner to choose a plan."],
]);

test('on cloud a signed in person cannot join a team that is full', function () {
    CloudBilling::enable();
    [$invitation, $token] = invitationAcceptanceInvite([], Team::factory()->subscribed(Plan::Starter)->create(['name' => 'Acme Labs', 'slug' => 'acme']));
    $user = User::factory()->create(['email' => 'newcomer@example.com']);

    $this->actingAs($user)
        ->from(route('invitations.show', $token))
        ->post(route('invitations.accept', $token))
        ->assertRedirect(route('invitations.show', $token))
        ->assertSessionHas('error', 'Starter is for one person. Ask an owner to change the plan.');

    expect($invitation->team->members()->whereKey($user->id)->exists())->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('a person whose own team is read-only can still join another team', function () {
    CloudBilling::enable();
    $lapsed = Team::factory()->trialEnded()->owned()->create();
    $user = CloudBilling::owner($lapsed);
    $pro = Team::factory()->subscribed(Plan::Pro)->create(['name' => 'Acme Labs', 'slug' => 'acme']);
    [, $token] = invitationAcceptanceInvite(['email' => Str::lower($user->email)], $pro);

    $this->actingAs($user)
        ->post(route('invitations.accept', $token))
        ->assertRedirect('http://localhost/acme/dashboard')
        ->assertSessionMissing('error');

    expect($user->fresh()->roleIn($pro))->toBe(TeamRole::Admin);
});

test('accepting when already on the team only opens it', function () {
    [$invitation, $token] = invitationAcceptanceInvite();
    $user = User::factory()->create(['email' => 'newcomer@example.com']);
    $invitation->team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect('http://localhost/acme/dashboard');

    expect($user->fresh()->roleIn($invitation->team))->toBe(TeamRole::Member)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'member_added')->count())->toBe(0);
});

test('an account made from a link that was offered for copying is not confirmed', function () {
    [$invitation, $token] = invitationAcceptanceInvite(['link_offered' => true]);

    invitationAcceptanceAccount($token)->assertRedirect('http://localhost/acme/dashboard');

    $user = User::query()->where('email', 'newcomer@example.com')->sole();

    expect($user->email_verified_at)->toBeNull()
        ->and($user->roleIn($invitation->team))->toBe(TeamRole::Admin);
});

test('on cloud accepting an emailed invitation confirms the address of an unconfirmed account', function () {
    CloudBilling::enable();
    [$invitation, $token] = invitationAcceptanceInvite([], Team::factory()->subscribed(Plan::Pro)->create(['name' => 'Acme Labs', 'slug' => 'acme']));
    $user = User::factory()->unverified()->create(['email' => 'newcomer@example.com']);

    $this->actingAs($user)->get(route('invitations.show', $token))->assertOk()->assertInertia(fn($page) => $page->where('state', 'join'));
    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect('http://localhost/acme/dashboard');

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and($user->fresh()->roleIn($invitation->team))->toBe(TeamRole::Admin);

    $this->actingAs($user->fresh())->get('/acme/dashboard')->assertOk();
});

test('accepting a link that was offered for copying joins without confirming the address', function () {
    [$invitation, $token] = invitationAcceptanceInvite(['link_offered' => true]);
    $user = User::factory()->unverified()->create(['email' => 'newcomer@example.com']);

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect('http://localhost/acme/dashboard');

    expect($user->fresh()->email_verified_at)->toBeNull()
        ->and($user->fresh()->roleIn($invitation->team))->toBe(TeamRole::Admin);
});
