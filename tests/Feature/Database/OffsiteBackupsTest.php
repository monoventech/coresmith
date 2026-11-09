<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Exceptions\OffsiteUploadFailed;
use App\Jobs\BackupDatabase;
use App\Jobs\SendBackupOffsite;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Services\Database\OffsiteBackups;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CloudBilling;
use Tests\Support\FakeSSHTransport;

function offsite(): OffsiteBackups
{
    return app(OffsiteBackups::class);
}

function offsiteDestination(Team $team, array $attributes = []): BackupDestination
{
    return BackupDestination::factory()->for($team)->create([
        'region' => 'eu-west-1',
        'bucket' => 'my-bucket',
        'prefix' => 'backups',
        'access_key_id' => 'AKIAEXAMPLE0000000000',
        'secret_access_key' => 'an-example-secret-access-key',
        ...$attributes,
    ]);
}

function offsiteDatabase(?Team $team = null, array $server = [], array $attributes = []): ManagedDatabase
{
    $team ??= Team::factory()->create(['slug' => 'monoven']);
    $host = Server::factory()->for($team)->create(['name' => 'forge-01', 'host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'status' => ServerStatus::Active, ...$server]);

    return ManagedDatabase::factory()->for($team)->for($host)->create(['type' => DatabaseType::Mysql, 'name' => 'app_db', ...$attributes]);
}

function offsiteConnection(ManagedDatabase $database, FakeSSHTransport $transport): SSHConnection
{
    return (new SSHConnection($database->server, $transport))->connect();
}

function offsiteTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('stat -c %s', "1024\n")->respondTo('mktemp', "/tmp/tmp.UpLoAd1234\n");
}

function offsiteRun(object $job, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([$job, 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 02:30:00'));
});

test('a presigned upload for aws names the bucket, the key and the expiry', function () {
    $destination = offsiteDestination(Team::factory()->create());

    $url = parse_url(offsite()->presignedPut($destination, 'backups/monoven/forge-01/app_db/app_db-1.sql.gz'));
    parse_str((string) ($url['query'] ?? ''), $query);

    expect($url['scheme'])->toBe('https')
        ->and($url['host'])->toBe('my-bucket.s3.eu-west-1.amazonaws.com')
        ->and($url['path'])->toBe('/backups/monoven/forge-01/app_db/app_db-1.sql.gz')
        ->and($query['X-Amz-Expires'] ?? null)->toBe('3600')
        ->and($query['X-Amz-Signature'] ?? '')->toMatch('/^[0-9a-f]{64}$/')
        ->and($query['X-Amz-Credential'] ?? '')->toStartWith('AKIAEXAMPLE0000000000/');
});

test('a custom endpoint with path-style addressing puts the bucket in the path', function () {
    $destination = offsiteDestination(Team::factory()->create(), ['endpoint' => 'https://minio.example.com', 'path_style' => true]);

    $url = parse_url(offsite()->presignedPut($destination, 'backups/x.sql.gz'));

    expect($url['host'])->toBe('minio.example.com')
        ->and($url['path'])->toBe('/my-bucket/backups/x.sql.gz');
});

test('a bucket with a dot in its name goes in the path, because its host would fail tls', function (array $attributes, string $host, string $path) {
    $url = parse_url(offsite()->presignedPut(offsiteDestination(Team::factory()->create(), $attributes), 'backups/x.sql.gz'));

    expect($url['host'])->toBe($host)
        ->and($url['path'])->toBe($path);
})->with([
    'on aws' => [['bucket' => 'backups.example.com'], 's3.eu-west-1.amazonaws.com', '/backups.example.com/backups/x.sql.gz'],
    'on another endpoint' => [['bucket' => 'backups.example.com', 'endpoint' => 'https://s3.example.com'], 's3.example.com', '/backups.example.com/backups/x.sql.gz'],
    'without a dot on aws' => [['bucket' => 'my-bucket'], 'my-bucket.s3.eu-west-1.amazonaws.com', '/backups/x.sql.gz'],
    'without a dot on another endpoint' => [['bucket' => 'my-bucket', 'endpoint' => 'https://s3.example.com'], 'my-bucket.s3.example.com', '/backups/x.sql.gz'],
]);

test('object keys keep only safe characters', function () {
    $database = offsiteDatabase(server: ['name' => 'forge 01/../x']);

    expect(offsite()->objectKey(offsiteDestination($database->team, ['prefix' => '/nightly/']), $database, '/var/coresmith/backups/app_db/app_db-20261018023000.sql.gz'))
        ->toBe('nightly/monoven/forge-01-..-x/app_db/app_db-20261018023000.sql.gz')
        ->and(offsite()->objectKey(offsiteDestination(Team::factory()->create(), ['prefix' => null]), $database, '/var/coresmith/backups/app_db/app_db-1.sql.gz'))
        ->toBe('monoven/forge-01-..-x/app_db/app_db-1.sql.gz');
});

test('the url travels in a file, never in a command', function () {
    $database = offsiteDatabase();
    $transport = offsiteTransport();

    $key = offsite()->send(offsiteConnection($database, $transport), $database, '/var/coresmith/backups/app_db/app_db-20261018023000.sql.gz', offsiteDestination($database->team));

    $commands = $transport->commands();
    $curl = collect($commands)->first(fn(string $command): bool => str_contains($command, 'curl '));

    expect($key)->toBe('backups/monoven/forge-01/app_db/app_db-20261018023000.sql.gz')
        ->and(implode("\n", $commands))->not->toContain('X-Amz-Signature')
        ->and(array_keys($transport->uploads()))->toBe(['/tmp/tmp.UpLoAd1234'])
        ->and($transport->uploads()['/tmp/tmp.UpLoAd1234'])->toStartWith('url = "https://my-bucket.s3.eu-west-1.amazonaws.com/backups/monoven/forge-01/app_db/')
        ->and($curl)->toContain('--max-time 1140')
        ->and($curl)->toContain("--config '/tmp/tmp.UpLoAd1234'")
        ->and($curl)->toContain("--upload-file '/var/coresmith/backups/app_db/app_db-20261018023000.sql.gz'")
        ->and(end($commands))->toContain("rm -f '/tmp/tmp.UpLoAd1234'");
});

test('the config file goes even when the upload fails', function () {
    $database = offsiteDatabase();
    $transport = offsiteTransport()->respondTo('curl ', "\ncurl: (22) The requested URL returned error: 403\n", 22);

    expect(fn() => offsite()->send(offsiteConnection($database, $transport), $database, '/var/coresmith/backups/app_db/app_db-1.sql.gz', offsiteDestination($database->team)))
        ->toThrow(OffsiteUploadFailed::class, 'The bucket refused the upload: curl: (22) The requested URL returned error: 403. Check the bucket name, region and keys.');

    $commands = $transport->commands();

    expect(end($commands))->toContain("rm -f '/tmp/tmp.UpLoAd1234'");
});

test('a dump over five gigabytes stays on the server', function () {
    $database = offsiteDatabase();
    $transport = offsiteTransport()->respondTo('stat -c %s', "6442450944\n");

    expect(fn() => offsite()->send(offsiteConnection($database, $transport), $database, '/var/coresmith/backups/app_db/app_db-1.sql.gz', offsiteDestination($database->team)))
        ->toThrow(OffsiteUploadFailed::class, 'The dump is 6 GB, over the 5 GB a single upload can carry, so it stayed on the server.');

    expect(implode("\n", $transport->commands()))->not->toContain('curl');
});

test('an offsite backup is handed to its own job', function () {
    Queue::fake([SendBackupOffsite::class]);
    $mysql = offsiteDatabase();
    $redis = offsiteDatabase($mysql->team, server: ['name' => 'forge-02'], attributes: ['type' => DatabaseType::Redis, 'port' => 6379, 'name' => 'cache']);

    offsiteRun(new BackupDatabase($mysql, '20261018023000', offsite: true), new FakeSSHTransport);

    Queue::assertPushed(SendBackupOffsite::class, fn(SendBackupOffsite $job): bool => $job->database->is($mysql)
        && $job->dumpPath === '/var/coresmith/backups/app_db/app_db-20261018023000.sql.gz');

    offsiteRun(new BackupDatabase($redis, '20261018023000', offsite: true), new FakeSSHTransport);
    offsiteRun(new BackupDatabase($mysql, '20261018023001'), new FakeSSHTransport);

    Queue::assertPushed(SendBackupOffsite::class, 1);
});

test('the upload job records where the backup went and keeps the newest three dumps', function () {
    $database = offsiteDatabase();
    offsiteDestination($database->team);
    $transport = offsiteTransport();

    offsiteRun(new SendBackupOffsite($database, '/var/coresmith/backups/app_db/app_db-20261018023000.sql.gz'), $transport);

    $database->refresh();
    $prune = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'tail -n +4'));

    expect($database->last_offsite_key)->toBe('backups/monoven/forge-01/app_db/app_db-20261018023000.sql.gz')
        ->and($database->last_offsite_at?->toDateTimeString())->toBe('2026-10-18 02:30:00')
        ->and($database->last_offsite_error)->toBeNull()
        ->and($prune)->toContain("'/var/coresmith/backups/app_db'")
        ->and($prune)->toContain("grep -E '/app_db-[0-9]{14}\\.sql\\.gz$'")
        ->and($prune)->toContain('rm -f');
});

