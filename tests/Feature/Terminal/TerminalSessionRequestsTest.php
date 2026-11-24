<?php

declare(strict_types=1);

use App\Broadcasting\TerminalChannel;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Enums\TerminalEndReason;
use App\Enums\TerminalMode;
use App\Enums\TerminalStatus;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\Terminal\TerminalInbox;
use App\Services\Terminal\TerminalScreen;

function terminalRequestsSession(TerminalStatus $status = TerminalStatus::Open): TerminalSession
{
    $server = Server::factory()->create(['status' => ServerStatus::Active]);
    $owner = User::factory()->create();
    $server->team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    return TerminalSession::query()->create(['team_id' => $server->team_id, 'server_id' => $server->id, 'user_id' => $owner->id, 'mode' => TerminalMode::Root, 'status' => $status, 'started_at' => now()]);
}

function terminalRequestsUrl(string $name, TerminalSession $session): string
{
    return route($name, [$session->server, $session]);
}

test('keystrokes go to the session job in the order they were posted', function () {
    $session = terminalRequestsSession();

    $this->actingAs($session->user)
        ->postJson(terminalRequestsUrl('servers.terminal.input', $session), ['id' => 'tab-1', 'n' => 1, 'data' => base64_encode('ls')])
        ->assertOk()
        ->assertJson(['status' => 'open', 'ended' => null]);
    $this->actingAs($session->user)->postJson(terminalRequestsUrl('servers.terminal.input', $session), ['id' => 'tab-1', 'n' => 2, 'data' => base64_encode("\r")]);

    expect(app(TerminalInbox::class)->drain($session))->toBe([
        ['type' => 'input', 'id' => 'tab-1', 'n' => 1, 'data' => base64_encode('ls')],
        ['type' => 'input', 'id' => 'tab-1', 'n' => 2, 'data' => base64_encode("\r")],
    ]);
});

test('a paste up to 8 KB goes through, and anything larger or not base64 is refused', function () {
    $session = terminalRequestsSession();
    $url = terminalRequestsUrl('servers.terminal.input', $session);

    $this->actingAs($session->user)->postJson($url, ['id' => 'tab-1', 'n' => 1, 'data' => base64_encode(str_repeat('a', 8192))])->assertOk();
    $this->actingAs($session->user)->postJson($url, ['id' => 'tab-1', 'n' => 2, 'data' => base64_encode(str_repeat('a', 8193))])->assertUnprocessable();
    $this->actingAs($session->user)->postJson($url, ['id' => 'tab-1', 'n' => 3, 'data' => '%%%'])->assertUnprocessable();

    expect(app(TerminalInbox::class)->drain($session))->toHaveCount(1);
});

test("nobody else can type into someone's session, not even another owner", function () {
    $session = terminalRequestsSession();
    $other = User::factory()->create();
    $session->team->members()->attach($other, ['role' => TeamRole::Owner->value]);

    foreach (['servers.terminal.input', 'servers.terminal.resize', 'servers.terminal.check-in'] as $name) {
        $this->actingAs($other)->postJson(terminalRequestsUrl($name, $session), ['id' => 'x', 'n' => 1, 'data' => base64_encode('id'), 'cols' => 80, 'rows' => 24])->assertNotFound();
    }

    $this->actingAs($other)->getJson(terminalRequestsUrl('servers.terminal.screen', $session))->assertNotFound();
    $this->actingAs($other)->deleteJson(terminalRequestsUrl('servers.terminal.destroy', $session))->assertNotFound();

    expect(app(TerminalInbox::class)->drain($session))->toBe([])
        ->and($session->fresh()->status)->toBe(TerminalStatus::Open);
});

