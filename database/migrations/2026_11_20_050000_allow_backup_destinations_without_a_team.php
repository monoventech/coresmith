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
        Schema::table('backup_destinations', function (Blueprint $table) {
            $table->ulid('team_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('backup_destinations')->whereNull('team_id')->delete();

        Schema::table('backup_destinations', function (Blueprint $table) {
            $table->ulid('team_id')->nullable(false)->change();
        });
    }
};
