<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Teams\TeamInvitations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * @return array{Team, TeamInvitation, string}
 */
function invitationRaceInvite(): array
{
    $team = Team::factory()->create(['name' => 'Acme Labs', 'slug' => 'acme']);
    $token = Str::random(40);
    $invitation = TeamInvitation::factory()->for($team)->withToken($token)->create(['email' => 'new@example.com']);

    return [$team, $invitation, $token];
}

function invitationRaceRegister(string $token): TestResponse
{
    return test()->from(route('invitations.show', $token))->post(route('invitations.register', $token), [
        'name' => 'Noor Haddad',
        'password' => 'a-long-enough-passphrase',
        'password_confirmation' => 'a-long-enough-passphrase',
    ]);
}

function invitationRaceFailure(string $message): QueryException
{
    return new QueryException('sqlite', 'update "team_invitations" set "accepted_at" = ?', [], new PDOException($message));
}

test('joining refuses an invitation that was accepted after it was opened', function () {
    [$team, , $token] = invitationRaceInvite();
    $stale = TeamInvitation::usable($token);
    TeamInvitation::query()->whereKey($stale->id)->update(['accepted_at' => now()]);
    $user = User::factory()->create(['email' => 'new@example.com']);

    expect(app(TeamInvitations::class)->join($stale, $user))->toBeFalse()
        ->and($user->fresh()->belongsToTeam($team))->toBeFalse();
});

test('joining refuses an invitation whose link was replaced after it was opened', function () {
    [$team, , $token] = invitationRaceInvite();
    $stale = TeamInvitation::usable($token);
    TeamInvitation::query()->whereKey($stale->id)->update(['token_hash' => TeamInvitation::hash('a-newer-token')]);
    $user = User::factory()->create(['email' => 'new@example.com']);

    expect(app(TeamInvitations::class)->join($stale, $user))->toBeFalse()
        ->and($user->fresh()->belongsToTeam($team))->toBeFalse();
});

test('joining refuses an account the invitation is not for', function () {
    [$team, $invitation] = invitationRaceInvite();
    $user = User::factory()->create(['email' => 'someone-else@example.com']);

    expect(app(TeamInvitations::class)->join($invitation, $user))->toBeFalse()
        ->and($user->fresh()->belongsToTeam($team))->toBeFalse()
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('a new account is rolled back when the invitation is used up while it is made', function () {
    [, $invitation, $token] = invitationRaceInvite();

    User::created(function () use ($invitation): void {
        TeamInvitation::query()->whereKey($invitation->id)->update(['accepted_at' => now()]);
    });

    invitationRaceRegister($token)->assertRedirect(route('invitations.show', $token));

    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();

    $this->assertGuest();
});

test('a request that loses the race to make the account lands on the invitation', function (Closure $race) {
    [, , $token] = invitationRaceInvite();

    User::creating($race);

    invitationRaceRegister($token)->assertRedirect(route('invitations.show', $token));

    expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse();

    $this->assertGuest();
})->with([
    'another request made the account first' => [function (User $user): void {
        DB::table('users')->insert(['id' => (string) Str::ulid(), 'name' => 'First', 'email' => $user->email, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }],
    'the database was busy' => [function (): void {
        throw invitationRaceFailure('SQLSTATE[HY000]: General error: 5 database is locked');
    }],
]);

test('a request that loses the race to join lands on the invitation', function () {
    [$team, , $token] = invitationRaceInvite();
    $user = User::factory()->create(['email' => 'new@example.com']);

    DB::connection()->beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'select') && str_contains($query, DB::getQueryGrammar()->wrap('team_invitations.id') . ' = ?')) {
            throw invitationRaceFailure('SQLSTATE[HY000]: General error: 5 database is locked');
        }
    });

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertRedirect(route('invitations.show', $token));

    expect($user->fresh()->belongsToTeam($team))->toBeFalse();
});

test('any other database failure is not mistaken for a lost race', function () {
    [, , $token] = invitationRaceInvite();
    $user = User::factory()->create(['email' => 'new@example.com']);

    TeamInvitation::updating(function (): void {
        throw invitationRaceFailure('SQLSTATE[HY000]: General error: 1 no such column: accepted_at');
    });

    $this->actingAs($user)->post(route('invitations.accept', $token))->assertServerError();
});

test('a key sync that fails after joining is reported and the person still lands on the team', function (string $path) {
    Exceptions::fake();
    [$team, $invitation, $token] = invitationRaceInvite();
    Server::factory()->for($team)->active()->create();
    Bus::shouldReceive('dispatch')->andThrow(invitationRaceFailure('SQLSTATE[HY000]: General error: 5 database is locked'));

    if ($path === 'accept') {
        $this->actingAs(User::factory()->create(['email' => 'new@example.com']))
            ->post(route('invitations.accept', $token))
            ->assertRedirect('http://localhost/acme/dashboard');
    } else {
        invitationRaceRegister($token)->assertRedirect('http://localhost/acme/dashboard');
    }

    $user = User::query()->where('email', 'new@example.com')->sole();

    $this->assertAuthenticatedAs($user);

    expect($user->belongsToTeam($team))->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    Exceptions::assertReported(fn(QueryException $exception): bool => str_contains($exception->getMessage(), 'database is locked'));
})->with(['register', 'accept']);
