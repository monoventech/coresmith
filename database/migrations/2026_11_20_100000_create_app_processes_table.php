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
        Schema::create('app_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('application_id')->constrained()->cascadeOnDelete();
            $table->string('unit', 191);
            $table->string('process', 64);
            $table->string('active_state', 32);
            $table->string('sub_state', 32);
            $table->unsignedBigInteger('memory_bytes')->nullable();
            $table->unsignedInteger('restarts')->default(0);
            $table->json('restart_times')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['unit', 'application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_processes');
    }
};
