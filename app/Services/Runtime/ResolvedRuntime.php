<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use Illuminate\Support\Arr;

final readonly class ResolvedRuntime
{
    public const FROM_PIN = 'pin';

    public const FROM_DEFAULT = 'default';

    /**
     * @param  list<string>  $heldBy
     */
    public function __construct(
        public string $runtime,
        public string $version,
        public string $source,
        public ?string $detail,
        public bool $installed,
        public ?string $note = null,
        public array $heldBy = [],
        public ?string $newest = null,
    ) {}

    public function describe(string $label): string
    {
        if ($this->heldBy !== []) {
            $verb = count($this->heldBy) === 1 ? 'stops' : 'stop';

            return "{$label} {$this->version}, the newest your composer.lock allows: " . Arr::join($this->heldBy, ', ', ' and ') . " {$verb} at " . self::line($this->version);
        }

        return match ($this->source) {
            self::FROM_PIN => "{$label} {$this->version}, the version chosen for this app",
            self::FROM_DEFAULT => "{$label} {$this->version}, the recommended version, because the repository names none",
            default => "{$label} {$this->version} from {$this->source}" . ($this->detail !== null && $this->detail !== $this->version ? " ({$this->detail})" : ''),
        };
    }

    /**
     * @return array{runtime: string, version: string, source: string, detail: ?string, held_by: list<string>, newest: ?string}
     */
    public function toArray(): array
    {
        return [
            'runtime' => $this->runtime,
            'version' => $this->version,
            'source' => $this->source,
            'detail' => $this->detail,
            'held_by' => $this->heldBy,
            'newest' => $this->newest,
        ];
    }

    public static function line(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['runtime'] ?? ''),
            (string) ($data['version'] ?? ''),
            (string) ($data['source'] ?? self::FROM_DEFAULT),
            isset($data['detail']) ? (string) $data['detail'] : null,
            true,
            null,
            array_values(array_filter((array) ($data['held_by'] ?? []), 'is_string')),
            isset($data['newest']) ? (string) $data['newest'] : null,
        );
    }
}
