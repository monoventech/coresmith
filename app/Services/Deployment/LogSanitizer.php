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

    private const PATTERNS = [
        '/([A-Za-z0-9_.]*(?:password|secret|token|credential|api_?key|access_key|private_key|auth)\s*=\s*)[^\s&"\']+/i' => '${1}[REDACTED]',
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [REDACTED]',
        '/(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{10,}/' => '[REDACTED_STRIPE_KEY]',
        '/AKIA[A-Z0-9]{16}/' => '[REDACTED_AWS_KEY]',
        '/gh[pousr]_[A-Za-z0-9]{20,}/' => '[REDACTED_GITHUB_TOKEN]',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/' => '[REDACTED_PRIVATE_KEY]',
        '/\b[a-z][a-z0-9+.-]*:\/\/[^\s:@\/]+:[^\s:@\/]+@/i' => '$0',
    ];

    public function sanitize(string $output, ?Application $application = null): string
    {
        $output = $this->redactEnvironmentValues($output, $application);
        $output = $this->redactCredentialsInUrls($output);

        foreach (self::PATTERNS as $pattern => $replacement) {
            if ($replacement === '$0') {
                continue;
            }

            $output = (string) preg_replace($pattern, $replacement, $output);
        }

        return $output;
    }

    private function redactEnvironmentValues(string $output, ?Application $application): string
    {
        if ($application === null) {
            return $output;
        }

        foreach ($this->environmentPairs($application) as $key => $value) {
            if (strlen($value) < self::MIN_REDACTABLE_LENGTH) {
                continue;
            }

            $output = str_replace($value, "[REDACTED:{$key}]", $output);
        }

        return $output;
    }

    private function redactCredentialsInUrls(string $output): string
    {
        return (string) preg_replace(
            '/(\b[a-z][a-z0-9+.-]*:\/\/[^\s:@\/]+):[^\s@\/]+@/i',
            '$1:[REDACTED]@',
            $output,
        );
    }

    /**
     * @return array<string, string>
     */
    private function environmentPairs(Application $application): array
    {
        $raw = (string) $application->env_variables;

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return array_filter(
                array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $decoded),
                static fn (string $value): bool => $value !== '',
            );
        }

        $pairs = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $value = trim(trim($value), '"\'');

            if ($value !== '') {
                $pairs[trim($key)] = $value;
            }
        }

        return $pairs;
    }
}
