<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Runtime\Catalog;
use App\Services\SSH\Contracts\SSHTransport;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\SSHConnection;
use Closure;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\UnableToConnectException;

final class FakeSSHTransport implements SSHTransport
{
    public const STAGED_UPLOAD = '/tmp/coresmith-upload.Fake000001';

    public const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4';

    private string $hostKey = self::HOST_KEY;

    private int $loginFailures = 0;

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

    /** @var array<string, Closure> */
    private array $hooks = [];

    /** @var list<string> */
    private array $loginKeys = [];

    private ?string $accepted = null;

    /** @var array{user: string, password: string}|null */
    private ?array $password = null;

    private bool $passwordsAllowed = true;

    /** @var list<string> */
    private array $passwordLogins = [];

    /** @var array<int, string> */
    private array $inputs = [];

    public static function keyNamed(string $label): string
    {
        return 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . hash('sha256', $label, true));
    }

    public static function fingerprintOf(string $label): string
    {
        return (string) HostKeyFingerprint::fromPublicKey(self::keyNamed($label));
    }

    public function setHostKey(string $hostKey): self
    {
        $this->hostKey = $hostKey;

        return $this;
    }

    public function failLogin(int $times = PHP_INT_MAX): self
    {
        $this->loginFailures = $times;

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

    public function onlyAccept(string $publicKey): self
    {
        $this->accepted = self::keyBlob($publicKey);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function loginKeys(): array
    {
        return $this->loginKeys;
    }

    public static function keyBlob(string $publicKey): string
    {
        return explode(' ', trim($publicKey))[1] ?? '';
    }

    public function login(string $user, PrivateKey $key): bool
    {
        $this->loginAttempted = true;
        $offered = $key->getPublicKey()->toString('OpenSSH');
        $this->loginKeys[] = $offered;

        if ($this->accepted !== null && self::keyBlob($offered) !== $this->accepted) {
            return false;
        }

        if ($this->loginFailures > 0) {
            $this->loginFailures--;

            return false;
        }

        return true;
    }

    public function acceptPassword(string $user, string $password): self
    {
        $this->password = ['user' => $user, 'password' => $password];

        return $this;
    }

    public function refusePasswords(): self
    {
        $this->passwordsAllowed = false;

        return $this;
    }

    /** @return list<string> */
    public function passwordLogins(): array
    {
        return $this->passwordLogins;
    }

    public function loginWithPassword(string $user, #[\SensitiveParameter] string $password): bool
    {
        $this->loginAttempted = true;
        $this->passwordLogins[] = $user;

        return $this->passwordsAllowed && $this->password === ['user' => $user, 'password' => $password];
    }

    public function acceptsPasswords(): bool
    {
        return $this->passwordsAllowed;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function sent(): array
    {
        return array_values(array_map(fn(int $index): array => [$this->commands[$index], $this->inputs[$index]], array_keys($this->inputs)));
    }

    public function inputFor(string $needle): ?string
    {
        foreach ($this->commands as $index => $command) {
            if (str_contains($command, $needle)) {
                return $this->inputs[$index] ?? null;
            }
        }

        return null;
    }

    public function execWithInput(string $command, #[\SensitiveParameter] string $input, int $timeout = self::COMMAND_SECONDS): string
    {
        $this->inputs[count($this->commands)] = $input;

        return $this->exec($command, null, $timeout);
    }

    public function exec(string $command, ?callable $onOutput = null, int $timeout = self::COMMAND_SECONDS): string
    {
        $this->commands[] = $command;
        $this->budgets[] = $timeout;
        $this->timedOut = false;

        $this->runHooks($command);

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

    public function onCommand(string $needle, Closure $callback): self
    {
        $this->hooks[$needle] = $callback;

        return $this;
    }

    private function runHooks(string $command): void
    {
        foreach ($this->hooks as $needle => $callback) {
            if (str_contains($command, $needle)) {
                unset($this->hooks[$needle]);
                $callback();
            }
        }
    }
}
