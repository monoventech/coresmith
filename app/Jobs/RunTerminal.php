<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TerminalEndReason;
use App\Enums\TerminalMode;
use App\Enums\TerminalStatus;
use App\Events\TerminalOutput;
use App\Events\TerminalWarning;
use App\Exceptions\SSHAuthenticationException;
use App\Models\TerminalSession;
use App\Services\Deployment\ReleasePaths;
use App\Services\Process\SystemdUnitBuilder;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Terminal\ServerFingerprint;
use App\Services\Terminal\TerminalChanges;
use App\Services\Terminal\TerminalInbox;
use App\Services\Terminal\TerminalScreen;
use App\Services\Terminal\TerminalSessions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class RunTerminal implements ShouldQueue
{
    use Queueable;

    public const READ_SECONDS = 0.03;

    public const PIECE_BYTES = 6000;

    public const IDLE_SECONDS = 600;

    public const LIMIT_SECONDS = 3600;

    public const CHECK_IN_SECONDS = 30;

    public const HIDDEN_CHECK_IN_SECONDS = 90;

    public const IDLE_WARNING_SECONDS = 60;

    public const LIMIT_WARNING_SECONDS = 300;

    public const SAVE_SECONDS = 15;

    public const SCREEN_SECONDS = 1;

    public int $tries = 1;

    public int $timeout = 4200;

    public bool $deleteWhenMissingModels = true;

    private int $seq = 0;

    private string $screen = '';

    private bool $screenChanged = false;

    private bool $stopping = false;

    private bool $terminated = false;

    public function __construct(public readonly TerminalSession $session)
    {
        $this->onQueue('terminals');
    }

    public function handle(SSHConnectionManager $connections, TerminalInbox $inbox, TerminalScreen $screens, SystemdUnitBuilder $units, ServerFingerprint $fingerprints, TerminalChanges $changes): void
    {
        $session = $this->session->fresh();

        if (!$session instanceof TerminalSession || !$session->live()) {
            return;
        }

        $server = $session->server;
        $previous = $this->listenForStop();
        $before = null;

        try {
            $connection = $connections->for($server);

            try {
                $fingerprint = $fingerprints->take($connection, $server);
                $connection->openShell((int) $session->cols, (int) $session->rows, $this->command($session, $units));

                $opened = TerminalSession::query()
                    ->whereKey($session->getKey())
                    ->where('status', TerminalStatus::Starting->value)
                    ->update(['status' => TerminalStatus::Open->value, 'started_at' => now(), 'last_input_at' => now(), 'last_seen_at' => now()]);

                if ($opened === 0) {
                    return;
                }

                $before = $fingerprint;
                $session->refresh();
                activity()->performedOn($server)->causedBy($session->user)->withProperties(['mode' => $session->mode->value, 'app' => $session->application?->name, 'by' => $session->user?->name])->log('terminal_opened');
                $this->emit($session, $this->opening($session));
                $this->run($session, $connection, $inbox, $screens);
            } finally {
                $connections->disconnect($server);
            }
        } catch (Throwable $e) {
            $this->end($session, TerminalEndReason::Failed, $screens);

            report($e);
        } finally {
            $this->restore($previous);
        }

        if ($before !== null) {
            $this->check($session, $connections, $fingerprints, $changes, $before);
        }
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    private function run(TerminalSession $session, SSHConnection $connection, TerminalInbox $inbox, TerminalScreen $screens): void
    {
        $opened = CarbonImmutable::now();
        $typed = $opened;
        $seen = $opened;
        $saved = $opened;
        $shown = $opened;
        $warned = [];
        $applied = [];
        $hidden = false;

        while (true) {
            if ($this->stopping) {
                $this->end($session, TerminalEndReason::Updated, $screens);

                return;
            }

            $output = $connection->readShell(self::READ_SECONDS);

            if ($output === null) {
                $this->end($session, TerminalEndReason::Ended, $screens);

                return;
            }

            if ($output !== '') {
                $this->emit($session, $output);
            }

            foreach ($inbox->drain($session) as $message) {
                $type = (string) ($message['type'] ?? '');
                $now = CarbonImmutable::now();

                if ($type === 'close') {
                    $this->end($session, TerminalEndReason::tryFrom((string) ($message['reason'] ?? '')) ?? TerminalEndReason::Ended, $screens);

                    return;
                }

                if ($type === 'attach') {
                    $screens->save($session, $this->screen, $this->seq);
                    $this->screenChanged = false;
                    $shown = $now;
                }

                if ($type === 'resize') {
                    $connection->resizeShell(max(20, min(500, (int) ($message['cols'] ?? 80))), max(5, min(200, (int) ($message['rows'] ?? 24))));
                }

                if ($type === 'input') {
                    $from = (string) ($message['id'] ?? '');
                    $n = (int) ($message['n'] ?? 0);

                    if ($n <= ($applied[$from] ?? 0)) {
                        continue;
                    }

                    $applied[$from] = $n;
                    $connection->writeShell((string) base64_decode((string) ($message['data'] ?? ''), true));
                }

                if (in_array($type, ['input', 'keep'], true)) {
                    $typed = $now;
                    unset($warned['idle']);
                }

                if (in_array($type, ['input', 'keep', 'seen', 'attach'], true)) {
                    $seen = $now;
                    $hidden = (bool) ($message['hidden'] ?? false);
                }
            }

            $now = CarbonImmutable::now();
            $reason = match (true) {
                $typed->diffInSeconds($now) >= self::IDLE_SECONDS => TerminalEndReason::Idle,
                $opened->diffInSeconds($now) >= self::LIMIT_SECONDS => TerminalEndReason::Hour,
                $seen->diffInSeconds($now) >= ($hidden ? self::HIDDEN_CHECK_IN_SECONDS : self::CHECK_IN_SECONDS) => TerminalEndReason::Left,
                default => null,
            };

            if ($reason !== null) {
                $this->end($session, $reason, $screens);

                return;
            }

            if (!isset($warned['idle']) && $typed->diffInSeconds($now) >= self::IDLE_SECONDS - self::IDLE_WARNING_SECONDS) {
                $warned['idle'] = true;
                rescue(fn() => event(new TerminalWarning((string) $session->getKey(), 'idle', $typed->addSeconds(self::IDLE_SECONDS)->getTimestamp())), report: false);
            }

            if (!isset($warned['hour']) && $opened->diffInSeconds($now) >= self::LIMIT_SECONDS - self::LIMIT_WARNING_SECONDS) {
                $warned['hour'] = true;
                rescue(fn() => event(new TerminalWarning((string) $session->getKey(), 'hour', $opened->addSeconds(self::LIMIT_SECONDS)->getTimestamp())), report: false);
            }

            if ($this->screenChanged && $shown->diffInSeconds($now) >= self::SCREEN_SECONDS) {
                $shown = $now;
                $screens->save($session, $this->screen, $this->seq);
                $this->screenChanged = false;
            }

            if ($saved->diffInSeconds($now) >= self::SAVE_SECONDS) {
                $saved = $now;
                $session->forceFill(['last_input_at' => $typed, 'last_seen_at' => $seen])->save();
            }
        }
    }

    private function emit(TerminalSession $session, string $bytes): void
    {
        $this->screen = substr($this->screen . $bytes, -TerminalScreen::BYTES);
        $this->screenChanged = true;

        foreach (str_split($bytes, self::PIECE_BYTES) as $piece) {
            $this->seq++;
            rescue(fn() => event(new TerminalOutput((string) $session->getKey(), $this->seq, $piece)), report: false);
        }
    }

    private function end(TerminalSession $session, TerminalEndReason $reason, TerminalScreen $screens): void
    {
        app(TerminalSessions::class)->end($session, $reason);
        $screens->forget($session);
        app(TerminalInbox::class)->forget($session);
        $this->screen = '';
    }

    /**
     * @param  array<string, string>  $before
     */
    private function check(TerminalSession $session, SSHConnectionManager $connections, ServerFingerprint $fingerprints, TerminalChanges $changes, array $before): void
    {
        $server = $session->server->fresh() ?? $session->server;
        $connection = $connections->fresh($server);

        try {
            $connection->connect();
            $after = $fingerprints->take($connection, $server);
            $items = $before === [] || $after === [] ? [TerminalChanges::unchecked()] : $changes->between($server, $before, $after);
        } catch (SSHAuthenticationException) {
            $items = [(int) ($before['key'] ?? 0) > 0 ? TerminalChanges::lostKey($server) : TerminalChanges::unchecked()];

            if ($items[0]['key'] === 'key') {
                $server->forceFill(['unreachable_since' => $server->unreachable_since ?? now(), 'last_connection_error' => "Coresmith's sign-in key was removed from the server during a terminal session."])->save();
            }
        } catch (Throwable) {
            $items = [TerminalChanges::unchecked()];
        } finally {
            rescue(fn() => $connection->disconnect(), report: false);
        }

        $session->forceFill(['changes' => $items])->save();

        if ($items !== [] && $items[0]['key'] !== 'unchecked') {
            activity()->performedOn($server)->causedBy($session->user)->withProperties(['changes' => array_column($items, 'title'), 'by' => $session->user?->name])->log('terminal_changed');
        }
    }

    private function listenForStop(): mixed
    {
        if (!function_exists('pcntl_signal')) {
            return null;
        }

        $previous = pcntl_signal_get_handler(SIGTERM);

        pcntl_signal(SIGTERM, function (int $signal, mixed $info = null) use ($previous): void {
            $this->stop();

            if (is_callable($previous)) {
                $previous($signal, $info);
            } elseif ($previous === SIG_DFL) {
                $this->terminated = true;
            }
        });

        return $previous;
    }

    private function restore(mixed $previous): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        if (is_callable($previous) || is_int($previous)) {
            pcntl_signal(SIGTERM, $previous);
        }

        if ($this->terminated && function_exists('posix_kill')) {
            posix_kill(getmypid(), SIGTERM);
        }
    }

    private function command(TerminalSession $session, SystemdUnitBuilder $units): string
    {
        $server = $session->server;

        if ($session->mode === TerminalMode::Root) {
            return $server->ssh_user === 'root' ? 'bash -l' : 'sudo -i';
        }

        $application = $session->application;
        $paths = new ReleasePaths($application);
        $shell = 'cd ' . escapeshellarg($paths->appDirectory($paths->current())) . ' && exec env PATH=' . escapeshellarg($units->path($application)) . ' bash';

        return 'sudo -u ' . escapeshellarg((string) $application->system_user) . ' -H bash -c ' . escapeshellarg($shell);
    }

    private function opening(TerminalSession $session): string
    {
        $where = $session->server->name;
        $who = $session->mode === TerminalMode::Root ? "You're root on {$where}." : "You're {$session->application?->name}'s user on {$where}, in its live release.";

        return "\e[2m{$who} A check runs when you finish and names anything Coresmith looks after that changed.\r\n"
            . "This session ends after 10 minutes without typing, or after an hour, so run long jobs in tmux.\e[0m\r\n\r\n";
    }
}
