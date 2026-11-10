<?php

declare(strict_types=1);

use App\Exceptions\UnsupportedOperatingSystemException;
use App\Models\Server;
use App\Services\Server\OsDetector;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function detectorTransport(string $osRelease, string $systemd = "systemd 255 (255.4-1ubuntu8)\n+PAM +AUDIT +SELINUX"): FakeSSHTransport
{
    return (new FakeSSHTransport)
        ->queueResult($osRelease, 0)
        ->queueResult($systemd, 0)
        ->queueResult("8\n", 0)
        ->queueResult("              total        used        free\nMem:          16040        2000       14040\n", 0)
        ->queueResult("Filesystem     1G-blocks  Used Available Use% Mounted on\n/dev/vda1           80G   20G       60G  25% /\n", 0);
}

function detectorConnection(FakeSSHTransport $transport): SSHConnection
{
    $server = Server::factory()->create(['host_key_fingerprint' => null]);

    return (new SSHConnection($server, $transport))->connect();
}

test('detects ubuntu with hardware details', function () {
    $info = (new OsDetector)->detect(detectorConnection(detectorTransport("ID=ubuntu\nVERSION_ID=\"24.04\"\n")));

    expect($info->name)->toBe('ubuntu')
        ->and($info->version)->toBe('24.04')
        ->and($info->cpuCores)->toBe(8)
        ->and($info->memoryMb)->toBe(16040)
        ->and($info->diskGb)->toBe(80);
});

test('strips quotes from version id', function () {
    expect((new OsDetector)->detect(detectorConnection(detectorTransport("ID=debian\nVERSION_ID=\"12\"\n")))->version)
        ->toBe('12');
});

test('lowercases the distribution id', function () {
    expect((new OsDetector)->detect(detectorConnection(detectorTransport("ID=Ubuntu\nVERSION_ID=24.04\n")))->name)
        ->toBe('ubuntu');
});

test('accepts every supported distribution', function (string $id) {
    expect((new OsDetector)->detect(detectorConnection(detectorTransport("ID={$id}\nVERSION_ID=\"1\"\n")))->name)
        ->toBe($id);
})->with(['ubuntu', 'debian', 'amzn', 'rocky', 'almalinux', 'fedora']);

test('rejects an unsupported distribution', function () {
    (new OsDetector)->detect(detectorConnection(detectorTransport("ID=gentoo\nVERSION_ID=\"2\"\n")));
})->throws(UnsupportedOperatingSystemException::class);

test('rejects a server without systemd', function () {
    $transport = (new FakeSSHTransport)
        ->queueResult("ID=ubuntu\nVERSION_ID=\"24.04\"\n", 0)
        ->queueResult('', 127);

    (new OsDetector)->detect(detectorConnection($transport));
})->throws(UnsupportedOperatingSystemException::class);

test('rejects an unreadable os-release', function () {
    (new OsDetector)->detect(detectorConnection((new FakeSSHTransport)->queueResult('', 1)));
})->throws(UnsupportedOperatingSystemException::class);

test('the detected info converts to the server meta shape', function () {
    $info = (new OsDetector)->detect(detectorConnection(detectorTransport("ID=ubuntu\nVERSION_ID=\"24.04\"\n")));

    expect($info->toMeta())->toBe(['cpu_cores' => 8, 'memory_mb' => 16040, 'disk_gb' => 80, 'systemd' => 255]);
});

test('records the systemd version the server runs, which decides how far apps on Coresmith\'s machine can be sandboxed', function (string $output, ?int $version) {
    $info = (new OsDetector)->detect(detectorConnection(detectorTransport("ID=ubuntu\nVERSION_ID=\"24.04\"\n", $output)));

    expect($info->systemd)->toBe($version);
})->with([
    'ubuntu 24.04' => ["systemd 255 (255.4-1ubuntu8)\n+PAM +AUDIT", 255],
    'rocky 8' => ["systemd 239 (239-82.el8)\n+PAM +AUDIT", 239],
    'amazon linux 2' => ["systemd 219\n +PAM +AUDIT", 219],
    'an answer it can\'t read' => ['something else entirely', null],
]);
