<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Edition;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\ServerTunnel;
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
     * @return array{host: string, url: string, certificate: string, managed: bool, ipv6_only: bool, tunnel: ?string, front_door: ?string, change: ?array<string, mixed>, check: ?array<string, mixed>, reach: ?array<string, mixed>, cloudflare: array{connected: bool, host: ?string, error: ?string, synced: ?string, tunnel: ?array{name: string, url: string}}, machine: ?string, follow: ?array{github: ?string, sso: ?string}}|null
     */
    public function present(): ?array
    {
        if (Edition::current()->isCloud()) {
            return null;
        }

        $host = $this->address->host();
        $url = rtrim((string) config('app.url'), '/');
        $change = AddressChange::current();
        $machine = Server::query()->where('runs_coresmith', true)->first();
        $tunnel = $machine?->tunnel()->first();
        $carried = $tunnel?->carries($host) === true;
        $frontDoor = $machine?->frontDoor()->with('door')->first();
        $throughDoor = $frontDoor?->carries($host) === true;

        return [
            'host' => $host,
            'url' => $url,
            'certificate' => InstallationAddress::certificate($host)->value,
            'managed' => $this->address->managed(),
            'ipv6_only' => !$carried && !$throughDoor && $this->ipv6Only($host),
            'tunnel' => $this->tunnel($machine, $tunnel, $carried),
            'front_door' => $throughDoor ? null : $frontDoor?->door?->name,
            'change' => $change,
            'check' => AddressCheck::current(),
            'reach' => AddressReading::current($host),
            'cloudflare' => $this->cloudflare($carried ? $tunnel : null, $tunnel === null ? null : $machine),
            'machine' => $machine === null ? null : route('servers.show', $machine),
            'follow' => ($change['state'] ?? null) !== 'done' ? null : [
                'github' => GitHubApp::query()->exists() ? route('source-providers.index') : null,
                'sso' => TeamSsoConnection::query()->exists() ? $url . SignIn::CALLBACK_PATH : null,
            ],
        ];
    }

    private function tunnel(?Server $machine, ?ServerTunnel $tunnel, bool $carried): ?string
    {
        return match (true) {
            $carried => 'carries',
            $tunnel !== null => 'on',
            (((array) $machine?->public_addresses)['ipv4_open'] ?? null) === false => 'off',
            default => null,
        };
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
     * @return array{connected: bool, host: ?string, error: ?string, synced: ?string, tunnel: ?array{name: string, url: string}}
     */
    private function cloudflare(?ServerTunnel $carrying, ?Server $tunneled): array
    {
        $status = CloudflareConnection::status();
        $synced = is_string($status['synced_at'] ?? null) ? CarbonImmutable::parse($status['synced_at']) : null;
        $using = $tunneled ?? ServerTunnel::usingToken()?->server;

        return [
            'connected' => CloudflareConnection::token() !== null,
            'host' => $status['host'] ?? null,
            'error' => $carrying === null ? ($status['error'] ?? null) : $carrying->error,
            'synced' => Formats::when($carrying === null ? $synced : $carrying->checked_at),
            'tunnel' => $using === null ? null : ['name' => $using->name, 'url' => route('servers.show', $using)],
        ];
    }
}
