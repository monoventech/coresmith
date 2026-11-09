<?php

declare(strict_types=1);

use App\Enums\DatabaseType;
use App\Models\Server;
use App\Services\Database\DatabaseManager;
use App\Services\Database\RedisInstance;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;

function liveRedisSettings(): ?array
{
    $host = (string) getenv('CORESMITH_LIVE_REDIS_SSH_HOST');
    $user = (string) getenv('CORESMITH_LIVE_REDIS_SSH_USER');
    $key = (string) getenv('CORESMITH_LIVE_REDIS_SSH_KEY');

    if ($host === '' || $user === '' || $key === '' || !is_readable($key)) {
        return null;
    }

    return ['host' => $host, 'port' => (int) (getenv('CORESMITH_LIVE_REDIS_SSH_PORT') ?: 22), 'user' => $user, 'key' => (string) file_get_contents($key)];
}

function liveRedisConnection(): SSHConnection
{
    $settings = liveRedisSettings();
    $server = Server::factory()->create([
        'ip_address' => $settings['host'],
        'ssh_port' => $settings['port'],
        'ssh_user' => $settings['user'],
        'ssh_private_key' => $settings['key'],
        'host_key_fingerprint' => null,
        'os_name' => (string) (getenv('CORESMITH_LIVE_REDIS_OS') ?: 'ubuntu'),
    ]);

    return app(SSHConnectionManager::class)->for($server);
}

function liveRedisPing(SSHConnection $connection, int $port, ?string $password): string
{
    $ping = $password === null
        ? "redis-cli -p {$port} ping"
        : 'sh -c ' . escapeshellarg("REDISCLI_AUTH=\"\$(cat)\" exec redis-cli -p {$port} ping");

    return trim(($password === null ? $connection->execute($ping) : $connection->executeWithInput($ping, $password))->output);
}

beforeEach(function () {
    if (liveRedisSettings() === null) {
        $this->markTestSkipped('No live Redis server configured. Set CORESMITH_LIVE_REDIS_SSH_HOST, CORESMITH_LIVE_REDIS_SSH_USER and CORESMITH_LIVE_REDIS_SSH_KEY (a private key file), and optionally CORESMITH_LIVE_REDIS_SSH_PORT and CORESMITH_LIVE_REDIS_OS, for a server where that user has sudo.');
    }
});

test('two Redis databases on a real server are two instances that only open with their own password', function () {
    $connection = liveRedisConnection();
    $instances = app(RedisInstance::class);

    try {
        app(DatabaseManager::class)->ensureServerInstalled($connection, DatabaseType::Redis);

        expect(trim($connection->execute('ss -Hltn | grep -c ":6379 " || true')->output))->toBe('0');

        $instances->create($connection, 6380, 'FirstInstancePassw0rdValue');
        $instances->create($connection, 6381, 'SecondInstancePassw0rdValue');

        expect(liveRedisPing($connection, 6380, 'FirstInstancePassw0rdValue'))->toBe('PONG')
            ->and(liveRedisPing($connection, 6381, 'SecondInstancePassw0rdValue'))->toBe('PONG')
            ->and(liveRedisPing($connection, 6380, 'SecondInstancePassw0rdValue'))->not->toBe('PONG')
            ->and(liveRedisPing($connection, 6381, 'FirstInstancePassw0rdValue'))->not->toBe('PONG')
            ->and(liveRedisPing($connection, 6380, null))->toContain('NOAUTH');

        expect($connection->execute('sudo -u nobody cat /etc/coresmith-redis/6380.conf')->successful())->toBeFalse()
            ->and($connection->execute('sudo -u nobody ls /var/lib/coresmith-redis/6380')->successful())->toBeFalse()
            ->and(trim($connection->execute("ps -eo args | grep -c '[F]irstInstancePassw0rdValue' || true")->output))->toBe('0');

        $instances->changePassword($connection, 6380, 'RotatedInstancePassw0rdValue');

        expect(liveRedisPing($connection, 6380, 'RotatedInstancePassw0rdValue'))->toBe('PONG')
            ->and(liveRedisPing($connection, 6380, 'FirstInstancePassw0rdValue'))->not->toBe('PONG');
    } finally {
        $instances->drop($connection, 6380);
        $instances->drop($connection, 6381);
    }

    expect(trim($connection->execute('ss -Hltn | grep -cE ":638[01] " || true')->output))->toBe('0')
        ->and($connection->execute('id -u coresmith-redis-6380')->successful())->toBeFalse()
        ->and($connection->execute('test -e /etc/systemd/system/coresmith-redis-6380.service')->successful())->toBeFalse();

    $connection->disconnect();
});
