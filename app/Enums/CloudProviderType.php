<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum CloudProviderType: string
{
    case Hetzner = 'hetzner';
    case DigitalOcean = 'digitalocean';
    case Vultr = 'vultr';
    case Linode = 'linode';

    public function label(): string
    {
        return match ($this) {
            self::Hetzner => 'Hetzner Cloud',
            self::DigitalOcean => 'DigitalOcean',
            self::Vultr => 'Vultr',
            self::Linode => 'Linode',
        };
    }

    public function apiUrl(): string
    {
        return rtrim((string) config("services.{$this->value}.api_url"), '/');
    }

    public function apiHost(): string
    {
        return (string) parse_url($this->apiUrl(), PHP_URL_HOST);
    }

    public function tokenUrl(): string
    {
        return match ($this) {
            self::Hetzner => 'https://console.hetzner.cloud/projects',
            self::DigitalOcean => 'https://cloud.digitalocean.com/account/api/tokens',
            self::Vultr => 'https://my.vultr.com/settings/#settingsapi',
            self::Linode => 'https://cloud.linode.com/profile/tokens',
        };
    }

    public function tokenHelp(): string
    {
        return match ($this) {
            self::Hetzner => 'In the Hetzner Cloud Console, open the project, then Security, API tokens, and generate a token with Read & Write permission.',
            self::DigitalOcean => 'In the DigitalOcean control panel, open API, then Tokens, and generate a token with full access, or with custom scopes: read on account, regions, sizes and images, every droplet and SSH key permission, and create and read on tags.',
            self::Vultr => "In the Vultr customer portal, open Account, then API, enable the API and copy the key. If access control is on, allow this panel's IP address.",
            self::Linode => 'In Akamai Cloud Manager, open your profile, then API tokens, and create a personal access token with read/write access to Linodes and read access to Images.',
        };
    }

    public function permissionHint(): string
    {
        return match ($this) {
            self::Hetzner => 'Generate a Read & Write token for the project.',
            self::DigitalOcean => 'Give it full access, or custom scopes with read on account, regions, sizes and images, every droplet and SSH key permission, and create and read on tags.',
            self::Vultr => 'Give the Vultr user this API key belongs to permission to create and manage servers.',
            self::Linode => 'Give it read/write access to Linodes and read access to Images.',
        };
    }

    public function resizeNeedsPowerOff(): bool
    {
        return match ($this) {
            self::Hetzner, self::DigitalOcean => true,
            self::Vultr, self::Linode => false,
        };
    }

    public function hasScopedTokens(): bool
    {
        return match ($this) {
            self::Hetzner, self::DigitalOcean, self::Linode => true,
            self::Vultr => false,
        };
    }
}
