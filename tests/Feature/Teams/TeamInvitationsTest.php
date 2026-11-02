<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Teams\TeamInvitations;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudBilling;

function teamInvitationsTeam(string $name = 'Acme Labs', string $slug = 'acme'): Team
{
    return Team::factory()->create(['name' => $name, 'slug' => $slug]);
}

function teamInvitationsMember(Team $team, TeamRole $role = TeamRole::Owner, string $name = 'Maya Lindqvist'): User
{
    $user = User::factory()->create(['name' => $name]);
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function teamInvitationsSend(User $user, Team $team, string $email, TeamRole $role = TeamRole::Member): TestResponse
{
    return test()->actingAs($user)->from(route('teams.index'))->post(route('teams.members.store', $team), ['email' => $email, 'role' => $role->value]);
}

function teamInvitationsLink(string $email): string
{
    $links = [];

    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$links): bool {
        if (($notifiable->routes['mail'] ?? null) !== $email) {
            return false;
        }

        $links[] = (string) $notification->toMail($notifiable)->actionUrl;

        return true;
    });

    return (string) end($links);
}

function teamInvitationsToken(string $link): string
{
    return Str::afterLast($link, '/');
}

beforeEach(function () {
    Notification::fake();
});

test('an address without an account is invited instead of refused', function () {
    Queue::fake();
    $team = teamInvitationsTeam();
    Server::factory()->for($team)->active()->create();
    $owner = teamInvitationsMember($team);

    teamInvitationsSend($owner, $team, ' New.Person@Example.com ', TeamRole::Admin)
        ->assertRedirect(route('teams.index'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Invitation sent to new.person@example.com.');

    $invitation = TeamInvitation::query()->sole();
    $token = teamInvitationsToken(teamInvitationsLink('new.person@example.com'));

    expect($invitation->team_id)->toBe($team->id)
        ->and($invitation->email)->toBe('new.person@example.com')
        ->and($invitation->role)->toBe(TeamRole::Admin)
        ->and($invitation->invited_by)->toBe($owner->id)
        ->and($invitation->accepted_at)->toBeNull()
        ->and(abs($invitation->expires_at->diffInSeconds(now()->addDays(7))))->toBeLessThan(5)
        ->and($token)->toHaveLength(40)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($team->members()->count())->toBe(1)
        ->and(User::query()->where('email', 'new.person@example.com')->exists())->toBeFalse();

    Queue::assertNotPushed(SyncAuthorizedKeys::class);
});

test('an invitation keeps only a hash of its token', function () {
    $token = 'a-token-only-the-invited-person-holds-00';
    $invitation = TeamInvitation::factory()->withToken($token)->create();

    expect(TeamInvitation::hash('abc'))->toBe(hash('sha256', 'abc'))
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and(TeamInvitation::usable($token)?->is($invitation))->toBeTrue()
        ->and(TeamInvitation::usable($invitation->token_hash))->toBeNull()
        ->and(TeamInvitation::usable('not-the-token'))->toBeNull()
        ->and($invitation->toArray())->not->toHaveKey('token_hash');
});

test('an accepted, expired or orphaned invitation is not usable', function () {
    $accepted = TeamInvitation::factory()->accepted()->withToken('accepted-token-00000000000000000000000000')->create();
    $expired = TeamInvitation::factory()->expired()->withToken('expired-token-000000000000000000000000000')->create();
    $orphaned = TeamInvitation::factory()->withToken('orphaned-token-00000000000000000000000000')->create();
    $orphaned->team->delete();

    expect(TeamInvitation::usable('accepted-token-00000000000000000000000000'))->toBeNull()
        ->and(TeamInvitation::usable('expired-token-000000000000000000000000000'))->toBeNull()
        ->and(TeamInvitation::usable('orphaned-token-00000000000000000000000000'))->toBeNull()
        ->and($accepted->isPending())->toBeFalse()
        ->and($expired->isPending())->toBeFalse();
});

test('an invitation lasts seven days', function () {
    $team = teamInvitationsTeam();

    teamInvitationsSend(teamInvitationsMember($team), $team, 'new@example.com')->assertSessionHasNoErrors();

    $token = teamInvitationsToken(teamInvitationsLink('new@example.com'));

    $this->travel(7)->days();
    $this->travel(-1)->minutes();

    expect(TeamInvitation::usable($token))->not->toBeNull();

    $this->travel(2)->minutes();

    expect(TeamInvitation::usable($token))->toBeNull();
});

test('someone with an account still joins at once, whatever case the address is typed in', function () {
    $team = teamInvitationsTeam();
    $owner = teamInvitationsMember($team);
    $person = User::factory()->create(['email' => 'person@example.com']);
    TeamInvitation::factory()->for($team)->create(['email' => 'person@example.com']);

    teamInvitationsSend($owner, $team, 'Person@Example.com')->assertSessionHas('success', 'Member added.');

    expect($team->members()->whereKey($person->id)->exists())->toBeTrue()
        ->and(TeamInvitation::query()->count())->toBe(0);

    Notification::assertNothingSent();
});

test('inviting the same address again replaces the pending invitation', function () {
    $team = teamInvitationsTeam();
    $owner = teamInvitationsMember($team);

    teamInvitationsSend($owner, $team, 'new@example.com')->assertSessionHasNoErrors();
    $first = teamInvitationsLink('new@example.com');

    $this->travel(3)->days();

    teamInvitationsSend($owner, $team, 'NEW@example.com', TeamRole::Admin)->assertSessionHas('success', 'Invitation sent to new@example.com.');
    $second = teamInvitationsLink('new@example.com');
    $invitation = TeamInvitation::query()->sole();

    expect($second)->not->toBe($first)
        ->and(TeamInvitation::usable(teamInvitationsToken($first)))->toBeNull()
        ->and(TeamInvitation::usable(teamInvitationsToken($second))?->is($invitation))->toBeTrue()
        ->and($invitation->role)->toBe(TeamRole::Admin)
        ->and(abs($invitation->expires_at->diffInSeconds(now()->addDays(7))))->toBeLessThan(5);

    Notification::assertSentOnDemandTimes(TeamInvitationNotification::class, 2);
});

test('an address whose account was deleted can be invited again', function () {
    $team = teamInvitationsTeam();
    TeamInvitation::factory()->for($team)->accepted()->create(['email' => 'gone@example.com']);

    teamInvitationsSend(teamInvitationsMember($team), $team, 'gone@example.com')->assertSessionHas('success', 'Invitation sent to gone@example.com.');

    expect(TeamInvitation::usable(teamInvitationsToken(teamInvitationsLink('gone@example.com'))))->not->toBeNull()
        ->and(TeamInvitation::query()->count())->toBe(1);
});

test('each team keeps its own invitation for an address', function () {
    $acme = teamInvitationsTeam();
    $globex = teamInvitationsTeam('Globex', 'globex');

    teamInvitationsSend(teamInvitationsMember($acme), $acme, 'new@example.com')->assertSessionHasNoErrors();
    teamInvitationsSend(teamInvitationsMember($globex, name: 'Hank Scorpio'), $globex, 'new@example.com')->assertSessionHasNoErrors();

    expect(TeamInvitation::query()->pluck('team_id')->sort()->values()->all())->toBe(collect([$acme->id, $globex->id])->sort()->values()->all());
});

test('the email says who invited them, to which team and for how long', function () {
    $notification = new TeamInvitationNotification('Acme Labs', 'Maya Lindqvist', 'http://localhost/invitations/abc');
    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($notification->via(new AnonymousNotifiable))->toBe(['mail'])
        ->and($mail->subject)->toBe('Maya Lindqvist invited you to Acme Labs on Coresmith')
        ->and($mail->introLines)->toBe(['Maya Lindqvist invited you to Acme Labs on Coresmith.'])
        ->and($mail->actionText)->toBe('Join Acme Labs')
        ->and($mail->actionUrl)->toBe('http://localhost/invitations/abc')
        ->and($mail->outroLines)->toBe(['The link works for 7 days.', 'If you were not expecting it, you can ignore this email.']);
});

test('the emailed link opens the invitation it was sent for', function () {
    $team = teamInvitationsTeam();

    teamInvitationsSend(teamInvitationsMember($team), $team, 'new@example.com')->assertSessionHasNoErrors();

    $link = teamInvitationsLink('new@example.com');

    expect($link)->toBe('http://localhost/invitations/' . teamInvitationsToken($link));

    $this->get($link)->assertOk()->assertInertia(fn($page) => $page
        ->component('auth/AcceptInvitation')
        ->where('invitation.team', 'Acme Labs')
        ->where('invitation.email', 'new@example.com'));
});

test("the link points at the panel's own address, whichever team address it was sent from", function () {
    $team = teamInvitationsTeam();
    $owner = teamInvitationsMember($team);

    $this->actingAs($owner)
        ->post('/acme' . route('teams.members.store', $team, false), ['email' => 'new@example.com', 'role' => TeamRole::Member->value])
        ->assertSessionHasNoErrors();

    $link = teamInvitationsLink('new@example.com');

    expect($link)->toStartWith('http://localhost/invitations/');

    $this->actingAs($owner)->get('/acme/settings/team')->assertInertia(fn($page) => $page->where('invitations.0.link', $link));
});

test('inviting and revoking are recorded, without the link', function () {
    $team = teamInvitationsTeam();
    $owner = teamInvitationsMember($team);

    teamInvitationsSend($owner, $team, 'new@example.com', TeamRole::Admin)->assertSessionHasNoErrors();
    $token = teamInvitationsToken(teamInvitationsLink('new@example.com'));

    $this->actingAs($owner)
        ->delete(route('teams.invitations.destroy', [$team, TeamInvitation::query()->sole()]))
        ->assertSessionHas('success', 'Invitation revoked.');

    $entries = Activity::query()->where('subject_id', $team->getKey())->orderBy('id')->get();

    expect($entries->pluck('description')->all())->toBe(['member_invited', 'invitation_revoked'])
        ->and($entries->pluck('properties')->map->all()->all())->toBe([
            ['email' => 'new@example.com', 'role' => 'admin'],
            ['email' => 'new@example.com'],
        ])
        ->and($entries->pluck('causer_id')->unique()->all())->toBe([$owner->getKey()])
        ->and((string) json_encode(Activity::query()->get()->toArray()))->not->toContain($token);
});

test('a revoked invitation stops working and leaves the list', function () {
    $team = teamInvitationsTeam();
    $admin = teamInvitationsMember($team, TeamRole::Admin);
    $invitation = TeamInvitation::factory()->for($team)->withToken('revoked-token-000000000000000000000000000')->create(['email' => 'new@example.com']);

    $this->actingAs($admin)
        ->from(route('teams.index'))
        ->delete(route('teams.invitations.destroy', [$team, $invitation]))
        ->assertRedirect(route('teams.index'))
        ->assertSessionHas('success', 'Invitation revoked.');

    expect(TeamInvitation::query()->count())->toBe(0)
        ->and(TeamInvitation::usable('revoked-token-000000000000000000000000000'))->toBeNull();

    $this->actingAs($admin)->get(route('teams.index'))->assertInertia(fn($page) => $page->where('invitations', []));
});

test('a deployer can neither invite nor revoke', function () {
    $team = teamInvitationsTeam();
    $deployer = teamInvitationsMember($team, TeamRole::Member);
    $invitation = TeamInvitation::factory()->for($team)->create();

    teamInvitationsSend($deployer, $team, 'new@example.com')->assertForbidden();
    $this->actingAs($deployer)->delete(route('teams.invitations.destroy', [$team, $invitation]))->assertForbidden();

    expect(TeamInvitation::query()->count())->toBe(1);

    Notification::assertNothingSent();
});

test('only an owner can invite someone as an owner', function () {
    $team = teamInvitationsTeam();
    $admin = teamInvitationsMember($team, TeamRole::Admin);
    $owner = teamInvitationsMember($team, TeamRole::Owner, 'Elin Larsen');

    teamInvitationsSend($admin, $team, 'new@example.com', TeamRole::Owner)
        ->assertSessionHasErrors(['role' => 'Only an owner can grant the owner role. Ask an owner of this team to do it.']);

    expect(TeamInvitation::query()->count())->toBe(0);

    Notification::assertNothingSent();

    teamInvitationsSend($owner, $team, 'new@example.com', TeamRole::Owner)->assertSessionHasNoErrors();

    expect(TeamInvitation::query()->sole()->role)->toBe(TeamRole::Owner);
});

test("another team's invitation, or one already accepted, cannot be revoked from here", function () {
    $team = teamInvitationsTeam();
    $owner = teamInvitationsMember($team);
    $theirs = TeamInvitation::factory()->create();
    $accepted = TeamInvitation::factory()->for($team)->accepted()->create();

    $this->actingAs($owner)->delete(route('teams.invitations.destroy', [$team, $theirs]))->assertNotFound();
    $this->actingAs($owner)->delete(route('teams.invitations.destroy', [$team, $accepted]))->assertNotFound();

    expect(TeamInvitation::query()->count())->toBe(2);
});

test('a team sends at most twenty invitations an hour', function () {
    $team = teamInvitationsTeam();
    $owner = teamInvitationsMember($team);
    $admin = teamInvitationsMember($team, TeamRole::Admin, 'Jonas Weber');
    $other = teamInvitationsTeam('Globex', 'globex');
    $otherOwner = teamInvitationsMember($other, name: 'Hank Scorpio');
    $refusal = 'This team has sent 20 invitations in the last hour. Try again in an hour.';

    foreach (range(1, 20) as $number) {
        teamInvitationsSend($owner, $team, "person{$number}@example.com")->assertSessionHasNoErrors();
    }

    teamInvitationsSend($owner, $team, 'person21@example.com')->assertSessionHasErrors(['email' => $refusal]);
    teamInvitationsSend($admin, $team, 'person21@example.com')->assertSessionHasErrors(['email' => $refusal]);
    teamInvitationsSend($owner, $team, User::factory()->create()->email)->assertSessionHas('success', 'Member added.');
    teamInvitationsSend($otherOwner, $other, 'person21@example.com')->assertSessionHasNoErrors();

    expect(TeamInvitation::query()->where('team_id', $team->id)->count())->toBe(20);

    $this->travel(61)->minutes();

    teamInvitationsSend($owner, $team, 'person21@example.com')->assertSessionHasNoErrors();

    expect(TeamInvitation::query()->where('team_id', $team->id)->count())->toBe(21);
});

test('the hourly limit follows the panel setting', function () {
    config(['coresmith.teams.invitations_per_hour' => 2]);
    $team = teamInvitationsTeam();
    $owner = teamInvitationsMember($team);

    teamInvitationsSend($owner, $team, 'first@example.com')->assertSessionHasNoErrors();
    teamInvitationsSend($owner, $team, 'second@example.com')->assertSessionHasNoErrors();
    teamInvitationsSend($owner, $team, 'third@example.com')->assertSessionHasErrors(['email' => 'This team has sent 2 invitations in the last hour. Try again in an hour.']);
});

test('the team tab lists pending invitations with their role and expiry', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00'));
    $team = teamInvitationsTeam();
    $deployer = teamInvitationsMember($team, TeamRole::Member);
    $amy = TeamInvitation::factory()->for($team)->create(['email' => 'amy@example.com', 'role' => TeamRole::Member, 'expires_at' => now()->addDays(2)]);
    TeamInvitation::factory()->for($team)->create(['email' => 'zed@example.com', 'role' => TeamRole::Admin, 'expires_at' => now()->addDays(7)]);
    TeamInvitation::factory()->for($team)->expired()->create(['email' => 'late@example.com']);
    TeamInvitation::factory()->for($team)->accepted()->create(['email' => 'done@example.com']);
    TeamInvitation::factory()->create(['email' => 'elsewhere@example.com']);

    $this->actingAs($deployer)->get(route('teams.index'))->assertInertia(fn($page) => $page
        ->component('settings/Team')
        ->has('invitations', 2)
        ->where('invitations.0', [
            'id' => $amy->id,
            'email' => 'amy@example.com',
            'role' => 'member',
            'role_label' => 'Deployer',
            'expires' => 'Nov 4',
            'link' => null,
        ])
        ->where('invitations.1.email', 'zed@example.com')
        ->where('invitations.1.role_label', 'Admin')
        ->where('invitations.1.expires', 'Nov 9'));
});

test('whoever sent an invitation can copy its link once, right after sending it', function () {
    $team = teamInvitationsTeam();
    $owner = teamInvitationsMember($team);
    $admin = teamInvitationsMember($team, TeamRole::Admin, 'Jonas Weber');

    teamInvitationsSend($owner, $team, 'new@example.com')->assertSessionHasNoErrors();
    $link = teamInvitationsLink('new@example.com');

    $this->actingAs($owner)->get(route('teams.index'))->assertInertia(fn($page) => $page->where('invitations.0.link', $link));
    $this->actingAs($owner)->get(route('teams.index'))->assertInertia(fn($page) => $page->where('invitations.0.link', null));

    teamInvitationsSend($owner, $team, 'new@example.com')->assertSessionHasNoErrors();

    $this->actingAs($admin)->get(route('teams.index'))
        ->assertInertia(fn($page) => $page->where('invitations.0.link', null))
        ->assertDontSee(teamInvitationsToken(teamInvitationsLink('new@example.com')));
});

test('a link is only offered while it still opens the invitation', function () {
    $invitation = TeamInvitation::factory()->withToken('the-current-token-0000000000000000000000')->create();
    $invitations = app(TeamInvitations::class);

    expect($invitations->issuedLink($invitation, ['id' => $invitation->id, 'token' => 'the-current-token-0000000000000000000000']))->toBe('http://localhost/invitations/the-current-token-0000000000000000000000')
        ->and($invitations->issuedLink($invitation, ['id' => $invitation->id, 'token' => 'a-token-that-was-replaced-00000000000000']))->toBeNull()
        ->and($invitations->issuedLink($invitation, ['id' => 'another-invitation', 'token' => 'the-current-token-0000000000000000000000']))->toBeNull()
        ->and($invitations->issuedLink($invitation, null))->toBeNull();
});

test("the link never reaches a deployer's page", function () {
    $team = teamInvitationsTeam();
    $admin = teamInvitationsMember($team, TeamRole::Admin);
    $deployer = teamInvitationsMember($team, TeamRole::Member, 'Priya Nair');

    teamInvitationsSend($admin, $team, 'new@example.com')->assertSessionHasNoErrors();
    $token = teamInvitationsToken(teamInvitationsLink('new@example.com'));

    $this->actingAs($deployer)->get(route('teams.index'))
        ->assertInertia(fn($page) => $page
            ->where('invitations.0.email', 'new@example.com')
            ->where('invitations.0.link', null)
            ->where('can.manageMembers', false))
        ->assertDontSee($token);

    teamInvitationsSend($admin, $team, 'other@example.com')->assertSessionHasNoErrors();
    $other = teamInvitationsToken(teamInvitationsLink('other@example.com'));
    $team->members()->updateExistingPivot($admin->id, ['role' => TeamRole::Member->value]);

    $this->actingAs($admin->fresh())->get(route('teams.index'))
        ->assertInertia(fn($page) => $page->where('invitations.1.email', 'other@example.com')->where('invitations.1.link', null))
        ->assertDontSee($other);
});

test('on cloud the plan is checked before anyone is invited', function () {
    CloudBilling::enable();
    $starter = Team::factory()->subscribed(Plan::Starter)->owned()->create();
    $lapsed = Team::factory()->trialEnded()->owned()->create();
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create();

    teamInvitationsSend(CloudBilling::owner($starter), $starter, 'new@example.com')
        ->assertSessionHas('error', 'Starter is for one person. Choose Pro or Business to add teammates.');
    teamInvitationsSend(CloudBilling::owner($lapsed), $lapsed, 'new@example.com')
        ->assertSessionHas('error', "This team's trial has ended, so it is read-only. Servers and apps keep running. Choose a plan in Billing settings to make changes again.");

    expect(TeamInvitation::query()->count())->toBe(0);

    Notification::assertNothingSent();

    teamInvitationsSend(CloudBilling::owner($pro), $pro, 'new@example.com')->assertSessionHas('success', 'Invitation sent to new@example.com.');

    expect(TeamInvitation::query()->sole()->team_id)->toBe($pro->id);
});

test('a read-only team can still revoke an invitation', function () {
    CloudBilling::enable();
    $lapsed = Team::factory()->trialEnded()->owned()->create();
    $invitation = TeamInvitation::factory()->for($lapsed)->create();

    $this->actingAs(CloudBilling::owner($lapsed))
        ->delete(route('teams.invitations.destroy', [$lapsed, $invitation]))
        ->assertSessionHas('success', 'Invitation revoked.');

    expect(TeamInvitation::query()->count())->toBe(0);
});
