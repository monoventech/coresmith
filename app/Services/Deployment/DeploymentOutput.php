<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use Closure;

final class DeploymentOutput
{
    public const FLUSH_MS = 250;

    public const FLUSH_BYTES = 4096;

    public const LONG_LINE_BYTES = 16384;

    private string $buffer = '';

    private float $flushedAt;

    private readonly Closure $now;

    public function __construct(private readonly Closure $flush, ?Closure $now = null)
    {
        $this->now = $now ?? static fn(): float => hrtime(true) / 1e6;
        $this->flushedAt = ($this->now)();
    }

    public function write(string $chunk): void
    {
        $this->buffer .= $chunk;
        $end = strrpos($this->buffer, "\n");

        if ($end === false) {
            if (strlen($this->buffer) > self::LONG_LINE_BYTES) {
                $whole = mb_strcut($this->buffer, 0, strlen($this->buffer), 'UTF-8');
                $this->buffer = substr($this->buffer, strlen($whole));
                $this->emit($whole);
            }

            return;
        }

        if (($this->now)() - $this->flushedAt < self::FLUSH_MS && strlen($this->buffer) < self::FLUSH_BYTES) {
            return;
        }

        $lines = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 1);
        $this->emit($lines);
    }

    public function finish(): void
    {
        if ($this->buffer === '') {
            return;
        }

        $rest = $this->buffer;
        $this->buffer = '';
        $this->emit($rest);
    }

    private function emit(string $text): void
    {
        $lines = array_map(static function (string $line): string {
            $line = rtrim($line, "\r");
            $overwritten = strrpos($line, "\r");

            return $overwritten === false ? $line : substr($line, $overwritten + 1);
        }, preg_split('/\r?\n/', $text) ?: []);

        $this->flushedAt = ($this->now)();
        ($this->flush)(implode("\n", $lines));
    }
}
