<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Plan;
use App\Models\Subscription;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => 'default',
            'stripe_id' => 'sub_' . Str::lower(Str::random(14)),
            'stripe_status' => 'active',
            'stripe_price' => Plan::Pro->checkoutPrice(),
            'quantity' => 1,
            'renews_at' => now()->addDays(30),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Subscription $subscription): void {
            $subscription->items()->create([
                'stripe_id' => 'si_' . Str::lower(Str::random(14)),
                'stripe_product' => 'prod_coresmith_cloud',
                'stripe_price' => (string) $subscription->stripe_price,
                'quantity' => 1,
            ]);
        });
    }

    public function plan(Plan $plan): static
    {
        return $this->state(['stripe_price' => $plan->checkoutPrice()]);
    }

    public function status(string $status): static
    {
        return $this->state(['stripe_status' => $status]);
    }

    public function trialing(CarbonInterface $until): static
    {
        return $this->state(['stripe_status' => 'trialing', 'trial_ends_at' => $until]);
    }

    public function ending(CarbonInterface $at): static
    {
        return $this->state(['ends_at' => $at]);
    }

    public function ended(CarbonInterface $at): static
    {
        return $this->state(['stripe_status' => 'canceled', 'ends_at' => $at, 'renews_at' => null]);
    }
}
