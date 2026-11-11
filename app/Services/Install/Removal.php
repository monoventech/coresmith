<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Install;

use App\Exceptions\RemovalRefused;
use App\Services\Address\AtomicFile;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;

final class Removal
{
    public const ANSWER_SECONDS = 10;

    public const POLL_MILLISECONDS = 250;

    public function installed(): bool
    {
        return @is_dir($this->folder());
    }

    public function started(): bool
    {
        return @is_file($this->folder() . '/request') || $this->status()['stage'] !== null;
    }

    public function ping(int $seconds = self::ANSWER_SECONDS): void
    {
        $id = Str::random(32);
        $this->request($id, 'ping');
        $deadline = now()->addSeconds($seconds);

        while (now()->lessThan($deadline)) {
            Sleep::for(self::POLL_MILLISECONDS)->milliseconds();

            $answer = json_decode((string) @file_get_contents($this->folder() . '/result.json'), true);

            if (is_array($answer) && ($answer['id'] ?? null) === $id) {
                return;
            }
        }

        @unlink($this->folder() . '/request');

        throw new RemovalRefused("Coresmith's removal helper didn't answer. Check it with journalctl -u coresmith-uninstall, then try again.");
    }

    public function start(): void
    {
        $this->request(Str::random(32), 'uninstall');
    }

    /**
     * @return array{stage: string|null, saved: string|null, removed: list<string>, failed: list<array{name: string, file: string, comment: string}>}
     */
    public function status(): array
    {
        $status = json_decode((string) @file_get_contents($this->folder() . '/status.json'), true);
        $released = json_decode((string) @file_get_contents($this->folder() . '/released.json'), true);
        $stage = is_array($status) && is_string($status['stage'] ?? null) ? $status['stage'] : null;
        $saved = is_array($status) && is_string($status['saved'] ?? null) && $status['saved'] !== '' ? $status['saved'] : null;

        return [
            'stage' => $stage,
            'saved' => $saved,
            'removed' => array_values(array_filter((array) (is_array($released) ? ($released['removed'] ?? []) : []), 'is_string')),
            'failed' => array_values(array_filter(array_map(
                fn(mixed $server): ?array => is_array($server) && is_string($server['name'] ?? null) && is_string($server['file'] ?? null) && is_string($server['comment'] ?? null)
                    ? ['name' => $server['name'], 'file' => $server['file'], 'comment' => $server['comment']]
                    : null,
                (array) (is_array($released) ? ($released['failed'] ?? []) : []),
            ))),
        ];
    }

    private function request(string $id, string $what): void
    {
        try {
            AtomicFile::write($this->folder() . '/request', "{$id} {$what}\n", 0640, "Coresmith couldn't ask its removal helper");
        } catch (RuntimeException $unwritten) {
            throw new RemovalRefused($unwritten->getMessage() . '. Run the installer again, then try again.');
        }
    }

    private function folder(): string
    {
        return rtrim((string) config('coresmith.removal.state_path'), '/');
    }
}
