<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DeployApplication;
use App\Jobs\InstallRuntimes;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\AppShell;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\ReleasePaths;
use App\Services\Deployment\SharedStorage;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    Http::preventStrayRequests();
});

function sharedStorageApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'type' => ApplicationType::Ruby,
        'framework' => 'rails',
        'php_version' => null,
        'ruby_version' => '3.4.10',
        'repository_url' => 'https://github.com/monoven/shop.git',
        'repository_branch' => 'main',
        'run_migrations' => true,
        'base_port' => 8000,
        ...$attributes,
    ]);
}

function sharedStorageTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('df /', "40\n");
}

function sharedStorageSeedingTransport(): FakeSSHTransport
{
    return sharedStorageTransport()->respondTo('/var/coresmith/apps/shop/current/storage', SharedStorage::SEEDED . "\n");
}

function sharedStorageDeploy(Deployment $deployment, FakeSSHTransport $transport, ?string $owner = null): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, '20261019-120000', $owner))->handle(app(SSHConnectionManager::class), app(DeploymentCommands::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function sharedStorageInstall(Deployment $deployment, FakeSSHTransport $transport, Closure $work): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        $work(new InstallRuntimes($deployment, '20261019-120000', [['runtime' => 'ruby', 'version' => '3.4.10']], 'owner-1'));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function sharedStoragePaused(Application $application, bool $seeded = true): Deployment
{
    return Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(20),
        'stale_after' => now()->addMinute(),
        'release' => '20261019-120000',
        'seeded_shared_storage' => $seeded,
        'stack' => ['runtimes' => ['ruby' => ['runtime' => 'ruby', 'version' => '3.4.10', 'source' => 'default', 'detail' => null]], 'framework' => null, 'tooling' => null],
    ]);
}

function sharedStorageRemoval(): string
{
    return "rm -rf '/var/coresmith/apps/shop/shared/storage'";
}

function sharedStorageCommandFor(Application $application): string
{
    $transport = sharedStorageTransport();

    sharedStorageDeploy(Deployment::factory()->for($application)->create(), $transport);

    return (string) collect($transport->commands())->first(fn(string $command): bool => str_contains($command, '/shared/logs'));
}

function sharedStorageScript(string $command): string
{
    return str_replace('$$', '$', str_replace("'\\''", "'", substr(Str::after($command, ' /bin/bash -c '), 1, -1)));
}

function sharedStorageRoot(): string
{
    $root = realpath(sys_get_temp_dir()) . '/coresmith-storage-' . Str::lower(Str::random(10));

    File::ensureDirectoryExists($root . '/shop/shared');
    File::ensureDirectoryExists($root . '/shop/releases/20261019-120000/storage');
    touch($root . '/shop/releases/20261019-120000/storage/.keep');

    return $root;
}

function sharedStorageLive(string $root): string
{
    $release = $root . '/shop/releases/20261018-090000';

    File::ensureDirectoryExists($release . '/storage');
    touch($release . '/storage/.keep');
    symlink($release, $root . '/shop/current');

    return $release . '/storage';
}

function sharedStorageRun(string $script, string $root, array $env = []): Process
{
    $process = new Process(['bash', '-c', str_replace(ReleasePaths::ROOT, $root, $script)], null, $env === [] ? null : $env);
    $process->run();

    return $process;
}

function sharedStorageShim(string $root, string $tool, string $then): array
{
    File::ensureDirectoryExists($root . '/bin');
    file_put_contents($root . '/bin/' . $tool, "#!/bin/bash\n" . escapeshellarg((string) (new ExecutableFinder)->find($tool)) . ' "$@" && ' . $then . "\n");
    chmod($root . '/bin/' . $tool, 0755);

    return ['PATH' => $root . '/bin:' . getenv('PATH')];
}

function sharedStorageSqlite(string $root, string $version, bool $vacuumFails = false): array
{
    File::ensureDirectoryExists($root . '/bin');
    file_put_contents($root . '/bin/sqlite3', implode("\n", [
        '#!/bin/bash',
        'printf \'%s\n\' "$*" >> ' . escapeshellarg($root . '/sqlite3.calls'),
        'if [ "$1" = -version ]; then echo ' . escapeshellarg($version . ' 2018-12-01 12:34:55 bf8c1b2b7a5960c282e543b9c293686dccff272512d08865f4600fb58238alt1') . '; exit 0; fi',
        ...($vacuumFails ? ['case "$*" in *"VACUUM INTO"*) echo "Error: database or disk is full" >&2; exit 1 ;; esac'] : []),
        'exec ' . escapeshellarg((string) (new ExecutableFinder)->find('sqlite3')) . ' "$@"',
    ]) . "\n");
    chmod($root . '/bin/sqlite3', 0755);

    return ['PATH' => $root . '/bin:' . getenv('PATH')];
}

