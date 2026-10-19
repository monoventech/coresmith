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
        Schema::table('applications', function (Blueprint $table) {
            $table->ulid('source_provider_id')->nullable()->index()->after('repository_provider');
            $table->string('repository_id')->nullable()->after('source_provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropIndex(['source_provider_id']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['source_provider_id', 'repository_id']);
        });
    }
};
