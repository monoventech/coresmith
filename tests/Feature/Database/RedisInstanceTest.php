<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Database\RedisInstance;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSSHTransport;

const REDIS_INSTANCE_PING = "sh -c 'REDISCLI_AUTH=\"\$(cat)\" exec redis-cli -p 6380 ping'";

function redisInstanceConnection(FakeSSHTransport $transport, string $user = 'root'): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'ssh_user' => $user]);

    return (new SSHConnection($server, $transport))->connect();
}

function redisInstanceAnswering(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('redis-cli -p 6380 ping', "PONG\n");
}

beforeEach(function () {
    Sleep::fake();
});

test('an instance runs as its own user, with data only it can enter and a config only it and root can read', function () {
    $transport = redisInstanceAnswering();

    app(RedisInstance::class)->create(redisInstanceConnection($transport), 6380, 'R3disPassw0rdValue');

    $commands = $transport->commands();
    $position = fn(string $needle): int|false => array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, $needle))) ?? false;

    expect($commands)->toContain('id -u coresmith-redis-6380 >/dev/null 2>&1 || useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin --user-group coresmith-redis-6380')
        ->toContain('install -d -m 0755 /etc/coresmith-redis /var/lib/coresmith-redis && install -d -m 0700 -o coresmith-redis-6380 -g coresmith-redis-6380 /var/lib/coresmith-redis/6380')
        ->toContain('install -m 0640 -o root -g coresmith-redis-6380 /dev/null /etc/coresmith-redis/6380.conf')
        ->and($position('install -m 0640 -o root'))->toBeLessThan($position('systemctl restart coresmith-redis-6380.service'));
});

test('an instance listens on loopback only, on its own port, behind its own password', function () {
    $transport = redisInstanceAnswering();

    app(RedisInstance::class)->create(redisInstanceConnection($transport), 6380, 'R3disPassw0rdValue');

    expect($transport->uploads()['/etc/coresmith-redis/6380.conf'] ?? null)->toBe(implode("\n", [
        'bind 127.0.0.1',
        'port 6380',
        'protected-mode yes',
        'requirepass R3disPassw0rdValue',
        'dir /var/lib/coresmith-redis/6380',
        'daemonize no',
        'logfile ""',
    ]) . "\n");
});

test('an instance runs sandboxed so it can only write its own data', function () {
    $transport = redisInstanceAnswering();

    app(RedisInstance::class)->create(redisInstanceConnection($transport), 6380, 'R3disPassw0rdValue');

    $unit = $transport->uploads()['/etc/systemd/system/coresmith-redis-6380.service'] ?? '';

    expect($unit)->toContain("User=coresmith-redis-6380\nGroup=coresmith-redis-6380")
        ->toContain('ExecStart=/usr/bin/redis-server /etc/coresmith-redis/6380.conf')
        ->toContain('ProtectSystem=strict')
        ->toContain('ReadWritePaths=/var/lib/coresmith-redis/6380')
        ->toContain('NoNewPrivileges=true')
        ->toContain('PrivateTmp=true')
        ->toContain('ProtectHome=true')
        ->toContain('WantedBy=multi-user.target')
        ->and($transport->commands())->toContain('systemctl daemon-reload && systemctl enable coresmith-redis-6380.service && systemctl restart coresmith-redis-6380.service');
});

test('an instance is only ready once it answers its own password, checked without putting it on the command line', function () {
    $transport = redisInstanceAnswering();

    app(RedisInstance::class)->create(redisInstanceConnection($transport), 6380, 'R3disPassw0rdValue');

    expect($transport->sent())->toBe([[REDIS_INSTANCE_PING, 'R3disPassw0rdValue']])
        ->and(implode("\n", $transport->commands()))->not->toContain('R3disPassw0rdValue');
});

test('an instance that never answers is reported, after a few tries', function () {
    $transport = (new FakeSSHTransport)->respondTo('redis-cli -p 6380 ping', "Could not connect to Redis at 127.0.0.1:6380: Connection refused\n");

    expect(fn() => app(RedisInstance::class)->create(redisInstanceConnection($transport), 6380, 'R3disPassw0rdValue'))
        ->toThrow(RuntimeException::class, "Redis on port 6380 didn't answer after it started. Its log is in journalctl -u coresmith-redis-6380.");

    expect(count(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'redis-cli -p 6380 ping'))))->toBe(RedisInstance::ATTEMPTS);
});

test('a sudo user reaches the instance through sudo, with the password still only on stdin', function () {
    $transport = redisInstanceAnswering();

    app(RedisInstance::class)->create(redisInstanceConnection($transport, 'deploy'), 6380, 'R3disPassw0rdValue');

    expect(array_column($transport->sent(), 1))->toContain('R3disPassw0rdValue')
        ->and(implode("\n", $transport->commands()))->not->toContain('R3disPassw0rdValue');
});

test('a new password is written into the config the instance already has, then the instance restarts on it', function () {
    $transport = redisInstanceAnswering();

    app(RedisInstance::class)->changePassword(redisInstanceConnection($transport), 6380, 'N3wR3disPassw0rd');

    expect($transport->uploads()['/etc/coresmith-redis/6380.conf'] ?? '')->toContain("requirepass N3wR3disPassw0rd\n")
        ->and($transport->commands())->toContain('systemctl restart coresmith-redis-6380.service')
        ->and($transport->sent())->toBe([[REDIS_INSTANCE_PING, 'N3wR3disPassw0rd']])
        ->and(implode("\n", $transport->commands()))->not->toContain('install -m 0640');
});

test('dropping an instance stops it and removes its unit, config, data and user', function () {
    $transport = new FakeSSHTransport;

    app(RedisInstance::class)->drop(redisInstanceConnection($transport), 6380);

    expect($transport->commands())->toBe([
        'systemctl disable --now coresmith-redis-6380.service || true',
        'rm -f /etc/systemd/system/coresmith-redis-6380.service /etc/coresmith-redis/6380.conf && rm -rf /var/lib/coresmith-redis/6380 && systemctl daemon-reload',
        'if id -u coresmith-redis-6380 >/dev/null 2>&1; then userdel coresmith-redis-6380; fi',
    ]);
});
