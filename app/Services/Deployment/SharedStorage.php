<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\SSH\SSHConnection;
use Throwable;

final class SharedStorage
{
    public const SEEDED = '@@shared-storage-seeded';

    public const PRIVATE_MODES = 'u+rwX,g-rwx,o-rwx';

    private const SEED_FROM_LIVE = <<<'BASH'
    if [ ! -e {shared} ] && [ -d {live} ] && [ ! -L {live} ]; then
      (
        live={live}
        work={work}
        trap 'rm -rf "$work"' EXIT
        set -o pipefail
        rm -rf "$work" && mkdir "$work" && cp -a "$live" "$work/storage" || exit 1
        statement={backup}
        if version=$(sqlite3 -version 2>/dev/null) && printf '%s\n' 3.27.0 "${version%% *}" | sort -C -V; then statement={vacuum}; fi
        find "$work/storage" -type f -name '*.sqlite3' -print0 | while IFS= read -r -d '' copy; do
          db="$live${copy#"$work/storage"}"
          { [ -f "$db" ] && sqlite3 "$db" "$statement" && mv -f {snapshot} "$copy" && rm -f "$copy-wal" "$copy-shm" "$copy-journal"; } || { echo "Could not copy $db." >&2; exit 1; }
        done || exit 1
        chmod -R {modes} "$work/storage" && mv "$work/storage" {shared} && echo {seeded}
      ) || { echo "Could not copy the live release's storage into shared/storage, so nothing was changed. Fix the error above, then deploy again." >&2; exit 1; }
    fi
    BASH;

    private const SEED_FROM_RELEASE = 'if [ -d {local} ] && [ ! -L {local} ] && [ ! -e {shared} ]; then { rm -rf {copy} && cp -a {local} {copy} && {chmod}mv {copy} {shared}; } || { rm -rf {copy}; echo "Could not copy the release\'s storage into shared/storage, so nothing was changed. Fix the error above, then deploy again." >&2; exit 1; }; fi; ';

    private const LINK = 'if [ -d {shared} ]; then rm -rf {local} && ln -sfn {shared} {local}; fi';

    public function script(Application $application, string $release): string
    {
        $paths = new ReleasePaths($application);
        $shared = $paths->shared() . '/storage';
        $local = $paths->appDirectory($release) . '/storage';

        $seed = match ($application->type) {
            ApplicationType::Php => $this->fromRelease($local, $shared),
            ApplicationType::Ruby => $this->fromLive($paths->appDirectory($paths->current()) . '/storage', $shared) . $this->fromRelease($local, $shared, self::PRIVATE_MODES),
            default => '',
        };

        $laravel = $application->type === ApplicationType::Php && strtolower((string) $application->framework) === 'laravel';
        $link = strtr(self::LINK, ['{shared}' => escapeshellarg($shared), '{local}' => escapeshellarg($local)]);

        return $laravel
            ? '[ -e ' . escapeshellarg($local) . ' ] || mkdir -p ' . escapeshellarg($local) . '; ' . $seed . $link . $this->laravelFolders($paths->appDirectory($release))
            : $seed . $link;
    }

    private function laravelFolders(string $app): string
    {
        $folders = implode(' ', array_map(fn(string $folder): string => escapeshellarg($app . '/' . $folder), ['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']));
        $database = escapeshellarg($app . '/database/database.sqlite');

        return "; mkdir -p {$folders} && { [ ! -d " . escapeshellarg($app . '/database') . " ] || [ -e {$database} ] || touch {$database}; }";
    }

    public function seeded(string $output): bool
    {
        return in_array(self::SEEDED, array_map(trim(...), preg_split('/\r\n|\n|\r/', $output) ?: []), true);
    }

    public function discard(SSHConnection $connection, Deployment $deployment): void
    {
        $application = $deployment->application;

        if (!$deployment->seeded_shared_storage || $application === null) {
            return;
        }

        $path = (new ReleasePaths($application))->shared() . '/storage';
        $log = app(DeploymentLog::class);

        try {
            $connection->execute('rm -rf ' . escapeshellarg($path), sudo: true)->throw();
        } catch (Throwable) {
            $log->append($deployment, "Could not remove {$path}, the copy of the live data this deploy made. Remove it by hand before deploying again, or the next deploy will use that copy instead of the live data.");

            return;
        }

        $deployment->forceFill(['seeded_shared_storage' => false])->save();
        $log->append($deployment, 'storage: removed the copy of the live data this deploy made, so the next deploy copies it again.');
    }

    private function fromLive(string $live, string $shared): string
    {
        $snapshot = $shared . '.seed/snapshot.sqlite3';

        return strtr(self::SEED_FROM_LIVE, [
            '{live}' => escapeshellarg($live),
            '{shared}' => escapeshellarg($shared),
            '{work}' => escapeshellarg($shared . '.seed'),
            '{backup}' => escapeshellarg('.backup "' . addcslashes($snapshot, '\\"') . '"'),
            '{vacuum}' => escapeshellarg("VACUUM INTO '" . str_replace("'", "''", $snapshot) . "'"),
            '{snapshot}' => escapeshellarg($snapshot),
            '{modes}' => self::PRIVATE_MODES,
            '{seeded}' => self::SEEDED,
        ]) . "\n";
    }

    private function fromRelease(string $local, string $shared, ?string $modes = null): string
    {
        $copy = escapeshellarg($shared . '.seed');

        return strtr(self::SEED_FROM_RELEASE, [
            '{local}' => escapeshellarg($local),
            '{shared}' => escapeshellarg($shared),
            '{copy}' => $copy,
            '{chmod}' => $modes === null ? '' : "chmod -R {$modes} {$copy} && ",
        ]);
    }
}
