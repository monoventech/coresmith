<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();

        if ($connection instanceof MySqlConnection) {
            $collation = $connection->isMaria() ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';

            Schema::table('trial_history', function (Blueprint $table) use ($collation): void {
                $table->string('email')->collation($collation)->change();
            });
        }

        DB::table('trial_history')->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->pluck('email')->each(function (string $email): void {
            $first = DB::table('trial_history')->where('email', $email)->orderBy('started_at')->orderBy('id')->value('id');

            DB::table('trial_history')->where('email', $email)->where('id', '!=', $first)->delete();
        });

        Schema::table('trial_history', function (Blueprint $table): void {
            $table->dropIndex(['email']);
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('trial_history', function (Blueprint $table): void {
            $table->dropUnique(['email']);
            $table->index('email');
        });
    }
};
