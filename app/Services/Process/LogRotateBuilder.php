<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;

final class LogRotateBuilder
{
    public const DIRECTORY = '/etc/logrotate.d';

    public const MAX_SIZE = '100M';

    public const KEEP = 7;

    public function path(Application $application): string
    {
        return self::DIRECTORY . '/coresmith-app-' . SafeName::assertSlug($application->slug);
    }

    /**
     * @param  list<string>  $processes
     */
    public function build(Application $application, array $processes): string
    {
        $paths = new ReleasePaths($application);
        $user = SafeName::assertSystemUser($application->system_user);
        $blocks = [];

        if ($processes !== []) {
            $blocks[] = $this->block(array_map($paths->processLog(...), $processes), 'root root');
        }

        $blocks[] = $this->block([$paths->schedulerLog()], $user . ' ' . $user);

        return '# Coresmith managed log rotation for ' . SafeName::assertSlug($application->slug) . " - do not edit manually\n" . implode("\n", $blocks);
    }

    /**
     * @param  list<string>  $processes
     */
    public function write(SSHConnection $connection, Application $application, array $processes): void
    {
        $stanza = $this->build($application, $processes);
        $path = $this->path($application);
        $file = escapeshellarg($path);

        $connection->execute('mkdir -p ' . escapeshellarg(self::DIRECTORY), sudo: true)->throw();
        $connection->uploadContent($stanza, $path);
        $connection->execute("chown root:root {$file} && chmod 644 {$file}", sudo: true)->throw();
    }

    public function remove(SSHConnection $connection, Application $application): void
    {
        $connection->execute('rm -f ' . escapeshellarg($this->path($application)), sudo: true)->throw();
    }

    /**
     * @param  list<string>  $logs
     */
    private function block(array $logs, string $owner): string
    {
        $size = self::MAX_SIZE;
        $keep = self::KEEP;

        return implode("\n", $logs) . "\n" . <<<BLOCK
        {
            su {$owner}
            daily
            maxsize {$size}
            rotate {$keep}
            compress
            copytruncate
            missingok
            notifempty
        }

        BLOCK;
    }
}
