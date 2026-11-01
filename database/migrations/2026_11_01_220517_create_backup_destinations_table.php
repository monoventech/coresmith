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
        Schema::create('backup_destinations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('endpoint')->nullable();
            $table->string('region', 32);
            $table->string('bucket', 63);
            $table->string('prefix', 200)->nullable();
            $table->text('access_key_id');
            $table->text('secret_access_key');
            $table->boolean('path_style')->default(false);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->timestamp('last_offsite_at')->nullable();
            $table->string('last_offsite_key')->nullable();
            $table->string('last_offsite_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn(['last_offsite_at', 'last_offsite_key', 'last_offsite_error']);
        });

        Schema::dropIfExists('backup_destinations');
    }
};
