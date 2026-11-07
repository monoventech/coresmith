<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Services\Deployment\LogSanitizer;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use RuntimeException;
use Throwable;

final class PanelHealth
{
    public const HORIZON_DOWN = "Horizon isn't running. Start it with sudo systemctl start coresmith-horizon.";

    public function __construct(private readonly LogSanitizer $sanitizer) {}

    /**
     * @return list<array{name: string, status: 'ok'|'warn'|'fail', detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->run('database', fn(): string => $this->database()),
            $this->run('migrations', fn(): string => $this->migrations()),
            $this->run('cache', fn(): string => $this->cache()),
            $this->run('queue', fn(): string => $this->queue()),
            $this->horizon(),
            $this->run('assets', fn(): string => $this->assets()),
            $this->run('storage', fn(): string => $this->storage()),
            $this->run('keys', fn(): string => $this->keys()),
        ];
    }

    /**
     * @param  list<array{name: string, status: string, detail: string}>  $checks
     */
    public static function healthy(array $checks): bool
    {
        return !in_array('fail', array_column($checks, 'status'), true);
    }

    /**
     * @param  callable(): string  $check
     * @return array{name: string, status: 'ok'|'fail', detail: string}
     */
    private function run(string $name, callable $check): array
    {
        try {
            return ['name' => $name, 'status' => 'ok', 'detail' => $check()];
        } catch (Throwable $e) {
            return ['name' => $name, 'status' => 'fail', 'detail' => $this->sanitizer->sanitize($e->getMessage())];
        }
    }

    private function database(): string
    {
        DB::connection()->select('select 1');

        return DB::connection()->getDriverName();
    }

    private function migrations(): string
    {
        $migrator = app(Migrator::class);

        $files = $migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]);
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $pending = count(array_diff(array_keys($files), $ran));

        if ($pending > 0) {
            throw new RuntimeException(($pending === 1 ? '1 migration has' : "{$pending} migrations have") . ' not run. Run php artisan migrate --force.');
        }

        return 'All ' . count($files) . ' migrations have run.';
    }

    private function cache(): string
    {
        $key = 'coresmith:health:' . Str::random(16);
        $value = Str::random(16);

        Cache::put($key, $value, 60);
        $read = Cache::get($key);
        Cache::forget($key);

        if ($read !== $value) {
            throw new RuntimeException("The cache didn't return what Coresmith stored in it.");
        }

        return (string) config('cache.default');
    }

    private function queue(): string
    {
        $driver = (string) config('queue.default');

        if ($driver === 'redis') {
            Redis::connection((string) config('queue.connections.redis.connection', 'default'))->ping();
        }

        return $driver;
    }

    /**
     * @return array{name: string, status: 'ok'|'warn', detail: string}
     */
    private function horizon(): array
    {
        try {
            $masters = (array) app(MasterSupervisorRepository::class)->all();
        } catch (Throwable) {
            $masters = [];
        }

        if ($masters === []) {
            return ['name' => 'horizon', 'status' => 'warn', 'detail' => self::HORIZON_DOWN];
        }

        $pids = implode(', ', array_map(fn(object $master): string => (string) ($master->pid ?? '?'), $masters));

        return ['name' => 'horizon', 'status' => 'ok', 'detail' => "Running (pid {$pids})."];
    }

    private function assets(): string
    {
        if (!is_file(public_path('build/manifest.json'))) {
            throw new RuntimeException("The interface hasn't been built. Run bun run build.");
        }

        return 'Built.';
    }

    private function storage(): string
    {
        $path = storage_path('framework');

        if (!is_dir($path) || !is_writable($path)) {
            throw new RuntimeException("Coresmith can't write to {$path}.");
        }

        return 'Writable.';
    }

    private function keys(): string
    {
        if ((string) config('app.key') === '' || (string) config('coresmith.vault_key') === '') {
            throw new RuntimeException('APP_KEY or CORESMITH_VAULT_KEY is missing.');
        }

        return 'Present.';
    }
}