function sharedStorageDatabase(string $path, array $rows, bool $wal = false): PDO
{
    $database = new PDO('sqlite:' . $path);

    if ($wal) {
        $database->exec('PRAGMA journal_mode=WAL');
    }

    $database->exec('CREATE TABLE IF NOT EXISTS products (name TEXT)');

    foreach ($rows as $row) {
        $database->prepare('INSERT INTO products (name) VALUES (?)')->execute([$row]);
    }

    return $database;
}

function sharedStorageRows(string $path): array
{
    return (new PDO('sqlite:' . $path))->query('SELECT name FROM products ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);
}

function sharedStorageFiles(string $directory): array
{
    return collect(File::allFiles($directory, true))->map(fn(SplFileInfo $file): string => $file->getRelativePathname())->sort()->values()->all();
}

function sharedStorageModes(string $directory): array
{
    $modes = ['' => sprintf('%o', fileperms($directory) & 0777)];
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($entries as $entry) {
        $modes[substr($entry->getPathname(), strlen($directory) + 1)] = sprintf('%o', $entry->getPerms() & 0777);
    }

    ksort($modes);

    return $modes;
}

function sharedStorageEntries(string $directory): array
{
    return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
}

function sharedStorageLink(string $path): ?string
{
    return is_link($path) ? readlink($path) : null;
}

test('a node app only links shared storage, as it did', function () {
    $application = sharedStorageApp(['type' => ApplicationType::Js, 'framework' => null, 'js_runtime' => 'node', 'node_version' => '24.15.0', 'ruby_version' => null]);
    $release = '/var/coresmith/apps/shop/releases/20261019-120000';

    expect(sharedStorageCommandFor($application))->toBe(app(AppShell::class)->run(
        $application,
        "mkdir -p '/var/coresmith/apps/shop/shared/logs'; if [ -d '/var/coresmith/apps/shop/shared/storage' ]; then rm -rf '{$release}/storage' && ln -sfn '/var/coresmith/apps/shop/shared/storage' '{$release}/storage'; fi",
        $release,
    ));
});

test('a laravel app copies its release storage into place only when the whole copy works, and has the folders and SQLite file Laravel expects', function () {
    $application = sharedStorageApp(['type' => ApplicationType::Php, 'framework' => 'laravel', 'php_version' => '8.5.5', 'ruby_version' => null]);
    $release = '/var/coresmith/apps/shop/releases/20261019-120000';
    $shared = "'/var/coresmith/apps/shop/shared/storage'";
    $copy = "'/var/coresmith/apps/shop/shared/storage.seed'";
    $local = "'{$release}/storage'";

    expect(sharedStorageCommandFor($application))->toBe(app(AppShell::class)->run(
        $application,
        "mkdir -p '/var/coresmith/apps/shop/shared/logs'; "
            . "[ -e {$local} ] || mkdir -p {$local}; "
            . "if [ -d {$local} ] && [ ! -L {$local} ] && [ ! -e {$shared} ]; then { rm -rf {$copy} && cp -a {$local} {$copy} && mv {$copy} {$shared}; } || { rm -rf {$copy}; echo \"Could not copy the release's storage into shared/storage, so nothing was changed. Fix the error above, then deploy again.\" >&2; exit 1; }; fi; "
            . "if [ -d {$shared} ]; then rm -rf {$local} && ln -sfn {$shared} {$local}; fi; "
            . "mkdir -p '{$release}/storage/framework/cache/data' '{$release}/storage/framework/sessions' '{$release}/storage/framework/views' '{$release}/storage/logs' '{$release}/bootstrap/cache' && { [ ! -d '{$release}/database' ] || [ -e '{$release}/database/database.sqlite' ] || touch '{$release}/database/database.sqlite'; }",
        $release,
    ));
});

test('a laravel deploy hands its shared storage folders to caddy after seeding them and before its processes start', function () {
    $application = sharedStorageApp(['type' => ApplicationType::Php, 'framework' => 'laravel', 'php_version' => '8.5.5', 'ruby_version' => null]);
    $transport = sharedStorageTransport();

    sharedStorageDeploy(Deployment::factory()->for($application)->create(), $transport);

    $commands = collect($transport->commands());
    $seeded = $commands->search(fn(string $command): bool => str_contains($command, '/shared/logs'));
    $shared = $commands->search((new AppAccount)->shareStorage($application));
    $started = $commands->search("systemctl enable 'coresmith-app-shop-fpm'");

    expect($seeded)->toBeInt()
        ->and($shared)->toBeInt()
        ->and($started)->toBeInt()
        ->and($seeded)->toBeLessThan($shared)
        ->and($shared)->toBeLessThan($started);
});

test('a rails deploy never hands its shared storage to caddy', function () {
    $application = sharedStorageApp();
    $transport = sharedStorageTransport();

    sharedStorageDeploy(Deployment::factory()->for($application)->create(), $transport);

    expect($transport->commands())->toContain("systemctl enable 'coresmith-app-shop-web'")
        ->and($transport->commands())->not->toContain((new AppAccount)->shareStorage($application));
});

test('a rails deploy runs its storage script as the app through the app shell, and the script parses', function () {
    $application = sharedStorageApp();
    $command = sharedStorageCommandFor($application);
    $script = sharedStorageScript($command);
    $outer = new Process(['bash', '-n', '-c', $command]);
    $outer->run();
    $inner = new Process(['bash', '-n', '-c', $script]);
    $inner->run();

    expect($command)->toBe(app(AppShell::class)->run($application, $script, '/var/coresmith/apps/shop/releases/20261019-120000'))
        ->and($command)->toStartWith('systemd-run --quiet --wait --pipe --collect --service-type=exec --uid=coresmith-app-shop --gid=coresmith-app-shop ')
        ->and($script)->toContain("'/var/coresmith/apps/shop/current/storage'")
        ->and($script)->toContain("statement='.backup \"/var/coresmith/apps/shop/shared/storage.seed/snapshot.sqlite3\"'")
        ->and($script)->toContain("statement='VACUUM INTO '\\''/var/coresmith/apps/shop/shared/storage.seed/snapshot.sqlite3'\\'''")
        ->and($script)->toContain("sqlite3 -version 2>/dev/null) && printf '%s\\n' 3.27.0 \"\${version%% *}\" | sort -C -V")
        ->and($script)->toContain('sqlite3 "$db" "$statement" && mv -f \'/var/coresmith/apps/shop/shared/storage.seed/snapshot.sqlite3\' "$copy"')
        ->and($script)->toContain('-print0')
        ->and($outer->getExitCode())->toBe(0, $outer->getErrorOutput())
        ->and($inner->getExitCode())->toBe(0, $inner->getErrorOutput());
});

test('a rails app in a folder of a monorepo seeds from and links that folder of each release', function () {
    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp(['directory' => 'backend'])));

    expect($script)->toContain("'/var/coresmith/apps/shop/current/backend/storage'")
        ->and($script)->toContain("ln -sfn '/var/coresmith/apps/shop/shared/storage' '/var/coresmith/apps/shop/releases/20261019-120000/backend/storage'");
});

