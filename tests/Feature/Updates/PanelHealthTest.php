<?php

declare(strict_types=1);

use App\Services\Updates\PanelHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    updatesFakeHorizon([4242]);

    $this->public = realpath(sys_get_temp_dir()) . '/csu-public-' . Str::lower(Str::random(8));
    mkdir("{$this->public}/build", 0700, true);
    file_put_contents("{$this->public}/build/manifest.json", '{}');
    $this->app->usePublicPath($this->public);
});

afterEach(function () {
    File::deleteDirectory($this->public);
});

function updatesHealthCheck(string $name): array
{
    return collect(app(PanelHealth::class)->checks())->firstWhere('name', $name);
}

function updatesHealthJson(): array
{
    $code = Artisan::call('coresmith:health', ['--json' => true]);

    return ['code' => $code, 'body' => json_decode(Artisan::output(), true)];
}

test('the health command reports every check as json', function () {
    ['code' => $code, 'body' => $body] = updatesHealthJson();

    expect($code)->toBe(0)
        ->and($body['ok'])->toBeTrue()
        ->and($body['release'])->toBe(basename(base_path()))
        ->and($body['version'])->toBe(config('coresmith.version'))
        ->and(array_column($body['checks'], 'name'))->toBe(['database', 'migrations', 'cache', 'queue', 'horizon', 'assets', 'storage', 'keys'])
        ->and(array_unique(array_column($body['checks'], 'status')))->toBe(['ok']);
});

test('a missing build fails the health check', function () {
    unlink("{$this->public}/build/manifest.json");

    expect(updatesHealthCheck('assets'))->toBe(['name' => 'assets', 'status' => 'fail', 'detail' => "The interface hasn't been built. Run bun run build."])
        ->and(updatesHealthJson()['code'])->toBe(1)
        ->and(Artisan::call('coresmith:health'))->toBe(1);
});

test('pending migrations fail the health check', function (int $count, string $detail) {
    $directory = realpath(sys_get_temp_dir()) . '/csu-migrations-' . Str::lower(Str::random(8));
    mkdir($directory, 0700, true);

    foreach (range(1, $count) as $i) {
        file_put_contents("{$directory}/2099_01_01_00000{$i}_add_something_{$i}.php", '<?php return new class extends Illuminate\Database\Migrations\Migration { public function up(): void {} };');
    }

    app('migrator')->path($directory);

    try {
        expect(updatesHealthCheck('migrations'))->toBe(['name' => 'migrations', 'status' => 'fail', 'detail' => $detail])
            ->and(updatesHealthJson()['code'])->toBe(1);
    } finally {
        File::deleteDirectory($directory);
    }
})->with([
    'one' => [1, '1 migration has not run. Run php artisan migrate --force.'],
    'two' => [2, '2 migrations have not run. Run php artisan migrate --force.'],
]);

test('horizon not running is a warning, not a failure', function () {
    updatesFakeHorizon([]);

    $checks = app(PanelHealth::class)->checks();

    expect(collect($checks)->firstWhere('name', 'horizon'))->toBe(['name' => 'horizon', 'status' => 'warn', 'detail' => "Horizon isn't running. Start it with sudo systemctl start coresmith-horizon."])
        ->and(PanelHealth::healthy($checks))->toBeTrue()
        ->and(updatesHealthJson()['code'])->toBe(0);
});

test('horizon that cannot be asked is a warning too', function () {
    app()->instance(MasterSupervisorRepository::class, Mockery::mock(MasterSupervisorRepository::class, fn($mock) => $mock->shouldReceive('all')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'))));

    expect(updatesHealthCheck('horizon'))->toBe(['name' => 'horizon', 'status' => 'warn', 'detail' => "Horizon isn't running. Start it with sudo systemctl start coresmith-horizon."]);
});

function updatesWithBrokenDatabase(Closure $assertions): void
{
    $default = config('database.default');
    config(['database.connections.csu_broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => ''], 'database.default' => 'csu_broken']);

    try {
        $assertions();
    } finally {
        config(['database.default' => $default]);
        DB::purge('csu_broken');
    }
}

test('an unreachable database fails the health check', function () {
    updatesWithBrokenDatabase(function () {
        expect(updatesHealthCheck('database')['status'])->toBe('fail')
            ->and(updatesHealthJson()['code'])->toBe(1);
    });
});

test('a missing key fails the health check', function (string $key) {
    config([$key => '']);

    expect(updatesHealthCheck('keys'))->toBe(['name' => 'keys', 'status' => 'fail', 'detail' => 'APP_KEY or CORESMITH_VAULT_KEY is missing.']);
})->with(['app.key', 'coresmith.vault_key']);

test('storage the panel cannot write fails the health check', function () {
    $storage = realpath(sys_get_temp_dir()) . '/csu-readonly-' . Str::lower(Str::random(8));
    mkdir("{$storage}/framework", 0700, true);
    chmod("{$storage}/framework", 0500);
    $this->app->useStoragePath($storage);

    try {
        expect(updatesHealthCheck('storage'))->toBe(['name' => 'storage', 'status' => 'fail', 'detail' => "Coresmith can't write to {$storage}/framework."]);
    } finally {
        chmod("{$storage}/framework", 0700);
        File::deleteDirectory($storage);
    }
});

test('a cache that loses what it stores fails the health check', function () {
    config(['cache.default' => 'null']);

    expect(updatesHealthCheck('cache')['status'])->toBe('fail');
});

test('a check that throws fails with what went wrong, and the others still run', function () {
    updatesWithBrokenDatabase(function () {
        $checks = app(PanelHealth::class)->checks();

        expect(array_column($checks, 'name'))->toHaveCount(8)
            ->and(collect($checks)->firstWhere('name', 'database')['detail'])->toContain('/nonexistent/dir/db.sqlite')
            ->and(collect($checks)->firstWhere('name', 'migrations')['status'])->toBe('fail')
            ->and(collect($checks)->firstWhere('name', 'assets')['status'])->toBe('ok');
    });
});

test('the health command reads as plain lines without --json', function () {
    $code = Artisan::call('coresmith:health');

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('database')->toContain('keys');
});
