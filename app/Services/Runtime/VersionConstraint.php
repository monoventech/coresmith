<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

final class VersionConstraint
{
    private const PARTIAL = '/^v?(\d+)(?:\.(\d+|[xX*]))?(?:\.(\d+|[xX*]))?(?:[-+.][0-9A-Za-z.-]*)?$/';

    /**
     * @param  list<list<array{0: string, 1: array{0: int, 1: int, 2: int}}>>  $alternatives
     */
    private function __construct(
        private readonly array $alternatives,
        public readonly ?string $exact,
    ) {}

    public static function parse(string $constraint, string $flavour = 'npm'): ?self
    {
        $constraint = trim((string) preg_replace('/@[A-Za-z]+/', '', $constraint));

        if ($constraint === '') {
            return null;
        }

        if (preg_match('/^(?:==?)?v?(\d+\.\d+\.\d+)$/', $constraint, $exact) === 1) {
            return new self([[['=', self::floor(self::partial($exact[1]) ?? [0, 0, 0])]]], $exact[1]);
        }

        if (in_array($flavour, ['composer', 'gem'], true) && preg_match('/^(?:==?\s*)?v?(\d+(?:\.\d+)?)$/', $constraint, $partial) === 1) {
            $floor = self::floor(self::partial($partial[1]) ?? [0, 0, 0]);

            return new self([[['=', $floor]]], implode('.', $floor));
        }

        $alternatives = [];

        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            $comparators = self::alternative(trim($alternative), $flavour);

            if ($comparators === null) {
                return null;
            }

            $alternatives[] = $comparators;
        }

