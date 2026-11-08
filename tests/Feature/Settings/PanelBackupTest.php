<?php

declare(strict_types=1);

use App\Jobs\BackUpPanel;
use App\Services\Panel\PanelBackup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

function backupUseProbeDatabase(string $root): void
{
    touch($root . '/panel.sqlite');

    config([
        'database.connections.backup_probe' => ['driver' => 'sqlite', 'database' => $root . '/panel.sqlite', 'prefix' => '', 'foreign_key_constraints' => true],
        'database.default' => 'backup_probe',
    ]);

    DB::connection('backup_probe')->statement('create table probe (note text)');
    DB::connection('backup_probe')->table('probe')->insert(['note' => 'still here']);

    (require base_path('database/migrations/2026_05_28_082634_create_activity_log_table.php'))->up();
}

function backupUseExternalDatabase(string $driver): void
{
    config([
        'database.connections.backup_external' => [
            'driver' => $driver,
            'host' => 'db.internal',
            'port' => $driver === 'pgsql' ? 5432 : 3306,
            'username' => 'coresmith',
            'password' => 's3cret-pass',
            'database' => 'coresmith_panel',
            'prefix' => '',
        ],
        'database.default' => 'backup_external',
    ]);
}

function backupFakeDump(string $flag): void
{
    Process::fake(function (PendingProcess $process) use ($flag) {
        $target = collect($process->command)->first(fn(string $argument): bool => str_starts_with($argument, $flag));
        file_put_contents(substr($target, strlen($flag)), '-- dump');

        return Process::result();
    });
}

function backupDirectory(): string
{
    return storage_path('app/private/backups');
}

function backupEntries(string $path): array
{
    return collect(new RecursiveIteratorIterator(new PharData($path)))
        ->map(fn(PharFileInfo $file): string => $file->getFilename())
        ->sort()
        ->values()
        ->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
    $this->backupRoot = realpath(sys_get_temp_dir()) . '/coresmith-backup-' . Str::lower(Str::random(10));
    File::ensureDirectoryExists($this->backupRoot . '/app/private');
    $this->app->useStoragePath($this->backupRoot);
    $this->previousConnection = config('database.default');
});

afterEach(function () {
    config(['database.default' => $this->previousConnection]);
    DB::purge('backup_probe');
    DB::purge('backup_external');
    File::deleteDirectory($this->backupRoot);
});

test('a backup writes a private compressed archive of the database and cleans up after itself', function () {
    backupUseProbeDatabase($this->backupRoot);

    $backup = app(PanelBackup::class)->run();
    $expected = backupDirectory() . '/coresmith-20260913-140509.tar.gz';

    expect($backup['path'])->toBe($expected)
        ->and(is_file($expected))->toBeTrue()
        ->and($backup['bytes'])->toBe(filesize($expected))
        ->and($backup['bytes'])->toBeGreaterThan(0)
        ->and(fileperms($expected) & 0777)->toBe(0600)
        ->and(fileperms(backupDirectory()) & 0777)->toBe(0700)
        ->and(is_file(backupDirectory() . '/coresmith-20260913-140509.tar'))->toBeFalse()
        ->and(is_dir(backupDirectory() . '/coresmith-20260913-140509'))->toBeFalse()
        ->and(app(PanelBackup::class)->verify($expected))->toBeTrue()
        ->and(backupEntries($expected))->toContain('database.sqlite');
});

test('the environment file is archived alongside the database when there is one', function () {
    backupUseProbeDatabase($this->backupRoot);

    $entries = backupEntries(app(PanelBackup::class)->run()['path']);

    expect(in_array('.env', $entries, true))->toBe(is_file(base_path('.env')));
});

test('the archived database is a working copy of the panel database', function () {
    backupUseProbeDatabase($this->backupRoot);

    $backup = app(PanelBackup::class)->run();
    (new PharData($backup['path']))->extractTo($this->backupRoot . '/restore', 'database.sqlite');

    $restored = new PDO('sqlite:' . $this->backupRoot . '/restore/database.sqlite');

    expect($restored->query('select note from probe')->fetchColumn())->toBe('still here');
});

