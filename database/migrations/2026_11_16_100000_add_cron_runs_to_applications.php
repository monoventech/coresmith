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
            $table->json('cron_runs')->nullable();
            $table->timestamp('cron_written_at')->nullable();
        });

        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->string('job', 16)->nullable()->after('application_id');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->dropColumn('job');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['cron_runs', 'cron_written_at']);
        });
    }
};
