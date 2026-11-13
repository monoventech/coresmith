<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\InstanceSetting;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyInstaller;
use App\Services\Caddy\CoresmithSite;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

final class HttpCaddyAdmin implements CaddyAdmin
{
    public const ADMIN = 'http://localhost:2019';

    public function __construct(private readonly CaddyInstaller $installer = new CaddyInstaller) {}

    public function reload(): void
    {
        $caddyfile = $this->caddyfile();

        if (!$this->managed()) {
            $this->load($caddyfile, 'text/caddyfile');

            return;
        }

        try {
            Cache::lock(CaddyApiClient::THIS_MACHINE_LOCK, CaddyApiClient::LOCK_SECONDS)->block(CaddyApiClient::LOCK_WAIT_SECONDS, fn() => $this->swapCoresmithsRoutes($caddyfile));
        } catch (LockTimeoutException) {
            throw new RuntimeException('Coresmith was busy with another change on this machine. Try again in a minute.');
        }
    }

    public function managed(): bool
    {
        try {
            $server = $this->request()->timeout(10)->get($this->url('/config/apps/http/servers/' . CaddyInstaller::SERVER));
        } catch (ConnectionException) {
            return false;
        }

        return $server->successful() && is_object(json_decode($server->body()));
    }

    private function swapCoresmithsRoutes(string $caddyfile): void
    {
        $current = $this->request()->timeout(30)->get($this->url('/config/'));

        if ($current->failed()) {
            throw new RuntimeException("The web server didn't give Coresmith its running settings: " . $this->reason($current));
        }

        $adapted = $this->request()->withBody($caddyfile, 'text/caddyfile')->timeout(30)->post($this->url('/adapt'));

        if ($adapted->failed()) {
            throw new RuntimeException('The web server refused the new address: ' . $this->reason($adapted));
        }

        $reading = json_decode($adapted->body(), false);
        $site = CoresmithSite::fromAdapted((string) json_encode($reading instanceof stdClass ? ($reading->result ?? null) : null, JSON_UNESCAPED_SLASHES));

        $this->load($this->installer->thisMachineConfig($site, InstanceSetting::get('notification_email'), $current->body()), 'application/json');
    }

    private function load(string $body, string $type): void
    {
        $response = $this->request()->withBody($body, $type)->timeout(30)->post($this->url('/load'));

        if ($response->failed()) {
            throw new RuntimeException('The web server refused the new address: ' . $this->reason($response));
        }
    }

    private function caddyfile(): string
    {
        $path = (string) config('coresmith.address.caddyfile');
        $caddyfile = @file_get_contents($path);

        if (!is_string($caddyfile) || $caddyfile === '') {
            throw new RuntimeException("Coresmith couldn't read {$path}.");
        }

        return $caddyfile;
    }

    private function request(): PendingRequest
    {
        $socket = $this->socket();

        return $socket === ''
            ? Http::withHeaders(['Origin' => self::ADMIN])
            : Http::withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => $socket]]);
    }

    private function url(string $path): string
    {
        return ($this->socket() === '' ? self::ADMIN : 'http://localhost') . $path;
    }

    private function socket(): string
    {
        return (string) config('coresmith.address.caddy_admin_socket');
    }

    private function reason(Response $response): string
    {
        return trim((string) ($response->json('error') ?? $response->body()));
    }
}
