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
     */
    public function resolve(string $runtime, ?Requirement $requirement, ?string $pin, array $installed): ResolvedRuntime
    {
        $note = null;

        if ($requirement !== null) {
            $constraint = VersionConstraint::parse($requirement->constraint, $requirement->flavour);

            if ($constraint !== null) {
                return $this->fromRequirement($runtime, $requirement, $constraint, $installed);
            }

            $note = "{$requirement->field} says " . Str::limit($requirement->raw, 60) . ', which is not a version Coresmith understands, so it was ignored.';
        }

        [$line, $source] = $pin !== null && $pin !== ''
            ? [$pin, ResolvedRuntime::FROM_PIN]
            : [$this->catalog->recommendedLine($runtime), ResolvedRuntime::FROM_DEFAULT];

        $constraint = VersionConstraint::parse($line);
        $version = $constraint?->exact ?? $constraint?->best($installed) ?? $this->catalog->line($runtime, $line)['latest'] ?? null;

        if ($version === null) {
            throw RuntimeUnavailable::retired($this->catalog->label($runtime), $line);
        }

        return new ResolvedRuntime($runtime, $version, $source, null, in_array($version, $installed, true), $note);
    }

    /**
     * @param  list<string>  $installed
     */
    private function fromRequirement(string $runtime, Requirement $requirement, VersionConstraint $constraint, array $installed): ResolvedRuntime
    {
        $label = $this->catalog->label($runtime);
        $version = $constraint->exact ?? $constraint->best($installed) ?? $constraint->best($this->catalog->versions($runtime));

        if ($version === null) {
            throw RuntimeUnavailable::unsatisfiable($label, $requirement, $this->catalog->range($runtime));
        }

        $oldest = $this->catalog->oldestLine($runtime);

        if ($oldest !== null && version_compare($this->catalog->lineOf($runtime, $version) ?? $version, $oldest, '<')) {
            throw RuntimeUnavailable::tooOld($label, $version, $requirement->field, $oldest);
        }

        return new ResolvedRuntime($runtime, $version, $requirement->source, $requirement->raw, in_array($version, $installed, true));
    }
}
