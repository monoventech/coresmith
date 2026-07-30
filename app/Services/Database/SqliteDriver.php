<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\SafeName;

final class SqliteDriver
{
    public function path(Application $application): string
    {
        return (new ReleasePaths($application))->shared().'/database.sqlite';
    }

    /**
     * @return list<string>
     */
    public function create(Application $application): array
    {
        $path = $this->path($application);
        $user = SafeName::assertSystemUser($application->system_user);
        $quoted = escapeshellarg($path);

        return [
            'mkdir -p '.escapeshellarg(dirname($path)),
            'test -f '.$quoted.' || sqlite3 '.$quoted." 'PRAGMA journal_mode=WAL;'",
            'chown '.$user.':'.$user.' '.$quoted,
            'chmod 600 '.$quoted,
        ];
    }

    public function enableWal(Application $application): string
    {
        return 'sqlite3 '.escapeshellarg($this->path($application))." 'PRAGMA journal_mode=WAL;'";
    }

    public function backup(Application $application, string $destination): string
    {
        $source = escapeshellarg($this->path($application));

        return 'sqlite3 '.$source.' '.escapeshellarg('.backup '.$destination)
            .' && gzip -f '.escapeshellarg($destination);
    }
}
