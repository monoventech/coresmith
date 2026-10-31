<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Runtime\Catalog;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\SSHConnection;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\UnableToConnectException;

final class FakeSSHTransport implements SSHTransport
{
    public const STAGED_UPLOAD = '/tmp/coresmith-upload.Fake000001';

    private string $hostKey = 'fake-host-key';

    private bool $loginSucceeds = true;

    private int $refusals = 0;

    private string $refusal = '';

    private bool $loginAttempted = false;

    private bool $connected = false;

    private int $exitStatus = 0;

    /** @var list<array{output: string, exitCode: int}> */
    private array $queued = [];

    /** @var array<string, array{output: string, exitCode: int}> */
    private array $matched = [];

    /** @var array<string, list<array{output: string, exitCode: int}>> */
    private array $sequences = [];

    /** @var list<string> */
    private array $commands = [];

    /** @var array<string, string> */
    private array $uploads = [];

    /** @var array<string, int> */
    private array $drops = [];

    /** @var array<string, string> */
    private array $timeouts = [];

    /** @var list<int> */
    private array $budgets = [];

    private bool $timedOut = false;

    /** @var list<string> */
    private array $calls = [];

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

    public function refuseConnections(int $times = PHP_INT_MAX, string $message = 'Cannot connect to 192.0.2.10:22. Error 111. Connection refused'): self
    {
        $this->refusals = $times;
        $this->refusal = $message;

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

    /**
     * @param  list<array{0: string, 1?: int}>  $responses
     */
    public function respondToSequence(string $needle, array $responses): self
    {
        $this->sequences[$needle] = array_map(
            fn(array $response): array => ['output' => (string) $response[0], 'exitCode' => (int) ($response[1] ?? 0)],
            $responses,
        );

        return $this;
    }

    public function dropConnectionOn(string $needle, int $times = 1): self
    {
        $this->drops[$needle] = $times;

        return $this;
    }

    public function timeOutOn(string $needle, string $partialOutput = ''): self
    {
        $this->timeouts[$needle] = $partialOutput;

        return $this;
    }

    public function budgetFor(string $needle): ?int
    {
        foreach ($this->commands as $index => $command) {
            if (str_contains($command, $needle)) {
                return $this->budgets[$index] ?? null;
            }
        }

        return null;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
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

    /** @return list<string> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function connect(string $host, int $port, int $timeout): void
    {
        $this->calls[] = 'connect';
        $this->connected = true;
    }

    public function hostKey(): string
    {
        if ($this->refusals > 0) {
            $this->refusals--;
            $this->connected = false;

            throw new UnableToConnectException($this->refusal);
        }

        return $this->hostKey;
    }

    public function login(string $user, PrivateKey $key): bool
    {
        $this->loginAttempted = true;

        return $this->loginSucceeds;
    }

    public function exec(string $command, ?callable $onOutput = null, int $timeout = self::COMMAND_SECONDS): string
    {
        $this->commands[] = $command;
        $this->budgets[] = $timeout;
        $this->timedOut = false;

        foreach ($this->drops as $needle => $remaining) {
            if ($remaining > 0 && str_contains($command, $needle)) {
                $this->drops[$needle] = $remaining - 1;
                $this->connected = false;

                throw new ConnectionClosedException('Connection closed by server');
            }
        }

        foreach ($this->timeouts as $needle => $partialOutput) {
            if (str_contains($command, $needle)) {
                $this->timedOut = true;

                if ($onOutput !== null) {
                    $onOutput($partialOutput);
                }

                return $partialOutput;
            }
        }

        $next = null;

        foreach ($this->sequences as $needle => $responses) {
            if (str_contains($command, $needle)) {
                $next = count($responses) > 1 ? array_shift($this->sequences[$needle]) : $responses[0];

                break;
            }
        }

        if ($next === null) {
            foreach ($this->matched as $needle => $response) {
                if (str_contains($command, $needle)) {
                    $next = $response;

                    break;
                }
            }
        }

        $next ??= array_shift($this->queued) ?? $this->healthyServer($command) ?? ['output' => '', 'exitCode' => 0];
        $this->exitStatus = $next['exitCode'];

        if ($onOutput !== null) {
            $onOutput($next['output']);
        }

        return $next['output'];
    }

    /**
     * @return array{output: string, exitCode: int}|null
     */
    private function healthyServer(string $command): ?array
    {
        if ($command === 'mktemp ' . SSHConnection::STAGING_TEMPLATE) {
            return ['output' => self::STAGED_UPLOAD . "\n", 'exitCode' => 0];
        }

        if (str_contains($command, "systemctl is-active 'coresmith-app-")) {
            return ['output' => "active\n", 'exitCode' => 0];
        }

        if (str_contains($command, "NRestarts --value 'coresmith-app-")) {
            return ['output' => "0\n", 'exitCode' => 0];
        }

        if (preg_match("~^ls -1 '/opt/asdf-data/installs/([a-z]+)'~", $command, $matches) === 1) {
            return ['output' => implode("\n", app(Catalog::class)->versions($matches[1])) . "\n", 'exitCode' => 0];
        }

        return null;
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
        $this->calls[] = 'disconnect';
        $this->connected = false;
    }
}
