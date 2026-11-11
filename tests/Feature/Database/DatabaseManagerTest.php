<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Enums\PrivilegeLevel;
use App\Exceptions\BackupNotFoundException;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\Database\DatabaseManager;
use App\Services\Database\MysqlDriver;
use App\Services\Database\PostgresDriver;
use App\Services\Database\SqliteDriver;
use App\Services\SSH\SSHConnection;
use Symfony\Component\Process\Process;
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
        ->and(implode("\n", array_column($transport->sent(), 1)))->toContain('CREATE USER IF NOT EXISTS')
        ->and($commands)->toContain('GRANT SELECT, INSERT')
        ->and((new MysqlDriver)->createUser('app_user', 'x'))->toContain("'app_user'@'localhost'");
});

test('a MySQL or PostgreSQL server binds to loopback only', function (DatabaseType $type, string $expected) {
    $transport = (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 3);

    databaseManager()->ensureServerInstalled(databaseConnection($transport), $type);

    expect(implode("\n", $transport->commands()))->toContain($expected);
})->with([
    [DatabaseType::Mysql, 'bind-address = 127.0.0.1'],
    [DatabaseType::Postgresql, "listen_addresses = '127.0.0.1'"],
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

function databaseManagerRestoreTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('mktemp', "/tmp/tmp.ReStOrE123\n");
}

test('restoring mysql recreates the database as root, then runs the dump as its own user', function () {
    $transport = databaseManagerRestoreTransport();
    $database = ManagedDatabase::factory()->create(['type' => DatabaseType::Mysql, 'name' => 'app_db', 'db_user' => 'app_user', 'db_password' => 'S3cretPassw0rd']);

    databaseManager()->restore(databaseConnection($transport), $database, '/var/coresmith/backups/app_db/app_db-20261110023000.sql.gz');

    expect($transport->commands())->toBe([
        "test -f '/var/coresmith/backups/app_db/app_db-20261110023000.sql.gz'",
        'mktemp',
        "mysql --protocol=socket -e 'SET SESSION lock_wait_timeout = 30; DROP DATABASE IF EXISTS `app_db`; CREATE DATABASE `app_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'",
        "gunzip -c '/var/coresmith/backups/app_db/app_db-20261110023000.sql.gz' | mysql --defaults-file='/tmp/tmp.ReStOrE123' 'app_db'",
        "rm -f '/tmp/tmp.ReStOrE123'",
    ])
        ->and($transport->uploads())->toBe(['/tmp/tmp.ReStOrE123' => "[client]\nuser=\"app_user\"\npassword=\"S3cretPassw0rd\"\nprotocol=socket\n"]);
});

test('restoring postgres recreates it owned by its user with its grants and extensions, then runs the dump as that user in one transaction', function () {
    $transport = databaseManagerRestoreTransport();
    $database = ManagedDatabase::factory()->create(['type' => DatabaseType::Postgresql, 'name' => 'app_db', 'db_user' => 'app_user', 'db_password' => 'S3cretPassw0rd', 'port' => 5432, 'extensions' => ['pg_trgm'], 'privilege_level' => PrivilegeLevel::Standard->value]);
    $postgres = new PostgresDriver;

    databaseManager()->restore(databaseConnection($transport), $database, '/var/coresmith/backups/app_db/app_db-20261110023000.sql.gz');

    expect($transport->commands())->toBe([
        "test -f '/var/coresmith/backups/app_db/app_db-20261110023000.sql.gz'",
        'mktemp',
        $postgres->command($postgres->endSessions('app_db')),
        $postgres->command($postgres->dropDatabase('app_db')),
        $postgres->command($postgres->createDatabase('app_db', 'app_user')),
        $postgres->command($postgres->grant('app_db', 'app_user', PrivilegeLevel::Standard), 'app_db'),
        $postgres->command($postgres->createExtension('pg_trgm', 'app_db'), 'app_db'),
        "gunzip -c '/var/coresmith/backups/app_db/app_db-20261110023000.sql.gz' | awk " . escapeshellarg(PostgresDriver::EXTENSION_COMMENTS) . " | PGPASSFILE='/tmp/tmp.ReStOrE123' psql -h 127.0.0.1 -p 5432 -U 'app_user' -d 'app_db' -v ON_ERROR_STOP=1 --single-transaction -q",
        "rm -f '/tmp/tmp.ReStOrE123'",
    ])
        ->and($transport->uploads())->toBe(['/tmp/tmp.ReStOrE123' => "127.0.0.1:5432:app_db:app_user:S3cretPassw0rd\n"]);
});

test('on a server reached as a sudo user, the credentials file belongs to root, so root can write it in /tmp', function () {
    $transport = (new FakeSSHTransport)->respondTo('coresmith-upload', "/tmp/coresmith-upload.AbCdEfGhIj\n")->respondTo('mktemp', "/tmp/tmp.ReStOrE123\n");
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'ssh_user' => 'deploy']);
    $database = ManagedDatabase::factory()->for($server)->create(['type' => DatabaseType::Mysql, 'name' => 'app_db', 'db_user' => 'app_user', 'db_password' => 'S3cretPassw0rd']);

    databaseManager()->restore((new SSHConnection($server, $transport))->connect(), $database, '/tmp/app_db.sql.gz');

    expect($transport->commands())->toContain('sudo mktemp')
        ->and(collect($transport->commands())->last())->toBe("sudo rm -f '/tmp/tmp.ReStOrE123'");
});

