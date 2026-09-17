<?php

declare(strict_types=1);

use App\Services\Server\PortScanner;

test('listening sockets become ports and the process holding each', function () {
    $output = implode("\n", [
        'LISTEN 0      511          0.0.0.0:80        0.0.0.0:*    users:(("nginx",pid=812,fd=6),("nginx",pid=811,fd=6))',
        'LISTEN 0      511             [::]:443          [::]:*    users:(("nginx",pid=812,fd=7))',
        'LISTEN 0      4096       127.0.0.1:8080      0.0.0.0:*    users:(("node",pid=1200,fd=21))',
        'LISTEN 0      128                *:9000            *:*',
        'LISTEN 0      80    [::ffff:127.0.0.1]:3306      *:*    users:(("mariadbd",pid=90,fd=20))',
        '',
    ]);

    expect((new PortScanner)->parse($output))->toBe([
        80 => 'nginx',
        443 => 'nginx',
        3306 => 'mariadbd',
        8080 => 'node',
        9000 => 'an unknown process',
    ]);
});

test('a scan that produced no usable lines parses to nothing', function (string $output) {
    expect((new PortScanner)->parse($output))->toBe([]);
})->with([
    'empty' => '',
    'whitespace' => "\n\n  \n",
    'ss is missing' => "bash: ss: command not found\n",
    'a header line only' => "State  Recv-Q Send-Q Local Address:Port Peer Address:Port Process\n",
    'a truncated line' => "LISTEN 0      511\n",
]);

test('a header above real lines is skipped and a truncated last line is dropped', function () {
    $output = implode("\n", [
        'State  Recv-Q Send-Q Local Address:Port Peer Address:Port Process',
        'LISTEN 0      511          0.0.0.0:80        0.0.0.0:*    users:(("nginx",pid=812,fd=6))',
        'LISTEN 0      4096       127.0.0.1:80',
    ]);

    expect((new PortScanner)->parse($output))->toBe([80 => 'nginx']);
});