test('a first rails deploy keeps storage in shared/storage, seeded from the release and readable by the app alone', function () {
    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp()));
    $root = sharedStorageRoot();

    try {
        $run = sharedStorageRun($script, $root);

        expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
            ->and($run->getOutput())->toBe('')
            ->and(sharedStorageFiles($root . '/shop/shared/storage'))->toBe(['.keep'])
            ->and(sharedStorageModes($root . '/shop/shared/storage'))->toBe(['' => '700', '.keep' => '600'])
            ->and(sharedStorageLink($root . '/shop/releases/20261019-120000/storage'))->toBe($root . '/shop/shared/storage')
            ->and(sharedStorageEntries($root . '/shop/shared'))->toBe(['logs', 'storage']);
    } finally {
        File::deleteDirectory($root);
    }
});

test('a rails app with a live release and no shared storage is seeded from the live storage, each database through VACUUM INTO', function () {
    if ((new ExecutableFinder)->find('sqlite3') === null) {
        $this->markTestSkipped('sqlite3 is not installed on this machine, so the seeding script cannot run for real.');
    }

    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp()));
    $root = sharedStorageRoot();
    $live = sharedStorageLive($root);
    $shared = $root . '/shop/shared/storage';
    $writer = sharedStorageDatabase($live . '/production.sqlite3', ['keyboard', 'mouse'], wal: true);
    sharedStorageDatabase($live . "/Ada's cache.sqlite3", ['fragment']);
    File::ensureDirectoryExists($live . '/xy/z1');
    file_put_contents($live . '/xy/z1/xyz1blob', 'uploaded bytes');
    clearstatcache();
    $pending = filesize($live . '/production.sqlite3-wal');

    try {
        $run = sharedStorageRun($script, $root);
        $files = sharedStorageFiles($shared);
        $modes = sharedStorageModes($shared);

        expect($pending)->toBeGreaterThan(0)
            ->and($run->getExitCode())->toBe(0, $run->getErrorOutput())
            ->and($run->getOutput())->toBe(SharedStorage::SEEDED . "\n")
            ->and($files)->toBe(['.keep', "Ada's cache.sqlite3", 'production.sqlite3', 'xy/z1/xyz1blob'])
            ->and($modes)->toBe(['' => '700', '.keep' => '600', "Ada's cache.sqlite3" => '600', 'production.sqlite3' => '600', 'xy' => '700', 'xy/z1' => '700', 'xy/z1/xyz1blob' => '600'])
            ->and(sharedStorageRows($shared . '/production.sqlite3'))->toBe(['keyboard', 'mouse'])
            ->and(sharedStorageRows($shared . "/Ada's cache.sqlite3"))->toBe(['fragment'])
            ->and(file_get_contents($shared . '/xy/z1/xyz1blob'))->toBe('uploaded bytes')
            ->and(sharedStorageLink($root . '/shop/releases/20261019-120000/storage'))->toBe($shared)
            ->and(sharedStorageEntries($root . '/shop/shared'))->toBe(['logs', 'storage'])
            ->and(is_link($live))->toBeFalse()
            ->and($writer->query('SELECT name FROM products ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN))->toBe(['keyboard', 'mouse']);
    } finally {
        unset($writer);
        File::deleteDirectory($root);
    }
});

