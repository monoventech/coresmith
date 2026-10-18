<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

use App\Services\SSH\Contracts\SSHTransport;
use LogicException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use ReflectionProperty;

final class Phpseclib3Transport implements SSHTransport
{
    public const ALGORITHMS = [
        'kex' => ['curve25519-sha256', 'curve25519-sha256@libssh.org'],
        'hostkey' => ['ssh-ed25519', 'rsa-sha2-512', 'rsa-sha2-256'],
        'client_to_server' => [
            'crypt' => ['chacha20-poly1305@openssh.com', 'aes256-gcm@openssh.com', 'aes128-gcm@openssh.com'],
            'mac' => ['hmac-sha2-512-etm@openssh.com', 'hmac-sha2-256-etm@openssh.com'],
            'comp' => ['none'],
        ],
        'server_to_client' => [
            'crypt' => ['chacha20-poly1305@openssh.com', 'aes256-gcm@openssh.com', 'aes128-gcm@openssh.com'],
            'mac' => ['hmac-sha2-512-etm@openssh.com', 'hmac-sha2-256-etm@openssh.com'],
            'comp' => ['none'],
        ],
    ];

    private ?SSH2 $ssh = null;

    private ?SFTP $sftp = null;

    private string $host = '';

    private int $port = 22;

    private int $timeout = 30;

    private string $user = '';

    private ?PrivateKey $key = null;

    public function connect(string $host, int $port, int $timeout): void
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;

        $this->ssh = new SSH2($host, $port);
        $this->ssh->setPreferredAlgorithms(self::ALGORITHMS);
        $this->ssh->setTimeout($timeout);
    }

    public function negotiatedAlgorithms(): array
    {
        return $this->connection()->getAlgorithmsNegotiated();
    }

    public function hostKey(): string
    {
        return (string) $this->connection()->getServerPublicHostKey();
    }

    public function login(string $user, PrivateKey $key): bool
    {
        $this->user = $user;
        $this->key = $key;

        return $this->connection()->login($user, $key);
    }

    public function exec(string $command, ?callable $onOutput = null, int $timeout = self::COMMAND_SECONDS): string
    {
        $ssh = $this->connection();
        $ssh->setTimeout($timeout);
        self::forgetExitStatus($ssh);

        if ($onOutput === null) {
            return (string) $ssh->exec($command);
        }

        $output = '';

        $ssh->exec($command, function (string $chunk) use ($onOutput, &$output): void {
            $output .= $chunk;
            $onOutput($chunk);
        });

        return $output;
    }

    public function timedOut(): bool
    {
        return $this->ssh !== null && $this->ssh->isTimeout();
    }

    public function exitStatus(): int
    {
        $status = $this->connection()->getExitStatus();

        return $status === false ? -1 : (int) $status;
    }

    public static function forgetExitStatus(SSH2 $ssh): void
    {
        (new ReflectionProperty(SSH2::class, 'exit_status'))->setValue($ssh, null);
    }

    public function uploadContent(string $content, string $remotePath): void
    {
        $this->sftp()->put($remotePath, $content);
    }

    public function disconnect(): void
    {
        $this->ssh?->disconnect();
        $this->sftp?->disconnect();

        $this->ssh = null;
        $this->sftp = null;
    }

    private function connection(): SSH2
    {
        if ($this->ssh === null) {
            throw new LogicException('SSH transport is not connected.');
        }

        return $this->ssh;
    }

    private function sftp(): SFTP
    {
        if ($this->key === null) {
            throw new LogicException('SSH transport is not authenticated.');
        }

        if ($this->sftp === null) {
            $this->sftp = new SFTP($this->host, $this->port);
            $this->sftp->setPreferredAlgorithms(self::ALGORITHMS);
            $this->sftp->setTimeout($this->timeout);
            $this->sftp->login($this->user, $this->key);
        }

        return $this->sftp;
    }
}
