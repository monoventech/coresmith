<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::getConnection() instanceof MySqlConnection) {
            return;
        }

        Schema::table('deployments', function (Blueprint $table) {
            $table->longText('log')->nullable()->change();
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->longText('env_variables')->nullable()->change();
        });
    }
};
