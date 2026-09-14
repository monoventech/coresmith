<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Support\MonovenTenant;
use Illuminate\Database\Seeder;

final class DemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        (new MonovenTenant)->build([
            'owner' => 'maya@monoven.io',
            'admin' => 'jonas@monoven.io',
            'member' => 'priya@monoven.io',
            'password' => self::PASSWORD,
        ]);
    }
}
