<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')->orderBy('created_at')->orderBy('id')->get(['id', 'email']);
        $taken = array_fill_keys($users->pluck('email')->all(), true);

        foreach ($users as $user) {
            $email = Str::lower(trim((string) $user->email));

            if ($email === $user->email || isset($taken[$email])) {
                continue;
            }

            DB::table('users')->where('id', $user->id)->update(['email' => $email]);

            unset($taken[$user->email]);
            $taken[$email] = true;
        }
    }
};