test('only the newest fourteen backups are kept and nothing else in the folder is touched', function () {
    backupUseProbeDatabase($this->backupRoot);
    File::ensureDirectoryExists(backupDirectory());

    foreach (range(1, 15) as $day) {
        file_put_contents(backupDirectory() . sprintf('/coresmith-202608%02d-040000.tar.gz', $day), 'old');
    }

    file_put_contents(backupDirectory() . '/notes.txt', 'keep me');
    file_put_contents(backupDirectory() . '/manual-export.tar.gz', 'keep me too');

    app(PanelBackup::class)->run();

    $archives = collect(File::files(backupDirectory()))->map(fn($file): string => $file->getFilename())->sort()->values();

    expect($archives->filter(fn(string $name): bool => str_starts_with($name, 'coresmith-'))->values()->all())->toBe([
        ...array_map(fn(int $day): string => sprintf('coresmith-202608%02d-040000.tar.gz', $day), range(3, 15)),
        'coresmith-20260913-140509.tar.gz',
    ])->and($archives->all())->toContain('notes.txt')
        ->and($archives->all())->toContain('manual-export.tar.gz');
});

test('the newest backup is reported with its size and a verified archive', function () {
    backupUseProbeDatabase($this->backupRoot);

    expect(app(PanelBackup::class)->latest())->toBeNull();

    $backup = app(PanelBackup::class)->run();
    $latest = app(PanelBackup::class)->latest();

    expect($latest['bytes'])->toBe($backup['bytes'])
        ->and($latest['verified'])->toBeTrue()
        ->and($latest['at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($latest['at']->getTimestamp())->toBe(filemtime($backup['path']));
});

test('a corrupt newest archive is reported as unverified', function () {
    File::ensureDirectoryExists(backupDirectory());
    file_put_contents(backupDirectory() . '/coresmith-20260913-040000.tar.gz', 'truncated');

    expect(app(PanelBackup::class)->latest()['verified'])->toBeFalse()
        ->and(app(PanelBackup::class)->verify(backupDirectory() . '/coresmith-20260913-040000.tar.gz'))->toBeFalse()
        ->and(app(PanelBackup::class)->verify(backupDirectory() . '/missing.tar.gz'))->toBeFalse();
});

test('a postgres panel is dumped with pg_dump and its password never appears in the command line', function () {
    backupUseExternalDatabase('pgsql');
    backupFakeDump('--file=');

    $backup = app(PanelBackup::class)->run();
    $work = backupDirectory() . '/coresmith-20260913-140509';

    Process::assertRan(fn(PendingProcess $process): bool => $process->command === [
        'pg_dump',
        '--no-owner',
        '--clean',
        '--if-exists',
        '--file=' . $work . '/database.sql',
        '--host=db.internal',
        '--port=5432',
        '--username=coresmith',
        'coresmith_panel',
    ] && $process->environment === ['PGPASSWORD' => 's3cret-pass']
        && !str_contains(implode(' ', $process->command), 's3cret-pass'));

    expect(backupEntries($backup['path']))->toContain('database.sql');
});

test('a mysql panel is dumped in a single transaction with its password in the environment', function () {
    backupUseExternalDatabase('mysql');
    backupFakeDump('--result-file=');

    $backup = app(PanelBackup::class)->run();
    $work = backupDirectory() . '/coresmith-20260913-140509';

    Process::assertRan(fn(PendingProcess $process): bool => $process->command === [
        'mysqldump',
        '--single-transaction',
        '--result-file=' . $work . '/database.sql',
        '--host=db.internal',
        '--port=3306',
        '--user=coresmith',
        'coresmith_panel',
    ] && $process->environment === ['MYSQL_PWD' => 's3cret-pass']);

    expect(backupEntries($backup['path']))->toContain('database.sql');
});

test('a failed dump is reported with the tools error and leaves no working files behind', function () {
    backupUseExternalDatabase('pgsql');
    Process::fake(fn() => Process::result(errorOutput: "pg_dump: error: connection refused\n", exitCode: 1));

    expect(fn() => app(PanelBackup::class)->run())->toThrow(RuntimeException::class, 'The database dump failed: pg_dump: error: connection refused');

    expect(File::directories(backupDirectory()))->toBe([])
        ->and(File::files(backupDirectory()))->toBe([]);
});

test('a panel on an unsupported database refuses to back up', function () {
    backupUseExternalDatabase('sqlsrv');

    expect(fn() => app(PanelBackup::class)->run())->toThrow(RuntimeException::class, 'Backups support Coresmith on SQLite, PostgreSQL and MySQL.');

    expect(File::directories(backupDirectory()))->toBe([]);
});

test('the backup command archives the panel and records the size', function () {
    backupUseProbeDatabase($this->backupRoot);
    $expected = backupDirectory() . '/coresmith-20260913-140509.tar.gz';

    $this->artisan('coresmith:backup')
        ->expectsOutputToContain('Backed up to ' . $expected)
        ->assertSuccessful();

    $activity = Activity::query()->where('description', 'panel_backed_up')->sole();

    expect($activity->properties['bytes'])->toBe(filesize($expected));
});

test('a backup that cannot dump the database is recorded as failed and exits non zero', function () {
    backupUseProbeDatabase($this->backupRoot);
    $work = backupDirectory() . '/coresmith-20260913-140509';
    File::ensureDirectoryExists($work);
    file_put_contents($work . '/database.sqlite', 'left over from an interrupted backup');

    $this->artisan('coresmith:backup')->assertFailed();

    $activity = Activity::query()->where('description', 'panel_backup_failed')->sole();

    expect($activity->properties['error'])->toContain('file is not a database')
        ->and(mb_strlen($activity->properties['error']))->toBeLessThanOrEqual(300)
        ->and(Activity::query()->where('description', 'panel_backed_up')->exists())->toBeFalse()
        ->and(is_dir($work))->toBeFalse()
        ->and(glob(backupDirectory() . '/*.tar.gz'))->toBe([]);
});

test('the queued backup job runs the backup command', function () {
    backupUseProbeDatabase($this->backupRoot);

    (new BackUpPanel)->handle();

    expect(is_file(backupDirectory() . '/coresmith-20260913-140509.tar.gz'))->toBeTrue()
        ->and(Activity::query()->where('description', 'panel_backed_up')->exists())->toBeTrue();
});

test('only one backup job can be queued at a time', function () {
    $job = new BackUpPanel;

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueFor)->toBe(900)
        ->and($job->timeout)->toBe(900)
        ->and($job->tries)->toBe(1)
        ->and($job->queue)->toBe('default');
});

test('the panel is backed up every night at four', function () {
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'coresmith:backup');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

function backupRestoreArgument(PendingProcess $process, string $prefix): ?string
{
    return collect($process->command)->first(fn(string $argument): bool => str_starts_with($argument, $prefix));
}

function backupExternalArchive(string $driver, string $flag): string
{
    backupUseExternalDatabase($driver);
    backupFakeDump($flag);

    return app(PanelBackup::class)->run()['path'];
}

test('a sqlite backup restores the database as it was', function () {
    backupUseProbeDatabase($this->backupRoot);
    $backup = app(PanelBackup::class)->run();
    DB::connection('backup_probe')->table('probe')->insert(['note' => 'after']);
    $live = $this->backupRoot . '/panel.sqlite';

    foreach (['-wal', '-shm', '-journal'] as $suffix) {
        file_put_contents($live . $suffix, 'stale');
    }

    app(PanelBackup::class)->restore($backup['path']);

    expect(file_exists($live . '-wal'))->toBeFalse()
        ->and(file_exists($live . '-shm'))->toBeFalse()
        ->and(file_exists($live . '-journal'))->toBeFalse();

    $notes = (new PDO('sqlite:' . $live))->query('select note from probe')->fetchAll(PDO::FETCH_COLUMN);

    expect($notes)->toBe(['still here'])
        ->and(fileperms($live) & 0777)->toBe(0600)
        ->and(glob($this->backupRoot . '/.restore-*'))->toBe([])
        ->and(DB::connection('backup_probe')->table('probe')->pluck('note')->all())->toBe(['still here']);
});

test('a postgres backup is restored in one transaction, over whatever the migrations made, with the password in the environment', function () {
    $archive = backupExternalArchive('pgsql', '--file=');
    Process::fake();

    app(PanelBackup::class)->restore($archive);

    Process::assertRan(function (PendingProcess $process): bool {
        $file = (string) backupRestoreArgument($process, '--file=');

        return $process->command === [
            'psql',
            '--quiet',
            '--single-transaction',
            '--set=ON_ERROR_STOP=1',
            '--command=DROP OWNED BY CURRENT_USER',
            $file,
            '--host=db.internal',
            '--port=5432',
            '--username=coresmith',
            'coresmith_panel',
        ] && preg_match('#^--file=' . preg_quote(storage_path('app/private'), '#') . '/restore-[A-Za-z0-9]+/database\.sql$#', $file) === 1
            && $process->environment === ['PGPASSWORD' => 's3cret-pass']
            && $process->timeout === 1800
            && !str_contains(implode(' ', $process->command), 's3cret-pass');
    });

    expect(glob(storage_path('app/private/restore-*')))->toBe([]);
});

test('a mysql backup recreates the database, then loads the dump through standard input, with the password in the environment', function () {
    $archive = backupExternalArchive('mysql', '--result-file=');
    $loaded = [];
    Process::fake(function (PendingProcess $process) use (&$loaded) {
        $loaded[] = is_resource($process->input) ? stream_get_contents($process->input) : $process->input;

        return Process::result();
    });

    app(PanelBackup::class)->restore($archive);

    Process::assertRanTimes(fn(PendingProcess $process): bool => $process->command[0] === 'mysql', 2);
    Process::assertRan(fn(PendingProcess $process): bool => $process->command === [
        'mysql',
        '--host=db.internal',
        '--port=3306',
        '--user=coresmith',
        '--execute=DROP DATABASE IF EXISTS `coresmith_panel`; CREATE DATABASE `coresmith_panel` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    ] && $process->environment === ['MYSQL_PWD' => 's3cret-pass'] && $process->timeout === 1800);
    Process::assertRan(fn(PendingProcess $process): bool => $process->command === ['mysql', '--host=db.internal', '--port=3306', '--user=coresmith', 'coresmith_panel']
        && $process->environment === ['MYSQL_PWD' => 's3cret-pass']
        && $process->timeout === 1800);

    Process::assertNotRan(fn(PendingProcess $process): bool => str_contains(implode(' ', $process->command), 'source '));

    expect($loaded[1])->toBe('-- dump')
        ->and(glob(storage_path('app/private/restore-*')))->toBe([]);
});

test('a mysql database that cannot be recreated is not loaded into', function () {
    $archive = backupExternalArchive('mysql', '--result-file=');
    Process::fake(fn() => Process::result(errorOutput: "ERROR 1044 (42000): Access denied for user 'coresmith'\n", exitCode: 1));

    expect(fn() => app(PanelBackup::class)->restore($archive))->toThrow(RuntimeException::class, "Restoring the database failed: ERROR 1044 (42000): Access denied for user 'coresmith'");

    Process::assertRanTimes(fn(PendingProcess $process): bool => $process->command[0] === 'mysql', 1);
});

test('an empty mysql dump is refused before the database is dropped', function () {
    backupUseExternalDatabase('mysql');
    Process::fake(function (PendingProcess $process) {
        $target = backupRestoreArgument($process, '--result-file=');

        if ($target !== null) {
            file_put_contents(substr($target, strlen('--result-file=')), '');
        }

        return Process::result();
    });
    $archive = app(PanelBackup::class)->run()['path'];

    expect(fn() => app(PanelBackup::class)->restore($archive))->toThrow(RuntimeException::class, 'is empty or can\'t be read, so nothing was restored.');

    Process::assertNotRan(fn(PendingProcess $process): bool => $process->command[0] === 'mysql');
});

test('a restore that fails says so and leaves nothing behind', function () {
    $archive = backupExternalArchive('pgsql', '--file=');
    Process::fake(fn() => Process::result(errorOutput: "ERROR: relation \"users\" does not exist\n", exitCode: 3));

    expect(fn() => app(PanelBackup::class)->restore($archive))->toThrow(RuntimeException::class, 'Restoring the database failed: ERROR: relation "users" does not exist')
        ->and(glob(storage_path('app/private/restore-*')))->toBe([]);
});

test('an unreadable archive is not restored', function () {
    backupUseProbeDatabase($this->backupRoot);
    File::ensureDirectoryExists(backupDirectory());
    $truncated = backupDirectory() . '/coresmith-20260913-040000.tar.gz';
    file_put_contents($truncated, 'truncated');

    expect(fn() => app(PanelBackup::class)->restore($truncated))->toThrow(RuntimeException::class, "The backup at {$truncated} can't be read, so it can't be restored.")
        ->and(fn() => app(PanelBackup::class)->restore(backupDirectory() . '/missing.tar.gz'))->toThrow(RuntimeException::class, "can't be read, so it can't be restored.")
        ->and(DB::connection('backup_probe')->table('probe')->pluck('note')->all())->toBe(['still here']);
});

test('an archive without this panel\'s database in it is not restored', function () {
    $archive = backupExternalArchive('pgsql', '--file=');
    backupUseProbeDatabase($this->backupRoot);

    expect(fn() => app(PanelBackup::class)->restore($archive))->toThrow(RuntimeException::class, "The backup at {$archive} has no database in it.")
        ->and(DB::connection('backup_probe')->table('probe')->pluck('note')->all())->toBe(['still here']);
});

test('a database name is quoted for mysql however it is spelled', function () {
    $archive = backupExternalArchive('mysql', '--result-file=');
    config(['database.connections.backup_external.database' => 'odd`name']);
    DB::purge('backup_external');
    Process::fake();

    app(PanelBackup::class)->restore($archive);

    Process::assertRan(fn(PendingProcess $process): bool => str_starts_with((string) backupRestoreArgument($process, '--execute='), '--execute=DROP DATABASE IF EXISTS `odd``name`; CREATE DATABASE `odd``name`'));
});
