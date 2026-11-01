<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Models\Application;
use App\Services\Deployment\AppShell;
use App\Services\Deployment\LogSanitizer;
use App\Services\Deployment\ReleasePaths;
use App\Services\SSH\SSHConnection;

final class SchedulerLogReader
{
    public const LINES = 50;

    public const LINE_LENGTH = 400;

    private const READ_SECONDS = 30;

    private const TERMINAL_CODE = '/\e\[[0-9;?]*[A-Za-z]/';

    private const CONTROL = '/[\x{0}-\x{8}\x{A}-\x{1F}\x{7F}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public function __construct(private readonly AppShell $shell, private readonly LogSanitizer $sanitizer) {}

    public function command(Application $application): string
    {
        $paths = new ReleasePaths($application);

        return $this->shell->run($application, 'tail -n ' . self::LINES . ' -- ' . escapeshellarg($paths->schedulerLog()) . ' 2>/dev/null || true', $paths->shared(), [], self::READ_SECONDS);
    }

    /**
     * @return list<string>
     */
    public function recent(SSHConnection $connection, Application $application): array
    {
        if ($application->current_release === null) {
            return [];
        }

        return $this->parse($connection->execute($this->command($application), sudo: true)->throw()->output, $application);
    }

    /**
     * @return list<string>
     */
    public function parse(string $output, Application $application): array
    {
        $text = (string) mb_scrub($this->sanitizer->sanitize($output, $application), 'UTF-8');

        $lines = array_map(
            fn(string $line): string => mb_substr((string) preg_replace(self::CONTROL, '', (string) preg_replace(self::TERMINAL_CODE, '', $line)), 0, self::LINE_LENGTH),
            explode("\n", $text),
        );

        if (end($lines) === '') {
            array_pop($lines);
        }

        return array_slice($lines, -self::LINES);
    }
}
