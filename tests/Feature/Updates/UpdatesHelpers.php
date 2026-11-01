<?php

declare(strict_types=1);

use App\Services\Updates\Release;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdateRun;
use App\Services\Updates\UpdateState;
use App\Services\Updates\UpdateStatus;
use App\Services\Updates\Version;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;

function updatesRegistry(): stdClass
{
    static $registry = null;

    return $registry ??= (object) ['base' => null, 'answers' => [], 'faked' => []];
}

function updatesUseTemporaryPaths(): array
{
    $base = realpath(sys_get_temp_dir()) . '/csu-' . Str::lower(Str::random(8));
    $paths = ['base' => $base, 'root' => "{$base}/root", 'state' => "{$base}/state", 'keys' => "{$base}/keys"];

    mkdir($paths['root'], 0700, true);
    mkdir($paths['keys'], 0700, true);

    config([
        'coresmith.updates.root' => $paths['root'],
        'coresmith.updates.state_path' => $paths['state'],
        'coresmith.updates.keys_path' => $paths['keys'],
    ]);

    updatesRegistry()->base = $base;

    return $paths;
}

function updatesCleanUp(): void
{
    $registry = updatesRegistry();

    if (is_string($registry->base) && is_dir($registry->base)) {
        File::deleteDirectory($registry->base);
    }

    $registry->base = null;
    $registry->answers = [];
    $registry->faked = [];
}

function updatesAnswer(string $url, array|string|Closure $answer, int $status = 200): void
{
    $registry = updatesRegistry();
    $registry->answers[$url] = [$answer, $status];

    if (in_array($url, $registry->faked, true)) {
        return;
    }

    $registry->faked[] = $url;

    Http::fake([$url => function () use ($url) {
        [$answer, $status] = updatesRegistry()->answers[$url];

        return $answer instanceof Closure ? $answer() : Http::response($answer, $status);
    }]);
}

function updatesFeed(array|string|Closure $answer, int $status = 200): void
{
    updatesAnswer((string) config('coresmith.updates.check_url'), $answer, $status);
}

function updatesFakeHorizon(array $pids): MasterSupervisorRepository
{
    $repository = new class($pids) implements MasterSupervisorRepository
    {
        public function __construct(public array $pids) {}

        public function names()
        {
            return array_map(fn(int|string $pid): string => "coresmith-{$pid}", $this->pids);
        }

        public function all()
        {
            return $this->get($this->names());
        }

        public function find($name)
        {
            return $this->get([$name])[0] ?? null;
        }

        public function get(array $names)
        {
            return array_values(array_filter(array_map(
                fn(int|string $pid): object => (object) ['name' => "coresmith-{$pid}", 'environment' => 'production', 'pid' => (string) $pid, 'status' => 'running', 'supervisors' => []],
                $this->pids,
            ), fn(object $master): bool => in_array($master->name, $names, true)));
        }

        public function update(MasterSupervisor $master) {}

        public function forget($name) {}

        public function flushExpired() {}
    };

    app()->instance(MasterSupervisorRepository::class, $repository);

    return $repository;
}

function updatesRelease(string $root, string $version, string $commit, array $meta = []): Release
{
    $name = ReleaseLayout::name(Version::parse($version), $commit);
    $path = "{$root}/releases/{$name}";

    if (!is_dir("{$path}/public")) {
        mkdir("{$path}/public", 0755, true);
    }

    file_put_contents("{$path}/public/index.php", "<?php echo '{$name}';\n");
    @symlink("{$root}/shared/.env", "{$path}/.env");
    @symlink("{$root}/shared/storage", "{$path}/storage");

    return app(ReleaseLayout::class)->seal($path, [
        'version' => $version,
        'commit' => $commit,
        'tag' => Version::parse($version)->tag(),
        'signature' => 'unchecked',
        'fingerprint' => null,
        'built_by' => 'installer',
        ...$meta,
    ]);
}

function updatesInstall(string $root, string $version = '0.1.0', string $commit = '8413dcea20f7fd49b704790b8013b5dd4340fa16'): Release
{
    foreach (['cache', 'sessions', 'views'] as $directory) {
        if (!is_dir("{$root}/shared/storage/framework/{$directory}")) {
            mkdir("{$root}/shared/storage/framework/{$directory}", 0755, true);
        }
    }

    file_put_contents("{$root}/shared/.env", "APP_KEY=base64:test\n");

    $release = updatesRelease($root, $version, $commit);
    symlink($release->path, "{$root}/app");

    return $release;
}

function updatesRun(string $to = '0.1.1', string $trigger = 'cli'): UpdateRun
{
    return app(UpdateState::class)->begin(UpdateStatus::start(app(ReleaseLayout::class)->current(), Version::parse($to), $trigger, null));
}

