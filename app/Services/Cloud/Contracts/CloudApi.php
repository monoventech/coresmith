<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Contracts;

use App\Enums\CloudProviderType;
use App\Services\Cloud\CloudAccount;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\CloudSize;
use Illuminate\Support\Collection;

interface CloudApi
{
    public function type(): CloudProviderType;

    public function verify(): CloudAccount;

    /**
     * @return Collection<int, CloudRegion>
     */
    public function regions(): Collection;

    /**
     * @return Collection<int, CloudSize>
     */
    public function sizes(): Collection;

    /**
     * @return Collection<int, CloudImage>
     */
    public function images(): Collection;

    public function uploadsKeys(): bool;

    public function uploadKey(string $name, string $publicKey): string;

    public function deleteKey(string $keyId): void;

    public function create(CloudServerSpec $spec): CloudInstance;

    public function find(string $instanceId): ?CloudInstance;

    public function findTagged(string $tag): ?CloudInstance;

    public function reboot(string $instanceId): void;

    public function resizeNeedsPowerOff(): bool;

    public function powerOff(string $instanceId): void;

    public function powerOn(string $instanceId): void;

    public function resize(string $instanceId, string $size): void;

    public function destroy(string $instanceId): void;
}
