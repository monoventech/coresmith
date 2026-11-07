<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Services\Deployment\LogSanitizer;
use Illuminate\Support\Sleep;
use RuntimeException;

final class UpdateState
{
    public const KEEP_RUNS = 20;

    public const LOCK_ATTEMPTS = 10;

    private const RUN_ID = '/^[0-9a-z]{26}$/D';

    public function __construct(
        private readonly UpdatePaths $paths,
        private readonly LogSanitizer $sanitizer,
    ) {}

    public function readCheck(): ?UpdateCheck
    {
        $data = $this->read($this->paths->checkFile());

        return $data === null ? null : UpdateCheck::fromArray($data);
    }

    public function writeCheck(UpdateCheck $check): void
    {
        $this->write($this->paths->checkFile(), $check->toArray());
    }

    public function acquireLock(): ?UpdateLock
    {
        $this->directory($this->paths->state());

        $handle = @fopen($this->paths->lockFile(), 'c+');

        if ($handle === false) {
            throw new RuntimeException("Coresmith couldn't open {$this->paths->lockFile()}.");
        }

        @chmod($this->paths->lockFile(), 0600);

        for ($attempt = 1; !flock($handle, LOCK_EX | LOCK_NB); $attempt++) {
            if ($attempt >= self::LOCK_ATTEMPTS) {
                fclose($handle);

                return null;
            }

            Sleep::for(50)->milliseconds();
        }

        ftruncate($handle, 0);
        fwrite($handle, getmypid() . "\n");
        fflush($handle);

        return new UpdateLock($handle);
    }

    public function isLocked(): bool
    {
        if (!is_file($this->paths->lockFile())) {
            return false;
        }

        $handle = @fopen($this->paths->lockFile(), 'r');

        if ($handle === false) {
            return false;
        }

        $free = flock($handle, LOCK_SH | LOCK_NB);

        if ($free) {
            flock($handle, LOCK_UN);
        }

        fclose($handle);

        return !$free;
    }

    public function status(): ?UpdateStatus
    {
        $data = $this->read($this->paths->statusFile());

        return $data === null ? null : UpdateStatus::fromArray($data);
    }

    public function interrupted(): bool
    {
        return $this->status()?->isRunning() === true && !$this->isLocked();
    }

    public function begin(UpdateStatus $status): UpdateRun
    {
        $this->save($status);

        return new UpdateRun($this, $status, $this->sanitizer);
    }

    public function save(UpdateStatus $status): void
    {
        $this->write($this->paths->statusFile(), $status->toArray());
        $this->write($this->paths->runs() . "/{$status->id}.json", $status->toArray());
        $this->pruneRuns();
    }

    public function lastSucceeded(): ?UpdateStatus
    {
        foreach ($this->runIds() as $id) {
            $data = $this->read($this->paths->runs() . "/{$id}.json");
            $status = $data === null ? null : UpdateStatus::fromArray($data);

            if ($status?->state === UpdateStatus::SUCCEEDED) {
                return $status;
            }
        }

        return null;
    }

    public function appendLog(string $id, string $line): void
    {
        if (preg_match(self::RUN_ID, $id) !== 1) {
            throw new RuntimeException("\"{$id}\" is not an update run.");
        }

        $this->directory($this->paths->runs());
        $file = $this->paths->runs() . "/{$id}.log";
        $new = !is_file($file);

        if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Coresmith couldn't write {$file}.");
        }

        if ($new) {
            @chmod($file, 0600);
        }
    }

    /**
     * @return list<string>
     */
    public function log(string $id, int $tail = 0): array
    {
        $file = $this->paths->runs() . "/{$id}.log";

        if (preg_match(self::RUN_ID, $id) !== 1 || !is_file($file)) {
            return [];
        }

        $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($file)), fn(string $line): bool => $line !== ''));

        return $tail > 0 ? array_slice($lines, -$tail) : $lines;
    }

    public function request(UpdateRequest $request): void
    {
        $this->write($this->paths->requestFile(), $request->toArray());
    }

    public function pendingRequest(): ?UpdateRequest
    {
        foreach ([$this->paths->requestFile(), $this->paths->takenRequestFile()] as $file) {
            $data = $this->read($file);

            if ($data !== null) {
                return UpdateRequest::fromArray($data);
            }
        }

        return null;
    }

    public function requestTaken(): bool
    {
        return is_file($this->paths->takenRequestFile());
    }

    public function cancelRequest(): bool
    {
        return is_file($this->paths->requestFile()) && @unlink($this->paths->requestFile());
    }

    public function clearTakenRequest(): bool
    {
        return is_file($this->paths->takenRequestFile()) && @unlink($this->paths->takenRequestFile());
    }

    public function takeRequest(): ?UpdateRequest
    {
        foreach ([$this->paths->takenRequestFile(), $this->paths->requestFile()] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $data = $this->read($file);
            @unlink($file);

            return $data === null ? null : UpdateRequest::fromArray($data);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function runIds(): array
    {
        $ids = array_values(array_filter(
            array_map(fn(string $file): string => basename($file, '.json'), glob($this->paths->runs() . '/*.json') ?: []),
            fn(string $id): bool => preg_match(self::RUN_ID, $id) === 1,
        ));

        rsort($ids);

        return $ids;
    }

    private function pruneRuns(): void
    {
        foreach (array_slice($this->runIds(), self::KEEP_RUNS) as $id) {
            @unlink($this->paths->runs() . "/{$id}.json");
            @unlink($this->paths->runs() . "/{$id}.log");
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private function directory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Coresmith couldn't create {$directory}.");
        }

        @chmod($directory, 0700);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function write(string $file, array $data): void
    {
        $directory = dirname($file);
        $this->directory($directory);

        $temporary = @tempnam($directory, '.' . basename($file) . '.');

        if ($temporary === false) {
            throw new RuntimeException("Coresmith couldn't write to {$directory}.");
        }

        if (@file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false
            || !@chmod($temporary, 0600)
            || !@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException("Coresmith couldn't write {$file}.");
        }
    }
}
