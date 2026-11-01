<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Services\Deployment\LogSanitizer;
use Closure;

final class UpdateRun
{
    /** @var list<Closure(string): void> */
    private array $listeners = [];

    public function __construct(
        private readonly UpdateState $state,
        private UpdateStatus $status,
        private readonly LogSanitizer $sanitizer,
    ) {}

    public function id(): string
    {
        return $this->status->id;
    }

    public function status(): UpdateStatus
    {
        return $this->status;
    }

    public function step(string $key): void
    {
        $this->save($this->status->withStep($key));
        $this->line($this->status->stepLabel());
    }

    public function mark(string $key, mixed $value): void
    {
        $this->save($this->status->withMarker($key, $value));
    }

    public function release(string $name): void
    {
        $this->save($this->status->withRelease($name));
    }

    public function signature(string $value): void
    {
        $this->save($this->status->withSignature($value));
    }

    public function output(string $chunk): void
    {
        $plain = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $chunk);

        foreach (preg_split('/\r\n|\r|\n/', $plain) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $line = $this->sanitizer->sanitize(rtrim($line));
            $this->state->appendLog($this->status->id, $line);

            foreach ($this->listeners as $listener) {
                $listener($line);
            }
        }
    }

    public function line(string $text): void
    {
        $this->output($text);
    }

    public function warn(string $text): void
    {
        $this->output("Warning: {$text}");
    }

    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function finish(string $state, ?string $message = null, ?string $failedStep = null): UpdateStatus
    {
        $this->save($this->status->finished($state, $message === null ? null : $this->sanitizer->sanitize($message), $failedStep));

        if ($message !== null) {
            $this->line($message);
        }

        return $this->status;
    }

    private function save(UpdateStatus $status): void
    {
        $this->status = $status;
        $this->state->save($status);
    }
}