test('a dump never runs through the superuser', function (DatabaseType $type) {
    $transport = databaseManagerRestoreTransport();
    $database = ManagedDatabase::factory()->create(['type' => $type, 'name' => 'app_db', 'db_user' => 'app_user', 'db_password' => 'S3cretPassw0rd']);

    databaseManager()->restore(databaseConnection($transport), $database, '/tmp/app_db.sql.gz');

    $dump = collect($transport->commands())->first(fn(string $command): bool => str_starts_with($command, 'gunzip -c'));

    expect($dump)->not->toContain('sudo -u postgres')
        ->and($dump)->not->toContain('mysql --protocol=socket')
        ->and($dump)->toMatch($type === DatabaseType::Mysql ? '/\| mysql --defaults-file=/' : "/-U 'app_user'/");
})->with([DatabaseType::Mysql, DatabaseType::Postgresql]);

test('the credentials file goes even when the dump fails', function (DatabaseType $type) {
    $transport = databaseManagerRestoreTransport()->respondTo('gunzip -c', 'ERROR 1050 (42S01) at line 25: Table already exists', 1);
    $database = ManagedDatabase::factory()->create(['type' => $type, 'name' => 'app_db', 'db_user' => 'app_user', 'db_password' => 'S3cretPassw0rd']);

    expect(fn() => databaseManager()->restore(databaseConnection($transport), $database, '/tmp/app_db.sql.gz'))->toThrow(SSHCommandFailedException::class);

    expect($transport->commands())->toContain("rm -f '/tmp/tmp.ReStOrE123'");
})->with([DatabaseType::Mysql, DatabaseType::Postgresql]);

test('a missing backup is refused before the database is touched', function (DatabaseType $type) {
    $transport = databaseManagerRestoreTransport()->respondTo('test -f', '', 1);
    $database = ManagedDatabase::factory()->create(['type' => $type, 'name' => 'app_db', 'db_user' => 'app_user', 'db_password' => 'S3cretPassw0rd']);

    expect(fn() => databaseManager()->restore(databaseConnection($transport), $database, '/tmp/missing.sql.gz'))
        ->toThrow(BackupNotFoundException::class);

    expect(implode("\n", $transport->commands()))->not->toContain('DROP')
        ->and(implode("\n", $transport->commands()))->not->toContain('gunzip');
})->with([DatabaseType::Mysql, DatabaseType::Postgresql]);

test('quotes, colons and backslashes in a password cannot break out of either credentials file', function () {
    expect((new MysqlDriver)->credentials('app_user', 'pa"ss\\wo#rd'))->toBe("[client]\nuser=\"app_user\"\npassword=\"pa\\\"ss\\\\wo#rd\"\nprotocol=socket\n")
        ->and((new PostgresDriver)->passfile('app_db', 'app_user', 5432, 'pa:ss\\word'))->toBe("127.0.0.1:5432:app_db:app_user:pa\\:ss\\\\word\n");
});

