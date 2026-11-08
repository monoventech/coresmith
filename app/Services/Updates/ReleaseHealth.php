<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Services\Deployment\LogSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Throwable;

final class ReleaseHealth
{
    public function __construct(private readonly LogSanitizer $sanitizer) {}

    public static function probeUrl(): string
    {
        return (string) config('coresmith.updates.probe_url');
    }

    public function check(Release $release, int $timeout): void
    {
        $this->healthCheck($release);
        $this->serving($release, $timeout);
    }

    private function healthCheck(Release $release): void
    {
        $result = Process::path($release->path)->timeout(120)->run([PHP_BINARY, 'artisan', 'coresmith:health', '--json']);
        $report = json_decode(trim($result->output()), true);

        if ($result->successful() && is_array($report) && ($report['ok'] ?? false) === true) {
            return;
        }

        $failed = is_array($report) && is_array($report['checks'] ?? null)
            ? array_map(
                fn(array $check): string => ($check['name'] ?? '?') . ': ' . ($check['detail'] ?? ''),
                array_values(array_filter($report['checks'], fn(mixed $check): bool => is_array($check) && ($check['status'] ?? null) === 'fail')),
            )
            : [];

        if ($failed === []) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $result->errorOutput() . "\n" . $result->output())), fn(string $line): bool => $line !== ''));
            $failed = [$lines[count($lines) - 1] ?? "coresmith:health exited with code {$result->exitCode()}"];
        }

        throw new UpdateFailed('health', $this->sanitizer->sanitize('The new release failed its health check: ' . implode(' ', $failed)));
    }

    private function serving(Release $release, int $timeout): void
    {
        $probe = self::probeUrl();
        $attempts = max(1, intdiv($timeout, 2));
        $problem = "Coresmith didn't answer on {$probe}.";

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->get($probe);

                $serving = $response->json('release');

                if ($response->status() === 200 && $serving === $release->name) {
                    return;
                }

                $problem = match (true) {
                    $response->status() !== 200 => "Coresmith answered {$response->status()} on {$probe}.",
                    is_string($serving) => "Coresmith is still serving {$serving}, not the new release.",
                    default => "Coresmith answered on {$probe} without saying which release it serves.",
                };
            } catch (Throwable) {
                $problem = "Coresmith didn't answer on {$probe}.";
            }

            if ($attempt < $attempts) {
                Sleep::for(2)->seconds();
            }
        }

        throw new UpdateFailed('health', $this->sanitizer->sanitize($problem));
    }
}
