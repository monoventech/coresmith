<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamInvoice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamInvoice>
 */
class TeamInvoiceFactory extends Factory
{
    protected $model = TeamInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = 'in_' . Str::lower(Str::random(14));

        return [
            'team_id' => Team::factory(),
            'stripe_id' => $id,
            'number' => 'CS-' . fake()->unique()->numerify('####-####'),
            'status' => 'paid',
            'total' => 1900,
            'currency' => 'usd',
            'period_start' => now()->startOfMonth(),
            'paid_at' => now(),
            'next_attempt_at' => null,
            'attempt_count' => 1,
            'hosted_url' => "https://invoice.stripe.com/i/{$id}",
            'pdf_url' => "https://pay.stripe.com/invoice/{$id}/pdf",
            'stripe_updated_at' => now(),
        ];
    }

    public function open(): static
    {
        return $this->state(['status' => 'open', 'paid_at' => null]);
    }

    public function failed(CarbonInterface $nextAttempt): static
    {
        return $this->state(['status' => 'open', 'paid_at' => null, 'attempt_count' => 1, 'next_attempt_at' => $nextAttempt]);
    }

    public function void(): static
    {
        return $this->state(['status' => 'void', 'paid_at' => null]);
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft', 'paid_at' => null, 'number' => null]);
    }
}
