<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\ReleasePaths;
use App\Services\Deployment\RemovedApplicationFolders;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class PruneReleases implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(private readonly Server $server)
    {
        $this->onQueue('default');
    }

    public function handle(SSHConnectionManager $connections): void
    {
        $connection = $connections->for($this->server);
        $removed = 0;

        try {
            foreach ($this->server->applications()->get() as $application) {
                $result = $connection->execute(self::command($application), sudo: true)->throw();
                $removed += (int) trim($result->output);
            }

            app(RemovedApplicationFolders::class)->clear($connection, $this->server);
        } finally {
            $connections->disconnect($this->server);
        }

        activity()
            ->performedOn($this->server)
            ->withProperties(['removed' => $removed, 'kept' => ReleasePaths::KEEP_RELEASES])
            ->log('releases_pruned');
    }

    public static function command(Application $application): string
    {
        $paths = new ReleasePaths($application);
        $releases = $paths->releases();
        $keep = ReleasePaths::KEEP_RELEASES + 1;

        return "current=\$(readlink -f {$paths->current()} 2>/dev/null || true); "
            . "ls -1dt {$releases}/*/ 2>/dev/null | sed 's:/\$::' | grep -E '/[0-9]{8}-?[0-9]{6}\$' | tail -n +{$keep} "
            . '| while read -r dir; do [ "$dir" = "$current" ] || { rm -rf "$dir" && echo x; }; done | wc -l';
    }
}
