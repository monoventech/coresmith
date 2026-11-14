<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\ServerStatus;
use App\Models\BackupDestination;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Services\Database\DatabaseBackups;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSSHTransport;

function databaseBackupsDatabase(bool $s3 = true, array $attributes = []): ManagedDatabase
{
    $team = Team::factory()->create(['slug' => 'monoven']);
    $server = Server::factory()->for($team)->create(['name' => 'anvil-01', 'host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'status' => ServerStatus::Active, 'last_connected_at' => now()]);

    if ($s3) {
        BackupDestination::factory()->for($team)->create([
            'region' => 'eu-west-1',
            'bucket' => 'my-bucket',
            'prefix' => 'backups',
            'access_key_id' => 'AKIAEXAMPLE0000000000',
            'secret_access_key' => 'an-example-secret-access-key',
        ]);
    }

    return ManagedDatabase::factory()->for($team)->for($server)->create(['type' => DatabaseType::Mysql, 'name' => 'shop', ...$attributes]);
}

function databaseBackupsOnServer(FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
}

/**
 * @param  list<array{0: string, 1: int}>  $objects
 */
function databaseBackupsPage(array $objects, ?string $next = null): string
{
    $contents = implode('', array_map(fn(array $object): string => "<Contents><Key>{$object[0]}</Key><Size>{$object[1]}</Size></Contents>", $objects));
    $truncated = $next === null ? 'false' : 'true';
    $token = $next === null ? '' : "<NextContinuationToken>{$next}</NextContinuationToken>";

    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<ListBucketResult xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\"><Name>my-bucket</Name><IsTruncated>{$truncated}</IsTruncated>{$token}{$contents}</ListBucketResult>";
}

beforeEach(function () {
    config(['app.timezone' => 'UTC']);
});

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

test("the server folder lists this database's dumps, newest first, and nothing else", function () {
    $database = databaseBackupsDatabase(s3: false);
    databaseBackupsOnServer((new FakeSSHTransport)->respondTo('find ', "shop-20261110023000.sql.gz\t1048576\nshop-20261111020000.sql.gz\t2048\nshop_old-20261109000000.sql.gz\t10\nshop-20261109000000.sql.gz.part\t3\nnotes.txt\t4\n"));

    $listing = app(DatabaseBackups::class)->list($database);

    expect(array_map(fn($backup): array => [$backup->stamp, $backup->size, $backup->path, $backup->key], $listing->backups))->toBe([
        ['20261111020000', 2048, '/var/coresmith/backups/shop/shop-20261111020000.sql.gz', null],
        ['20261110023000', 1048576, '/var/coresmith/backups/shop/shop-20261110023000.sql.gz', null],
    ])
        ->and($listing->backups[0]->at->equalTo(CarbonImmutable::parse('2026-11-11 02:00:00', 'UTC')))->toBeTrue()
        ->and($listing->backups[0]->onServer())->toBeTrue()
        ->and($listing->backups[0]->inS3())->toBeFalse()
        ->and($listing->serverError)->toBeNull()
        ->and($listing->s3Error)->toBeNull();
});

test('the server is asked only to read its own backup folder', function () {
    $database = databaseBackupsDatabase(s3: false);
    $transport = new FakeSSHTransport;
    databaseBackupsOnServer($transport);

    app(DatabaseBackups::class)->list($database);

    expect($transport->commands())->toBe(["find '/var/coresmith/backups/shop' -maxdepth 1 -type f -name 'shop-*.sql.gz' -printf '%f\\t%s\\n' 2>/dev/null || true"]);
});

test('copies in S3 are listed from a presigned listing, page by page', function () {
    $database = databaseBackupsDatabase();
    databaseBackupsOnServer(new FakeSSHTransport);
    Http::fake(['my-bucket.s3.eu-west-1.amazonaws.com/*' => Http::sequence()
        ->push(databaseBackupsPage([['backups/monoven/anvil-01/shop/shop-20261109023000.sql.gz', 100], ['backups/monoven/anvil-01/shop/notes.txt', 5]], 'page-two'))
        ->push(databaseBackupsPage([['backups/monoven/anvil-01/shop/shop-20261110023000.sql.gz', 200]]))]);

    $listing = app(DatabaseBackups::class)->list($database);

    expect(array_map(fn($backup): array => [$backup->stamp, $backup->size, $backup->path, $backup->key], $listing->backups))->toBe([
        ['20261110023000', 200, null, 'backups/monoven/anvil-01/shop/shop-20261110023000.sql.gz'],
        ['20261109023000', 100, null, 'backups/monoven/anvil-01/shop/shop-20261109023000.sql.gz'],
    ])
        ->and($listing->s3Error)->toBeNull();

    $requests = Http::recorded()->map(fn(array $pair): Request => $pair[0])->values();

    expect($requests)->toHaveCount(2)
        ->and($requests[0]->url())->toContain('list-type=2')
        ->and($requests[0]->url())->toContain('prefix=backups%2Fmonoven%2Fanvil-01%2Fshop%2F')
        ->and($requests[0]->url())->toContain('X-Amz-Signature=')
        ->and($requests[1]->url())->toContain('continuation-token=page-two');
});

test('a backup in both places shows once with both', function () {
    $database = databaseBackupsDatabase();
    databaseBackupsOnServer((new FakeSSHTransport)->respondTo('find ', "shop-20261110023000.sql.gz\t200\n"));
    Http::fake(['my-bucket.s3.eu-west-1.amazonaws.com/*' => Http::response(databaseBackupsPage([['backups/monoven/anvil-01/shop/shop-20261110023000.sql.gz', 200]]))]);

    $listing = app(DatabaseBackups::class)->list($database);

    expect($listing->backups)->toHaveCount(1)
        ->and($listing->backups[0]->path)->toBe('/var/coresmith/backups/shop/shop-20261110023000.sql.gz')
        ->and($listing->backups[0]->key)->toBe('backups/monoven/anvil-01/shop/shop-20261110023000.sql.gz')
        ->and($listing->backups[0]->onServer())->toBeTrue()
        ->and($listing->backups[0]->inS3())->toBeTrue();
});

test('an unreachable server says so and still lists S3', function () {
    $database = databaseBackupsDatabase();
    databaseBackupsOnServer((new FakeSSHTransport)->refuseConnections());
    Http::fake(['my-bucket.s3.eu-west-1.amazonaws.com/*' => Http::response(databaseBackupsPage([['backups/monoven/anvil-01/shop/shop-20261110023000.sql.gz', 200]]))]);

    $listing = app(DatabaseBackups::class)->list($database);

    expect($listing->serverError)->toBe("Coresmith couldn't reach anvil-01 to list its backups.")
        ->and($listing->backups)->toHaveCount(1)
        ->and($listing->backups[0]->inS3())->toBeTrue();
});

test('a failed S3 listing says so instead of listing nothing', function () {
    $database = databaseBackupsDatabase();
    databaseBackupsOnServer((new FakeSSHTransport)->respondTo('find ', "shop-20261110023000.sql.gz\t200\n"));
    Http::fake(['my-bucket.s3.eu-west-1.amazonaws.com/*' => Http::response('<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', 403)]);

    $listing = app(DatabaseBackups::class)->list($database);

    expect($listing->s3Error)->toBe("Coresmith couldn't list the copies in S3. S3 said: Access Denied. Check the bucket name, region and keys in Settings, Team.")
        ->and($listing->backups)->toHaveCount(1)
        ->and($listing->backups[0]->onServer())->toBeTrue();
});

test('a team without S3 is never asked about it', function () {
    $database = databaseBackupsDatabase(s3: false);
    databaseBackupsOnServer(new FakeSSHTransport);
    Http::fake();

    $listing = app(DatabaseBackups::class)->list($database);

    expect($listing->s3Error)->toBeNull();
    Http::assertNothingSent();
});

test('a backup is found by its time, wherever it is', function () {
    $database = databaseBackupsDatabase(s3: false);
    databaseBackupsOnServer((new FakeSSHTransport)->respondTo('find ', "shop-20261110023000.sql.gz\t200\n"));

    expect(app(DatabaseBackups::class)->find($database, '20261110023000')?->size)->toBe(200)
        ->and(app(DatabaseBackups::class)->find($database, '20261109000000'))->toBeNull();
});
