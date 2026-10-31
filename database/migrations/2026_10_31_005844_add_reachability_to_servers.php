<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('unreachable_since')->nullable()->after('last_connected_at');
            $table->unsignedTinyInteger('connection_failures')->default(0)->after('unreachable_since');
            $table->string('last_connection_error', 255)->nullable()->after('connection_failures');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['unreachable_since', 'connection_failures', 'last_connection_error']);
        });
    }
};