function updatesMachineKey(array $command): ?string
{
    $first = $command[0] ?? '';
    $second = $command[1] ?? '';

    return match (true) {
        $first === 'git' && $second === 'init' => 'init',
        $first === 'git' && in_array('fetch', $command, true) => 'fetch',
        $first === 'git' && in_array('verify-tag', $command, true) => 'verify',
        $first === 'git' && in_array('checkout', $command, true) => 'checkout',
        $first === 'git' && in_array('rev-parse', $command, true) => 'rev-parse',
        in_array($first, ['git', 'composer', 'bun'], true) && $second === '--version' => "tool:{$first}",
        $first === 'composer' && $second === 'check-platform-reqs' => 'platform',
        $first === 'composer' && $second === 'install' => 'composer',
        $first === PHP_BINARY && $second === 'artisan' => 'artisan:' . ($command[2] ?? ''),
        $first === 'bun' && $second === 'install' => 'bun-install',
        $first === 'bun' && $second === 'run' && ($command[2] ?? '') === 'build' => 'bun-build',
        $first === 'setfacl' => 'setfacl',
        $first === 'systemctl' && $second === 'is-active' => 'is-active',
        $first === 'systemctl' && $second === 'show' => 'main-pid',
        $first === 'gpg' => 'gpg',
        $first === 'gpgconf' => 'gpgconf',
        default => null,
    };
}

function updatesMachine(array $failures = []): void
{
    $registry = updatesRegistry();
    $registry->processes = [];
    $registry->pidReads = [];

    Process::fake(['*' => function (PendingProcess $process) use ($failures) {
        $registry = updatesRegistry();
        $command = is_array($process->command) ? array_values(array_map('strval', $process->command)) : [(string) $process->command];
        $registry->processes[] = ['command' => implode(' ', $command), 'path' => $process->path, 'timeout' => $process->timeout, 'environment' => $process->environment];
        $key = updatesMachineKey($command);
        $failure = $key === null ? null : ($failures[$key] ?? (str_starts_with($key, 'tool:') ? ($failures['tools'] ?? null) : null));

        if ($failure instanceof Closure) {
            return $failure($process);
        }

        if (is_array($failure)) {
            return Process::result(output: $failure[2] ?? '', errorOutput: $failure[1] ?? '', exitCode: $failure[0]);
        }

        return updatesMachineEffect($key, $command, $process, $failures);
    }]);
}

function updatesMachineEffect(?string $key, array $command, PendingProcess $process, array $failures): mixed
{
    $registry = updatesRegistry();
    $directory = ($index = array_search('-C', $command, true)) === false ? null : ($command[$index + 1] ?? null);

    switch ($key) {
        case 'init':
            $target = end($command);

            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }

            break;
        case 'verify':
            return Process::result(errorOutput: "[GNUPG:] NEWSIG\n[GNUPG:] GOODSIG 0123456789ABCDEF Coresmith Releases <releases@coresmith.io>\n[GNUPG:] VALIDSIG 1111111111111111111111111111111111111111 2026-10-18 1792310400 0 4 0 22 10 00 2222222222222222222222222222222222222222\n[GNUPG:] TRUST_UNDEFINED 0 pgp\n");
        case 'checkout':
            $tag = end($command);

            foreach (['storage/app', 'bootstrap/cache', 'public'] as $folder) {
                if (!is_dir("{$directory}/{$folder}")) {
                    mkdir("{$directory}/{$folder}", 0755, true);
                }
            }

            file_put_contents("{$directory}/VERSION", ($failures['version'] ?? ltrim($tag, 'v')) . "\n");
            file_put_contents("{$directory}/composer.lock", '{}');
            file_put_contents("{$directory}/artisan", "<?php\n");
            file_put_contents("{$directory}/storage/app/.gitignore", "*\n");
            file_put_contents("{$directory}/bootstrap/cache/.gitignore", "*\n");
            file_put_contents("{$directory}/public/index.php", "<?php\n");

            break;
        case 'rev-parse':
            return Process::result(output: "a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0\n");
        case 'composer':
            @mkdir("{$process->path}/vendor", 0755, true);
            file_put_contents("{$process->path}/vendor/autoload.php", "<?php\n");

            break;
        case 'bun-install':
            @mkdir("{$process->path}/node_modules/.bin", 0755, true);
            file_put_contents("{$process->path}/node_modules/.bin/vite", '');

            break;
        case 'bun-build':
            @mkdir("{$process->path}/public/build", 0755, true);
            file_put_contents("{$process->path}/public/build/manifest.json", '{}');

            break;
        case 'main-pid':
            $unit = (string) ($command[2] ?? '');
            $registry->pidReads[$unit] = ($registry->pidReads[$unit] ?? 0) + 1;

            return Process::result(output: (1000 + (($failures['main-pid'] ?? null) === 'stuck' ? 1 : $registry->pidReads[$unit])) . "\n");
    }

    return Process::result();
}

function updatesRan(): array
{
    return array_column(updatesRegistry()->processes ?? [], 'command');
}

function updatesProcesses(): array
{
    return updatesRegistry()->processes ?? [];
}