test('a refused upload is recorded and keeps every dump', function () {
    $database = offsiteDatabase(attributes: ['last_offsite_at' => now()->subDay(), 'last_offsite_key' => 'backups/old.sql.gz']);
    offsiteDestination($database->team);
    $transport = offsiteTransport()->respondTo('curl ', "curl: (22) The requested URL returned error: 403\n", 22);

    offsiteRun(new SendBackupOffsite($database, '/var/coresmith/backups/app_db/app_db-1.sql.gz'), $transport);

    $database->refresh();

    expect($database->last_offsite_error)->toBe('The bucket refused the upload: curl: (22) The requested URL returned error: 403. Check the bucket name, region and keys.')
        ->and($database->last_offsite_key)->toBe('backups/old.sql.gz')
        ->and(implode("\n", $transport->commands()))->not->toContain('tail -n +4');
});

test('a failed upload job says what to check without guessing the cause', function () {
    $database = offsiteDatabase(server: ['name' => 'forge-01']);

    (new SendBackupOffsite($database, '/var/coresmith/backups/app_db/app_db-1.sql.gz'))->failed(new RuntimeException('Connection refused'));

    expect($database->fresh()->last_offsite_error)->toBe('The upload failed. Check that forge-01 is online and the S3 settings are right, then back up again.');
});

