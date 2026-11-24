<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Logs;

use Illuminate\Http\Request;

final class LogQuery
{
    public const WINDOWS = ['15m' => 900, '1h' => 3600, '24h' => 86400, '7d' => 604800, 'all' => 0];

    public const LEVELS = ['all', 'warnings', 'errors'];

    public const SEARCH_LENGTH = 200;

    public const AROUND_SECONDS = 10;

    public const SHORT_BYTES = 1_048_576;

    public const LONG_BYTES = 5_242_880;

    /**
     * @param  list<string>  $sources
     */
    public function __construct(
        public readonly string $window = '1h',
        public readonly string $search = '',
        public readonly ?int $around = null,
        public readonly array $sources = [],
        public readonly string $level = 'all',
    ) {}

    public static function from(Request $request): self
    {
        $window = (string) $request->query('window', '1h');
        $around = $request->query('around');
        $level = (string) $request->query('level', 'all');
        $sources = $request->query('sources', []);

        return new self(
            array_key_exists($window, self::WINDOWS) ? $window : '1h',
            mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $request->query('q', ''))), 0, self::SEARCH_LENGTH),
            is_string($around) && ctype_digit($around) && (int) $around > now()->subDays(30)->getTimestamp() && (int) $around <= now()->getTimestamp() ? (int) $around : null,
            is_array($sources) ? array_values(array_filter($sources, fn(mixed $source): bool => is_string($source) && preg_match('/^[a-z][a-z0-9:_-]{0,63}$/', $source) === 1)) : [],
            in_array($level, self::LEVELS, true) ? $level : 'all',
        );
    }

    public function since(int $now): ?int
    {
        if ($this->around !== null) {
            return $this->around - self::AROUND_SECONDS;
        }

        $seconds = self::WINDOWS[$this->window];

        return $seconds === 0 ? null : $now - $seconds;
    }

    public function until(): ?int
    {
        return $this->around === null ? null : $this->around + self::AROUND_SECONDS;
    }

    public function bytes(): int
    {
        return $this->around === null && in_array($this->window, ['15m', '1h'], true) ? self::SHORT_BYTES : self::LONG_BYTES;
    }

    public function key(): string
    {
        return hash('xxh128', $this->window . "\0" . $this->search . "\0" . ($this->around ?? ''));
    }

    public function wants(string $source): bool
    {
        return $this->sources === [] || in_array($source, $this->sources, true);
    }

    public function allows(string $level): bool
    {
        return match ($this->level) {
            'errors' => $level === 'error',
            'warnings' => in_array($level, ['warning', 'error'], true),
            default => true,
        };
    }
}
