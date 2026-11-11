<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\Database\DatabaseManager;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Str;

function liveDatabaseSettings(): ?array
{
    $host = (string) getenv('CORESMITH_LIVE_DB_SSH_HOST');
    $user = (string) getenv('CORESMITH_LIVE_DB_SSH_USER');
    $key = (string) getenv('CORESMITH_LIVE_DB_SSH_KEY');

    if ($host === '' || $user === '' || $key === '' || !is_readable($key)) {
        return null;
    }

    return ['host' => $host, 'port' => (int) (getenv('CORESMITH_LIVE_DB_SSH_PORT') ?: 22), 'user' => $user, 'key' => (string) file_get_contents($key)];
}

function liveDatabaseConnection(): SSHConnection
{
    $settings = liveDatabaseSettings();
    $server = Server::factory()->create([
        'ip_address' => $settings['host'],
        'ssh_port' => $settings['port'],
        'ssh_user' => $settings['user'],
        'ssh_private_key' => $settings['key'],
        'host_key_fingerprint' => null,
        'os_name' => 'ubuntu',
    ]);

    return app(SSHConnectionManager::class)->for($server);
}

function liveDatabaseSignsIn(SSHConnection $connection, ManagedDatabase $database, string $password): bool
{
    $command = match ($database->type) {
        DatabaseType::Mysql => 'MYSQL_PWD=' . escapeshellarg($password) . ' mysql --protocol=socket -u ' . escapeshellarg((string) $database->db_user) . " -N -e 'SELECT 1'",
        DatabaseType::Postgresql => 'PGPASSWORD=' . escapeshellarg($password) . ' psql -h 127.0.0.1 -U ' . escapeshellarg((string) $database->db_user) . ' -d ' . escapeshellarg((string) $database->name) . " -tAc 'SELECT 1'",
    };

    $result = $connection->execute($command);

    return $result->successful() && trim($result->output) === '1';
}

beforeEach(function () {
    if (liveDatabaseSettings() === null) {
        $this->markTestSkipped('No live database server configured. Set CORESMITH_LIVE_DB_SSH_HOST, CORESMITH_LIVE_DB_SSH_USER and CORESMITH_LIVE_DB_SSH_KEY (a private key file), and optionally CORESMITH_LIVE_DB_SSH_PORT, for a server running MySQL and PostgreSQL where that user has sudo.');
    }
});

test('a real database user signs in with the rotated password, and the old one is refused', function (DatabaseType $type) {
    $connection = liveDatabaseConnection();
    $databases = app(DatabaseManager::class);
    $suffix = Str::lower(Str::random(8));
    $database = ManagedDatabase::factory()->for($connection->server())->create([
        'type' => $type,
        'name' => "live_rotate_{$suffix}",
        'db_user' => "live_rotate_{$suffix}",
        'privilege_level' => 'standard',
    ]);
    $old = DatabaseManager::generatePassword();
    $new = DatabaseManager::generatePassword();

    try {
        $databases->create($connection, $database, $old);

        expect(liveDatabaseSignsIn($connection, $database, $old))->toBeTrue();

        $databases->changePassword($connection, $database, $new);

        expect(liveDatabaseSignsIn($connection, $database, $new))->toBeTrue()
            ->and(liveDatabaseSignsIn($connection, $database, $old))->toBeFalse();
    } finally {
        $databases->drop($connection, $database);
        $connection->disconnect();
    }
})->with([DatabaseType::Mysql, DatabaseType::Postgresql]);

function liveDatabaseExists(SSHConnection $connection, ManagedDatabase $database): array
{
    $name = (string) $database->name;
    $user = (string) $database->db_user;

    $queries = match ($database->type) {
        DatabaseType::Mysql => [
            "mysql --protocol=socket -N -e \"SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$name}'\"",
            "mysql --protocol=socket -N -e \"SELECT COUNT(*) FROM mysql.user WHERE user = '{$user}'\"",
        ],
        DatabaseType::Postgresql => [
            "sudo -u postgres psql -tAc \"SELECT COUNT(*) FROM pg_database WHERE datname = '{$name}'\"",
            "sudo -u postgres psql -tAc \"SELECT COUNT(*) FROM pg_roles WHERE rolname = '{$user}'\"",
        ],
    };

    return array_map(fn(string $query): string => trim($connection->execute($query, sudo: true)->output), $queries);
}