test('each database is copied with VACUUM INTO from sqlite3 3.27.0 on and with .backup below it, compared as versions', function (string $version, string $used, string $unused) {
    if ((new ExecutableFinder)->find('sqlite3') === null) {
        $this->markTestSkipped('sqlite3 is not installed on this machine, so the seeding script cannot run for real.');
    }

    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp()));
    $root = sharedStorageRoot();
    $live = sharedStorageLive($root);
    $shared = $root . '/shop/shared/storage';
    $writer = sharedStorageDatabase($live . '/production.sqlite3', ['keyboard', 'mouse'], wal: true);
    $env = sharedStorageSqlite($root, $version);

    try {
        $run = sharedStorageRun($script, $root, $env);
        $calls = (string) file_get_contents($root . '/sqlite3.calls');
        $files = sharedStorageFiles($shared);

        expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
            ->and($run->getOutput())->toBe(SharedStorage::SEEDED . "\n")
            ->and($calls)->toContain($used)
            ->and($calls)->not->toContain($unused)
            ->and($files)->toBe(['.keep', 'production.sqlite3'])
            ->and(sharedStorageRows($shared . '/production.sqlite3'))->toBe(['keyboard', 'mouse']);
    } finally {
        unset($writer);
        File::deleteDirectory($root);
    }
})->with([
    'amazon linux 2' => ['3.7.17', '.backup', 'VACUUM INTO'],
    'rocky and alma 8' => ['3.26.0', '.backup', 'VACUUM INTO'],
    'the first with VACUUM INTO' => ['3.27.0', 'VACUUM INTO', '.backup'],
    'ubuntu 24.04' => ['3.45.1', 'VACUUM INTO', '.backup'],
]);

