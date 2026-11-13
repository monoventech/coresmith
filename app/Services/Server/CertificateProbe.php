<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Services\SafeName;
use Carbon\CarbonImmutable;
use Throwable;

final class CertificateProbe
{
    public const TIMEOUT_SECONDS = 3;

    public const BATCH = 40;

    public const COMMAND_SECONDS = 150;

    /**
     * @param  list<string>  $domains
     * @return list<string>
     */
    public function commands(array $domains): array
    {
        return array_map(function (array $batch): string {
            $names = implode(' ', array_map(fn(string $domain): string => escapeshellarg(SafeName::assertDomain($domain)), $batch));

            return "for d in {$names}; do printf '@@cert %s ' \"\$d\"; echo | timeout " . self::TIMEOUT_SECONDS . ' openssl s_client -servername "$d" -connect 127.0.0.1:443 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null || echo none; done';
        }, array_chunk($domains, self::BATCH));
    }

    /**
     * @param  list<string>  $domains
     * @return array<string, CarbonImmutable|null>
     */
    public function parse(string $output, array $domains): array
    {
        $found = [];

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            if (preg_match('/^@@cert (\S+) (?:notAfter=(.+)|none)$/', trim($line), $matches) !== 1 || !in_array($matches[1], $domains, true)) {
                continue;
            }

            if (($matches[2] ?? '') === '') {
                $found[$matches[1]] = null;

                continue;
            }

            $expires = $this->date($matches[2]);

            if ($expires !== null) {
                $found[$matches[1]] = $expires;
            }
        }

        return $found;
    }

    private function date(string $value): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('M j H:i:s Y T', (string) preg_replace('/\s+/', ' ', trim($value)));
        } catch (Throwable) {
            return null;
        }

        return $parsed instanceof CarbonImmutable ? $parsed->utc() : null;
    }
}
