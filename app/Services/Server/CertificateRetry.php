<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Exceptions\SSHCommandFailedException;
use App\Models\Server;
use App\Services\Caddy\CaddyApiClient;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Facades\Cache;

final readonly class CertificateRetry
{
    public const EVERY_MINUTES = 10;

    public const WAIT_SECONDS = 45;

    public function __construct(private CaddyApiClient $caddy) {}

    public static function cacheKey(Server $server): string
    {
        return 'certificate-retry:' . $server->getKey();
    }

    /**
     * @param  list<string>  $missing
     * @return list<string>
     */
    public function pointedHere(Server $server, array $missing): array
    {
        if ($missing === []) {
            return [];
        }

        $ready = [];

        foreach ($server->applications()->get() as $application) {
            foreach ((array) $application->domain_reach as $domain => $reading) {
                if (in_array($domain, $missing, true) && is_array($reading) && ($reading['ready'] ?? null) === true) {
                    $ready[] = (string) $domain;
                }
            }
        }

        return array_values(array_unique($ready));
    }

    public function ask(SSHConnection $connection): bool
    {
        if (!Cache::add(self::cacheKey($connection->server()), true, now()->addMinutes(self::EVERY_MINUTES))) {
            return false;
        }

        try {
            $this->caddy->retryCertificates($connection);
        } catch (SSHCommandFailedException $e) {
            report($e);

            return false;
        }

        return true;
    }
}