test('a VACUUM INTO that fails for real fails the seed instead of falling back to .backup', function () {
    if ((new ExecutableFinder)->find('sqlite3') === null) {
        $this->markTestSkipped('sqlite3 is not installed on this machine, so the seeding script cannot run for real.');
    }

    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp()));
    $root = sharedStorageRoot();
    $live = sharedStorageLive($root);
    sharedStorageDatabase($live . '/production.sqlite3', ['keyboard']);
    $env = sharedStorageSqlite($root, '3.45.1', vacuumFails: true);

    try {
        $run = sharedStorageRun($script, $root, $env);

        expect($run->getExitCode())->not->toBe(0)
            ->and($run->getErrorOutput())->toContain('Error: database or disk is full')
            ->and($run->getErrorOutput())->toContain('Could not copy ' . $root . '/shop/current/storage/production.sqlite3.')
            ->and((string) file_get_contents($root . '/sqlite3.calls'))->not->toContain('.backup')
            ->and(sharedStorageEntries($root . '/shop/shared'))->toBe(['logs'])
            ->and(sharedStorageLink($root . '/shop/releases/20261019-120000/storage'))->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});

test('a rails app that already has shared storage only links it', function () {
    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp()));
    $root = sharedStorageRoot();
    $live = sharedStorageLive($root);
    $shared = $root . '/shop/shared/storage';
    sharedStorageDatabase($live . '/production.sqlite3', ['stale']);
    File::ensureDirectoryExists($shared);
    sharedStorageDatabase($shared . '/production.sqlite3', ['current']);

    try {
        $run = sharedStorageRun($script, $root);

        expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
            ->and($run->getOutput())->toBe('')
            ->and(sharedStorageFiles($shared))->toBe(['production.sqlite3'])
            ->and(sharedStorageRows($shared . '/production.sqlite3'))->toBe(['current'])
            ->and(sharedStorageLink($root . '/shop/releases/20261019-120000/storage'))->toBe($shared)
            ->and(sharedStorageEntries($root . '/shop/shared'))->toBe(['logs', 'storage']);
    } finally {
        File::deleteDirectory($root);
    }
});

test('a live release whose storage is a link is not copied, so shared/storage is seeded from the new release', function () {
    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp()));
    $root = sharedStorageRoot();
    $live = $root . '/shop/releases/20261018-090000';
    $shared = $root . '/shop/shared/storage';
    File::ensureDirectoryExists($live);
    File::ensureDirectoryExists($root . '/volume');
    file_put_contents($root . '/volume/upload', 'uploaded bytes');
    symlink($root . '/volume', $live . '/storage');
    symlink($live, $root . '/shop/current');

    try {
        $run = sharedStorageRun($script, $root);

        expect($run->getExitCode())->toBe(0, $run->getErrorOutput())
            ->and(is_link($shared))->toBeFalse()
            ->and(sharedStorageFiles($shared))->toBe(['.keep'])
            ->and(sharedStorageLink($root . '/shop/releases/20261019-120000/storage'))->toBe($shared)
            ->and(sharedStorageFiles($root . '/volume'))->toBe(['upload']);
    } finally {
        File::deleteDirectory($root);
    }
});

test('a release copy that fails stops the deploy and leaves no shared storage behind', function (array $attributes) {
    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp($attributes)));
    $root = sharedStorageRoot();
    $env = sharedStorageShim($root, 'cp', "{ echo 'cp: storage/.keep: No space left on device' >&2; exit 1; }");

    try {
        $run = sharedStorageRun($script, $root, $env);

        expect($run->getExitCode())->not->toBe(0)
            ->and($run->getErrorOutput())->toContain('No space left on device')
            ->and($run->getErrorOutput())->toContain("Could not copy the release's storage into shared/storage, so nothing was changed.")
            ->and(sharedStorageEntries($root . '/shop/shared'))->toBe(['logs'])
            ->and(sharedStorageLink($root . '/shop/releases/20261019-120000/storage'))->toBeNull()
            ->and(sharedStorageFiles($root . '/shop/releases/20261019-120000/storage'))->toBe(['.keep']);
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    'laravel' => [['type' => ApplicationType::Php, 'framework' => 'laravel', 'php_version' => '8.5.5', 'ruby_version' => null]],
    'rails' => [[]],
]);

test('a live database that cannot be copied leaves no shared storage behind and says which one failed', function () {
    if ((new ExecutableFinder)->find('sqlite3') === null) {
        $this->markTestSkipped('sqlite3 is not installed on this machine, so the seeding script cannot run for real.');
    }

    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp()));
    $root = sharedStorageRoot();
    $live = sharedStorageLive($root);
    file_put_contents($live . '/production.sqlite3', str_repeat('not a database ', 64));
    file_put_contents($live . '/upload', 'uploaded bytes');

    try {
        $run = sharedStorageRun($script, $root);

        expect($run->getExitCode())->not->toBe(0)
            ->and($run->getOutput())->toBe('')
            ->and($run->getErrorOutput())->toContain('file is not a database')
            ->and($run->getErrorOutput())->toContain('/shop/current/storage/production.sqlite3')
            ->and(sharedStorageEntries($root . '/shop/shared'))->toBe(['logs'])
            ->and(sharedStorageLink($root . '/shop/releases/20261019-120000/storage'))->toBeNull()
            ->and(sharedStorageFiles($live))->toBe(['.keep', 'production.sqlite3', 'upload']);
    } finally {
        File::deleteDirectory($root);
    }
});

