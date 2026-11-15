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
        Schema::create('monitors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->float('threshold');
            $table->unsignedSmallInteger('duration');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['team_id', 'kind']);
        });

        Schema::create('server_monitors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->float('threshold');
            $table->unsignedSmallInteger('duration');
            $table->timestamps();
            $table->unique(['server_id', 'kind']);
        });

        Schema::create('monitor_alerts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('level', 16)->nullable();
            $table->float('peak')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('open_key', 100)->nullable()->unique();
            $table->timestamps();
            $table->index(['server_id', 'opened_at']);
        });

        Schema::create('app_checks_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedInteger('answered')->default(0);
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'day']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->timestamp('answered_at')->nullable();
            $table->unsignedSmallInteger('failed_checks')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['answered_at', 'failed_checks']);
        });

        Schema::dropIfExists('app_checks_daily');
        Schema::dropIfExists('monitor_alerts');
        Schema::dropIfExists('server_monitors');
        Schema::dropIfExists('monitors');
    }
};
