<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Enums\TerminalEndReason;
use App\Enums\TerminalMode;
use App\Enums\TerminalStatus;
use App\Events\TerminalEnded;
use App\Events\TerminalOutput;
use App\Events\TerminalWarning;
use App\Jobs\RunTerminal;
use App\Models\Application;
use App\Models\Server;
use App\Models\TerminalSession;
use App\Models\User;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Terminal\ServerFingerprint;
use App\Services\Terminal\TerminalChanges;
use App\Services\Terminal\TerminalInbox;
use App\Services\Terminal\TerminalScreen;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function runTerminalSession(array $attributes = [], string $sshUser = 'coresmith'): TerminalSession
{
    $server = Server::factory()->create(['name' => 'web-1', 'ssh_user' => $sshUser, 'status' => ServerStatus::Active]);
    $owner = User::factory()->create();
    $server->team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    return TerminalSession::query()->create(array_merge([
        'team_id' => $server->team_id,
        'server_id' => $server->id,
        'user_id' => $owner->id,
        'mode' => TerminalMode::Root,
        'status' => TerminalStatus::Starting,
        'cols' => 120,
        'rows' => 32,
    ], $attributes));
}

function runTerminalHandle(RunTerminal $job, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([$job, 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
        app(SSHConnectionManager::class)->disconnectAll();
    }
}

function runTerminalPush(TerminalSession $session, array $message): void
{
    app(TerminalInbox::class)->push($session, $message);
}

function runTerminalTyped(string $text, int $n, string $from = 'tab-1'): array
{
    return ['type' => 'input', 'id' => $from, 'n' => $n, 'data' => base64_encode($text)];
}

function runTerminalOutput(): string
{
    return Event::dispatched(TerminalOutput::class)->map(fn(array $call): string => base64_decode($call[0]->broadcastWith()['data']))->implode('');
}

beforeEach(function () {
    Event::fake([TerminalOutput::class, TerminalWarning::class, TerminalEnded::class]);
    $this->travelTo(now()->startOfMinute());
});

test('a root shell opens with sudo, says who you are and how it ends, and streams output in order', function () {
    $session = runTerminalSession();
    $transport = (new FakeSSHTransport)->shellPrints('hello', str_repeat('x', 13000))->shellEndsAfterOutput();

    runTerminalHandle(new RunTerminal($session), $transport);

    $seqs = Event::dispatched(TerminalOutput::class)->map(fn(array $call): int => $call[0]->broadcastWith()['seq'])->all();

    expect($transport->shellCommand())->toBe('sudo -i')
        ->and($transport->shellSizes()[0])->toBe([120, 32])
        ->and($seqs)->toBe([1, 2, 3, 4, 5])
        ->and(runTerminalOutput())->toContain("You're root on web-1.")
        ->toContain('This session ends after 10 minutes without typing, or after an hour, so run long jobs in tmux.')
        ->toEndWith('hello' . str_repeat('x', 13000))
        ->and($session->fresh()->status)->toBe(TerminalStatus::Closed)
        ->and($session->fresh()->end_reason)->toBe(TerminalEndReason::Ended)
        ->and(Activity::query()->where('description', 'terminal_opened')->value('properties')['mode'] ?? null)->toBe('root')
        ->and(Activity::query()->where('description', 'terminal_ended')->value('properties')['reason'] ?? null)->toBe('ended');

    Event::assertDispatched(TerminalEnded::class, fn(TerminalEnded $event): bool => $event->broadcastWith()['reason'] === 'ended');
});

test('Coresmith signed in as root opens a login shell instead of sudo', function () {
    $transport = (new FakeSSHTransport)->shellEndsAfterOutput();

    runTerminalHandle(new RunTerminal(runTerminalSession(sshUser: 'root')), $transport);

    expect($transport->shellCommand())->toBe('bash -l');
});

test("an app's shell runs as the app's user in its live release, with the app's tools on the path", function () {
    $session = runTerminalSession();
    $application = Application::factory()->for($session->server)->create(['name' => 'shop', 'slug' => 'shop', 'current_release' => '20261124-110000']);
    $session->forceFill(['mode' => TerminalMode::App, 'application_id' => $application->id])->save();
    $transport = (new FakeSSHTransport)->shellEndsAfterOutput();

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($transport->shellCommand())->toStartWith('sudo -u ' . escapeshellarg((string) $application->system_user) . ' -H bash -c ')
        ->toContain('/var/coresmith/apps/shop/current')
        ->toContain('exec env PATH=')
        ->and(runTerminalOutput())->toContain("You're shop's user on web-1, in its live release.");
});

test('keystrokes reach the shell in order, and a batch that arrives twice is typed once', function () {
    $session = runTerminalSession();
    $reads = 0;
    $transport = (new FakeSSHTransport)->onShellRead(function () use ($session, &$reads) {
        $reads++;

        if ($reads === 1) {
            runTerminalPush($session, runTerminalTyped('ls', 1));
            runTerminalPush($session, runTerminalTyped(' -la', 2));
            runTerminalPush($session, runTerminalTyped(' -la', 2));
            runTerminalPush($session, runTerminalTyped("\r", 3));
            runTerminalPush($session, runTerminalTyped('pwd', 1, 'tab-2'));
        }

        if ($reads === 2) {
            runTerminalPush($session, ['type' => 'close', 'reason' => 'ended']);
        }
    });

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($transport->shellTyped())->toBe("ls -la\rpwd")
        ->and($session->fresh()->end_reason)->toBe(TerminalEndReason::Ended);
});

test('a resize reaches the shell, kept within sane bounds', function () {
    $session = runTerminalSession();
    $reads = 0;
    $transport = (new FakeSSHTransport)->onShellRead(function () use ($session, &$reads) {
        $reads++;

        if ($reads === 1) {
            runTerminalPush($session, ['type' => 'resize', 'cols' => 9999, 'rows' => 1]);
            runTerminalPush($session, ['type' => 'resize', 'cols' => 96, 'rows' => 30]);
            runTerminalPush($session, ['type' => 'close']);
        }
    });

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($transport->shellSizes())->toBe([[120, 32], [500, 5], [96, 30]]);
});

test('ten minutes without typing ends the session even while output keeps coming, after a warning a minute before', function () {
    $session = runTerminalSession();
    $transport = (new FakeSSHTransport)->onShellRead(function (FakeSSHTransport $transport) use ($session) {
        $transport->shellPrints("tail line\n");
        runTerminalPush($session, ['type' => 'seen']);
        $this->travel(20)->seconds();
    });

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($session->fresh()->end_reason)->toBe(TerminalEndReason::Idle)
        ->and($session->fresh()->ended_at->diffInSeconds($session->fresh()->started_at, true))->toBeGreaterThanOrEqual(600)->toBeLessThan(640);

    Event::assertDispatched(TerminalWarning::class, fn(TerminalWarning $event): bool => $event->broadcastWith()['reason'] === 'idle');
});

test('Keep it open counts as typing', function () {
    $session = runTerminalSession();
    $reads = 0;
    $transport = (new FakeSSHTransport)->onShellRead(function () use ($session, &$reads) {
        $reads++;
        runTerminalPush($session, ['type' => $reads === 28 ? 'keep' : 'seen']);
        $this->travel(20)->seconds();
    });

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($session->fresh()->end_reason)->toBe(TerminalEndReason::Idle)
        ->and($session->fresh()->ended_at->diffInSeconds($session->fresh()->started_at, true))->toBeGreaterThanOrEqual(28 * 20 + 600);
});

test('a session ends after an hour however much you type, with a warning five minutes before', function () {
    $session = runTerminalSession();
    $n = 0;
    $transport = (new FakeSSHTransport)->onShellRead(function () use ($session, &$n) {
        runTerminalPush($session, runTerminalTyped('a', ++$n));
        $this->travel(2)->minutes();
    });

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($session->fresh()->end_reason)->toBe(TerminalEndReason::Hour);

    Event::assertDispatched(TerminalWarning::class, fn(TerminalWarning $event): bool => $event->broadcastWith()['reason'] === 'hour');
});

test('a page that stops checking in ends the session after 30 seconds', function () {
    $session = runTerminalSession();
    $transport = (new FakeSSHTransport)->onShellRead(fn() => $this->travel(11)->seconds());

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($session->fresh()->end_reason)->toBe(TerminalEndReason::Left)
        ->and($session->fresh()->ended_at->diffInSeconds($session->fresh()->started_at, true))->toBeGreaterThanOrEqual(30)->toBeLessThan(45);
});

test('a hidden tab gets 90 seconds between check-ins, since browsers slow its timers', function () {
    $session = runTerminalSession();
    $reads = 0;
    $transport = (new FakeSSHTransport)->onShellRead(function () use ($session, &$reads) {
        $reads++;

        if ($reads === 1) {
            runTerminalPush($session, ['type' => 'seen', 'hidden' => true]);
        }

        $this->travel(11)->seconds();
    });

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($session->fresh()->end_reason)->toBe(TerminalEndReason::Left)
        ->and($session->fresh()->ended_at->diffInSeconds($session->fresh()->started_at, true))->toBeGreaterThanOrEqual(90)->toBeLessThanOrEqual(110);
});

test('a page that comes back gets the screen saved at once', function () {
    $session = runTerminalSession();
    $reads = 0;
    $saved = null;
    $transport = (new FakeSSHTransport)->shellPrints('$ uptime')->onShellRead(function () use ($session, &$reads, &$saved) {
        $reads++;

        if ($reads === 2) {
            runTerminalPush($session, ['type' => 'attach']);
        }

        if ($reads === 3) {
            $saved = app(TerminalScreen::class)->read($session);
            runTerminalPush($session, ['type' => 'close']);
        }
    });

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($saved['seq'] ?? null)->toBe(2)
        ->and(base64_decode($saved['screen'] ?? ''))->toEndWith('$ uptime');
});

test('Coresmith restarting its workers ends the session as an update, and the worker still hears the stop', function () {
    $session = runTerminalSession();
    $heard = 0;
    $worker = function () use (&$heard) {
        $heard++;
    };
    pcntl_signal(SIGTERM, $worker);
    $reads = 0;
    $transport = (new FakeSSHTransport)->onShellRead(function () use ($session, &$reads) {
        $reads++;

        if ($reads === 1) {
            pcntl_signal_get_handler(SIGTERM)(SIGTERM);
        }

        if ($reads === 5) {
            runTerminalPush($session, ['type' => 'close']);
        }
    });

    try {
        runTerminalHandle(new RunTerminal($session), $transport);

        expect($session->fresh()->end_reason)->toBe(TerminalEndReason::Updated)
            ->and($heard)->toBe(1)
            ->and(pcntl_signal_get_handler(SIGTERM))->toBe($worker);
    } finally {
        pcntl_signal(SIGTERM, SIG_DFL);
    }
});

test('a session ended before its job starts never opens a shell', function () {
    $session = runTerminalSession(['status' => TerminalStatus::Closed, 'end_reason' => TerminalEndReason::Ended]);
    $transport = new FakeSSHTransport;

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($transport->shellCommand())->toBeNull();
});

test('a session ended while its shell was opening stops there', function () {
    $session = runTerminalSession();
    $transport = (new FakeSSHTransport)->onShellOpen(fn() => $session->forceFill(['status' => TerminalStatus::Closed, 'end_reason' => TerminalEndReason::Ended])->save());

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($session->fresh()->started_at)->toBeNull()
        ->and(Activity::query()->where('description', 'terminal_opened')->exists())->toBeFalse();

    Event::assertNotDispatched(TerminalOutput::class);
});

test("a server Coresmith can't reach ends the session as failed", function () {
    $session = runTerminalSession();
    $transport = (new FakeSSHTransport)->refuseConnections();

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($session->fresh()->status)->toBe(TerminalStatus::Closed)
        ->and($session->fresh()->end_reason)->toBe(TerminalEndReason::Failed)
        ->and($session->fresh()->end_reason->sentence())->toBe("It couldn't open a shell on the server.");
});

function runTerminalPrint(array $values): string
{
    return implode("\n", array_map(fn(string $key, string $value): string => ServerFingerprint::MARK . " {$key}={$value}", array_keys($values), $values)) . "\n";
}

test('the check after a session names what changed and writes it to Activity', function () {
    $session = runTerminalSession();
    Application::factory()->for($session->server)->create(['name' => 'shop', 'slug' => 'shop', 'processes' => [['name' => 'worker', 'command' => 'php artisan queue:work']]]);
    $same = ['key' => '1', 'sudo' => 'a1', 'units' => 'b1', 'settings' => 'c1', 'web' => 'd1', 'running' => 'active'];
    $transport = (new FakeSSHTransport)
        ->respondToSequence(ServerFingerprint::MARK, [
            [runTerminalPrint([...$same, 'firewall' => 'e1', 'processes' => 'coresmith-app-shop-worker'])],
            [runTerminalPrint([...$same, 'firewall' => 'e2', 'processes' => ''])],
        ])
        ->shellEndsAfterOutput();

    runTerminalHandle(new RunTerminal($session), $transport);

    expect(array_column($session->fresh()->changes, 'key'))->toBe(['firewall', 'processes'])
        ->and($session->fresh()->changes[1]['detail'])->toBe("These stopped: shop's worker. Start them again from each app's page.")
        ->and(Activity::query()->where('description', 'terminal_changed')->sole()->properties['changes'])->toBe(['The firewall rules', "The apps' processes"]);
});

test('changed process settings and stopped processes read as two different changes', function () {
    $server = runTerminalSession()->server;
    $changes = app(TerminalChanges::class)->between($server, ['units' => 'b1', 'processes' => 'coresmith-app-shop-worker'], ['units' => 'b2', 'processes' => '']);

    expect(array_column($changes, 'title'))->toBe(["The apps' process settings", "The apps' processes"]);
});

test('a session that changed nothing says so', function () {
    $session = runTerminalSession();
    $print = runTerminalPrint(['key' => '1', 'sudo' => 'a1', 'units' => 'b1', 'settings' => 'c1', 'web' => 'd1', 'firewall' => 'e1', 'running' => 'active', 'processes' => '']);
    $transport = (new FakeSSHTransport)->respondTo(ServerFingerprint::MARK, $print)->shellEndsAfterOutput();

    runTerminalHandle(new RunTerminal($session), $transport);

    expect($session->fresh()->changes)->toBe([])
        ->and(Activity::query()->where('description', 'terminal_changed')->exists())->toBeFalse();
});

test("removing Coresmith's key marks the server unreachable at once", function () {
    $session = runTerminalSession();
    $reads = 0;
    $transport = (new FakeSSHTransport)->respondTo(ServerFingerprint::MARK, runTerminalPrint(['key' => '1', 'firewall' => 'e1']));
    $transport->onShellRead(function (FakeSSHTransport $transport) use ($session, &$reads) {
        $reads++;

        if ($reads === 1) {
            $transport->failLogin();
            runTerminalPush($session, ['type' => 'close']);
        }
    });

    runTerminalHandle(new RunTerminal($session), $transport);

    expect(array_column($session->fresh()->changes, 'key'))->toBe(['key'])
        ->and($session->server->fresh()->unreachable_since)->not->toBeNull()
        ->and($session->server->fresh()->last_connection_error)->toBe("Coresmith's sign-in key was removed from the server during a terminal session.");
});

test("a server Coresmith couldn't read before the session gets no guesses afterwards", function () {
    $session = runTerminalSession();
    $transport = (new FakeSSHTransport)->shellEndsAfterOutput();

    runTerminalHandle(new RunTerminal($session), $transport);

    expect(array_column($session->fresh()->changes, 'key'))->toBe(['unchecked']);
});

test('the fingerprint reads each thing Coresmith looks after in one command, as root', function () {
    $server = runTerminalSession()->server;
    $script = app(ServerFingerprint::class)->script($server);

    expect($script)->toContain('authorized_keys')
        ->toContain('/etc/sudoers.d/')
        ->toContain('/etc/systemd/system/coresmith-*')
        ->toContain('/etc/coresmith')
        ->toContain('/config/')
        ->toContain('systemctl is-active caddy')
        ->and(ServerFingerprint::parse("motd line\n" . ServerFingerprint::MARK . " key=1\n" . ServerFingerprint::MARK . " web=abc\n"))->toBe(['key' => '1', 'web' => 'abc']);
});