test('a dump that is gone is named and nothing is sent', function () {
    $database = offsiteDatabase(server: ['name' => 'forge-01']);
    offsiteDestination($database->team);
    $transport = (new FakeSSHTransport)->respondTo('stat -c %s', "stat: cannot statx: No such file or directory\n", 1);

    offsiteRun(new SendBackupOffsite($database, '/var/coresmith/backups/app_db/app_db-20261018023000.sql.gz'), $transport);

    expect($database->fresh()->last_offsite_error)->toBe('Could not find the dump app_db-20261018023000.sql.gz on forge-01, so nothing was sent. Back up again.')
        ->and($database->fresh()->last_offsite_at)->toBeNull()
        ->and(collect($transport->commands())->filter(fn(string $command): bool => str_contains($command, 'curl')))->toBeEmpty();
});

test('settings that cannot build an upload address say so before touching the server', function () {
    $database = offsiteDatabase();
    offsiteDestination($database->team, ['endpoint' => 'not an address']);
    $transport = offsiteTransport();

    offsiteRun(new SendBackupOffsite($database, '/var/coresmith/backups/app_db/app_db-20261018023000.sql.gz'), $transport);

    expect($database->fresh()->last_offsite_error)->toBe('Could not build the upload address. Check the bucket, region and endpoint in the S3 settings.')
        ->and($database->fresh()->last_offsite_at)->toBeNull()
        ->and(collect($transport->commands())->filter(fn(string $command): bool => str_contains($command, 'curl') || str_contains($command, 'mktemp')))->toBeEmpty();
});

