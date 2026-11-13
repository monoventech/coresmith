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

    public const SEEDED_DATABASE = '@@shared-database-seeded';

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

    private const DATABASE = <<<'BASH'
    mkdir -p {folder} {shared} && chmod 700 {shared} || exit 1
    if [ ! -e {database} ]; then
      if [ -f {live} ] && [ ! -L {live} ]; then
        {
          rm -f {seed} && statement={backup} &&
          { if version=$(sqlite3 -version 2>/dev/null) && printf '%s\n' 3.27.0 "${version%% *}" | sort -C -V; then statement={vacuum}; fi; } &&
          sqlite3 {live} "$statement" && chmod 600 {seed} && mv -f {seed} {database} && echo {seeded}
        } || { rm -f {seed}; echo "Could not copy the live release's SQLite database into shared/database, so nothing was changed. Fix the error above, then deploy again." >&2; exit 1; }
      elif [ -f {local} ] && [ ! -L {local} ]; then
        { cp {local} {seed} && chmod 600 {seed} && mv -f {seed} {database}; } || { rm -f {seed}; echo "Could not copy the release's SQLite database into shared/database, so nothing was changed. Fix the error above, then deploy again." >&2; exit 1; }
      else
        ( umask 077 && : > {database} ) || exit 1
      fi
    fi
    ln -sfn {database} {local}
    BASH;

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

        $laravel = $this->laravel($application);
        $link = strtr(self::LINK, ['{shared}' => escapeshellarg($shared), '{local}' => escapeshellarg($local)]);

        return $laravel
            ? '[ -e ' . escapeshellarg($local) . ' ] || mkdir -p ' . escapeshellarg($local) . '; ' . $seed . $link . $this->laravelFolders($paths->appDirectory($release)) . "\n" . $this->database($paths, $release)
            : $seed . $link;
    }

    public function relinkDatabase(Application $application, string $release): ?string
    {
        if (!$this->laravel($application)) {
            return null;
        }

        $paths = new ReleasePaths($application);
        $database = escapeshellarg($paths->shared() . '/database/database.sqlite');
        $folder = $paths->appDirectory($release) . '/database';
        $local = escapeshellarg($folder . '/database.sqlite');

        return "if [ -e {$database} ] && [ ! -L {$local} ]; then mkdir -p " . escapeshellarg($folder) . " && ln -sfn {$database} {$local}; fi";
    }

    public function seededDatabase(string $output): bool
    {
        return in_array(self::SEEDED_DATABASE, array_map(trim(...), preg_split('/\r\n|\n|\r/', $output) ?: []), true);
    }

    private function laravel(Application $application): bool
    {
        return $application->type === ApplicationType::Php && strtolower((string) $application->framework) === 'laravel';
    }

    private function database(ReleasePaths $paths, string $release): string
    {
        $shared = $paths->shared() . '/database';
        $seed = $shared . '/database.sqlite.seed';

        return strtr(self::DATABASE, [
            '{folder}' => escapeshellarg($paths->appDirectory($release) . '/database'),
            '{shared}' => escapeshellarg($shared),
            '{database}' => escapeshellarg($shared . '/database.sqlite'),
            '{live}' => escapeshellarg($paths->appDirectory($paths->current()) . '/database/database.sqlite'),
            '{local}' => escapeshellarg($paths->appDirectory($release) . '/database/database.sqlite'),
            '{seed}' => escapeshellarg($seed),
            '{backup}' => escapeshellarg('.backup "' . addcslashes($seed, '\\"') . '"'),
            '{vacuum}' => escapeshellarg("VACUUM INTO '" . str_replace("'", "''", $seed) . "'"),
            '{seeded}' => self::SEEDED_DATABASE,
        ]);
    }

    private function laravelFolders(string $app): string
    {
        $folders = implode(' ', array_map(fn(string $folder): string => escapeshellarg($app . '/' . $folder), ['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']));

        return "; mkdir -p {$folders} || exit 1";
    }

    public function seeded(string $output): bool
    {
        return in_array(self::SEEDED, array_map(trim(...), preg_split('/\r\n|\n|\r/', $output) ?: []), true);
    }

    public function discard(SSHConnection $connection, Deployment $deployment): void
    {
        $application = $deployment->application;

        if ($application === null) {
            return;
        }

        $shared = (new ReleasePaths($application))->shared();

        if ($deployment->seeded_shared_storage) {
            $this->remove($connection, $deployment, $shared . '/storage', 'seeded_shared_storage', 'the live data');
        }

        if ($deployment->seeded_shared_database) {
            $this->remove($connection, $deployment, $shared . '/database', 'seeded_shared_database', 'the live SQLite database');
        }
    }

    private function remove(SSHConnection $connection, Deployment $deployment, string $path, string $flag, string $what): void
    {
        $log = app(DeploymentLog::class);

        try {
            $connection->execute('rm -rf ' . escapeshellarg($path), sudo: true)->throw();
        } catch (Throwable) {
            $log->append($deployment, "Could not remove {$path}, the copy of {$what} this deploy made. Remove it by hand before deploying again, or the next deploy will use that copy instead of {$what}.");

            return;
        }

        $deployment->forceFill([$flag => false])->save();
        $log->append($deployment, "storage: removed the copy of {$what} this deploy made, so the next deploy copies it again.");
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
