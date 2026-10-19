<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandTimedOut;
use App\Models\Server;
use App\Services\Deployment\LogSanitizer;
use App\Services\SSH\Contracts\SSHTransport;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\ConnectionClosedException;
use RuntimeException;

final class SSHConnection
{
    public const TIMEOUT_SECONDS = 30;

    public const COMMAND_ACTIVITY = 'ssh_command_executed';

    public const STAGING_TEMPLATE = '/tmp/coresmith-upload.XXXXXXXXXX';

    private const STAGED_PATTERN = '~^/tmp/coresmith-upload\.[A-Za-z0-9]{10}\z~';

    private const SHELL_SYNTAX = '/[|&;<>()$`\n]/';

    private const OWN_SHELL = '/^(?:ba)?sh -c /';

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

    public function execute(string $command, bool $sudo = false, int $timeout = SSHTransport::COMMAND_SECONDS): SSHResult
    {
        $command = $this->prepare($command, $sudo);

        try {
            $output = $this->connect()->transport->exec($command, null, $timeout);
        } catch (ConnectionClosedException $e) {
            $this->authenticated = false;

            throw $e;
        }

        return $this->conclude($command, $output, $timeout);
    }

    public function stream(string $command, callable $onOutput, bool $sudo = false, int $timeout = SSHTransport::COMMAND_SECONDS): SSHResult
    {
        $command = $this->prepare($command, $sudo);

        try {
            $output = $this->connect()->transport->exec($command, $onOutput, $timeout);
        } catch (ConnectionClosedException $e) {
            $this->authenticated = false;

            throw $e;
        }

        return $this->conclude($command, $output, $timeout);
    }

    public function uploadContent(#[\SensitiveParameter] string $content, string $remotePath): void
    {
        if ($this->server->ssh_user === 'root') {
            $this->connect()->transport->uploadContent($content, $remotePath);

            return;
        }

        $staged = trim($this->execute('mktemp ' . self::STAGING_TEMPLATE)->throw()->output);

        if (preg_match(self::STAGED_PATTERN, $staged) !== 1) {
            throw new RuntimeException("Could not stage the upload for [{$remotePath}] on {$this->server->name}.");
        }

        try {
            $this->connect()->transport->uploadContent($content, $staged);

            $source = escapeshellarg($staged);
            $target = escapeshellarg($remotePath);

            $this->execute("if [ -e {$target} ]; then cat {$source} > {$target}; else install -m 644 {$source} {$target}; fi", sudo: true)->throw();
        } finally {
            $this->execute('rm -f ' . escapeshellarg($staged));
        }
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

        $this->server->forceFill([
            'pending_host_key_fingerprint' => $fingerprint,
            'host_key_changed_at' => now(),
        ])->save();

        activity()
            ->performedOn($this->server)
            ->withProperties(['expected' => $known, 'actual' => $fingerprint])
            ->log('ssh_host_key_changed');

        throw HostKeyChangedException::forServer($this->server->ip_address, $known, $fingerprint);
    }

    private function prepare(string $command, bool $sudo): string
    {
        if (!$sudo || $this->server->ssh_user === 'root') {
            return $command;
        }

        if (preg_match(self::OWN_SHELL, $command) === 1 || preg_match(self::SHELL_SYNTAX, $command) !== 1) {
            return 'sudo ' . $command;
        }

        return 'sudo bash -c ' . escapeshellarg($command);
    }

    private function conclude(string $command, string $output, int $timeout): SSHResult
    {
        if ($this->transport->timedOut()) {
            $this->record($command, $output, -1);
            $this->disconnect();

            throw new SSHCommandTimedOut(SSHCommandTimedOut::display(app(LogSanitizer::class)->sanitize($command)), $timeout, $output);
        }

        return $this->record($command, $output, $this->transport->exitStatus());
    }

    private function record(string $command, string $output, int $exitCode): SSHResult
    {
        activity()
            ->performedOn($this->server)
            ->withProperties([
                'command' => app(LogSanitizer::class)->sanitize($command),
                'exit_code' => $exitCode,
                'output_length' => strlen($output),
            ])
            ->log(self::COMMAND_ACTIVITY);

        return new SSHResult($output, $exitCode);
    }
}