        return $alternatives === [] ? null : new self($alternatives, null);
    }

    public function satisfiedBy(string $version): bool
    {
        $parts = self::partial($version);

        if ($parts === null) {
            return false;
        }

        $candidate = self::floor($parts);

        foreach ($this->alternatives as $comparators) {
            if (self::matches($comparators, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $versions
     */
    public function best(array $versions): ?string
    {
        $matching = array_values(array_filter($versions, fn(string $version): bool => $this->satisfiedBy($version)));

        usort($matching, fn(string $a, string $b): int => version_compare(ltrim($b, 'v'), ltrim($a, 'v')));

        return $matching[0] ?? null;
    }

    /**
     * @return list<array{0: string, 1: array{0: int, 1: int, 2: int}}>|null
     */
    private static function alternative(string $alternative, string $flavour): ?array
    {
        $alternative = (string) preg_replace('/(>=|<=|~>|!=|==|[<>=^~])\s+/', '$1', $alternative);

        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $alternative, $hyphen) === 1) {
            $low = self::partial($hyphen[1]);
            $high = self::partial($hyphen[2]);

            if ($low === null || $high === null) {
                return null;
            }

            return [
                ['>=', self::floor($low)],
                $high[2] === null ? ['<', self::ceiling($high)] : ['<=', self::floor($high)],
            ];
        }

        $comparators = [];

        foreach (preg_split('/[\s,]+/', $alternative) ?: [] as $token) {
            if ($token === '' || in_array(strtolower($token), ['*', 'x'], true)) {
                continue;
            }

            $parsed = self::token($token, $flavour);

            if ($parsed === null) {
                return null;
            }

            array_push($comparators, ...$parsed);
        }

        return $comparators;
    }

    /**
     * @return list<array{0: string, 1: array{0: int, 1: int, 2: int}}>|null
     */
    private static function token(string $token, string $flavour): ?array
    {
        if (preg_match('/^(>=|<=|~>|!=|==|[<>=^~])?(.+)$/', $token, $matches) !== 1) {
            return null;
        }

        $parts = self::partial($matches[2]);

        if ($parts === null) {
            return null;
        }

        $floor = self::floor($parts);
        $missing = $parts[2] === null;
        $wildcard = self::wildcard($matches[2]);
        $widens = $missing && ($wildcard || $flavour === 'npm');

        return match ($matches[1]) {
            '^' => [['>=', $floor], ['<', self::caret($parts)]],
            '~>' => [['>=', $floor], ['<', self::pessimistic($parts)]],
            '~' => [['>=', $floor], ['<', $flavour === 'npm' ? self::tilde($parts) : self::pessimistic($parts)]],
            '>=' => [['>=', $floor]],
            '>' => $widens ? [['>=', self::ceiling($parts)]] : [['>', $floor]],
            '<' => [['<', $floor]],
            '<=' => $widens ? [['<', self::ceiling($parts)]] : [['<=', $floor]],
            '!=' => $wildcard || ($missing && $flavour === 'npm') ? null : [['!=', $floor]],
            default => $widens ? [['>=', $floor], ['<', self::ceiling($parts)]] : [['=', $floor]],
        };
    }

    /**
     * @return array{0: int, 1: ?int, 2: ?int}|null
     */
    private static function partial(string $version): ?array
    {
        if (preg_match(self::PARTIAL, $version, $matches) !== 1) {
            return null;
        }

        $minor = isset($matches[2]) && ctype_digit($matches[2]) ? (int) $matches[2] : null;
        $patch = $minor !== null && isset($matches[3]) && ctype_digit($matches[3]) ? (int) $matches[3] : null;

        return [(int) $matches[1], $minor, $patch];
    }

    private static function wildcard(string $version): bool
    {
        if (preg_match(self::PARTIAL, $version, $matches) !== 1) {
            return false;
        }

        return (isset($matches[2]) && !ctype_digit($matches[2])) || (isset($matches[3]) && !ctype_digit($matches[3]));
    }

    /**
     * @param  array{0: int, 1: ?int, 2: ?int}  $parts
     * @return array{0: int, 1: int, 2: int}
     */
    private static function floor(array $parts): array
    {
        return [$parts[0], $parts[1] ?? 0, $parts[2] ?? 0];
    }

    /**
     * @param  array{0: int, 1: ?int, 2: ?int}  $parts
     * @return array{0: int, 1: int, 2: int}
     */
    private static function ceiling(array $parts): array
    {
        return $parts[1] === null ? [$parts[0] + 1, 0, 0] : [$parts[0], $parts[1] + 1, 0];
    }

    /**
     * @param  array{0: int, 1: ?int, 2: ?int}  $parts
     * @return array{0: int, 1: int, 2: int}
     */
    private static function caret(array $parts): array
    {
        [$major, $minor, $patch] = $parts;

        if ($major > 0 || $minor === null) {
            return [$major + 1, 0, 0];
        }

        if ($minor > 0 || $patch === null) {
            return [0, $minor + 1, 0];
        }

        return [0, 0, $patch + 1];
    }

    /**
     * @param  array{0: int, 1: ?int, 2: ?int}  $parts
     * @return array{0: int, 1: int, 2: int}
     */
    private static function tilde(array $parts): array
    {
        return $parts[1] === null ? [$parts[0] + 1, 0, 0] : [$parts[0], $parts[1] + 1, 0];
    }

    /**
     * @param  array{0: int, 1: ?int, 2: ?int}  $parts
     * @return array{0: int, 1: int, 2: int}
     */
    private static function pessimistic(array $parts): array
    {
        return $parts[2] === null ? [$parts[0] + 1, 0, 0] : [$parts[0], (int) $parts[1] + 1, 0];
    }

    /**
     * @param  list<array{0: string, 1: array{0: int, 1: int, 2: int}}>  $comparators
     * @param  array{0: int, 1: int, 2: int}  $candidate
     */
    private static function matches(array $comparators, array $candidate): bool
    {
        foreach ($comparators as [$operator, $bound]) {
            $order = $candidate <=> $bound;

            $holds = match ($operator) {
                '>=' => $order >= 0,
                '>' => $order > 0,
                '<' => $order < 0,
                '<=' => $order <= 0,
                '!=' => $order !== 0,
                default => $order === 0,
            };

            if (!$holds) {
                return false;
            }
        }

        return true;
    }
}