test('the extension comment filter keeps data rows and drops only comment statements', function () {
    $dump = "SET client_encoding = 'UTF8';\n"
        . "CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;\n"
        . "COMMENT ON EXTENSION pg_trgm IS 'text similarity measurement and index searching based on trigrams';\n"
        . "COPY public.notes (id, body) FROM stdin;\n"
        . "1\tCOMMENT ON EXTENSION is my note\n"
        . "\\.\n"
        . "COMMENT ON TABLE public.notes IS 'kept';\n";

    $filtered = (new Process(['awk', PostgresDriver::EXTENSION_COMMENTS]))->setInput($dump)->mustRun()->getOutput();

    expect($filtered)->toBe("SET client_encoding = 'UTF8';\n"
        . "CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public;\n"
        . "COPY public.notes (id, body) FROM stdin;\n"
        . "1\tCOMMENT ON EXTENSION is my note\n"
        . "\\.\n"
        . "COMMENT ON TABLE public.notes IS 'kept';\n");
});

test('a mysql password change keeps the user and quotes the new password', function () {
    expect((new MysqlDriver)->changePassword('app_user', "pa'ss"))->toBe("ALTER USER 'app_user'@'localhost' IDENTIFIED BY 'pa\\'ss';");
});

test('a postgres password change keeps the role and doubles a quote', function () {
    expect((new PostgresDriver)->changePassword('app_user', "pa'ss"))->toBe("ALTER ROLE \"app_user\" WITH PASSWORD 'pa''ss';");
});

test('a password change refuses a user name carrying sql metacharacters', function (string $driver) {
    (new $driver)->changePassword("evil'@'%", 'secret');
})->with([MysqlDriver::class, PostgresDriver::class])->throws(UnsafeNameException::class);

test('changing a database password sends the statement to the engine\'s own client, never on the command line', function (DatabaseType $type, string $client, string $statement) {
    $transport = new FakeSSHTransport;
    $database = ManagedDatabase::factory()->create(['type' => $type, 'name' => 'app_db', 'db_user' => 'app_user']);

    databaseManager()->changePassword(databaseConnection($transport), $database, 'N3wPassw0rdValue');

    expect($transport->sent())->toBe([[$client, $statement]])
        ->and(implode("\n", $transport->commands()))->not->toContain('N3wPassw0rdValue');
})->with([
    'mysql' => [DatabaseType::Mysql, 'mysql --protocol=socket', "ALTER USER 'app_user'@'localhost' IDENTIFIED BY 'N3wPassw0rdValue';"],
    'postgres' => [DatabaseType::Postgresql, 'sudo -u postgres psql -v ON_ERROR_STOP=1', "ALTER ROLE \"app_user\" WITH PASSWORD 'N3wPassw0rdValue';"],
]);

test('creating a database never puts its password on the command line', function (DatabaseType $type, string $statement) {
    $transport = new FakeSSHTransport;
    $database = ManagedDatabase::factory()->create(['type' => $type, 'name' => 'app_db', 'db_user' => 'app_user', 'privilege_level' => 'standard']);

    databaseManager()->create(databaseConnection($transport), $database, 'Cr3atedPassw0rdValue');

    expect(implode("\n", $transport->commands()))->not->toContain('Cr3atedPassw0rdValue')
        ->and(array_column($transport->sent(), 1))->toContain($statement);
})->with([
    'mysql' => [DatabaseType::Mysql, "CREATE USER IF NOT EXISTS 'app_user'@'localhost' IDENTIFIED BY 'Cr3atedPassw0rdValue';"],
    'postgres' => [DatabaseType::Postgresql, "CREATE ROLE \"app_user\" LOGIN PASSWORD 'Cr3atedPassw0rdValue' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;"],
]);

test('a failed password change on the server is reported, not swallowed', function () {
    $transport = (new FakeSSHTransport)->respondTo('mysql --protocol=socket', "ERROR 1396 (HY000): Operation ALTER USER failed for 'app_user'@'localhost'", 1);
    $database = ManagedDatabase::factory()->create(['type' => DatabaseType::Mysql, 'name' => 'app_db', 'db_user' => 'app_user']);

    databaseManager()->changePassword(databaseConnection($transport), $database, 'N3wPassw0rdValue');
})->throws(SSHCommandFailedException::class);
