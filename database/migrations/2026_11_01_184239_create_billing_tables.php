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
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->string('pm_expires', 5)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('trial_reminded_at')->nullable();
            $table->string('pending_checkout_id')->nullable();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('team_id')->constrained();
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'stripe_status']);
        });

        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->string('meter_id')->nullable();
            $table->string('meter_event_name')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'stripe_price']);
        });

        Schema::create('team_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('team_id')->constrained();
            $table->string('stripe_id')->unique();
            $table->string('number')->nullable();
            $table->string('status');
            $table->bigInteger('total');
            $table->string('currency', 3);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->text('hosted_url')->nullable();
            $table->text('pdf_url')->nullable();
            $table->timestamp('stripe_updated_at');
            $table->timestamps();

            $table->index(['team_id', 'period_start']);
        });

        Schema::create('stripe_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('processed_at');
        });

        Schema::create('trial_history', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->ulid('team_id')->nullable();
            $table->timestamp('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_history');
        Schema::dropIfExists('stripe_events');
        Schema::dropIfExists('team_invoices');
        Schema::dropIfExists('subscription_items');
        Schema::dropIfExists('subscriptions');

        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'pm_expires', 'trial_ends_at', 'trial_reminded_at', 'pending_checkout_id']);
        });
    }
};
