<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

use App\Stacks\Go;
use App\Stacks\Java;
use App\Stacks\JavaScript;
use App\Stacks\Language;
use App\Stacks\Php;
use App\Stacks\Python;
use App\Stacks\Ruby;
use App\Stacks\StaticSite;

enum ApplicationType: string
{
    case Php = 'php';
    case Js = 'js';
    case Ruby = 'ruby';
    case Static = 'static';
    case Python = 'python';
    case Go = 'go';
    case Java = 'java';

    public function runtime(?string $jsRuntime = null): ?string
    {
        return match ($this) {
            self::Php => 'php',
            self::Ruby => 'ruby',
            self::Python => 'python',
            self::Go => 'go',
            self::Java => 'java',
            self::Js => match ($jsRuntime) {
                'node' => 'nodejs',
                'deno' => 'deno',
                default => 'bun',
            },
            self::Static => null,
        };
    }

    public function language(): Language
    {
        return app(match ($this) {
            self::Php => Php::class,
            self::Js => JavaScript::class,
            self::Ruby => Ruby::class,
            self::Static => StaticSite::class,
            self::Python => Python::class,
            self::Go => Go::class,
            self::Java => Java::class,
        });
    }
}
