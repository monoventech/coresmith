<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class UpdateStatus
{
    public const SCHEMA = 1;

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const ABORTED = 'aborted';

    public const ROLLED_BACK = 'rolled_back';

    public const FAILED = 'failed';

    public const STATES = [self::RUNNING, self::SUCCEEDED, self::ABORTED, self::ROLLED_BACK, self::FAILED];

    public const STEPS = [
        'preflight' => 'Checking this server',
        'fetch' => 'Fetching the release',
        'verify' => 'Checking the signature',
        'dependencies' => 'Installing PHP dependencies',
        'assets' => 'Building the interface',
        'cache' => 'Warming caches',
        'drain' => 'Pausing the queue',
        'backup' => 'Backing up the database',
        'migrate' => 'Running migrations',
        'switch' => 'Switching to the new release',
        'restart' => 'Restarting the queue and websockets',
        'health' => 'Checking the panel answers',
        'rollback' => 'Rolling back',
    ];

    public const COUNTED = 12;

    public const MARKERS = ['built' => null, 'maintenance' => false, 'backup' => null, 'migrating' => false, 'switched' => false, 'healthy' => false];

    /**
     * @param  array<string, mixed>  $markers
     */
    private function __construct(
        public readonly string $id,
        public readonly string $state,
        public readonly string $trigger,
        public readonly ?string $requestedBy,
        public readonly ?string $fromVersion,
        public readonly ?string $fromRelease,
        public readonly ?string $toVersion,
        public readonly ?string $toRelease,
        public readonly string $step,
        public readonly ?string $failedStep,
        public readonly ?string $message,
        public readonly ?string $signature,
        public readonly array $markers,
        public readonly CarbonImmutable $startedAt,
        public readonly CarbonImmutable $updatedAt,
        public readonly ?CarbonImmutable $finishedAt,
    ) {}

    public static function start(?Release $current, ?Version $target, string $trigger, ?string $requestedBy): self
    {
        $now = CarbonImmutable::now();

        return new self(
            Str::lower((string) Str::ulid($now)),
            self::RUNNING,
            $trigger,
            $requestedBy,
            $current?->version === null ? null : (string) $current->version,
            $current?->name,
            $target === null ? null : (string) $target,
            null,
            'preflight',
            null,
            null,
            null,
            self::MARKERS,
            $now,
            $now,
            null,
        );
    }

    public function withStep(string $step): self
    {
        if (!array_key_exists($step, self::STEPS)) {
            throw new InvalidArgumentException("\"{$step}\" is not an update step.");
        }

        return $this->copy(['step' => $step]);
    }

    public function withMarker(string $key, mixed $value): self
    {
        return $this->copy(['markers' => [...$this->markers, $key => $value]]);
    }

    public function withRelease(string $name): self
    {
        return $this->copy(['toRelease' => $name]);
    }

    public function withSignature(string $signature): self
    {
        return $this->copy(['signature' => $signature]);
    }

    public function finished(string $state, ?string $message = null, ?string $failedStep = null): self
    {
        return $this->copy(['state' => $state, 'message' => $message, 'failedStep' => $failedStep, 'finishedAt' => CarbonImmutable::now()]);
    }

    public function isRunning(): bool
    {
        return $this->state === self::RUNNING;
    }

    public function stepLabel(): string
    {
        return self::STEPS[$this->step] ?? $this->step;
    }

    public function stepNumber(): ?int
    {
        $index = array_search($this->step, array_keys(self::STEPS), true);

        return $index === false || $index >= self::COUNTED ? null : $index + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'id' => $this->id,
            'state' => $this->state,
            'trigger' => $this->trigger,
            'requested_by' => $this->requestedBy,
            'from' => ['version' => $this->fromVersion, 'release' => $this->fromRelease],
            'to' => ['version' => $this->toVersion, 'release' => $this->toRelease],
            'step' => $this->step,
            'failed_step' => $this->failedStep,
            'message' => $this->message,
            'signature' => $this->signature,
            'markers' => $this->markers,
            'started_at' => $this->startedAt->toIso8601String(),
            'updated_at' => $this->updatedAt->toIso8601String(),
            'finished_at' => $this->finishedAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $state = $data['state'] ?? null;
        $step = $data['step'] ?? null;

        if (!is_string($id) || preg_match('/^[0-9a-z]{26}$/D', $id) !== 1 || !in_array($state, self::STATES, true) || !is_string($step) || !array_key_exists($step, self::STEPS)) {
            return null;
        }

        try {
            $startedAt = CarbonImmutable::parse((string) ($data['started_at'] ?? ''));
            $updatedAt = CarbonImmutable::parse((string) ($data['updated_at'] ?? ''));
            $finishedAt = is_string($data['finished_at'] ?? null) ? CarbonImmutable::parse($data['finished_at']) : null;
        } catch (Throwable) {
            return null;
        }

        $from = is_array($data['from'] ?? null) ? $data['from'] : [];
        $to = is_array($data['to'] ?? null) ? $data['to'] : [];

        return new self(
            $id,
            $state,
            is_string($data['trigger'] ?? null) ? $data['trigger'] : 'cli',
            is_string($data['requested_by'] ?? null) || is_int($data['requested_by'] ?? null) ? (string) $data['requested_by'] : null,
            self::text($from['version'] ?? null),
            self::text($from['release'] ?? null),
            self::text($to['version'] ?? null),
            self::text($to['release'] ?? null),
            $step,
            self::text($data['failed_step'] ?? null),
            self::text($data['message'] ?? null),
            self::text($data['signature'] ?? null),
            is_array($data['markers'] ?? null) ? [...self::MARKERS, ...$data['markers']] : self::MARKERS,
            $startedAt,
            $updatedAt,
            $finishedAt,
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function copy(array $changes): self
    {
        $values = [
            'id' => $this->id,
            'state' => $this->state,
            'trigger' => $this->trigger,
            'requestedBy' => $this->requestedBy,
            'fromVersion' => $this->fromVersion,
            'fromRelease' => $this->fromRelease,
            'toVersion' => $this->toVersion,
            'toRelease' => $this->toRelease,
            'step' => $this->step,
            'failedStep' => $this->failedStep,
            'message' => $this->message,
            'signature' => $this->signature,
            'markers' => $this->markers,
            'startedAt' => $this->startedAt,
            'updatedAt' => CarbonImmutable::now(),
            'finishedAt' => $this->finishedAt,
            ...$changes,
        ];

        return new self(...$values);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
