<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;

final class LogSanitizer
{
    public const MIN_REDACTABLE_LENGTH = 8;

    public const SECRET_NAME = '/(PASS|SECRET|TOKEN|KEY|DSN|PRIVATE|CREDENTIAL|AUTH|SALT|CERT)/i';

    public const CREDENTIAL_SHAPE = '/^(?=\S*\d)(?=\S*[A-Za-z])\S{24,}\z/';

    private const TERMINAL_CODES = '/\e(?:\[[0-?]*[ -\/]*[@-~]|\][^\a\e]*(?:\a|\e\\\\)|[@-Z\\\\-_])/';

    private const PATTERNS = [
        '/([A-Za-z0-9_.]*(?:password|secret|token|credential|api_?key|access_key|private_key|auth)\s*=\s*)[^\s&"\']+/i' => '${1}[REDACTED]',
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [REDACTED]',
        '/(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{10,}/' => '[REDACTED_STRIPE_KEY]',
        '/AKIA[A-Z0-9]{16}/' => '[REDACTED_AWS_KEY]',
        '/gh[pousr]_[A-Za-z0-9][A-Za-z0-9_.\-]{19,}/' => '[REDACTED_GITHUB_TOKEN]',
        '/github_pat_[A-Za-z0-9_]{22,}/' => '[REDACTED_GITHUB_TOKEN]',
        '/dop_v1_[a-f0-9]{64}/' => '[REDACTED_DIGITALOCEAN_TOKEN]',
        '/(X-Amz-Signature=)[0-9a-f]+/i' => '${1}[REDACTED]',
        '/whsec_[A-Za-z0-9]{10,}/' => '[REDACTED_STRIPE_WEBHOOK_SECRET]',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----(?:[\s\S]{0,16384}?-----END [A-Z ]*PRIVATE KEY-----|[\s\S]{0,16384})/' => '[REDACTED_PRIVATE_KEY]',
    ];

    public function sanitize(string $output, ?Application $application = null): string
    {
        $output = (string) preg_replace(self::TERMINAL_CODES, '', $output);
        $output = $this->redactEnvironmentValues($output, $application);
        $output = $this->redactCredentialsInUrls($output);

        foreach (self::PATTERNS as $pattern => $replacement) {
            $output = (string) preg_replace($pattern, $replacement, $output);
        }

        return $output;
    }

    private function redactEnvironmentValues(string $output, ?Application $application): string
    {
        if ($application === null) {
            return $output;
        }

        $pairs = array_filter(
            $this->environmentPairs($application),
            fn(string $value, string $key): bool => $this->secret($key, $value),
            ARRAY_FILTER_USE_BOTH,
        );

        uasort($pairs, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($pairs as $key => $value) {
            $output = str_replace($value, "[REDACTED:{$key}]", $output);
        }

        return $output;
    }

    private function redactCredentialsInUrls(string $output): string
    {
        return (string) preg_replace(
            '/([a-z][a-z0-9+.-]{0,31}:\/\/)[^\s@\/]+@/i',
            '$1[REDACTED]@',
            $output,
        );
    }

    private function secret(string $key, string $value): bool
    {
        if (strlen($value) < self::MIN_REDACTABLE_LENGTH) {
            return false;
        }

        return preg_match(self::SECRET_NAME, $key) === 1 || preg_match(self::CREDENTIAL_SHAPE, $value) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function environmentPairs(Application $application): array
    {
        return array_filter(
            app(EnvironmentFile::class)->parse((string) $application->env_variables),
            static fn(string $value): bool => $value !== '',
        );
    }
}
