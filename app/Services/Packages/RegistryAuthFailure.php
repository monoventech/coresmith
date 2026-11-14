<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Packages;

use App\Enums\PackageSource;
use App\Models\Application;

final class RegistryAuthFailure
{
    private const UNSET_VARIABLE = '/Failed to replace env in config: \$\{(?<variable>[A-Za-z_][A-Za-z0-9_]*)\}/';

    private const REFUSALS = [
        '~The "(?<url>https?://[^"]+)" file could not be downloaded \(HTTP/[\d.]+ (?<status>40[13])~',
        "~The '(?<url>https?://[^']+)' URL required authentication \\(HTTP (?<status>40[13])\\)~",
        "~Invalid credentials for '(?<url>https?://[^']+)'~",
        '~(?<status>40[13]) (?:Unauthorized|Forbidden)[^\n]*? - (?:GET|PUT|POST) (?<url>https?://\S+)~',
        '~ERR_PNPM_FETCH_(?<status>40[13])\s+GET (?<url>https?://\S+?):~',
        '~"(?<url>https?://[^"\s]+?): Request failed \\\\?"(?<status>40[13])~',
        '~Invalid authentication \([^)]*\)(?:[^\n]*\n){1,3}?[^\n]*Request URL: (?<url>https?://\S+)~',
        '~\bGET (?<url>https?://\S+) - (?<status>40[13])\b~',
        '~HTTP error (?<status>40[13]) while getting (?<url>https?://\S+)~',
        '~(?<status>40[13]) Client Error: [A-Za-z ]+ for url: (?<url>https?://\S+)~',
        '~\((?<status>40[13]) [A-Za-z ]+\) for url \((?<url>https?://[^)\s]+)\)~',
        '~from/to (?<id>[A-Za-z0-9._-]+) \((?<url>https?://[^)\s]+)\): status code: (?<status>40[13])~',
        "~Could not (?:GET|HEAD) '(?<url>https?://[^']+)'\\. Received status code (?<status>40[13])~",
        "~could not read Username for '(?<url>https?://[^']+)'~",
        "~Authentication failed for '(?<url>https?://[^']+)'~",
        '~Authentication is required for (?<host>[a-z0-9.-]+?)\.?$~m',
        '~Bad username or password for (?<url>https?://\S+?)\.?$~m',
    ];

    public function __construct(private readonly PackageCredentials $credentials) {}

    public function reason(string $output, Application $application): ?string
    {
        if (preg_match(self::UNSET_VARIABLE, $output, $match) === 1) {
            return "The repository's .npmrc reads {$match['variable']}, which isn't set. Add the registry's token to Private packages in Settings, Git, then deploy again.";
        }

        foreach (self::REFUSALS as $pattern) {
            if (preg_match($pattern, $output, $match) !== 1) {
                continue;
            }

            $host = ($match['host'] ?? '') !== '' ? strtolower($match['host']) : strtolower((string) parse_url($match['url'] ?? '', PHP_URL_HOST));
            $id = ($match['id'] ?? '') !== '' ? $match['id'] : null;

            if ($host === '') {
                return null;
            }

            if (!$this->held($application, $host, $id)) {
                $subject = $id === null ? $host : "the Maven repository {$id} ({$host})";

                return "The install needs credentials for {$subject}, and Coresmith has none. Add them to Private packages in Settings, Git, then deploy again.";
            }

            if (($match['status'] ?? '') === '403') {
                return "{$host} accepted Coresmith's credentials, but they can't read this package. Give them access to it, then deploy again.";
            }

            return "{$host} didn't accept the credentials Coresmith has for it. Check them in Private packages in Settings, Git, then deploy again.";
        }

        return null;
    }

    private function held(Application $application, string $host, ?string $id): bool
    {
        foreach ($this->credentials->forApplication($application) as $credential) {
            if ($credential->source === PackageSource::Maven ? $credential->target === $id : PackageCredentials::label($credential) === $host) {
                return true;
            }
        }

        return false;
    }
}
