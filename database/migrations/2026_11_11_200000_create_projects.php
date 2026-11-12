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
        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->timestamps();
            $table->unique(['team_id', 'name']);
        });

        Schema::create('environments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->ulid('environment_id')->nullable()->index();
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->ulid('environment_id')->nullable()->index();
        });

        Schema::table('access_grants', function (Blueprint $table) {
            $table->ulid('project_id')->nullable();
            $table->ulid('environment_id')->nullable();
            $table->unique(['user_id', 'project_id']);
            $table->unique(['user_id', 'environment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('access_grants', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'environment_id']);
            $table->dropUnique(['user_id', 'project_id']);
            $table->dropColumn(['project_id', 'environment_id']);
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->dropIndex(['environment_id']);
            $table->dropColumn('environment_id');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['environment_id']);
            $table->dropColumn('environment_id');
        });

        Schema::dropIfExists('environments');
        Schema::dropIfExists('projects');
    }
};
