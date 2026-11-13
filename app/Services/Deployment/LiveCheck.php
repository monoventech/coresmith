<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Str;

final class LiveCheck
{
    private const SECONDS = 15;

    public function ask(SSHConnection $connection, Application $application): ?LiveAnswer
    {
        $domain = trim((string) $application->domain);
        $served = in_array($application->type, [ApplicationType::Js, ApplicationType::Ruby], true) && $application->base_port !== null;

        if ($domain === '' && !$served) {
            return null;
        }

        $url = $domain === '' ? 'http://127.0.0.1:' . $application->base_port . '/' : 'https://' . $domain . '/';
        $command = $domain === ''
            ? 'curl -s -o /dev/null --max-time ' . self::SECONDS . " -w '%{http_code}' " . escapeshellarg($url)
            : 'curl -sk -o /dev/null --max-time ' . self::SECONDS . " -w '%{http_code}' --resolve " . escapeshellarg($domain . ':443:127.0.0.1') . ' ' . escapeshellarg($url);
        $status = (int) trim($connection->execute($command, timeout: self::SECONDS + 5)->output);

        if ($status < 100 || $status > 599) {
            return null;
        }

        return new LiveAnswer($url, $status, $status >= 500 ? $this->newestError($connection, $application) : null);
    }

    private function newestError(SSHConnection $connection, Application $application): ?string
    {
        if (strtolower((string) $application->framework) !== 'laravel') {
            return null;
        }

        $paths = new ReleasePaths($application);
        $logs = escapeshellarg($paths->appDirectory($paths->current()) . '/storage/logs');
        $line = trim($connection->execute("f=\$(ls -t {$logs}/*.log 2>/dev/null | head -n 1); [ -n \"\$f\" ] && tail -n 400 \"\$f\" | grep -E '\\.(ERROR|CRITICAL|ALERT|EMERGENCY): ' | tail -n 1", sudo: true)->output);

        if (preg_match('/\.(?:ERROR|CRITICAL|ALERT|EMERGENCY): (.+?)(?: \{"|$)/', $line, $matches) !== 1) {
            return null;
        }

        return Str::limit(trim($matches[1]), 300);
    }
}
