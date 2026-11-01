<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud\Tasks;

use App\Models\Server;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class CloudTask
{
    public const CREATE = 'create';

    public const DESTROY = 'destroy';

    public const RESIZE = 'resize';

    public const RUNNING = 'running';

    public const FAILED = 'failed';

    public const DONE = 'done';

    public const STALE_SECONDS = 120;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $type,
        public string $state,
        public string $step,
        public array $data,
        public ?string $error,
        public ?string $by,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $stepStartedAt,
        public ?CarbonImmutable $heartbeatAt,
    ) {}

    public static function of(Server $server): ?self
    {
        $task = $server->provider_task;

        if (!is_array($task) || !is_string($task['type'] ?? null) || !is_string($task['state'] ?? null) || !is_string($task['step'] ?? null)) {
            return null;
        }

        return new self(
            $task['type'],
            $task['state'],
            $task['step'],
            is_array($task['data'] ?? null) ? $task['data'] : [],
            is_string($task['error'] ?? null) ? $task['error'] : null,
            is_string($task['by'] ?? null) ? $task['by'] : null,
            self::time($task['started_at'] ?? null),
            self::time($task['step_started_at'] ?? null),
            self::time($task['heartbeat_at'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function start(Server $server, string $type, string $step, array $data = [], ?User $by = null): self
    {
        $now = now()->toIso8601String();
        $userId = $by === null ? null : (string) $by->getKey();

        $server->forceFill(['provider_task' => [
            'type' => $type,
            'state' => self::RUNNING,
            'step' => $step,
            'started_at' => $now,
            'step_started_at' => $now,
            'heartbeat_at' => $now,
            'finished_at' => null,
            'error' => null,
            'by' => $userId,
            'data' => $data,
        ]])->save();

        $at = CarbonImmutable::parse($now);

        return new self($type, self::RUNNING, $step, $data, null, $userId, $at, $at, $at);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function advance(Server $server, string $step, array $data = []): void
    {
        $now = now()->toIso8601String();

        self::change($server, ['step' => $step, 'step_started_at' => $now, 'heartbeat_at' => $now], $data, ['last_error']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function touch(Server $server, array $data = []): void
    {
        self::change($server, ['heartbeat_at' => now()->toIso8601String()], $data);
    }

    public static function fail(Server $server, string $error): void
    {
        self::change($server, ['state' => self::FAILED, 'finished_at' => now()->toIso8601String(), 'error' => $error]);
    }

    public static function finish(Server $server): void
    {
        self::change($server, ['state' => self::DONE, 'finished_at' => now()->toIso8601String()]);
    }

    public static function resume(Server $server): void
    {
        if (self::of($server)?->state !== self::FAILED) {
            return;
        }

        $now = now()->toIso8601String();

        self::change($server, ['state' => self::RUNNING, 'step_started_at' => $now, 'heartbeat_at' => $now, 'finished_at' => null, 'error' => null]);
    }

    public static function clear(Server $server): void
    {
        $server->forceFill(['provider_task' => null])->save();
    }

    public static function running(Server $server, ?string $type = null): bool
    {
        $task = self::of($server);

        return $task !== null && $task->state === self::RUNNING && ($type === null || $task->type === $type);
    }

    public static function refusal(Server $server, string $action): ?string
    {
        $task = self::of($server);

        if ($task === null || $task->state !== self::RUNNING) {
            return null;
        }

        $label = $server->cloudProvider?->type->label() ?? 'its provider';

        return match ($task->type) {
            self::RESIZE => "Coresmith is resizing {$server->name} at {$label}. {$action} once it's back.",
            self::DESTROY => "Coresmith is destroying {$server->name} at {$label}.",
            self::CREATE => "Coresmith is still creating {$server->name} at {$label}.",
            default => null,
        };
    }

    public function stepSeconds(): int
    {
        return $this->stepStartedAt === null ? 0 : max(0, (int) $this->stepStartedAt->diffInSeconds(now()));
    }

    public function isStale(): bool
    {
        return $this->state === self::RUNNING
            && ($this->heartbeatAt === null || $this->heartbeatAt->lt(now()->subSeconds(self::STALE_SECONDS)));
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $data
     * @param  list<string>  $forget
     */
    private static function change(Server $server, array $changes, array $data = [], array $forget = []): void
    {
        $task = $server->provider_task;

        if (!is_array($task)) {
            return;
        }

        $kept = array_diff_key(is_array($task['data'] ?? null) ? $task['data'] : [], array_flip($forget));

        $server->forceFill(['provider_task' => array_replace($task, $changes, ['data' => array_replace($kept, $data)])])->save();
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
