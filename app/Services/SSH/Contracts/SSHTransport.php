<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH\Contracts;

use phpseclib3\Crypt\Common\PrivateKey;

interface SSHTransport
{
    public const COMMAND_SECONDS = 600;

    public const PACKAGE_SECONDS = 1200;

    public function connect(string $host, int $port, int $timeout): void;

    public function hostKey(): string;

    public function login(string $user, PrivateKey $key): bool;

    public function exec(string $command, ?callable $onOutput = null, int $timeout = self::COMMAND_SECONDS): string;

    public function timedOut(): bool;

    public function exitStatus(): int;

    public function uploadContent(string $content, string $remotePath): void;

    public function disconnect(): void;
}
