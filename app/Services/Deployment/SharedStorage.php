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
use App\Stacks\StorageSeed;
use Throwable;

final class SharedStorage
{
    public const SEEDED = '@@shared-storage-seeded';

    public const SEEDED_DATABASE = '@@shared-database-seeded';

    public const COPIED = '.copied-from-live';

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
    mkdir -p {folder} {shared} && chmod 700 {shared} && rm -f {copied} || exit 1
    made=() seeded= seed=
    fail() { rm -f "${made[@]}" "$seed" {copied}; echo "$1" >&2; exit 1; }
    names=database.sqlite
    for dir in {live} {shared} {folder}; do
      for file in "$dir"/*.sqlite; do
        name=${file##*/}
        case "$name" in *[!A-Za-z0-9._-]*) ;; *.sqlite) if [ -f "$file" ] && [[ " $names " != *" $name "* ]]; then names="$names $name"; fi ;; esac
      done
    done
    for name in $names; do
      database={shared}/$name live={live}/$name link={folder}/$name seed={shared}/$name.seed
      if [ ! -e "$database" ]; then
        if [ -f "$live" ] && [ ! -L "$live" ]; then
          rm -f "$seed" && statement=".backup \"$seed\"" || fail "Could not copy database/$name from the live release."
          if version=$(sqlite3 -version 2>/dev/null) && printf '%s\n' 3.27.0 "${version%% *}" | sort -C -V; then statement="VACUUM INTO '$seed'"; fi
          { sqlite3 "$live" "$statement" && chmod 600 "$seed" && mv -f "$seed" "$database" && ( umask 077 && printf '%s\n' "$name" >> {copied} ); } || fail "Could not copy database/$name from the live release into shared/database, so nothing was changed. Fix the error above, then deploy again."
          seeded=1
        elif [ -f "$link" ] && [ ! -L "$link" ]; then
          { cp "$link" "$seed" && chmod 600 "$seed" && mv -f "$seed" "$database"; } || fail "Could not copy database/$name from the release into shared/database, so nothing was changed. Fix the error above, then deploy again."
        else
          ( umask 077 && : > "$database" ) || fail "Could not create shared/database/$name."
        fi
        made+=("$database")
      fi
      ln -sfn "$database" "$link" || fail "Could not link shared/database/$name into the release."
    done
    if [ -n "$seeded" ]; then echo {seeded}; fi
    BASH;

    public function script(Application $application, string $release): string
    {
        $paths = new ReleasePaths($application);
        $shared = $paths->shared() . '/storage';
        $local = $paths->appDirectory($release) . '/storage';

        $seed = match ($application->type->language()->storageSeed()) {
            StorageSeed::FromRelease => $this->fromRelease($local, $shared),
            StorageSeed::FromLiveRelease => $this->fromLive($paths->appDirectory($paths->current()) . '/storage', $shared) . $this->fromRelease($local, $shared, self::PRIVATE_MODES),
            StorageSeed::None => '',
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
        $shared = escapeshellarg($paths->shared() . '/database');
        $folder = escapeshellarg($paths->appDirectory($release) . '/database');

        return "if [ -d {$shared} ]; then for file in {$shared}/*.sqlite; do if [ -f \"\$file\" ] && [ ! -L {$folder}/\"\${file##*/}\" ]; then mkdir -p {$folder} && ln -sfn \"\$file\" {$folder}/\"\${file##*/}\" || exit 1; fi; done; fi";
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
        return strtr(self::DATABASE, [
            '{folder}' => escapeshellarg($paths->appDirectory($release) . '/database'),
            '{shared}' => escapeshellarg($paths->shared() . '/database'),
            '{live}' => escapeshellarg($paths->appDirectory($paths->current()) . '/database'),
            '{copied}' => escapeshellarg($paths->shared() . '/database/' . self::COPIED),
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
            $this->removeCopiedDatabases($connection, $deployment, $shared . '/database');
        }
    }

    private function removeCopiedDatabases(SSHConnection $connection, Deployment $deployment, string $folder): void
    {
        $log = app(DeploymentLog::class);
        $quoted = escapeshellarg($folder);
        $copied = escapeshellarg($folder . '/' . self::COPIED);

        try {
            $connection->execute("if [ -f {$copied} ]; then while IFS= read -r name; do case \"\$name\" in *[!A-Za-z0-9._-]*) ;; *.sqlite) rm -f {$quoted}/\"\$name\" || exit 1 ;; esac; done < {$copied} && rm -f {$copied}; fi", sudo: true)->throw();
        } catch (Throwable) {
            $log->append($deployment, "Could not remove the SQLite files this deploy copied from the live release into {$folder}. They are listed in {$folder}/" . self::COPIED . '. Remove those files by hand before deploying again, or the next deploy will use the copies instead of the live data.');

            return;
        }

        $deployment->forceFill(['seeded_shared_database' => false])->save();
        $log->append($deployment, 'storage: removed the copies of the live SQLite files this deploy made, so the next deploy copies them again.');
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
