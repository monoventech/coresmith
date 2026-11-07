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
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('release')->nullable()->after('triggered_by');
            $table->string('branch')->nullable()->after('release');
            $table->string('commit_author')->nullable()->after('commit_message');
            $table->string('tag')->nullable()->after('commit_author');
            $table->boolean('tag_signed')->nullable()->after('tag');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['release', 'branch', 'commit_author', 'tag', 'tag_signed']);
        });
    }
};
