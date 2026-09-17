<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\PrivilegeLevel;
use App\Exceptions\BackupNotFoundException;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\Database\DatabaseManager;
use App\Services\Database\MysqlDriver;
use App\Services\Database\PostgresDriver;
use App\Services\Database\SqliteDriver;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeSSHTransport;

function databaseManager(): DatabaseManager
{
    return new DatabaseManager(new MysqlDriver, new PostgresDriver);
}

function databaseConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);

    return (new SSHConnection($server, $transport))->connect();
}

test('a database name carrying sql metacharacters is refused', function (string $name) {
    (new MysqlDriver)->createDatabase($name);
})->with([
    'app`; DROP DATABASE mysql; --',
    "app'; DROP DATABASE mysql; --",
    'app` UNION SELECT',
    'app; rm -rf /',
    'app-with-hyphen',
    'app name',
    '',
])->throws(UnsafeNameException::class);

test('a database user carrying sql metacharacters is refused', function () {
    (new MysqlDriver)->createUser("evil'@'%' IDENTIFIED BY 'x'; GRANT ALL ON *.* TO 'evil", 'secret');
})->throws(UnsafeNameException::class);

test('a postgres identifier carrying a double quote is refused', function () {
    (new PostgresDriver)->createDatabase('app" ; DROP DATABASE postgres; --', 'owner');
})->throws(UnsafeNameException::class);

test('a password containing a quote cannot break out of the sql literal', function () {
    $sql = (new MysqlDriver)->createUser('app_user', "pa'ss\\word");

    expect($sql)->toContain("pa\\'ss")->and($sql)->not->toContain("BY 'pa'ss");
});

test('a postgres password quote is doubled rather than escaped', function () {
    expect((new PostgresDriver)->createUser('app_user', "pa'ss"))->toContain("'pa''ss'");
});

test('standard grants exclude every administrative privilege', function (string $forbidden) {
    $sql = (new MysqlDriver)->grant('app_db', 'app_user', PrivilegeLevel::Standard);

    expect($sql)->not->toContain($forbidden);
})->with(['GRANT OPTION', 'SUPER', 'FILE', 'PROCESS', 'CREATE USER', 'RELOAD', 'SHUTDOWN', 'ALL PRIVILEGES']);

test('grants are scoped to the apps own database only', function () {
    $sql = (new MysqlDriver)->grant('app_db', 'app_user', PrivilegeLevel::Standard);

    expect($sql)->toContain('ON `app_db`.*')
        ->and($sql)->not->toContain('ON *.*')
        ->and($sql)->toContain("TO 'app_user'@'localhost'");
});

test('extended adds view and routine privileges to standard', function () {
    $extended = (new MysqlDriver)->grant('app_db', 'app_user', PrivilegeLevel::Extended);

    expect($extended)->toContain('CREATE VIEW')->and($extended)->toContain('CREATE ROUTINE');
});

test('even full privileges withhold grant option and super', function () {
    $full = (new MysqlDriver)->grant('app_db', 'app_user', PrivilegeLevel::Full);

    expect($full)->not->toContain('GRANT OPTION')->and($full)->not->toContain('SUPER');
});

test('a postgres role cannot create databases, roles or superusers', function () {
    $sql = (new PostgresDriver)->createUser('app_user', 'secret');

    expect($sql)->toContain('NOSUPERUSER')
        ->and($sql)->toContain('NOCREATEDB')
        ->and($sql)->toContain('NOCREATEROLE');
});

test('generated passwords are long', function () {
    expect(strlen(DatabaseManager::generatePassword()))->toBe(32);
});

test('generated passwords differ every time', function () {
    expect(DatabaseManager::generatePassword())->not->toBe(DatabaseManager::generatePassword());
});

test('creating a mysql database makes the db, the user and the grant', function () {
    $transport = new FakeSSHTransport;
    $database = ManagedDatabase::factory()->create([
        'type' => DatabaseType::Mysql,
        'name' => 'app_db',
        'db_user' => 'app_user',
        'privilege_level' => 'standard',
    ]);

    databaseManager()->create(databaseConnection($transport), $database, 'secret-password');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('CREATE DATABASE IF NOT EXISTS `app_db`')
        ->and($commands)->toContain('CREATE USER IF NOT EXISTS')
        ->and($commands)->toContain('GRANT SELECT, INSERT')
        ->and((new MysqlDriver)->createUser('app_user', 'x'))->toContain("'app_user'@'localhost'");
});

