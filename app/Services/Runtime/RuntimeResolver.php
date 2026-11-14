<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Exceptions\RuntimeUnavailable;
use Illuminate\Support\Str;

final class RuntimeResolver
{
    public function __construct(private readonly Catalog $catalog) {}

    /**
     * @param  list<string>  $installed
     * @param  list<Limit>  $limits
     */
    public function resolve(string $runtime, ?Requirement $requirement, ?string $pin, array $installed, array $limits = []): ResolvedRuntime
    {
        $note = null;
        $pin = $pin === '' ? null : $pin;
        $bounds = $this->bounds($limits);

        if ($requirement !== null) {
            $constraint = VersionConstraint::parse($requirement->constraint, $requirement->flavour);

            if ($constraint !== null) {
                return $pin === null
                    ? $this->fromRequirement($runtime, $requirement, $constraint, $installed, $bounds)
                    : $this->pinnedWithin($runtime, $pin, $requirement, $constraint, $installed, $bounds);
            }

            $note = "{$requirement->field} says " . Str::limit($requirement->raw, 60) . ', which is not a version Coresmith understands, so it was ignored.';
        }

        if ($pin !== null) {
            return $this->pinned($runtime, $pin, $installed, $bounds, $note);
        }

        return $this->recommended($runtime, $installed, $bounds, $note);
    }

    /**
     * @param  list<string>  $installed
     * @param  list<array{Limit, VersionConstraint}>  $bounds
     */
    private function fromRequirement(string $runtime, Requirement $requirement, VersionConstraint $constraint, array $installed, array $bounds): ResolvedRuntime
    {
        $label = $this->catalog->label($runtime);
        $catalog = $this->catalog->versions($runtime);

        if ($constraint->exact !== null) {
            $blocking = $this->blocking($constraint->exact, $bounds);

            if ($blocking !== []) {
                throw RuntimeUnavailable::locked($label, $requirement, $blocking);
            }
        }

        $version = $constraint->exact ?? $this->recommendedWithin($runtime, $constraint, $installed, $bounds) ?? $constraint->best($this->fitting($installed, $bounds)) ?? $constraint->best($this->fitting($catalog, $bounds));
        $newest = $constraint->exact === null ? $constraint->best($catalog) : null;

        if ($version === null) {
            if ($newest !== null && $bounds !== []) {
                throw RuntimeUnavailable::locked($label, $requirement, $this->blocking($newest, $bounds));
            }

            throw RuntimeUnavailable::unsatisfiable($label, $requirement, $this->catalog->range($runtime));
        }

        $oldest = $this->catalog->oldestLine($runtime);

        if ($oldest !== null && version_compare($this->catalog->lineOf($runtime, $version) ?? $version, $oldest, '<')) {
            throw RuntimeUnavailable::tooOld($label, $version, $requirement->field, $oldest);
        }

        $held = $newest !== null ? $this->blocking($newest, $bounds) : [];

        return new ResolvedRuntime($runtime, $version, $requirement->source, $requirement->raw, in_array($version, $installed, true), null, $this->names($held), $held === [] ? null : $newest);
    }

    /**
     * @param  list<string>  $installed
     * @param  list<array{Limit, VersionConstraint}>  $bounds
     */
    private function pinnedWithin(string $runtime, string $pin, Requirement $requirement, VersionConstraint $constraint, array $installed, array $bounds): ResolvedRuntime
    {
        $label = $this->catalog->label($runtime);
        $line = VersionConstraint::parse($pin);
        $latest = $line?->exact ?? $this->catalog->line($runtime, $pin)['latest'] ?? null;

        if ($latest === null) {
            throw RuntimeUnavailable::retired($label, $pin);
        }

        if (!$constraint->satisfiedBy($latest)) {
            throw RuntimeUnavailable::pinRuledOut($label, $pin, $requirement);
        }

        $blocking = $this->blocking($latest, $bounds);

        if ($blocking !== []) {
            throw RuntimeUnavailable::pinLocked($label, $pin, $blocking);
        }

        $usable = array_values(array_filter($this->fitting($installed, $bounds), fn(string $version): bool => $constraint->satisfiedBy($version)));
        $version = $line?->exact ?? $line?->best($usable) ?? $latest;

        return new ResolvedRuntime($runtime, $version, ResolvedRuntime::FROM_PIN, null, in_array($version, $installed, true));
    }

