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
        Schema::create('server_tunnels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('server_id')->unique();
            $table->string('state', 16);
            $table->string('account_id')->nullable();
            $table->string('tunnel_id')->nullable();
            $table->json('hosts')->nullable();
            $table->json('skipped')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_tunnels');
    }
};
