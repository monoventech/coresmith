<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server\Contracts;

interface FirewallManager
{
    public function allow(int $port, string $protocol = 'tcp'): string;

    public function deny(int $port, string $protocol = 'tcp'): string;

    public function remove(int $port, string $protocol = 'tcp'): string;

    public function allowFrom(string $ip, int $port): string;

    public function denyIncomingByDefault(): string;

    public function status(): string;

    public function isActive(): string;

    public function enable(): string;
}
