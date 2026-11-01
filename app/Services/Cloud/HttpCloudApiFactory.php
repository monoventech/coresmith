<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Enums\CloudProviderType;
use App\Exceptions\CloudProviderException;
use App\Exceptions\VaultDecryptionException;
use App\Models\CloudProvider;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\Providers\DigitalOceanApi;
use App\Services\Cloud\Providers\HetznerApi;
use App\Services\Cloud\Providers\LinodeApi;
use App\Services\Cloud\Providers\VultrApi;

final class HttpCloudApiFactory implements CloudApiFactory
{
    public function for(CloudProvider $account): CloudApi
    {
        try {
            $token = $account->api_token;
        } catch (VaultDecryptionException) {
            $token = null;
        }

        if ($token === null || trim($token) === '') {
            throw CloudProviderException::noToken($account->type);
        }

        return $this->make($account->type, $token);
    }

    public function make(CloudProviderType $type, #[\SensitiveParameter] string $token): CloudApi
    {
        return match ($type) {
            CloudProviderType::Hetzner => new HetznerApi($token),
            CloudProviderType::DigitalOcean => new DigitalOceanApi($token),
            CloudProviderType::Vultr => new VultrApi($token),
            CloudProviderType::Linode => new LinodeApi($token),
        };
    }
}
