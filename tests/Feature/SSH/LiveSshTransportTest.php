<?php

declare(strict_types=1);

use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\Phpseclib3Transport;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;

function liveSshSettings(): ?array
{
    $host = (string) getenv('CORESMITH_LIVE_SSH_HOST');
    $user = (string) getenv('CORESMITH_LIVE_SSH_USER');
    $key = (string) getenv('CORESMITH_LIVE_SSH_KEY');

    if ($host === '' || $user === '' || $key === '' || !is_readable($key)) {
        return null;
    }

    return [
        'host' => $host,
        'port' => (int) (getenv('CORESMITH_LIVE_SSH_PORT') ?: 22),
        'user' => $user,
        'key' => (string) file_get_contents($key),
    ];
}

function liveSshTransport(): Phpseclib3Transport
{
    $settings = liveSshSettings();
    $transport = new Phpseclib3Transport;
    $transport->connect($settings['host'], $settings['port'], 15);

    expect($transport->login($settings['user'], PublicKeyLoader::load($settings['key'])))->toBeTrue();

    return $transport;
}

beforeEach(function () {
    if (liveSshSettings() === null) {
        $this->markTestSkipped('No live server configured. Set CORESMITH_LIVE_SSH_HOST, CORESMITH_LIVE_SSH_USER and CORESMITH_LIVE_SSH_KEY (and optionally CORESMITH_LIVE_SSH_PORT) to run these checks against a real machine.');
    }
});

test('a real server negotiates only the algorithms coresmith pins', function () {
    $transport = liveSshTransport();
    $negotiated = $transport->negotiatedAlgorithms();
    $pinned = Phpseclib3Transport::ALGORITHMS;

    expect($pinned['kex'])->toContain($negotiated['kex'])
        ->and($pinned['hostkey'])->toContain($negotiated['hostkey'])
        ->and($pinned['client_to_server']['crypt'])->toContain($negotiated['client_to_server']['crypt'])
        ->and($pinned['server_to_client']['crypt'])->toContain($negotiated['server_to_client']['crypt']);

    $transport->disconnect();
});

test('a real server presents the same host key fingerprint on every connection', function () {
    $first = liveSshTransport();
    $fingerprint = HostKeyFingerprint::fromHostKey($first->hostKey());
    $first->disconnect();

    $second = liveSshTransport();

    expect($fingerprint)->toStartWith('SHA256:')
        ->and(HostKeyFingerprint::fromHostKey($second->hostKey()))->toBe($fingerprint);

    $second->disconnect();
});

test('a command on a real server returns its output and exit status', function () {
    $transport = liveSshTransport();

    expect($transport->exec('printf hello'))->toBe('hello')
        ->and($transport->exitStatus())->toBe(0);

    $transport->exec('exit 3');
    expect($transport->exitStatus())->toBe(3);

    $transport->exec('true');
    expect($transport->exitStatus())->toBe(0);

    $transport->disconnect();
});

test('a real server sends standard error back alongside standard output', function () {
    $transport = liveSshTransport();
    $output = $transport->exec('echo out; echo err >&2');

    expect($output)->toContain('out')
        ->and($output)->toContain('err');

    $transport->disconnect();
});

test('streamed output from a real server adds up to the whole output', function () {
    $transport = liveSshTransport();
    $chunks = [];

    $output = $transport->exec('for i in 1 2 3; do echo line$i; sleep 0.2; done', function (string $chunk) use (&$chunks): void {
        $chunks[] = $chunk;
    });

    expect($output)->toBe("line1\nline2\nline3\n")
        ->and(implode('', $chunks))->toBe($output)
        ->and($transport->exitStatus())->toBe(0);

    $transport->disconnect();
});

test('a command that outlives its timeout on a real server is reported as timed out', function () {
    $transport = liveSshTransport();
    $transport->exec('sleep 5', null, 2);

    expect($transport->timedOut())->toBeTrue();

    $transport->disconnect();
});

test('large output from a real server arrives whole', function () {
    $transport = liveSshTransport();
    $output = $transport->exec("head -c 1048576 /dev/zero | tr '\\0' x");

    expect(strlen($output))->toBe(1048576);

    $transport->disconnect();
});

test('content uploaded to a real server lands byte for byte', function () {
    $transport = liveSshTransport();
    $path = '/tmp/coresmith-live-' . Str::lower(Str::random(12));
    $content = "first line\nüñíçødé line\n" . str_repeat('x', 70000) . "\n";

    $transport->uploadContent($content, $path);

    expect($transport->exec('cat ' . escapeshellarg($path)))->toBe($content);

    $transport->exec('rm -f ' . escapeshellarg($path));
    $transport->disconnect();
});
