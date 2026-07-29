<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\SSH\Contracts\SSHTransport;
use phpseclib3\Crypt\Common\PrivateKey;

final class FakeSSHTransport implements SSHTransport
{
    private string $hostKey = 'fake-host-key';

    private bool $loginSucceeds = true;

    private bool $loginAttempted = false;

    private bool $connected = false;

    private int $exitStatus = 0;

    /** @var list<array{output: string, exitCode: int}> */
    private array $queued = [];

    /** @var array<string, array{output: string, exitCode: int}> */
    private array $matched = [];

    /** @var list<string> */
    private array $commands = [];

    /** @var array<string, string> */
    private array $uploads = [];

    public function setHostKey(string $hostKey): self
    {
        $this->hostKey = $hostKey;

        return $this;
    }

    public function failLogin(): self
    {
        $this->loginSucceeds = false;

        return $this;
    }

    public function queueResult(string $output, int $exitCode): self
    {
        $this->queued[] = ['output' => $output, 'exitCode' => $exitCode];

        return $this;
    }

    public function respondTo(string $needle, string $output, int $exitCode = 0): self
    {
        $this->matched[$needle] = ['output' => $output, 'exitCode' => $exitCode];

        return $this;
    }

    /** @return list<string> */
    public function commands(): array
    {
        return $this->commands;
    }

    /** @return array<string, string> */
    public function uploads(): array
    {
        return $this->uploads;
    }

    public function loginAttempted(): bool
    {
        return $this->loginAttempted;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function connect(string $host, int $port, int $timeout): void
    {
        $this->connected = true;
    }

    public function hostKey(): string
    {
        return $this->hostKey;
    }

    public function login(string $user, PrivateKey $key): bool
    {
        $this->loginAttempted = true;

        return $this->loginSucceeds;
    }

    public function exec(string $command, ?callable $onOutput = null): string
    {
        $this->commands[] = $command;

        $next = null;

        foreach ($this->matched as $needle => $response) {
            if (str_contains($command, $needle)) {
                $next = $response;

                break;
            }
        }

        $next ??= array_shift($this->queued) ?? ['output' => '', 'exitCode' => 0];
        $this->exitStatus = $next['exitCode'];

        if ($onOutput !== null) {
            $onOutput($next['output']);
        }

        return $next['output'];
    }

    public function exitStatus(): int
    {
        return $this->exitStatus;
    }

    public function uploadContent(string $content, string $remotePath): void
    {
        $this->uploads[$remotePath] = $content;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }
}
