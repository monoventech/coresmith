<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\SSHCommandTimedOut;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Str;
use Throwable;

final class CronSyncFailure
{
    private const LENGTH = 300;

    public static function reason(Throwable $e, Application $application): string
    {
        $server = (string) $application->server?->name;

        $reason = match (true) {
            $e instanceof HostKeyChangedException => "{$server} presented a different host key, so Coresmith stopped before changing anything. Review the key on the server page, then try again.",
            $e instanceof SSHAuthenticationException => "Coresmith couldn't sign in to {$server} over SSH. Check the server's SSH key, then try again.",
            $e instanceof SSHCommandFailedException => self::refused($e, $server),
            $e instanceof SSHCommandTimedOut, $e instanceof MaxAttemptsExceededException => "{$server} took too long to answer. Try again in a minute.",
            $e instanceof LockTimeoutException => 'Another change to these jobs was still being written. Try again in a minute.',
            $e instanceof UnsafeNameException => "One of these jobs has a schedule cron can't read, so nothing was written. Fix that job, then try again.",
            default => "Couldn't reach {$server} over SSH. Check it's online, then try again.",
        };

        return Str::limit(app(LogSanitizer::class)->sanitize($reason, $application), self::LENGTH, '');
    }

    private static function refused(SSHCommandFailedException $e, string $server): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $e->output())), fn(string $line): bool => $line !== ''));
        $last = $lines === [] ? '' : rtrim((string) end($lines), '.');

        return $last === ''
            ? "{$server} refused the change. Check the server's disk space, then try again."
            : "{$server} refused the change: {$last}. Check the server's disk space, then try again.";
    }
}
