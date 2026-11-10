<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Edition;
use App\Models\GitHubApp;
use App\Models\TeamSsoConnection;
use App\Services\Address\AddressChange;
use App\Services\Address\AddressCheck;
use App\Services\Address\CloudflareConnection;
use App\Services\Address\InstallationAddress;
use App\Services\Sso\SignIn;
use Carbon\CarbonImmutable;

final class AddressPresenter
{
    public function __construct(private readonly InstallationAddress $address) {}

    /**
     * @return array{host: string, url: string, certificate: string, managed: bool, change: ?array<string, mixed>, check: ?array<string, mixed>, cloudflare: array{connected: bool, host: ?string, error: ?string, synced: ?string}, follow: ?array{github: ?string, sso: ?string}}|null
     */
    public function present(): ?array
    {
        if (Edition::current()->isCloud()) {
            return null;
        }

        $host = $this->address->host();
        $url = rtrim((string) config('app.url'), '/');
        $change = AddressChange::current();

        return [
            'host' => $host,
            'url' => $url,
            'certificate' => InstallationAddress::certificate($host)->value,
            'managed' => $this->address->managed(),
            'change' => $change,
            'check' => AddressCheck::current(),
            'cloudflare' => $this->cloudflare(),
            'follow' => ($change['state'] ?? null) !== 'done' ? null : [
                'github' => GitHubApp::query()->exists() ? route('source-providers.index') : null,
                'sso' => TeamSsoConnection::query()->exists() ? $url . SignIn::CALLBACK_PATH : null,
            ],
        ];
    }

    /**
     * @return array{connected: bool, host: ?string, error: ?string, synced: ?string}
     */
    private function cloudflare(): array
    {
        $status = CloudflareConnection::status();
        $synced = is_string($status['synced_at'] ?? null) ? CarbonImmutable::parse($status['synced_at']) : null;

        return [
            'connected' => CloudflareConnection::token() !== null,
            'host' => $status['host'] ?? null,
            'error' => $status['error'] ?? null,
            'synced' => Formats::when($synced),
        ];
    }
}
