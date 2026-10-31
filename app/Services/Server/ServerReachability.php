<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandTimedOut;
use App\Models\Server;
use App\Notifications\Preferences;
use App\Notifications\ServerReachableNotification;
use App\Notifications\ServerUnreachableNotification;
use App\Notifications\TeamNotifier;
use App\Services\Panel\Formats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\UnableToConnectException;
use RuntimeException;
use Throwable;

final class ServerReachability
{
    public const FAILURES_BEFORE_UNREACHABLE = 3;

    private const FAILURES_CEILING = 255;

    private const PRE_BANNER_FAILURES = ['Connection timed out whilst', 'Error reading SSH identification string'];

    public static function isConnectionFailure(Throwable $e): bool
    {
        return $e instanceof UnableToConnectException
            || $e instanceof ConnectionClosedException
            || $e instanceof SSHAuthenticationException
            || $e instanceof SSHCommandTimedOut
            || self::isPreBannerFailure($e);
    }

    public static function reason(Server $server, Throwable $e): string
    {
        return match (true) {
            $e instanceof SSHAuthenticationException => "The server refused Coresmith's key for {$server->ssh_user}. Check the key is still in ~/.ssh/authorized_keys.",
            $e instanceof SSHCommandTimedOut, $e instanceof ConnectionClosedException, self::isPreBannerFailure($e) => 'The server took the connection but stopped answering. It may be overloaded or rebooting.',
            default => "The connection to port {$server->ssh_port} was refused or timed out. Check the server is running and its firewall allows SSH from this panel.",
        };
    }

    public function failed(Server $server, Throwable $e): void
    {
        $reason = self::reason($server, $e);
        $ceiling = self::FAILURES_CEILING;

        $this->query($server)->update([
            'connection_failures' => DB::raw("CASE WHEN connection_failures < {$ceiling} THEN connection_failures + 1 ELSE {$ceiling} END"),
            'last_connection_error' => $reason,
        ]);

        $server->refresh();

        if ($server->connection_failures < self::FAILURES_BEFORE_UNREACHABLE) {
            return;
        }

        $markedAt = now();

        if ($this->query($server)->whereNull('unreachable_since')->update(['unreachable_since' => $markedAt]) !== 1) {
            return;
        }

        $server->forceFill(['unreachable_since' => $markedAt])->syncOriginalAttribute('unreachable_since');

        activity()
            ->performedOn($server)
            ->withProperties(['error' => $reason])
            ->log('server_unreachable');

        $this->alert($server, new ServerUnreachableNotification($server, $reason));
    }

    public function answered(Server $server): void
    {
        $outageFrom = $server->last_connected_at ?? $server->unreachable_since;
        $now = now();
        $answer = ['last_connected_at' => $now, 'connection_failures' => 0, 'last_connection_error' => null];

        $recovered = $server->unreachable_since !== null
            && $this->query($server)->whereNotNull('unreachable_since')->update([...$answer, 'unreachable_since' => null]) === 1;

        if (!$recovered) {
            $this->query($server)->update($answer);
        }

        $server->forceFill([...$answer, 'unreachable_since' => null])->syncOriginalAttributes([...array_keys($answer), 'unreachable_since']);

        if (!$recovered) {
            return;
        }

        $seconds = $outageFrom === null ? 0 : max(0, (int) $outageFrom->diffInSeconds($now));

        activity()
            ->performedOn($server)
            ->withProperties(['seconds' => $seconds])
            ->log('server_reachable');

        $this->alert($server, new ServerReachableNotification($server, Formats::downFor($seconds)));
    }

    private function alert(Server $server, Notification $notification): void
    {
        try {
            app(TeamNotifier::class)->send($server->team, Preferences::SERVER_ALERTS, $notification);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function isPreBannerFailure(Throwable $e): bool
    {
        if (!$e instanceof RuntimeException) {
            return false;
        }

        foreach (self::PRE_BANNER_FAILURES as $prefix) {
            if (str_starts_with($e->getMessage(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function query(Server $server): Builder
    {
        return Server::query()->whereKey($server->getKey());
    }
}
