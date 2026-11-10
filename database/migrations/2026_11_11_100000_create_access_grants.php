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
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->ulid('team_id');
            $table->ulid('user_id');
            $table->ulid('server_id')->nullable();
            $table->ulid('application_id')->nullable();
            $table->timestamps();
            $table->foreign('team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->unique(['user_id', 'server_id']);
            $table->unique(['user_id', 'application_id']);
            $table->index(['team_id', 'user_id']);
        });

        Schema::table('team_user', function (Blueprint $table) {
            $table->boolean('sees_everything')->default(true);
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            $table->boolean('sees_everything')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropColumn('sees_everything');
        });

        Schema::table('team_user', function (Blueprint $table) {
            $table->dropColumn('sees_everything');
        });

        Schema::dropIfExists('access_grants');
    }
};