    /**
     * @param  list<string>  $installed
     * @param  list<array{Limit, VersionConstraint}>  $bounds
     */
    private function pinned(string $runtime, string $pin, array $installed, array $bounds, ?string $note): ResolvedRuntime
    {
        $label = $this->catalog->label($runtime);
        $line = VersionConstraint::parse($pin);
        $version = $line?->exact ?? $line?->best($this->fitting($installed, $bounds)) ?? $this->catalog->line($runtime, $pin)['latest'] ?? null;

        if ($version === null) {
            throw RuntimeUnavailable::retired($label, $pin);
        }

        $blocking = $this->blocking($version, $bounds);

        if ($blocking !== []) {
            throw RuntimeUnavailable::pinLocked($label, $pin, $blocking);
        }

        return new ResolvedRuntime($runtime, $version, ResolvedRuntime::FROM_PIN, null, in_array($version, $installed, true), $note);
    }

    /**
     * @param  list<string>  $installed
     * @param  list<array{Limit, VersionConstraint}>  $bounds
     */
    private function recommended(string $runtime, array $installed, array $bounds, ?string $note): ResolvedRuntime
    {
        $label = $this->catalog->label($runtime);
        $line = $this->catalog->recommendedLine($runtime);
        $constraint = VersionConstraint::parse($line);
        $version = $constraint?->exact ?? $constraint?->best($installed) ?? $this->catalog->line($runtime, $line)['latest'] ?? null;

        if ($version === null) {
            throw RuntimeUnavailable::retired($label, $line);
        }

        $held = $this->blocking($version, $bounds);

        if ($held === []) {
            return new ResolvedRuntime($runtime, $version, ResolvedRuntime::FROM_DEFAULT, null, in_array($version, $installed, true), $note);
        }

        $fallback = $this->newest($this->fitting($installed, $bounds)) ?? $this->newest($this->fitting($this->catalog->versions($runtime), $bounds));

        if ($fallback === null) {
            throw RuntimeUnavailable::lockedAlone($label, $held);
        }

        return new ResolvedRuntime($runtime, $fallback, ResolvedRuntime::FROM_DEFAULT, null, in_array($fallback, $installed, true), $note, $this->names($held), $version);
    }

    /**
     * @param  list<string>  $installed
     * @param  list<array{Limit, VersionConstraint}>  $bounds
     */
    private function recommendedWithin(string $runtime, VersionConstraint $constraint, array $installed, array $bounds): ?string
    {
        if (!$this->catalog->prefersRecommended($runtime)) {
            return null;
        }

        $line = $this->catalog->recommendedLine($runtime);
        $latest = $this->catalog->line($runtime, $line)['latest'] ?? null;
        $ours = array_values(array_filter($installed, fn(string $version): bool => $this->catalog->lineOf($runtime, $version) === $line));

        return $constraint->best($this->fitting($ours, $bounds)) ?? ($latest === null ? null : $constraint->best($this->fitting([$latest], $bounds)));
    }

    /**
     * @param  list<Limit>  $limits
     * @return list<array{Limit, VersionConstraint}>
     */
    private function bounds(array $limits): array
    {
        $bounds = [];

        foreach ($limits as $limit) {
            $constraint = VersionConstraint::parse($limit->constraint, 'composer');

            if ($constraint !== null) {
                $bounds[] = [$limit, $constraint];
            }
        }

        return $bounds;
    }

    /**
     * @param  list<string>  $versions
     * @param  list<array{Limit, VersionConstraint}>  $bounds
     * @return list<string>
     */
    private function fitting(array $versions, array $bounds): array
    {
        return array_values(array_filter($versions, fn(string $version): bool => $this->blocking($version, $bounds) === []));
    }

    /**
     * @param  list<array{Limit, VersionConstraint}>  $bounds
     * @return list<Limit>
     */
    private function blocking(string $version, array $bounds): array
    {
        $blocking = [];

        foreach ($bounds as [$limit, $constraint]) {
            if (!$constraint->satisfiedBy($version)) {
                $blocking[] = $limit;
            }
        }

        return $blocking;
    }

    /**
     * @param  list<string>  $versions
     */
    private function newest(array $versions): ?string
    {
        usort($versions, fn(string $a, string $b): int => version_compare($b, $a));

        return $versions[0] ?? null;
    }

    /**
     * @param  list<Limit>  $limits
     * @return list<string>
     */
    private function names(array $limits): array
    {
        return array_map(fn(Limit $limit): string => "{$limit->package} {$limit->version}", $limits);
    }
}
