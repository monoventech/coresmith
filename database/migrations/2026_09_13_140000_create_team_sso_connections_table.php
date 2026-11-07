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
        Schema::create('team_sso_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('team_id')->unique();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->string('issuer');
            $table->string('client_id');
            $table->text('client_secret');
            $table->string('email_domain', 253)->index();
            $table->string('domain_token', 64);
            $table->timestamp('domain_verified_at')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('jit_provisioning')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_sso_connections');
    }
};
