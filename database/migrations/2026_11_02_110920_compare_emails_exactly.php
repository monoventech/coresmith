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
        $connection = Schema::getConnection();

        if (!$connection instanceof MySqlConnection) {
            return;
        }

        $collation = $connection->isMaria() ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';

        foreach (['users', 'team_invitations', 'password_reset_tokens'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($collation) {
                $table->string('email')->collation($collation)->change();
            });
        }
    }
};
