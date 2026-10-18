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
        Schema::create('github_apps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('team_id')->index();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->string('app_id')->unique();
            $table->string('slug');
            $table->string('name');
            $table->string('client_id');
            $table->text('client_secret');
            $table->text('webhook_secret')->nullable();
            $table->text('private_key');
            $table->string('owner_login');
            $table->string('owner_type');
            $table->string('html_url');
            $table->json('permissions')->nullable();
            $table->json('events')->nullable();
            $table->string('hook_url')->nullable();
            $table->ulid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_apps');
    }
};