test('a resize and a check-in reach the job, and Keep it open counts as typing', function () {
    $session = terminalRequestsSession();

    $this->actingAs($session->user)->postJson(terminalRequestsUrl('servers.terminal.resize', $session), ['cols' => 132, 'rows' => 43])->assertOk();
    $this->actingAs($session->user)->postJson(terminalRequestsUrl('servers.terminal.resize', $session), ['cols' => 9000, 'rows' => 43])->assertUnprocessable();
    $this->actingAs($session->user)->postJson(terminalRequestsUrl('servers.terminal.check-in', $session), ['hidden' => true])->assertOk();
    $this->actingAs($session->user)->postJson(terminalRequestsUrl('servers.terminal.check-in', $session), ['keep' => true])->assertOk();

    expect(app(TerminalInbox::class)->drain($session))->toBe([
        ['type' => 'resize', 'cols' => 132, 'rows' => 43],
        ['type' => 'seen', 'hidden' => true],
        ['type' => 'keep', 'hidden' => false],
    ]);
});

test('a page that comes back asks the job for the screen and gets the latest one saved', function () {
    $session = terminalRequestsSession();
    app(TerminalScreen::class)->save($session, "\$ uptime\r\n 12:00 up 3 days", 7);

    $this->actingAs($session->user)
        ->getJson(terminalRequestsUrl('servers.terminal.screen', $session))
        ->assertOk()
        ->assertJson(['seq' => 7, 'screen' => base64_encode("\$ uptime\r\n 12:00 up 3 days"), 'status' => 'open', 'ended' => null]);

    expect(app(TerminalInbox::class)->drain($session))->toBe([['type' => 'attach']]);
});

test('a finished session answers with how it ended and takes no more input', function () {
    $session = terminalRequestsSession(TerminalStatus::Closed);
    $session->forceFill(['end_reason' => TerminalEndReason::Idle, 'ended_at' => now()->addMinutes(12)])->save();

    $this->actingAs($session->user)
        ->postJson(terminalRequestsUrl('servers.terminal.input', $session), ['id' => 'tab-1', 'n' => 1, 'data' => base64_encode('ls')])
        ->assertOk()
        ->assertJson(['status' => 'closed', 'ended' => ['reason' => 'idle', 'sentence' => 'It ended after 10 minutes without typing.', 'minutes' => 12]]);

    expect(app(TerminalInbox::class)->drain($session))->toBe([]);
});

test('ending an open session asks its job to close the shell', function () {
    $session = terminalRequestsSession();

    $this->actingAs($session->user)->deleteJson(terminalRequestsUrl('servers.terminal.destroy', $session))->assertOk();

    expect(app(TerminalInbox::class)->drain($session))->toBe([['type' => 'close', 'reason' => 'ended']]);
});

test('ending a session whose job has not started closes it at once', function () {
    $session = terminalRequestsSession(TerminalStatus::Starting);

    $this->actingAs($session->user)
        ->deleteJson(terminalRequestsUrl('servers.terminal.destroy', $session))
        ->assertOk()
        ->assertJson(['status' => 'closed', 'ended' => ['reason' => 'ended']]);

    expect($session->fresh()->end_reason)->toBe(TerminalEndReason::Ended);
});

test("a session's channel is only for the person who opened it", function () {
    $session = terminalRequestsSession();
    $other = User::factory()->create();
    $session->team->members()->attach($other, ['role' => TeamRole::Owner->value]);
    $channel = new TerminalChannel;

    expect($channel->join($session->user, (string) $session->id))->toBeTrue()
        ->and($channel->join($other, (string) $session->id))->toBeFalse()
        ->and($channel->join($session->user, 'missing'))->toBeFalse();
});

test('someone who lost the right to a root shell is cut off at their next keystroke', function () {
    $session = terminalRequestsSession();
    $session->team->forceFill(['root_terminals' => false])->save();

    $this->actingAs($session->user)
        ->postJson(terminalRequestsUrl('servers.terminal.input', $session), ['id' => 'tab-1', 'n' => 1, 'data' => base64_encode('id')])
        ->assertOk()
        ->assertJson(['status' => 'closed', 'ended' => ['reason' => 'root_off']]);

    expect(app(TerminalInbox::class)->drain($session))->toBe([['type' => 'close', 'reason' => 'root_off']]);
});