test('a prune that fails after a good upload leaves the backup recorded as sent', function () {
    $database = offsiteDatabase();
    offsiteDestination($database->team);
    $transport = offsiteTransport()->timeOutOn('tail -n +4');

    offsiteRun(new SendBackupOffsite($database, '/var/coresmith/backups/app_db/app_db-20261018023000.sql.gz'), $transport);

    expect($database->fresh()->last_offsite_key)->toBe('backups/monoven/forge-01/app_db/app_db-20261018023000.sql.gz')
        ->and($database->fresh()->last_offsite_error)->toBeNull();
});

test('the longest key the panel can build survives the save after a good upload', function () {
    $database = offsiteDatabase(server: ['name' => str_repeat('s', 255)]);
    offsiteDestination($database->team, ['prefix' => str_repeat('p', 200)]);

    offsiteRun(new SendBackupOffsite($database, '/var/coresmith/backups/app_db/app_db-20261018023000.sql.gz'), offsiteTransport());

    $key = (string) $database->fresh()->last_offsite_key;

    expect(strlen($key))->toBeGreaterThan(255)
        ->and($key)->toEndWith('/app_db/app_db-20261018023000.sql.gz')
        ->and(Schema::getColumnType('databases', 'last_offsite_key'))->toBe('text');
});

test('widening the key keeps the rows, the indexes and the foreign keys', function () {
    $migration = require database_path('migrations/2026_11_04_162049_fit_long_offsite_keys.php');
    $database = offsiteDatabase(attributes: ['last_offsite_key' => 'backups/old.sql.gz', 'last_offsite_error' => 'Refused.']);
    $server = $database->server;
    $team = $database->team;
    $owner = $team->owner;
    $shape = fn(): array => [
        collect(Schema::getIndexes('databases'))->pluck('name')->sort()->values()->all(),
        collect(Schema::getForeignKeys('databases'))->pluck('columns')->sortBy(fn(array $columns): string => $columns[0])->values()->all(),
    ];
    $before = $shape();

    $migration->down();
    $narrow = Schema::getColumnType('databases', 'last_offsite_key');
    $migration->up();

    $wide = Schema::getColumnType('databases', 'last_offsite_key');
    $kept = ManagedDatabase::query()->find($database->id);
    $after = $shape();

    $kept?->forceDelete();
    $server->forceDelete();
    $team->forceDelete();
    $owner?->forceDelete();

    expect($narrow)->not->toBe('text')
        ->and($wide)->toBe('text')
        ->and($kept?->last_offsite_key)->toBe('backups/old.sql.gz')
        ->and($kept?->last_offsite_error)->toBe('Refused.')
        ->and($after)->toBe($before);
});

