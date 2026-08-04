<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services;

use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Str;

final class SlugGenerator
{
    public const USER_PREFIX = 'coresmith-app-';

    public const MAX_SLUG_LENGTH = 32 - 14;

    private const ADJECTIVES = ['jumping', 'gentle', 'swift', 'quiet', 'bright', 'clever', 'brave', 'tidy'];

    private const NOUNS = ['apple', 'forest', 'river', 'anvil', 'ember', 'harbour', 'meadow', 'lantern'];

    public function fromName(?string $name, Server $server): string
    {
        $base = $this->normalise($name === null || trim($name) === '' ? $this->randomName() : $name);

        if (!$this->taken($base)) {
            return $base;
        }

        do {
            $suffix = '-'.Str::lower(Str::random(3));
            $candidate = $this->normalise(Str::substr($base, 0, self::MAX_SLUG_LENGTH - strlen($suffix)).$suffix);
        } while ($this->taken($candidate));

        return $candidate;
    }

    public function systemUser(string $slug): string
    {
        return self::USER_PREFIX.SafeName::assertSlug($slug);
    }

    private function normalise(string $value): string
    {
        $slug = Str::substr(Str::slug($value), 0, self::MAX_SLUG_LENGTH);
        $slug = trim($slug, '-');

        return $slug === '' ? $this->normalise($this->randomName()) : $slug;
    }

    private function randomName(): string
    {
        return self::ADJECTIVES[array_rand(self::ADJECTIVES)].'-'.self::NOUNS[array_rand(self::NOUNS)];
    }

    private function taken(string $slug): bool
    {
        return Application::withTrashed()
            ->where('slug', $slug)
            ->exists();
    }
}
