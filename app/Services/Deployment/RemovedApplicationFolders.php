<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Exceptions\SSHCommandFailedException;
use App\Models\Application;
use App\Models\Server;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class RemovedApplicationFolders
{
    public const KEEP_DAYS = 7;

    /**
     * @return Collection<int, Application>
     */
    public function due(Server $server): Collection
    {
        return $this->pick(Application::withTrashed()->where('server_id', $server->getKey())->get());
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @return Collection<int, Application>
     */
    public function pick(Collection $applications): Collection
    {
        $cutoff = now()->subDays(self::KEEP_DAYS);
        $inUse = $applications
            ->filter(fn(Application $application): bool => !$application->trashed() || !$application->deleted_at->lessThan($cutoff))
            ->pluck('slug')
            ->all();

        return $applications
            ->filter(fn(Application $application): bool => $application->trashed()
                && $application->folder_removed_at === null
                && $application->deleted_at->lessThan($cutoff)
                && !in_array($application->slug, $inUse, true))
            ->values();
    }

    public function clear(SSHConnection $connection, Server $server): int
    {
        $cleared = 0;

        foreach ($this->due($server) as $application) {
            try {
                $connection->execute(self::command($application), sudo: true)->throw();
            } catch (InvalidArgumentException | SSHCommandFailedException $failure) {
                report($failure);

                continue;
            }

            $application->forceFill(['folder_removed_at' => now()])->saveQuietly();
            $cleared++;
        }

        if ($cleared > 0) {
            activity()->performedOn($server)->withProperties(['apps' => $cleared])->log('removed_apps_cleared');
        }

        return $cleared;
    }

    public static function command(Application $application): string
    {
        return 'rm -rf ' . escapeshellarg((new ReleasePaths($application))->base());
    }
}
