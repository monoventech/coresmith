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
        Schema::table('deployments', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('finished_at');
            $table->ulid('cancelled_by')->nullable()->index()->after('cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropIndex(['cancelled_by']);
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['cancel_requested_at', 'cancelled_by']);
        });
    }
};
