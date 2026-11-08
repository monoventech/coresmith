<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

use App\Services\SafeName;
use App\Services\Server\SecurityHardening;
use InvalidArgumentException;

final class AuthorizedKeyCommand
{
    private const SAFE_COMMENT = '~^[A-Za-z0-9@._-]+$~D';

    public static function script(string $user, string $publicKey): string
    {
        if (preg_match(SafeName::SYSTEM_USER_PATTERN, $user) !== 1) {
            throw new InvalidArgumentException("[{$user}] is not a Linux user name.");
        }

        $key = self::line($publicKey);
        $exists = $user === 'root' ? '' : "id -u {$user} >/dev/null && ";
        $owner = $user === 'root' ? '' : " && chown {$user}: . authorized_keys";

        return "k=\"{$key}\"; {$exists}mkdir -p ~{$user}/.ssh && cd ~{$user}/.ssh && touch authorized_keys && chmod 700 . && chmod 600 authorized_keys{$owner} && { grep -qxF \"\$k\" authorized_keys || echo \"\$k\" >> authorized_keys; }";
    }

    public static function forPerson(string $user, string $publicKey): string
    {
        return 'sudo sh -c ' . escapeshellarg(self::script($user, $publicKey));
    }

    private static function line(string $publicKey): string
    {
        $key = trim($publicKey);

        if (preg_match(SecurityHardening::PUBLIC_KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('That is not an OpenSSH public key.');
        }

        [$type, $blob, $comment] = array_pad(explode(' ', $key, 3), 3, '');

        return "{$type} {$blob}" . (preg_match(self::SAFE_COMMENT, $comment) === 1 ? " {$comment}" : '');
    }
}
