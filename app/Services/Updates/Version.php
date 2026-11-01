<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use InvalidArgumentException;
use Stringable;

final class Version implements Stringable
{
    private const PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?$/D';

    private const MAX_LENGTH = 64;

    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly ?string $prerelease,
    ) {}

    public static function parse(string $value): self
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw new InvalidArgumentException("\"{$value}\" is not a version like 1.2.3.");
        }

        return new self((int) $matches[1], (int) $matches[2], (int) $matches[3], ($matches[4] ?? '') === '' ? null : $matches[4]);
    }

    public static function tryParse(mixed $value): ?self
    {
        if (!is_string($value)) {
            return null;
        }

        try {
            return self::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function current(): self
    {
        return self::parse((string) config('coresmith.version'));
    }

    public function compare(self $other): int
    {
        foreach ([[$this->major, $other->major], [$this->minor, $other->minor], [$this->patch, $other->patch]] as [$mine, $theirs]) {
            if ($mine !== $theirs) {
                return $mine <=> $theirs;
            }
        }

        return match (true) {
            $this->prerelease === $other->prerelease => 0,
            $this->prerelease === null => 1,
            $other->prerelease === null => -1,
            default => self::comparePrerelease(explode('.', $this->prerelease), explode('.', $other->prerelease)),
        };
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isPrerelease(): bool
    {
        return $this->prerelease !== null;
    }

    public function isPatchUpdateOf(self $other): bool
    {
        return !$this->isPrerelease()
            && !$other->isPrerelease()
            && $this->major === $other->major
            && $this->minor === $other->minor
            && $this->patch > $other->patch;
    }

    public function tag(): string
    {
        return 'v' . $this;
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}.{$this->patch}" . ($this->prerelease === null ? '' : "-{$this->prerelease}");
    }

    /**
     * @param  list<string>  $mine
     * @param  list<string>  $theirs
     */
    private static function comparePrerelease(array $mine, array $theirs): int
    {
        foreach ($mine as $index => $identifier) {
            if (!array_key_exists($index, $theirs)) {
                return 1;
            }

            $result = self::compareIdentifier($identifier, $theirs[$index]);

            if ($result !== 0) {
                return $result;
            }
        }

        return count($mine) < count($theirs) ? -1 : 0;
    }

    private static function compareIdentifier(string $mine, string $theirs): int
    {
        $mineNumeric = ctype_digit($mine);
        $theirsNumeric = ctype_digit($theirs);

        return match (true) {
            $mineNumeric && $theirsNumeric => (strlen($mine) <=> strlen($theirs)) ?: strcmp($mine, $theirs) <=> 0,
            $mineNumeric => -1,
            $theirsNumeric => 1,
            default => strcmp($mine, $theirs) <=> 0,
        };
    }
}
