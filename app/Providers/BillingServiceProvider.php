<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Providers;

use App\Models\Subscription;
use App\Models\Team;
use App\Services\Billing\StripeHttpClient;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Stripe\ApiRequestor;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Cashier::ignoreRoutes();
    }

    public function boot(): void
    {
        Cashier::useCustomerModel(Team::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::keepPastDueSubscriptionsActive();
        ApiRequestor::setHttpClient($this->app->make(StripeHttpClient::class));

        $base = config('coresmith.billing.stripe_api_base');

        if (is_string($base) && $base !== '' && !$this->app->isProduction()) {
            Cashier::$apiBaseUrl = rtrim($base, '/');
        }
    }
}
