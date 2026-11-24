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
        Schema::create('terminal_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('server_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 8);
            $table->string('status', 12)->default('starting');
            $table->unsignedSmallInteger('cols')->default(120);
            $table->unsignedSmallInteger('rows')->default(32);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_input_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 24)->nullable();
            $table->json('changes')->nullable();
            $table->timestamps();
            $table->index(['status', 'user_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_sessions');
    }
};