test('pruning keeps the newest three dumps of this database and touches nothing else', function () {
    $directory = sys_get_temp_dir() . '/coresmith prune ' . Str::random(12);
    $ours = ['app_db-20261014023000.sql.gz', 'app_db-20261015023000.sql.gz', 'app_db-20261016023000.sql.gz', 'app_db-20261017023000.sql.gz', 'app_db-20261018023000.sql.gz'];
    $others = ['app_db-20261018023000.sql', 'app_db-notes.sql.gz', 'app_db_two-20261010023000.sql.gz', 'my_app_db-20261010023000.sql.gz', 'other-20261010023000.sql.gz', 'other-20261011023000.sql.gz', 'other-20261012023000.sql.gz', 'other-20261013023000.sql.gz'];
    File::ensureDirectoryExists($directory);

    try {
        foreach ([...$ours, ...$others] as $name) {
            touch("{$directory}/{$name}");
        }

        $database = offsiteDatabase(server: ['ssh_user' => 'root']);
        $transport = new FakeSSHTransport;
        offsite()->pruneDumps(offsiteConnection($database, $transport), $database, "{$directory}/app_db-20261018023000.sql.gz");
        $command = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'tail -n +4'));

        expect(Process::run(['bash', '-c', $command])->successful())->toBeTrue();

        $left = collect(File::files($directory))->map(fn(SplFileInfo $file): string => $file->getFilename())->sort()->values()->all();

        expect($left)->toBe(collect([...array_slice($ours, 2), ...$others])->sort()->values()->all());
    } finally {
        File::deleteDirectory($directory);
    }
});

test('a team without a destination sends nothing', function () {
    $database = offsiteDatabase();
    $transport = new FakeSSHTransport;

    offsiteRun(new SendBackupOffsite($database, '/var/coresmith/backups/app_db/app_db-1.sql.gz'), $transport);

    expect($transport->commands())->toBe([])
        ->and($database->fresh()->last_offsite_at)->toBeNull();
});

test('the upload job fits its supervisor', function () {
    $job = new SendBackupOffsite(offsiteDatabase(), '/var/coresmith/backups/app_db/app_db-1.sql.gz');

    expect($job->queue)->toBe('server-tasks')
        ->and($job->tries)->toBe(2)
        ->and($job->timeout)->toBe(1260)
        ->and(OffsiteBackups::UPLOAD_SECONDS + 60)->toBeLessThanOrEqual($job->timeout);
});

test('on cloud the nightly run covers business teams only', function () {
    CloudBilling::enable();
    Queue::fake();
    $business = Team::factory()->subscribed(Plan::Business)->owned()->create(['slug' => 'atelier']);
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create(['slug' => 'pro']);
    $mysql = offsiteDatabase($business);
    $postgres = offsiteDatabase($business, attributes: ['type' => DatabaseType::Postgresql, 'name' => 'reports']);
    $redis = offsiteDatabase($business, attributes: ['type' => DatabaseType::Redis, 'port' => 6379, 'name' => 'cache']);
    $stopped = offsiteDatabase($business, server: ['name' => 'forge-02', 'status' => ServerStatus::Disconnected], attributes: ['name' => 'archive']);
    offsiteDatabase($pro, attributes: ['name' => 'pro_db']);
    $businessDestination = offsiteDestination($business);
    $proDestination = offsiteDestination($pro);

    $this->artisan('coresmith:backup-databases')->assertSuccessful();

    $pushed = collect(Queue::pushed(BackupDatabase::class))->map(fn(BackupDatabase $job) => $job->database->getKey())->sort()->values()->all();

    expect($pushed)->toBe(collect([$mysql->getKey(), $postgres->getKey()])->sort()->values()->all())
        ->and($businessDestination->fresh()->last_run_at?->toDateTimeString())->toBe('2026-10-18 02:30:00')
        ->and($proDestination->fresh()->last_run_at)->toBeNull()
        ->and($redis->exists)->toBeTrue()
        ->and($stopped->exists)->toBeTrue();

    Queue::assertPushed(BackupDatabase::class, fn(BackupDatabase $job): bool => $job->offsite && $job->timestamp === '20261018023000');
});

test('self-hosted teams with a destination are backed up', function () {
    Queue::fake();
    $team = Team::factory()->owned()->create();
    $database = offsiteDatabase($team);
    offsiteDestination($team);

    $this->artisan('coresmith:backup-databases')->assertSuccessful();

    Queue::assertPushed(BackupDatabase::class, fn(BackupDatabase $job): bool => $job->database->is($database) && $job->offsite);
});

test('the nightly run is scheduled', function () {
    $event = collect(app(Schedule::class)->events())->first(fn(Event $event): bool => $event->description === 'coresmith:backup-databases');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
