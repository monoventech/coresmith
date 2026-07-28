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

final class Phpseclib3Transport implements SSHTransport
{
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
        $this->ssh->setTimeout($timeout);
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

    public function exec(string $command, ?callable $onOutput = null): string
    {
        if ($onOutput === null) {
            return (string) $this->connection()->exec($command);
        }

        $output = '';

        $this->connection()->exec($command, function (string $chunk) use ($onOutput, &$output): void {
            $output .= $chunk;
            $onOutput($chunk);
        });

        return $output;
    }

    public function exitStatus(): int
    {
        return (int) $this->connection()->getExitStatus();
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
            $this->sftp->setTimeout($this->timeout);
            $this->sftp->login($this->user, $this->key);
        }

        return $this->sftp;
    }
}
