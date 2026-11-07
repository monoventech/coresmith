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
            $table->unsignedTinyInteger('connection_answers')->default(0)->after('connection_failures');
            $table->timestamp('unreachable_alerted_at')->nullable()->after('unreachable_since');
            $table->unsignedSmallInteger('unalerted_outages')->default(0)->after('unreachable_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['connection_answers', 'unreachable_alerted_at', 'unalerted_outages']);
        });
    }
};
