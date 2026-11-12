<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Runtime\Limit;
use App\Services\Runtime\Requirement;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

final class RuntimeUnavailable extends RuntimeException implements ExplainsFailure
{
    public static function unsatisfiable(string $label, Requirement $requirement, string $range): self
    {
        return new self("{$requirement->field} asks for {$label} " . Str::limit($requirement->raw, 60) . ", which matches no version Coresmith can install ({$range}). Change it, then deploy.");
    }

    /**
     * @param  list<string>  $missing
     */
    public static function incomplete(string $label, string $version, array $missing): self
    {
        $verb = count($missing) === 1 ? 'is' : 'are';

        return new self("{$label} {$version} finished installing, but " . Arr::join($missing, ', ', ' and ') . " {$verb} missing. Read the install log above, then deploy again.");
    }

    public static function tooOld(string $label, string $version, string $field, string $oldest): self
    {
        return new self("{$field} asks for {$label} {$version}, older than the oldest version Coresmith builds ({$oldest}). Set it to {$oldest} or newer, then deploy.");
    }

    public static function retired(string $label, string $line): self
    {
        return new self("This app was added with {$label} {$line}, which Coresmith no longer builds. Add a version file to the repository, then deploy.");
    }

    /**
     * @param  list<Limit>  $blocking
     */
    public static function locked(string $label, Requirement $requirement, array $blocking): self
    {
        return new self("{$requirement->field} asks for {$label} " . Str::limit($requirement->raw, 60) . ', but composer.lock holds ' . self::holds($label, $blocking) . '. Run composer update ' . self::names($blocking) . ', commit composer.lock, then deploy.');
    }

    /**
     * @param  list<Limit>  $blocking
     */
    public static function lockedAlone(string $label, array $blocking): self
    {
        return new self('composer.lock holds ' . self::holds($label, $blocking) . ", and no {$label} version Coresmith can install fits them all. Run composer update " . self::names($blocking) . ', commit composer.lock, then deploy.');
    }

    public static function pinRuledOut(string $label, string $line, Requirement $requirement): self
    {
        return new self("This app is set to {$label} {$line}, but {$requirement->field} asks for " . Str::limit($requirement->raw, 60) . ". Pick another version on the app's page or change {$requirement->source}, then deploy.");
    }

    /**
     * @param  list<Limit>  $blocking
     */
    public static function pinLocked(string $label, string $line, array $blocking): self
    {
        return new self("This app is set to {$label} {$line}, but composer.lock holds " . self::holds($label, $blocking) . ". Pick another version on the app's page, or run composer update " . self::names($blocking) . ' and commit composer.lock, then deploy.');
    }

    /**
     * @param  list<Limit>  $blocking
     */
    private static function holds(string $label, array $blocking): string
    {
        return Arr::join(array_map(fn(Limit $limit): string => "{$limit->package} {$limit->version} ({$label} {$limit->constraint})", $blocking), ', ', ' and ');
    }

    /**
     * @param  list<Limit>  $blocking
     */
    private static function names(array $blocking): string
    {
        return implode(' ', array_map(fn(Limit $limit): string => $limit->package, $blocking));
    }
}
