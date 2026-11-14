<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\SSH;

use App\Exceptions\HostKeyChangedException;
use App\Exceptions\ServerHasNoAddress;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandTimedOut;
use App\Exceptions\SSHPasswordRefused;
use App\Models\Server;
use App\Services\Deployment\LogSanitizer;
use App\Services\SSH\Contracts\SSHTransport;
use Closure;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\BadDecryptionException;
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
        private readonly int $timeout = self::TIMEOUT_SECONDS,
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

        $this->open();

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

    public function signInWithPassword(string $user, #[\SensitiveParameter] string $password): static
    {
        $this->open();

        if (!$this->transport->loginWithPassword($user, $password)) {
            throw SSHPasswordRefused::forUser((string) $this->server->ip_address, $user, $this->transport->acceptsPasswords());
        }

        $this->authenticated = true;

        return $this;
    }

    public function execute(string $command, bool $sudo = false, int $timeout = SSHTransport::COMMAND_SECONDS): SSHResult
    {
        return $this->run($this->prepare($command, $sudo), $timeout);
    }

    public function stream(string $command, callable $onOutput, bool $sudo = false, int $timeout = SSHTransport::COMMAND_SECONDS): SSHResult
    {
        return $this->run($this->prepare($command, $sudo), $timeout, $onOutput);
    }

    public function executeWithInput(string $command, #[\SensitiveParameter] string $input, int $timeout = SSHTransport::COMMAND_SECONDS, bool $sudo = false): SSHResult
    {
        return $this->run($this->prepare($command, $sudo), $timeout, input: $input);
    }

    public function uploadContent(#[\SensitiveParameter] string $content, string $remotePath, int $timeout = SSHTransport::COMMAND_SECONDS): void
    {
        if ($this->server->ssh_user === 'root') {
            $this->guarded(fn() => $this->connect()->transport->uploadContent($content, $remotePath));

            return;
        }

        $staged = trim($this->execute('mktemp ' . self::STAGING_TEMPLATE, timeout: $timeout)->throw()->output);

        if (preg_match(self::STAGED_PATTERN, $staged) !== 1) {
            throw new RuntimeException("Could not stage the upload for [{$remotePath}] on {$this->server->name}.");
        }

        try {
            $this->guarded(fn() => $this->connect()->transport->uploadContent($content, $staged));

            $source = escapeshellarg($staged);
            $target = escapeshellarg($remotePath);

            $this->execute("if [ -e {$target} ]; then cat {$source} > {$target}; else install -m 644 {$source} {$target}; fi", sudo: true, timeout: $timeout)->throw();
        } finally {
            $this->execute('rm -f ' . escapeshellarg($staged), timeout: $timeout);
        }
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $content = @file_get_contents($localPath);

        if ($content === false) {
            throw new RuntimeException("Coresmith couldn't read the local file [{$localPath}].");
        }

        $this->uploadContent($content, $remotePath);
    }

    public function disconnect(): void
    {
        $this->transport->disconnect();
        $this->authenticated = false;
    }

    private function open(): void
    {
        if ($this->server->ip_address === null) {
            throw ServerHasNoAddress::forServer($this->server);
        }

        $this->transport->connect($this->server->ip_address, $this->server->ssh_port, $this->timeout);

        $this->verifyHostKey();
    }

    private function run(string $command, int $timeout, ?callable $onOutput = null, #[\SensitiveParameter] ?string $input = null): SSHResult
    {
        $output = $this->guarded(function () use ($command, $timeout, $onOutput, $input): string {
            $transport = $this->connect()->transport;

            return $input === null
                ? $transport->exec($command, $onOutput, $timeout)
                : $transport->execWithInput($command, $input, $timeout);
        });

        return $this->conclude($command, $output, $timeout);
    }

    private function guarded(Closure $work): mixed
    {
        try {
            return $work();
        } catch (ConnectionClosedException $e) {
            $this->authenticated = false;

            throw $e;
        } catch (BadDecryptionException $e) {
            $this->authenticated = false;
            $this->transport->disconnect();

            throw new ConnectionClosedException("The SSH connection to {$this->server->name} broke: a packet from the server failed its integrity check.", 0, $e);
        }
    }

    private function verifyHostKey(): void
    {
        $hostKey = $this->transport->hostKey();
        $fingerprint = HostKeyFingerprint::fromPublicKey($hostKey)
            ?? throw new ConnectionClosedException("{$this->server->ip_address} did not present a host key Coresmith could read.");
        $known = $this->server->host_key_fingerprint;

        if ($known !== null && hash_equals($known, $fingerprint)) {
            return;
        }

        if ($known === null || $known === '' || hash_equals($known, HostKeyFingerprint::legacy($hostKey))) {
            $this->server->forceFill(['host_key_fingerprint' => $fingerprint])->save();

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
