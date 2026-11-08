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
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('moving_at')->nullable();
            $table->string('moving_to')->nullable();
            $table->longText('move_file')->nullable();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->json('takeover')->nullable();
        });

        Schema::create('move_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->text('public_key');
            $table->text('secret_key')->nullable();
            $table->timestamp('expires_at');
            $table->longText('file')->nullable();
            $table->foreignUlid('team_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('move_codes');

        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('takeover');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['moving_at', 'moving_to', 'move_file']);
        });
    }
};
