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

    public const KEY_BLOCK_BYTES = 16384;

    private const KEY_BEGIN = '/^.*-----BEGIN [A-Z ]*PRIVATE KEY-----/m';

    private const KEY_END = '/-----END [A-Z ]*PRIVATE KEY-----/';

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
                $this->emit($this->cut());
            }

            return;
        }

        if (($this->now)() - $this->flushedAt < self::FLUSH_MS && strlen($this->buffer) < self::FLUSH_BYTES) {
            return;
        }

        $end = $this->beforeOpenKey(substr($this->buffer, 0, $end)) ?? $end;

        if ($end < 0) {
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

    private function beforeOpenKey(string $lines): ?int
    {
        if (preg_match_all(self::KEY_BEGIN, $lines, $begins, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }

        $at = (int) end($begins[0])[1];

        if (preg_match(self::KEY_END, $lines, $ends, 0, $at) === 1 || strlen($this->buffer) - $at > self::KEY_BLOCK_BYTES) {
            return null;
        }

        return $at - 1;
    }

    private function cut(): string
    {
        $space = max((int) strrpos($this->buffer, ' '), (int) strrpos($this->buffer, "\t"));
        $piece = $space > 0 ? substr($this->buffer, 0, $space + 1) : mb_strcut($this->buffer, 0, strlen($this->buffer), 'UTF-8');
        $this->buffer = substr($this->buffer, strlen($piece));

        return $piece;
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
