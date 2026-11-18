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
        Schema::create('application_database', function (Blueprint $table) {
            $table->ulid('application_id');
            $table->ulid('database_id');
            $table->timestamps();
            $table->primary(['application_id', 'database_id']);
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->foreign('database_id')->references('id')->on('databases')->cascadeOnDelete();
            $table->index('database_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_database');
    }
};
