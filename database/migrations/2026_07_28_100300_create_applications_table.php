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
        Schema::create('applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('server_id');
            $table->ulid('team_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type');
            $table->string('js_runtime')->nullable();
            $table->string('framework')->nullable();
            $table->string('domain')->nullable();
            $table->string('repository_url')->nullable();
            $table->string('repository_branch')->default('main');
            $table->string('repository_provider')->nullable();
            $table->text('custom_blueprint')->nullable();
            $table->text('env_variables')->nullable();
            $table->string('system_user');
            $table->unsignedSmallInteger('base_port');
            $table->string('php_version')->nullable();
            $table->string('node_version')->nullable();
            $table->string('ruby_version')->nullable();
            $table->string('directory')->default('/');
            $table->string('web_directory')->default('/public');
            $table->string('database_type')->nullable();
            $table->boolean('run_migrations')->default(true);
            $table->unsignedSmallInteger('max_upload_size_mb')->default(50);
            $table->string('status')->default('pending');
            $table->boolean('quick_deploy')->default(false);
            $table->string('power_state')->default('active');
            $table->string('app_size')->default('small');
            $table->unsignedSmallInteger('idle_timeout_minutes')->default(30);
            $table->timestamp('last_request_at')->nullable();
            $table->unsignedSmallInteger('ssr_port')->nullable();
            $table->json('toggles')->nullable();
            $table->json('processes')->nullable();
            $table->json('cron_jobs')->nullable();
            $table->foreign('server_id')->references('id')->on('servers');
            $table->foreign('team_id')->references('id')->on('teams');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
