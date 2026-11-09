<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Exceptions\ExistingDatabaseServerRefused;
use App\Jobs\CreateDatabase;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\Database\DatabaseManager;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

function existingDbConnection(FakeSSHTransport $transport, array $server = []): SSHConnection
{
    return (new SSHConnection(Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', ...$server]), $transport))->connect();
}

test('a mariadb server that was already running is used without installing or rebinding anything', function () {
    $transport = (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 0);
    $connection = existingDbConnection($transport);

    expect(app(DatabaseManager::class)->ensureServerInstalled($connection, DatabaseType::Mysql))->toBeTrue();

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("mysql --protocol=socket -e 'SELECT 1'")
        ->not->toContain('apt-get install')
        ->not->toContain('bind-address')
        ->not->toContain('systemctl restart');
});

test('an existing server Coresmith cannot administer is refused with what to change', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('systemctl is-active --quiet', '', 0)
        ->respondTo("mysql --protocol=socket -e 'SELECT 1'", "ERROR 1045 (28000): Access denied for user 'root'@'localhost'", 1);

    expect(fn() => app(DatabaseManager::class)->ensureServerInstalled(existingDbConnection($transport, ['name' => 'forge-09']), DatabaseType::Mysql))
        ->toThrow(ExistingDatabaseServerRefused::class, 'MySQL is already running on forge-09, but Coresmith cannot create databases on it.');
});

test('an existing redis is pinged before Coresmith claims it can use it', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('systemctl is-active --quiet', '', 0)
        ->respondTo('redis-cli -t 3 ping', "PONG\n", 0);

    expect(app(DatabaseManager::class)->ensureServerInstalled(existingDbConnection($transport), DatabaseType::Redis))->toBeTrue()
        ->and(implode("\n", $transport->commands()))->toContain('redis-cli -t 3 ping');
});

test('an existing redis that does not answer is refused with what to change', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('systemctl is-active --quiet', '', 0)
        ->respondTo('redis-cli -t 3 ping', "NOAUTH Authentication required.\n", 0);

    expect(fn() => app(DatabaseManager::class)->ensureServerInstalled(existingDbConnection($transport, ['name' => 'forge-09']), DatabaseType::Redis))
        ->toThrow(ExistingDatabaseServerRefused::class, 'Redis is already running on forge-09, but Coresmith cannot reach it.');
});

test('an engine Coresmith installed is recorded, so it is never mistaken for an existing one', function () {
    $transport = (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 3);
    $connection = existingDbConnection($transport);

    expect(app(DatabaseManager::class)->ensureServerInstalled($connection, DatabaseType::Postgresql))->toBeFalse()
        ->and($connection->server()->fresh()->meta['installed_databases'])->toBe(['postgresql']);

    $running = (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 0);
    $again = new SSHConnection($connection->server()->fresh(), $running);

    expect(app(DatabaseManager::class)->ensureServerInstalled($again->connect(), DatabaseType::Postgresql))->toBeFalse();
});

test('a server that already has a coresmith managed database of that engine counts as coresmith installed', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    ManagedDatabase::factory()->postgres()->for($server)->create(['is_external' => false]);
    $transport = (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 0);

    expect(app(DatabaseManager::class)->ensureServerInstalled((new SSHConnection($server, $transport))->connect(), DatabaseType::Postgresql))->toBeFalse();
});

test('creating a database on a server that already ran the engine marks it external', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $database = ManagedDatabase::factory()->for($server)->create(['name' => 'reports', 'db_user' => 'reports_app', 'is_external' => false]);
    $transport = (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 0);

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new CreateDatabase($database), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($database->fresh()->is_external)->toBeTrue();
});

test('an existing redis Coresmith cannot even probe says to install the client, not to change a password', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('systemctl is-active --quiet', '', 0)
        ->respondTo('redis-cli', "sh: 1: redis-cli: command not found\n", 127);

    expect(fn() => app(DatabaseManager::class)->ensureServerInstalled(existingDbConnection($transport, ['name' => 'forge-09']), DatabaseType::Redis))
        ->toThrow(ExistingDatabaseServerRefused::class, 'Install redis-tools on the server so Coresmith can check it, then try again.');
});

test('the job outlasts the package install budget it delegates to', function () {
    expect((new CreateDatabase(ManagedDatabase::factory()->make()))->timeout)->toBeGreaterThan(SSHTransport::PACKAGE_SECONDS);
});

test('the redis probe cannot hang the provisioning job', function () {
    $transport = (new FakeSSHTransport)
        ->respondTo('systemctl is-active --quiet', '', 0)
        ->respondTo('redis-cli', "PONG\n", 0);

    app(DatabaseManager::class)->ensureServerInstalled(existingDbConnection($transport), DatabaseType::Redis);

    expect(implode("\n", $transport->commands()))->toContain('redis-cli -t 3 ping');
});

test('creating a database waits for a password rotation on it, then gives up rather than racing it', function () {
    Sleep::fake(syncWithCarbon: true);
    $database = ManagedDatabase::factory()->create(['name' => 'reports', 'db_user' => 'reports_app']);
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);
    $held = $database->credentialsLock();
    $held->get();

    try {
        expect(fn() => app()->call([new CreateDatabase($database), 'handle']))->toThrow(LockTimeoutException::class);
    } finally {
        $held->release();
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($transport->calls())->toBe([]);
});

test('creating a database lets go of it when done', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $database = ManagedDatabase::factory()->for($server)->create(['name' => 'reports', 'db_user' => 'reports_app']);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 3));

    try {
        app()->call([new CreateDatabase($database), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($database->credentialsLock()->get())->toBeTrue();
});

test('the hold on a database outlasts the job that creates it', function () {
    expect(ManagedDatabase::CREDENTIALS_LOCK_SECONDS)->toBeGreaterThan((new CreateDatabase(ManagedDatabase::factory()->make()))->timeout);
});

test('a password the job sets has not been shown yet, even when an earlier one had', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu']);
    $database = ManagedDatabase::factory()->for($server)->create(['name' => 'reports', 'db_user' => 'reports_app', 'credentials_shown_at' => now()]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => (new FakeSSHTransport)->respondTo('systemctl is-active --quiet', '', 3));

    try {
        app()->call([new CreateDatabase($database), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($database->fresh()->credentials_shown_at)->toBeNull();
});
