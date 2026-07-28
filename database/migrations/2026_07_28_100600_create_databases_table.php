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
        Schema::create('databases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('server_id');
            $table->ulid('team_id');
            $table->string('type');
            $table->string('name');
            $table->string('db_user')->nullable();
            $table->text('db_password')->nullable();
            $table->string('host')->default('127.0.0.1');
            $table->unsignedSmallInteger('port');
            $table->string('status')->default('active');
            $table->string('privilege_level')->default('standard');
            $table->json('extensions')->nullable();
            $table->boolean('is_external')->default(false);
            $table->foreign('server_id')->references('id')->on('servers');
            $table->foreign('team_id')->references('id')->on('teams');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('databases');
    }
};
