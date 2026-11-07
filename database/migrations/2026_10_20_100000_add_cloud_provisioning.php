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
        Schema::table('cloud_providers', function (Blueprint $table) {
            $table->text('api_token')->nullable()->change();
            $table->string('account_label')->nullable()->after('name');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('token_failed_at')->nullable();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->ulid('cloud_provider_id')->nullable()->after('team_id');
            $table->index('cloud_provider_id');
            $table->string('provider_server_id', 64)->nullable();
            $table->string('provider_size', 64)->nullable();
            $table->string('provider_image', 64)->nullable();
            $table->decimal('provider_monthly_cost', 10, 2)->nullable();
            $table->string('provider_currency', 3)->nullable();
            $table->json('provider_task')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropIndex(['cloud_provider_id']);
            $table->dropColumn([
                'cloud_provider_id',
                'provider_server_id',
                'provider_size',
                'provider_image',
                'provider_monthly_cost',
                'provider_currency',
                'provider_task',
            ]);
        });

        Schema::table('cloud_providers', function (Blueprint $table) {
            $table->dropColumn(['account_label', 'verified_at', 'token_failed_at']);
        });
    }
};
