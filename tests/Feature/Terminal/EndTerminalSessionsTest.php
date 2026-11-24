<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Enums\TerminalEndReason;
use App\Enums\TerminalMode;
use App\Enums\TerminalStatus;
use App\Events\TerminalEnded;
use App\Models\Server;
use App\Models\Team;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\Terminal\TerminalInbox;
use App\Services\Terminal\TerminalScreen;
use App\Services\Terminal\TerminalSessions;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Spatie\Activitylog\Models\Activity;

function endTerminalsMember(Team $team, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function endTerminalsSession(User $user, Server $server, TerminalMode $mode = TerminalMode::Root, TerminalStatus $status = TerminalStatus::Open): TerminalSession
{
    return TerminalSession::query()->create(['team_id' => $server->team_id, 'server_id' => $server->id, 'user_id' => $user->id, 'mode' => $mode, 'status' => $status, 'started_at' => now()]);
}

function endTerminalsServer(?Team $team = null, array $attributes = []): Server
{
    return Server::factory()->create(array_merge(['status' => ServerStatus::Active], $team === null ? [] : ['team_id' => $team->id], $attributes));
}

test('ending a session closes it, tells its page and its job, and writes it to Activity', function () {
    Event::fake([TerminalEnded::class]);
    $server = endTerminalsServer();
    $session = endTerminalsSession(endTerminalsMember($server->team), $server);

    event(new Logout('web', $session->user));

    expect($session->fresh()->status)->toBe(TerminalStatus::Closed)
        ->and($session->fresh()->end_reason)->toBe(TerminalEndReason::SignedOut)
        ->and(app(TerminalInbox::class)->drain($session))->toBe([['type' => 'close', 'reason' => 'signed_out']])
        ->and(Activity::query()->where('description', 'terminal_ended')->sole()->properties['reason'])->toBe('signed_out');

    Event::assertDispatched(TerminalEnded::class, fn(TerminalEnded $event): bool => $event->sessionId === $session->id);
});

test("signing out ends only that person's sessions", function () {
    $server = endTerminalsServer();
    $mine = endTerminalsSession(endTerminalsMember($server->team), $server);
    $theirs = endTerminalsSession(endTerminalsMember($server->team), endTerminalsServer($server->team));

    event(new Logout('web', $mine->user));

    expect($mine->fresh()->status)->toBe(TerminalStatus::Closed)
        ->and($theirs->fresh()->status)->toBe(TerminalStatus::Open);
});

test('a password change and turning off two-factor sign-in end the sessions', function () {
    $server = endTerminalsServer();
    $owner = endTerminalsMember($server->team);
    $first = endTerminalsSession($owner, $server);

    $owner->update(['password' => 'a brand new password']);

    expect($first->fresh()->end_reason)->toBe(TerminalEndReason::PasswordChanged);

    $second = endTerminalsSession($owner, $server);
    event(new TwoFactorAuthenticationDisabled($owner));

    expect($second->fresh()->end_reason)->toBe(TerminalEndReason::TwoFactorOff);
});

test("a role change ends the person's sessions in that team only", function () {
    Queue::fake();
    $server = endTerminalsServer();
    $owner = endTerminalsMember($server->team);
    $developer = endTerminalsMember($server->team, TeamRole::Developer);
    $here = endTerminalsSession($developer, $server, TerminalMode::App);
    $elsewhere = endTerminalsServer();
    $elsewhere->team->members()->attach($developer, ['role' => TeamRole::Owner->value]);
    $there = endTerminalsSession($developer, $elsewhere);

    $this->actingAs($owner)->put(route('teams.members.update', [$server->team, $developer]), ['role' => TeamRole::Developer->value]);

    expect($here->fresh()->status)->toBe(TerminalStatus::Open);

    $this->actingAs($owner)->put(route('teams.members.update', [$server->team, $developer]), ['role' => TeamRole::Member->value]);

    expect($here->fresh()->end_reason)->toBe(TerminalEndReason::RoleChanged)
        ->and($there->fresh()->status)->toBe(TerminalStatus::Open);
});

test('removing someone from the team ends their sessions there', function () {
    Queue::fake();
    $server = endTerminalsServer();
    $owner = endTerminalsMember($server->team);
    $other = endTerminalsMember($server->team);
    $session = endTerminalsSession($other, $server);

    $this->actingAs($owner)->delete(route('teams.members.destroy', [$server->team, $other]));

    expect($session->fresh()->end_reason)->toBe(TerminalEndReason::LeftTeam);
});

test("turning Root terminals off ends the team's root shells and leaves app shells open", function () {
    $server = endTerminalsServer();
    $owner = endTerminalsMember($server->team);
    $root = endTerminalsSession($owner, $server);
    $app = endTerminalsSession(endTerminalsMember($server->team), endTerminalsServer($server->team), TerminalMode::App);
    $otherTeam = endTerminalsServer();
    $otherRoot = endTerminalsSession(endTerminalsMember($otherTeam->team), $otherTeam);

    $this->actingAs($owner)->put(route('settings.root-terminals.update'), ['root_terminals' => false]);

    expect($root->fresh()->end_reason)->toBe(TerminalEndReason::RootOff)
        ->and($app->fresh()->status)->toBe(TerminalStatus::Open)
        ->and($otherRoot->fresh()->status)->toBe(TerminalStatus::Open);
});

test('the minute sweep ends sessions whose server, rights or job are gone, and leaves healthy ones', function () {
    $team = Team::factory()->create();
    $owner = endTerminalsMember($team);
    $developer = endTerminalsMember($team, TeamRole::Developer);

    $healthy = endTerminalsSession($owner, endTerminalsServer($team));
    $unreachable = endTerminalsSession($owner, endTerminalsServer($team, ['unreachable_since' => now()]));
    $moving = endTerminalsSession($owner, endTerminalsServer($team, ['status' => ServerStatus::Moving]));
    $removed = endTerminalsSession($owner, tap(endTerminalsServer($team))->delete());
    $notTheirs = endTerminalsSession($developer, endTerminalsServer($team, ['created_by' => $owner->id]), TerminalMode::App);
    $stale = endTerminalsSession($owner, endTerminalsServer($team));
    TerminalSession::query()->whereKey($stale->id)->update(['updated_at' => now()->subMinutes(3)]);
    $neverStarted = endTerminalsSession($owner, endTerminalsServer($team), status: TerminalStatus::Starting);
    TerminalSession::query()->whereKey($neverStarted->id)->update(['updated_at' => now()->subMinutes(3)]);

    $this->artisan('coresmith:sweep-terminals')->expectsOutput('Ended 6 sessions.')->assertSuccessful();

    expect($healthy->fresh()->status)->toBe(TerminalStatus::Open)
        ->and($unreachable->fresh()->end_reason)->toBe(TerminalEndReason::ServerUnavailable)
        ->and($moving->fresh()->end_reason)->toBe(TerminalEndReason::ServerUnavailable)
        ->and($removed->fresh()->end_reason)->toBe(TerminalEndReason::ServerUnavailable)
        ->and($notTheirs->fresh()->end_reason)->toBe(TerminalEndReason::RoleChanged)
        ->and($stale->fresh()->end_reason)->toBe(TerminalEndReason::Failed)
        ->and($neverStarted->fresh()->end_reason)->toBe(TerminalEndReason::Failed);
});

test('the sweep ends root shells when the switch was turned off another way', function () {
    $server = endTerminalsServer();
    $session = endTerminalsSession(endTerminalsMember($server->team), $server);
    $server->team->forceFill(['root_terminals' => false])->save();

    $this->artisan('coresmith:sweep-terminals')->assertSuccessful();

    expect($session->fresh()->end_reason)->toBe(TerminalEndReason::RootOff);
});

test('the sweep runs every minute', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn($event) => str_contains((string) $event->command, 'coresmith:sweep-terminals'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *');
});

test('an ended session keeps none of what was typed or shown', function () {
    $server = endTerminalsServer();
    $session = endTerminalsSession(endTerminalsMember($server->team), $server);
    app(TerminalInbox::class)->push($session, ['type' => 'input', 'id' => 'tab-1', 'n' => 1, 'data' => base64_encode('secret-password')]);
    app(TerminalScreen::class)->save($session, 'mysql -p secret-password', 4);

    app(TerminalSessions::class)->end($session, TerminalEndReason::Ended);

    expect(app(TerminalInbox::class)->drain($session))->toBe([['type' => 'close', 'reason' => 'ended']])
        ->and(app(TerminalScreen::class)->read($session))->toBe(['screen' => '', 'seq' => 0]);
});
