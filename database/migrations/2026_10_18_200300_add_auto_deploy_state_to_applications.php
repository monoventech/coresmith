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
        Schema::table('applications', function (Blueprint $table) {
            $table->string('repository_head', 40)->nullable()->after('repository_id');
            $table->string('repository_head_source', 8)->nullable()->after('repository_head');
            $table->timestamp('repository_head_at')->nullable()->after('repository_head_source');
            $table->timestamp('polled_at')->nullable()->after('repository_head_at');
            $table->string('poll_error', 255)->nullable()->after('polled_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['repository_head', 'repository_head_source', 'repository_head_at', 'polled_at', 'poll_error']);
        });
    }
};
