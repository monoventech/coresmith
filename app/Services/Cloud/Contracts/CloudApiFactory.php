<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Contracts;

use App\Enums\CloudProviderType;
use App\Models\CloudProvider;

interface CloudApiFactory
{
    public function for(CloudProvider $account): CloudApi;

    public function make(CloudProviderType $type, #[\SensitiveParameter] string $token): CloudApi;
}
