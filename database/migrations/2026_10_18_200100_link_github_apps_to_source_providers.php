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
        Schema::table('source_providers', function (Blueprint $table) {
            $table->ulid('github_app_id')->nullable()->index()->after('installation_id');
            $table->string('account_type')->nullable()->after('github_app_id');
            $table->string('repository_selection')->nullable()->after('account_type');
            $table->timestamp('suspended_at')->nullable()->after('repository_selection');
            $table->string('last_delivery_id')->nullable()->after('suspended_at');
            $table->string('last_event')->nullable()->after('last_delivery_id');
            $table->unsignedSmallInteger('last_status')->nullable()->after('last_event');
            $table->timestamp('last_delivered_at')->nullable()->after('last_status');
        });

        Schema::table('source_providers', function (Blueprint $table) {
            $table->dropColumn(['app_id', 'app_private_key']);
        });
    }

    public function down(): void
    {
        Schema::table('source_providers', function (Blueprint $table) {
            $table->text('app_private_key')->nullable();
            $table->string('app_id')->nullable();
        });

        Schema::table('source_providers', function (Blueprint $table) {
            $table->dropIndex(['github_app_id']);
        });

        Schema::table('source_providers', function (Blueprint $table) {
            $table->dropColumn([
                'github_app_id',
                'account_type',
                'repository_selection',
                'suspended_at',
                'last_delivery_id',
                'last_event',
                'last_status',
                'last_delivered_at',
            ]);
        });
    }
};
