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
        Schema::create('sso_identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->index();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->ulid('team_id')->index();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->string('issuer');
            $table->string('subject');
            $table->string('email');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['issuer', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_identities');
    }
};
