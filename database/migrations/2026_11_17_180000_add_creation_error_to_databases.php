<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('databases', function (Blueprint $table): void {
            $table->string('creation_error', 500)->nullable()->after('status');
        });

        DB::table('databases')
            ->whereNull('db_password')
            ->whereNull('deleted_at')
            ->where('created_at', '<', now()->subHour())
            ->where(fn($query) => $query->where('type', '!=', 'redis')->orWhere('port', '!=', 6379))
            ->update(['status' => 'failed']);
    }

    public function down(): void
    {
        DB::table('databases')->where('status', 'failed')->update(['status' => 'active']);
        DB::table('databases')->where('status', 'creating')->update(['status' => 'active']);

        Schema::table('databases', function (Blueprint $table): void {
            $table->dropColumn('creation_error');
        });
    }
};
