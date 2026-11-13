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
use App\Services\Address\AddressReading;
use App\Services\Address\CertificateSource;
use App\Services\Address\CloudflareConnection;
use App\Services\Address\InstallationAddress;
use App\Services\Network\AddressResolver;
use App\Services\Sso\SignIn;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class AddressPresenter
{
    public const RECORDS_SECONDS = 600;

    public function __construct(
        private readonly InstallationAddress $address,
        private readonly AddressResolver $resolver,
    ) {}

    /**
     * @return array{host: string, url: string, certificate: string, managed: bool, ipv6_only: bool, change: ?array<string, mixed>, check: ?array<string, mixed>, reach: ?array<string, mixed>, cloudflare: array{connected: bool, host: ?string, error: ?string, synced: ?string}, follow: ?array{github: ?string, sso: ?string}}|null
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
            'ipv6_only' => $this->ipv6Only($host),
            'change' => $change,
            'check' => AddressCheck::current(),
            'reach' => AddressReading::current($host),
            'cloudflare' => $this->cloudflare(),
            'follow' => ($change['state'] ?? null) !== 'done' ? null : [
                'github' => GitHubApp::query()->exists() ? route('source-providers.index') : null,
                'sso' => TeamSsoConnection::query()->exists() ? $url . SignIn::CALLBACK_PATH : null,
            ],
        ];
    }

    private function ipv6Only(string $host): bool
    {
        if (InstallationAddress::certificate($host) !== CertificateSource::Public) {
            return false;
        }

        $records = Cache::remember('address-records:' . $host, self::RECORDS_SECONDS, fn(): array => $this->resolver->addresses($host));
        $family = fn(int $flag): bool => array_filter($records, fn(string $record): bool => filter_var($record, FILTER_VALIDATE_IP, $flag) !== false) !== [];

        return $family(FILTER_FLAG_IPV6) && !$family(FILTER_FLAG_IPV4);
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
