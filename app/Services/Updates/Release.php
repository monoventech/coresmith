<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use Carbon\CarbonImmutable;
use Throwable;

final class Release
{
    public const SENTINEL = '.coresmith-release.json';

    public const SCHEMA = 1;

    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly ?Version $version,
        public readonly ?string $commit,
        public readonly ?string $tag,
        public readonly string $signature,
        public readonly ?string $fingerprint,
        public readonly ?CarbonImmutable $builtAt,
        public readonly string $builtBy,
    ) {}

    public static function fromDirectory(string $path): ?self
    {
        $file = rtrim($path, '/') . '/' . self::SENTINEL;

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        if (!is_array($data)) {
            return null;
        }

        try {
            $builtAt = is_string($data['built_at'] ?? null) ? CarbonImmutable::parse($data['built_at']) : null;
        } catch (Throwable) {
            $builtAt = null;
        }

        return new self(
            basename(rtrim($path, '/')),
            rtrim($path, '/'),
            Version::tryParse($data['version'] ?? null),
            self::text($data['commit'] ?? null),
            self::text($data['tag'] ?? null),
            self::text($data['signature'] ?? null) ?? 'unchecked',
            self::text($data['fingerprint'] ?? null),
            $builtAt,
            self::text($data['built_by'] ?? null) ?? '',
        );
    }

    /**
     * @return array{schema: int, version: ?string, commit: ?string, tag: ?string, signature: string, fingerprint: ?string, built_at: ?string, built_by: string}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => $this->version === null ? null : (string) $this->version,
            'commit' => $this->commit,
            'tag' => $this->tag,
            'signature' => $this->signature,
            'fingerprint' => $this->fingerprint,
            'built_at' => $this->builtAt?->toIso8601String(),
            'built_by' => $this->builtBy,
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