test('a live database that disappears while it is copied fails the seed instead of copying an empty one', function () {
    if ((new ExecutableFinder)->find('sqlite3') === null) {
        $this->markTestSkipped('sqlite3 is not installed on this machine, so the seeding script cannot run for real.');
    }

    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp()));
    $root = sharedStorageRoot();
    $live = sharedStorageLive($root);
    sharedStorageDatabase($live . '/production.sqlite3', ['keyboard']);
    $env = sharedStorageShim($root, 'cp', 'rm -f ' . escapeshellarg($live . '/production.sqlite3'));

    try {
        $run = sharedStorageRun($script, $root, $env);

        expect($run->getExitCode())->not->toBe(0)
            ->and($run->getErrorOutput())->toContain('/shop/current/storage/production.sqlite3')
            ->and(sharedStorageEntries($root . '/shop/shared'))->toBe(['logs'])
            ->and(sharedStorageFiles($live))->toBe(['.keep']);
    } finally {
        File::deleteDirectory($root);
    }
});

test('a search for databases that fails part way fails the seed instead of keeping plain copies', function () {
    if ((new ExecutableFinder)->find('sqlite3') === null) {
        $this->markTestSkipped('sqlite3 is not installed on this machine, so the seeding script cannot run for real.');
    }

    $script = sharedStorageScript(sharedStorageCommandFor(sharedStorageApp()));
    $root = sharedStorageRoot();
    $live = sharedStorageLive($root);
    sharedStorageDatabase($live . '/production.sqlite3', ['keyboard']);
    $env = sharedStorageShim($root, 'find', "{ echo 'find: storage/unreadable: Permission denied' >&2; exit 1; }");

    try {
        $run = sharedStorageRun($script, $root, $env);

        expect($run->getExitCode())->not->toBe(0)
            ->and($run->getErrorOutput())->toContain('Permission denied')
            ->and(sharedStorageEntries($root . '/shop/shared'))->toBe(['logs'])
            ->and(sharedStorageLink($root . '/shop/releases/20261019-120000/storage'))->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});

test('a rails deploy whose storage seed fails stops with the seed output in its log', function () {
    $deployment = Deployment::factory()->for(sharedStorageApp())->create();
    $transport = sharedStorageTransport()->respondTo('/var/coresmith/apps/shop/current/storage', "Error: file is not a database\n", 1);

    expect(fn() => sharedStorageDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->log)->toContain('Error: file is not a database')
        ->and(implode("\n", $transport->commands()))->not->toContain('mv -Tf');
});

test('a storage copy that fails is recorded as that step, so the deployment page does not blame the clone', function () {
    $deployment = Deployment::factory()->for(sharedStorageApp())->create();
    $transport = sharedStorageTransport()->respondTo('/var/coresmith/apps/shop/current/storage', "Error: file is not a database\n", 1);

    expect(fn() => sharedStorageDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($deployment->fresh()->failed_step)->toBe('storage')
        ->and($deployment->fresh()->failure_reason)->toBeNull();
});

test('a deploy that fails after its storage step records no failed step', function () {
    $deployment = Deployment::factory()->for(sharedStorageApp())->create();
    $transport = sharedStorageTransport()->respondTo('rails db:migrate', "ActiveRecord::StatementInvalid\n", 1);

    expect(fn() => sharedStorageDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failed_step)->toBeNull();
});

test('a deploy says once when it copies the live data, and records it on the deployment', function (bool $seeded, int $lines) {
    $deployment = Deployment::factory()->for(sharedStorageApp())->create();

    sharedStorageDeploy($deployment, sharedStorageTransport()->respondTo('/var/coresmith/apps/shop/current/storage', $seeded ? SharedStorage::SEEDED . "\n" : ''));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->seeded_shared_storage)->toBe($seeded)
        ->and(substr_count((string) $deployment->fresh()->log, "storage: copied the live release's data into shared/storage. Changes made while this deploy runs stay in the previous release."))->toBe($lines);
})->with([
    'copied' => [true, 1],
    'not copied' => [false, 0],
]);

test('a deploy that copied the live data and fails before the swap removes its copy', function () {
    $deployment = Deployment::factory()->for(sharedStorageApp())->create();
    $transport = sharedStorageSeedingTransport()->respondTo('rails db:migrate', "ActiveRecord::StatementInvalid\n", 1);

    expect(fn() => sharedStorageDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    $commands = $transport->commands();
    $migrate = collect($commands)->search(fn(string $command): bool => str_contains($command, 'rails db:migrate'));

    expect($commands)->toContain(sharedStorageRemoval())
        ->and(array_search(sharedStorageRemoval(), $commands, true))->toBeGreaterThan($migrate)
        ->and(implode("\n", $commands))->not->toContain('mv -Tf')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->seeded_shared_storage)->toBeFalse()
        ->and($deployment->fresh()->log)->toContain('storage: removed the copy of the live data this deploy made, so the next deploy copies it again.');
});

test('a deploy whose storage command copied the live data and then failed still removes its copy', function () {
    $deployment = Deployment::factory()->for(sharedStorageApp())->create();
    $transport = sharedStorageTransport()->respondTo('/var/coresmith/apps/shop/current/storage', SharedStorage::SEEDED . "\nln: failed to create symbolic link: Read-only file system\n", 1);

    expect(fn() => sharedStorageDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($transport->commands())->toContain(sharedStorageRemoval())
        ->and($deployment->fresh()->seeded_shared_storage)->toBeFalse()
        ->and($deployment->fresh()->log)->toContain('ln: failed to create symbolic link');
});

test('a deploy that copied the live data and fails after the swap keeps it, because the live release uses it', function () {
    $deployment = Deployment::factory()->for(sharedStorageApp())->create();
    $transport = sharedStorageSeedingTransport()->respondTo('ls -1dt', "ls: cannot access\n", 2);

    expect(fn() => sharedStorageDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(implode("\n", $transport->commands()))->toContain('mv -Tf')
        ->and($transport->commands())->not->toContain(sharedStorageRemoval())
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->seeded_shared_storage)->toBeTrue();
});

test('a deploy that did not copy the live data leaves shared storage alone when it fails', function () {
    $deployment = Deployment::factory()->for(sharedStorageApp())->create();
    $transport = sharedStorageTransport()->respondTo('rails db:migrate', "ActiveRecord::StatementInvalid\n", 1);

    expect(fn() => sharedStorageDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($transport->commands())->not->toContain(sharedStorageRemoval())
        ->and($deployment->fresh()->log)->not->toContain('storage:');
});

test('a copy that cannot be removed tells the person to remove it before deploying again', function () {
    $deployment = Deployment::factory()->for(sharedStorageApp())->create();
    $transport = sharedStorageSeedingTransport()
        ->respondTo('rails db:migrate', "ActiveRecord::StatementInvalid\n", 1)
        ->respondTo(sharedStorageRemoval(), "rm: cannot remove '/var/coresmith/apps/shop/shared/storage': Device or resource busy\n", 1);

    expect(fn() => sharedStorageDeploy($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($deployment->fresh()->seeded_shared_storage)->toBeTrue()
        ->and($deployment->fresh()->log)->toContain('Could not remove /var/coresmith/apps/shop/shared/storage, the copy of the live data this deploy made. Remove it by hand before deploying again, or the next deploy will use that copy instead of the live data.');
});

test('after its copy is removed, the next deploy copies the data that is live then', function () {
    if ((new ExecutableFinder)->find('sqlite3') === null) {
        $this->markTestSkipped('sqlite3 is not installed on this machine, so the seeding script cannot run for real.');
    }

    $application = sharedStorageApp();
    $script = sharedStorageScript(sharedStorageCommandFor($application));
    $failing = sharedStorageSeedingTransport()->respondTo('rails db:migrate', "ActiveRecord::StatementInvalid\n", 1);

    expect(fn() => sharedStorageDeploy(Deployment::factory()->for($application)->create(), $failing))->toThrow(SSHCommandFailedException::class);

    $removal = (string) collect($failing->commands())->first(fn(string $command): bool => $command === sharedStorageRemoval());
    $root = sharedStorageRoot();
    $live = sharedStorageLive($root);
    $shared = $root . '/shop/shared/storage';
    sharedStorageDatabase($live . '/production.sqlite3', ['keyboard']);

    try {
        $first = sharedStorageRun($script, $root);
        $copied = sharedStorageRows($shared . '/production.sqlite3');
        $removed = sharedStorageRun($removal, $root);
        $after = sharedStorageEntries($root . '/shop/shared');
        sharedStorageDatabase($live . '/production.sqlite3', ['mouse']);
        $next = sharedStorageRun($script, $root);

        expect($first->getOutput())->toBe(SharedStorage::SEEDED . "\n")
            ->and($copied)->toBe(['keyboard'])
            ->and($removed->getExitCode())->toBe(0, $removed->getErrorOutput())
            ->and($after)->toBe(['logs'])
            ->and($next->getExitCode())->toBe(0, $next->getErrorOutput())
            ->and($next->getOutput())->toBe(SharedStorage::SEEDED . "\n")
            ->and(sharedStorageRows($shared . '/production.sqlite3'))->toBe(['keyboard', 'mouse']);
    } finally {
        File::deleteDirectory($root);
    }
});

test('a deploy that pauses for a runtime install and fails after resuming still removes its copy', function () {
    Queue::fake();
    $application = sharedStorageApp();
    $deployment = Deployment::factory()->for($application)->create();

    sharedStorageDeploy($deployment, sharedStorageSeedingTransport()->respondTo("ls -1 '/opt/asdf-data/installs/ruby'", "\n"));

    $owner = null;
    Queue::assertPushed(InstallRuntimes::class, function (InstallRuntimes $job) use (&$owner): bool {
        $owner = (fn(): string => $this->lockOwner)->call($job);

        return true;
    });

    expect($deployment->fresh()->seeded_shared_storage)->toBeTrue();

    $resumed = sharedStorageTransport()
        ->respondTo("test -d '/opt/asdf-data/installs/ruby/3.4.10'", "yes\n")
        ->respondTo('rails db:migrate', "ActiveRecord::StatementInvalid\n", 1);

    expect(fn() => sharedStorageDeploy($deployment->fresh(), $resumed, $owner))->toThrow(SSHCommandFailedException::class);

    expect($resumed->commands())->toContain(sharedStorageRemoval())
        ->and($deployment->fresh()->seeded_shared_storage)->toBeFalse();
});

test('a resumed deploy whose lock another deploy now holds leaves shared storage alone', function () {
    $application = sharedStorageApp();
    $deployment = sharedStoragePaused($application);
    Cache::lock('deploy:' . $application->id, 60, 'someone-else')->get();
    $transport = sharedStorageTransport();

    expect(fn() => sharedStorageDeploy($deployment->fresh(), $transport, 'owner-1'))->toThrow(DeploymentAborted::class);

    expect($transport->commands())->toContain('rm -rf /var/coresmith/apps/shop/releases/20261019-120000')
        ->and($transport->commands())->not->toContain(sharedStorageRemoval())
        ->and($deployment->fresh()->seeded_shared_storage)->toBeTrue();
});

test('a runtime install that fails removes the copy its deploy made, only while it holds the deploy lock', function (Closure $fail, string $holder, bool $removed) {
    $application = sharedStorageApp();
    $deployment = sharedStoragePaused($application);
    Cache::lock('deploy:' . $application->id, 3600, $holder)->get();
    $transport = sharedStorageTransport()->respondTo('test -d', "no\n")->respondTo('asdf install', "configure: error: no acceptable C compiler found\n", 1);

    sharedStorageInstall($deployment->fresh(), $transport, $fail);

    expect(in_array(sharedStorageRemoval(), $transport->commands(), true))->toBe($removed)
        ->and($deployment->fresh()->seeded_shared_storage)->toBe(!$removed);
})->with([
    'the install fails' => [fn(InstallRuntimes $job) => rescue(fn() => app()->call([$job, 'handle']), report: false), 'owner-1', true],
    'the install job dies' => [fn(InstallRuntimes $job) => $job->failed(new MaxAttemptsExceededException('timed out')), 'owner-1', true],
    'another deploy holds the lock' => [fn(InstallRuntimes $job) => rescue(fn() => app()->call([$job, 'handle']), report: false), 'someone-else', false],
]);
