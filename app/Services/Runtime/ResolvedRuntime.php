<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final readonly class ResolvedRuntime
{
    public const FROM_PIN = 'pin';

    public const FROM_DEFAULT = 'default';

    public function __construct(
        public string $runtime,
        public string $version,
        public string $source,
        public ?string $detail,
        public bool $installed,
        public ?string $note = null,
    ) {}

    public function describe(string $label): string
    {
        return match ($this->source) {
            self::FROM_PIN => "{$label} {$this->version}, the version chosen when the app was added",
            self::FROM_DEFAULT => "{$label} {$this->version}, the recommended version, because the repository names none",
            default => "{$label} {$this->version} from {$this->source}" . ($this->detail !== null && $this->detail !== $this->version ? " ({$this->detail})" : ''),
        };
    }

    /**
     * @return array{runtime: string, version: string, source: string, detail: ?string}
     */
    public function toArray(): array
    {
        return [
            'runtime' => $this->runtime,
            'version' => $this->version,
            'source' => $this->source,
            'detail' => $this->detail,
        ];
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
        );
    }
}