test('a database server binds to loopback only', function (DatabaseType $type, string $expected) {
    $transport = (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 3);

    databaseManager()->ensureServerInstalled(databaseConnection($transport), $type);

    expect(implode("\n", $transport->commands()))->toContain($expected);
})->with([
    [DatabaseType::Mysql, 'bind-address = 127.0.0.1'],
    [DatabaseType::Postgresql, "listen_addresses = '127.0.0.1'"],
    [DatabaseType::Redis, 'bind 127.0.0.1'],
]);

test('a database server is only installed when missing', function () {
    $transport = (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 3);

    databaseManager()->ensureServerInstalled(databaseConnection($transport), DatabaseType::Mysql);

    expect($transport->commands()[1])->toContain('dpkg-query')
        ->and($transport->commands()[1])->toContain('||');
});

test('a backup is compressed and readable only by root', function () {
    $transport = new FakeSSHTransport;
    $database = ManagedDatabase::factory()->create([
        'type' => DatabaseType::Mysql,
        'name' => 'app_db',
    ]);

    $path = databaseManager()->backup(databaseConnection($transport), $database, '20260730120000');

    $commands = implode("\n", $transport->commands());

    expect($path)->toBe('/var/coresmith/backups/app_db/app_db-20260730120000.sql.gz')
        ->and($commands)->toContain('mysqldump --single-transaction')
        ->and($commands)->toContain('gzip')
        ->and($commands)->toContain('chmod 600');
});

test('a sqlite database is private to the app user', function () {
    $application = Application::factory()->create(['slug' => 'my-app', 'system_user' => 'coresmith-app-abcd']);
    $commands = implode("\n", (new SqliteDriver)->create($application));

    expect($commands)->toContain('/var/coresmith/apps/my-app/shared/database.sqlite')
        ->and($commands)->toContain('chmod 600')
        ->and($commands)->toContain('chown coresmith-app-abcd:coresmith-app-abcd');
});

test('a sqlite database lives in shared so it survives deployments', function () {
    $application = Application::factory()->create(['slug' => 'my-app']);

    expect((new SqliteDriver)->path($application))
        ->toBe('/var/coresmith/apps/my-app/shared/database.sqlite')
        ->and((new SqliteDriver)->path($application))->not->toContain('/releases/');
});

test('sqlite is created in wal mode', function () {
    $application = Application::factory()->create(['slug' => 'my-app']);

    expect(implode("\n", (new SqliteDriver)->create($application)))->toContain('journal_mode=WAL');
});

test('a sqlite backup uses the consistent snapshot command', function () {
    $application = Application::factory()->create(['slug' => 'my-app']);
    $command = (new SqliteDriver)->backup($application, '/var/coresmith/backups/my-app/db.sqlite');

    expect($command)->toContain('.backup')->and($command)->toContain('gzip');
});

test('restoring pipes the compressed backup back into the database', function () {
    $transport = new FakeSSHTransport;
    $database = ManagedDatabase::factory()->create(['type' => DatabaseType::Mysql, 'name' => 'app_db']);

    databaseManager()->restore(databaseConnection($transport), $database, '/var/coresmith/backups/app_db/app_db-1.sql.gz');

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('gunzip -c')->and($commands)->toContain('| mysql');
});

test('restoring a postgres database uses psql', function () {
    $transport = new FakeSSHTransport;
    $database = ManagedDatabase::factory()->create(['type' => DatabaseType::Postgresql, 'name' => 'app_db']);

    databaseManager()->restore(databaseConnection($transport), $database, '/tmp/app_db.sql.gz');

    expect(implode("\n", $transport->commands()))->toContain('sudo -u postgres psql');
});

test('restoring refuses when the backup file is missing rather than truncating the database', function () {
    $transport = (new FakeSSHTransport)->respondTo('test -f', '', 1);
    $database = ManagedDatabase::factory()->create(['type' => DatabaseType::Mysql, 'name' => 'app_db']);

    expect(fn() => databaseManager()->restore(databaseConnection($transport), $database, '/tmp/missing.sql.gz'))
        ->toThrow(BackupNotFoundException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('gunzip');
});

test('a backup can be uploaded to the configured s3 disk', function () {
    Storage::fake('s3');
    $transport = (new FakeSSHTransport)->respondTo('cat ', "dump-bytes\n", 0);
    $database = ManagedDatabase::factory()->create(['type' => DatabaseType::Mysql, 'name' => 'app_db']);

    $key = databaseManager()->uploadBackupToS3(
        databaseConnection($transport),
        $database,
        '/var/coresmith/backups/app_db/app_db-1.sql.gz',
    );

    expect($key)->toContain('app_db-1.sql.gz');
    Storage::disk('s3')->assertExists($key);
});
