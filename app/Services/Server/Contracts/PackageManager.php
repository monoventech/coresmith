<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server\Contracts;

interface PackageManager
{
    public function update(): string;

    /**
     * @param  list<string>  $packages
     */
    public function install(array $packages): string;

    public function isInstalled(string $package): string;

    public function addRepository(string $repo): string;
}