test('a real database and its user are gone once Coresmith drops them, even with a session still open', function (DatabaseType $type) {
    $connection = liveDatabaseConnection();
    $databases = app(DatabaseManager::class);
    $suffix = Str::lower(Str::random(8));
    $database = ManagedDatabase::factory()->for($connection->server())->create([
        'type' => $type,
        'name' => "live_drop_{$suffix}",
        'db_user' => "live_drop_{$suffix}",
        'privilege_level' => 'standard',
    ]);
    $password = DatabaseManager::generatePassword();

    try {
        $databases->create($connection, $database, $password);

        expect(liveDatabaseExists($connection, $database))->toBe(['1', '1']);

        $held = match ($type) {
            DatabaseType::Mysql => 'MYSQL_PWD=' . escapeshellarg($password) . ' mysql --protocol=socket -u ' . escapeshellarg((string) $database->db_user) . ' ' . escapeshellarg((string) $database->name) . " -e 'SELECT SLEEP(20)'",
            DatabaseType::Postgresql => 'PGPASSWORD=' . escapeshellarg($password) . ' psql -h 127.0.0.1 -U ' . escapeshellarg((string) $database->db_user) . ' -d ' . escapeshellarg((string) $database->name) . " -c 'SELECT pg_sleep(20)'",
        };
        $connection->execute('nohup sh -c ' . escapeshellarg($held) . ' >/dev/null 2>&1 & sleep 2');

        $databases->drop($connection, $database);

        expect(liveDatabaseExists($connection, $database))->toBe(['0', '0'])
            ->and(liveDatabaseSignsIn($connection, $database, $password))->toBeFalse();
    } finally {
        $databases->drop($connection, $database);
        $connection->disconnect();
    }
})->with([DatabaseType::Mysql, DatabaseType::Postgresql]);

function liveDatabaseAs(ManagedDatabase $database, string $password, string $sql): string
{
    return match ($database->type) {
        DatabaseType::Mysql => 'MYSQL_PWD=' . escapeshellarg($password) . ' mysql --protocol=socket -u ' . escapeshellarg((string) $database->db_user) . ' ' . escapeshellarg((string) $database->name) . ' -N -e ' . escapeshellarg($sql),
        DatabaseType::Postgresql => 'PGPASSWORD=' . escapeshellarg($password) . ' psql -h 127.0.0.1 -U ' . escapeshellarg((string) $database->db_user) . ' -d ' . escapeshellarg((string) $database->name) . ' -v ON_ERROR_STOP=1 -tAc ' . escapeshellarg($sql),
    };
}

test('a real database comes back exactly as its backup, with what changed since gone and its own user still in charge', function (DatabaseType $type) {
    $connection = liveDatabaseConnection();
    $databases = app(DatabaseManager::class);
    $suffix = Str::lower(Str::random(8));
    $postgres = $type === DatabaseType::Postgresql;
    $database = ManagedDatabase::factory()->for($connection->server())->create([
        'type' => $type,
        'name' => "live_restore_{$suffix}",
        'db_user' => "live_restore_{$suffix}",
        'privilege_level' => 'standard',
        'port' => $postgres ? 5432 : 3306,
        'extensions' => $postgres ? ['pg_trgm'] : [],
    ]);
    $password = DatabaseManager::generatePassword();
    $database->forceFill(['db_password' => $password])->save();
    $as = fn(string $sql): string => trim($connection->execute(liveDatabaseAs($database, $password, $sql))->throw()->output);
    $tableSchema = $postgres ? 'public' : (string) $database->name;

    try {
        $databases->create($connection, $database, $password);
        $as('CREATE TABLE notes (id INT PRIMARY KEY, body TEXT)');
        $as("INSERT INTO notes VALUES (1, 'kept'), (2, 'COMMENT ON EXTENSION is my note')");
        $dump = $databases->backup($connection, $database, now()->format('YmdHis'));
        $as("INSERT INTO notes VALUES (3, 'lost')");
        $as("UPDATE notes SET body = 'changed' WHERE id = 1");
        $as('CREATE TABLE later (id INT)');

        $databases->restore($connection, $database, $dump);

        expect($as('SELECT body FROM notes ORDER BY id'))->toBe("kept\nCOMMENT ON EXTENSION is my note")
            ->and($as("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$tableSchema}' AND table_name = 'later'"))->toBe('0');

        $as("INSERT INTO notes VALUES (4, 'after')");

        expect($as('SELECT COUNT(*) FROM notes'))->toBe('3');

        if ($postgres) {
            expect($as("SELECT tableowner FROM pg_tables WHERE tablename = 'notes'"))->toBe((string) $database->db_user)
                ->and($as("SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_trgm'"))->toBe('1');
        }
    } finally {
        $databases->drop($connection, $database);
        $connection->execute('rm -rf ' . escapeshellarg(DatabaseManager::BACKUP_ROOT . '/' . $database->name), sudo: true);
        $connection->disconnect();
    }
})->with([DatabaseType::Mysql, DatabaseType::Postgresql]);
