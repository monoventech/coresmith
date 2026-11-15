<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum PackageSource: string
{
    case Composer = 'composer';
    case Npm = 'npm';
    case Python = 'python';
    case Maven = 'maven';
    case Ruby = 'ruby';
    case Git = 'git';

    public function label(): string
    {
        return match ($this) {
            self::Composer => 'Composer',
            self::Npm => 'npm',
            self::Python => 'Python',
            self::Maven => 'Maven or Gradle',
            self::Ruby => 'RubyGems',
            self::Git => 'Git over HTTPS',
        };
    }
}
