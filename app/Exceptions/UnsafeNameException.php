<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

final class UnsafeNameException extends InvalidArgumentException
{
    public static function slug(string $value): self
    {
        return new self("[{$value}] is not a valid application slug. Slugs are lowercase letters, digits and hyphens only.");
    }

    public static function process(string $value): self
    {
        return new self("[{$value}] is not a valid process name. Process names are lowercase letters, digits and hyphens only.");
    }

    public static function databaseIdentifier(string $value): self
    {
        return new self("[{$value}] is not a valid database name or user. SQL identifiers cannot be parameterised, so only letters, digits and underscores are accepted.");
    }

    public static function cronSchedule(string $value): self
    {
        return new self('[' . addcslashes($value, "\0..\37") . '] is not a valid cron schedule. Write five fields separated by single spaces, using only digits and * / , - in each, such as */5 * * * *.');
    }

    public static function domain(string $value): self
    {
        return new self("[{$value}] is not a valid domain name.");
    }

    public static function branch(string $value): self
    {
        return new self('[' . addcslashes($value, "\0..\37") . '] is not a branch name git accepts as a plain name, so it could be read as an option or a pattern.');
    }

    public static function relativePath(string $value): self
    {
        return new self('[' . addcslashes($value, "\0..\37") . '] is not a path inside the repository. Use letters, digits, dots, hyphens and underscores, with no . or .. segments.');
    }

    public static function systemUser(string $value): self
    {
        return new self("[{$value}] is not a valid system user name.");
    }

    public static function unit(string $value): self
    {
        return new self('[' . addcslashes($value, "\0..\37") . '] is not a valid deploy unit name. Unit names are coresmith-build-, coresmith-migrate- or coresmith-blueprint-, then the app slug and a release.');
    }

    public static function workerCommand(): self
    {
        return new self('A worker command cannot be blank: it would run /usr/bin/env with nothing to execute and print the unit\'s environment to the log.');
    }
}
