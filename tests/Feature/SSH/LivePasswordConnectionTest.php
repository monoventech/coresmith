<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Jobs\AuditServer;
use App\Models\Server;
use App\Services\Server\ServerConnector;
use App\Services\SSH\AuthorizedKeyCommand;
use App\Services\SSH\KeyPairGenerator;
use App\Services\SSH\Phpseclib3Transport;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;

function livePasswordSettings(): ?array
{
    $host = (string) getenv('CORESMITH_LIVE_SSH_HOST');
    $user = (string) getenv('CORESMITH_LIVE_SSH_PASSWORD_USER');
    $password = (string) getenv('CORESMITH_LIVE_SSH_PASSWORD');

    if ($host === '' || $user === '' || $password === '') {
        return null;
    }

    return ['host' => $host, 'port' => (int) (getenv('CORESMITH_LIVE_SSH_PORT') ?: 22), 'user' => $user, 'password' => $password];
}

function livePasswordTransport(): Phpseclib3Transport
{
    $settings = livePasswordSettings();
    $transport = new Phpseclib3Transport;
    $transport->connect($settings['host'], $settings['port'], 15);

    return $transport;
}

beforeEach(function () {
    if (livePasswordSettings() === null) {
        $this->markTestSkipped('No live password login configured. Set CORESMITH_LIVE_SSH_HOST, CORESMITH_LIVE_SSH_PASSWORD_USER and CORESMITH_LIVE_SSH_PASSWORD (and optionally CORESMITH_LIVE_SSH_PORT) for a user whose sudo asks for that password.');
    }
});

test('a real server signs in with a password and sudo reads it from stdin', function () {
    $settings = livePasswordSettings();
    $transport = livePasswordTransport();

    expect($transport->loginWithPassword($settings['user'], $settings['password']))->toBeTrue();

    $output = $transport->execWithInput("sudo -S -p '' id -u", $settings['password'] . "\n", 30);

    expect(trim($output))->toBe('0')
        ->and($transport->exitStatus())->toBe(0);

    expect(trim($transport->exec('id -un')))->toBe($settings['user']);

    $transport->disconnect();
});

test('sudo given the wrong password on stdin fails instead of waiting for another try', function () {
    $settings = livePasswordSettings();
    $transport = livePasswordTransport();
    $transport->loginWithPassword($settings['user'], $settings['password']);

    $output = $transport->execWithInput("sudo -S -p '' id -u", "not the password\n", 30);

    expect($transport->exitStatus())->not->toBe(0)
        ->and($transport->timedOut())->toBeFalse()
        ->and($output)->not->toContain('not the password');

    $transport->disconnect();
});

test('a real server refuses a wrong password and still says it takes passwords', function () {
    $settings = livePasswordSettings();
    $transport = livePasswordTransport();

    expect($transport->loginWithPassword($settings['user'], 'not the password'))->toBeFalse()
        ->and($transport->acceptsPasswords())->toBeTrue();

    $transport->disconnect();
});

test('the key script installs a key on a real server for root and for a user, once each', function () {
    $settings = livePasswordSettings();
    $transport = livePasswordTransport();
    $transport->loginWithPassword($settings['user'], $settings['password']);
    $key = app(KeyPairGenerator::class)->generate('coresmith@live-check')->publicKey;
    $blob = explode(' ', $key)[1];
    $users = ['root', $settings['user']];
    $asRoot = fn(string $script): string => $transport->execWithInput("sudo -S -p '' sh -c " . escapeshellarg($script), $settings['password'] . "\n", 30);

    try {
        foreach ($users as $user) {
            $asRoot(AuthorizedKeyCommand::script($user, $key));
            $asRoot(AuthorizedKeyCommand::script($user, $key));

            expect($transport->exitStatus())->toBe(0)
                ->and($asRoot("cd ~{$user}/.ssh && stat -c '%U %a' . authorized_keys && grep -c " . escapeshellarg($blob) . ' authorized_keys'))
                ->toBe("{$user} 700\n{$user} 600\n1\n");
        }
    } finally {
        foreach ($users as $user) {
            $asRoot("sed -i '\\#{$blob}#d' ~{$user}/.ssh/authorized_keys");
        }

        $transport->disconnect();
    }
});

test('a real server is connected with a sudo user\'s password and then signs in as root with the key', function () {
    Queue::fake();
    $settings = livePasswordSettings();
    $server = Server::factory()->create([
        'ip_address' => $settings['host'],
        'ssh_port' => $settings['port'],
        'ssh_user' => $settings['user'],
        'host_key_fingerprint' => null,
        'status' => ServerStatus::Pending,
    ]);

    app(ServerConnector::class)->withPassword($server, $settings['user'], $settings['password']);

    $root = app(SSHConnectionManager::class)->fresh($server->refresh());

    try {
        expect($server->ssh_user)->toBe('root')
            ->and($server->last_connection_error)->toBeNull()
            ->and(trim($root->execute('whoami')->output))->toBe('root');

        Queue::assertPushed(AuditServer::class);
    } finally {
        $root->execute("sed -i '\\#" . explode(' ', $server->ssh_public_key)[1] . "#d' /root/.ssh/authorized_keys");
        $root->disconnect();
    }
});
