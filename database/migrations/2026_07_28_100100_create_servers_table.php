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
        Schema::create('servers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('team_id');
            $table->string('name');
            $table->string('ip_address');
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_user')->default('root');
            $table->text('ssh_private_key');
            $table->text('ssh_public_key');
            $table->string('host_key_fingerprint')->nullable();
            $table->string('os_name')->nullable();
            $table->string('os_version')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('provisioning_phase')->nullable();
            $table->text('provisioning_log')->nullable();
            $table->json('installed_runtimes')->nullable();
            $table->json('meta')->nullable();
            $table->boolean('audit_logging_enabled')->default(false);
            $table->timestamp('last_connected_at')->nullable();
            $table->foreign('team_id')->references('id')->on('teams');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
