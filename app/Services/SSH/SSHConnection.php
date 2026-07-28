<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHAuthenticationException;
use App\Models\Server;
use App\Services\SSH\Contracts\SSHTransport;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;

final class SSHConnection
{
    public const TIMEOUT_SECONDS = 30;

    private bool $authenticated = false;

    public function __construct(
        private readonly Server $server,
        private readonly SSHTransport $transport,
    ) {}

    public function server(): Server
    {
        return $this->server;
    }

    public function connect(): static
    {
        if ($this->authenticated) {
            return $this;
        }

        $this->transport->connect($this->server->ip_address, $this->server->ssh_port, self::TIMEOUT_SECONDS);

        $this->verifyHostKey();

        $key = PublicKeyLoader::load($this->server->ssh_private_key);

        if (!$key instanceof PrivateKey) {
            throw SSHAuthenticationException::notAPrivateKey($this->server->ip_address);
        }

        if (!$this->transport->login($this->server->ssh_user, $key)) {
            throw SSHAuthenticationException::forServer($this->server->ip_address, $this->server->ssh_user);
        }

        $this->authenticated = true;

        return $this;
    }

    public function execute(string $command, bool $sudo = false): SSHResult
    {
        $command = $this->prepare($command, $sudo);

        $output = $this->connect()->transport->exec($command);

        return $this->record($command, $output);
    }

    public function stream(string $command, callable $onOutput, bool $sudo = false): SSHResult
    {
        $command = $this->prepare($command, $sudo);

        $output = $this->connect()->transport->exec($command, $onOutput);

        return $this->record($command, $output);
    }

    public function uploadContent(string $content, string $remotePath): void
    {
        $this->connect()->transport->uploadContent($content, $remotePath);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $content = @file_get_contents($localPath);

        if ($content === false) {
            throw new RuntimeException("Unable to read local file [{$localPath}].");
        }

        $this->uploadContent($content, $remotePath);
    }

    public function disconnect(): void
    {
        $this->transport->disconnect();
        $this->authenticated = false;
    }

    private function verifyHostKey(): void
    {
        $fingerprint = HostKeyFingerprint::fromHostKey($this->transport->hostKey());
        $known = $this->server->host_key_fingerprint;

        if ($known === null || $known === '') {
            $this->server->forceFill(['host_key_fingerprint' => $fingerprint])->save();

            return;
        }

        if (hash_equals($known, $fingerprint)) {
            return;
        }

        activity()
            ->performedOn($this->server)
            ->withProperties(['expected' => $known, 'actual' => $fingerprint])
            ->log('ssh_host_key_changed');

        throw HostKeyChangedException::forServer($this->server->ip_address, $known, $fingerprint);
    }

    private function prepare(string $command, bool $sudo): string
    {
        return $sudo && $this->server->ssh_user !== 'root' ? "sudo {$command}" : $command;
    }

    private function record(string $command, string $output): SSHResult
    {
        $exitCode = $this->transport->exitStatus();

        activity()
            ->performedOn($this->server)
            ->withProperties([
                'command' => $command,
                'exit_code' => $exitCode,
                'output_length' => strlen($output),
            ])
            ->log('ssh_command_executed');

        return new SSHResult($output, $exitCode);
    }
}
