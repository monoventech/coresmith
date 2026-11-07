<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

final class SSHCommandTimedOut extends RuntimeException implements ExplainsFailure
{
    public function __construct(
        public readonly string $command,
        public readonly int $seconds,
        public readonly string $partialOutput,
    ) {
        parent::__construct('`' . $command . '` ran for ' . self::duration($seconds) . ' without finishing, so Coresmith stopped it. Check what it was waiting on, then try again.');
    }

    public static function display(string $command): string
    {
        $command = (string) preg_replace('/^sudo /', '', $command);
        $command = self::inner('~^bash -c \'(.*)\'\z~s', $command);
        $command = self::inner('~^systemd-run .*? -- /bin/bash -c \'(.*)\'\z~s', $command);
        $command = (string) preg_replace('/^cd \S+ && /', '', $command);
        $command = (string) preg_replace('/^PATH=\S+ /', '', $command);

        return Str::limit($command, 100);
    }

    private static function inner(string $pattern, string $command): string
    {
        return preg_match($pattern, $command, $matches) === 1 ? str_replace("'\\''", "'", $matches[1]) : $command;
    }

    public static function duration(int $seconds): string
    {
        if ($seconds % 60 !== 0) {
            return $seconds . ' seconds';
        }

        $minutes = intdiv($seconds, 60);

        return $minutes . ' ' . Str::plural('minute', $minutes);
    }
}
