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
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('subject_id', 36)->nullable()->change();
            $table->string('causer_id', 36)->nullable()->change();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('tokenable_id', 36)->change();
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->string('id')->change();
        });
    }
};
