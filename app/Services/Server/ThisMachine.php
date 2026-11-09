<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Exceptions\ThisMachineRefused;
use App\Models\Server;
use App\Services\Address\AtomicFile;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

final class ThisMachine
{
    public const INSTALLER = 'curl -fsSL https://get.coresmith.io/install | sudo bash';

    public const ANSWER_SECONDS = 30;

    public const REMOVE_SECONDS = 15;

    public const POLL_MILLISECONDS = 250;

    private const PINNABLE_KEYS = ['ED25519', 'RSA'];

    private const FINGERPRINT = '/^SHA256:[A-Za-z0-9+\/]{43}$/';

    public function installed(): bool
    {
        return is_dir($this->folder());
    }

    public function server(): ?Server
    {
        return Server::query()->where('runs_coresmith', true)->first();
    }

    public function add(Server $server): ThisMachineAccess
    {
        $answer = $this->ask('add ' . implode(' ', array_slice(explode(' ', trim((string) $server->ssh_public_key)), 0, 2)), self::ANSWER_SECONDS);
        $port = $answer['port'] ?? null;
        $keys = (array) ($answer['host_keys'] ?? []);
        $fingerprint = collect(self::PINNABLE_KEYS)->map(fn(string $kind): mixed => $keys[$kind] ?? null)->first(fn(mixed $fingerprint): bool => is_string($fingerprint) && preg_match(self::FINGERPRINT, $fingerprint) === 1);

        if (!is_int($port) || $port < 1 || $port > 65535 || !is_string($fingerprint)) {
            throw new ThisMachineRefused("Coresmith's helper answered without the machine's SSH port or a host key Coresmith can check. Run the installer again, then try again.");
        }

        return new ThisMachineAccess($port, $fingerprint);
    }

    public function remove(int $seconds = self::ANSWER_SECONDS): void
    {
        $this->ask('remove', $seconds);
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(string $what, int $seconds): array
    {
        $id = Str::random(32);

        try {
            AtomicFile::write($this->folder() . '/request', "{$id} {$what}\n", 0640, "Coresmith couldn't ask its helper for access to this machine");
        } catch (RuntimeException $unwritten) {
            throw new ThisMachineRefused($unwritten->getMessage() . '. Run the installer again, then try again.');
        }

        $deadline = now()->addSeconds($seconds);

        while (now()->lessThan($deadline)) {
            Sleep::for(self::POLL_MILLISECONDS)->milliseconds();

            $answer = $this->answer($id);

            if ($answer === null) {
                continue;
            }

            if (($answer['ok'] ?? false) !== true) {
                throw new ThisMachineRefused(is_string($answer['error'] ?? null) ? $answer['error'] : "Coresmith's helper refused without saying why. Check it with journalctl -u coresmith-this-machine.");
            }

            return $answer;
        }

        throw new ThisMachineRefused("Coresmith's helper didn't answer within {$seconds} seconds. Check it with journalctl -u coresmith-this-machine, then try again.");
    }

    /**
     * @return array<string, mixed>|null
     */
    private function answer(string $id): ?array
    {
        $answer = json_decode((string) @file_get_contents($this->folder() . '/result.json'), true);

        return is_array($answer) && ($answer['id'] ?? null) === $id ? $answer : null;
    }

    private function folder(): string
    {
        return rtrim((string) config('coresmith.this_machine.state_path'), '/');
    }
}
