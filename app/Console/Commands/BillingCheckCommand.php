<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Edition;
use App\Enums\Plan;
use App\Services\Panel\Formats;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Price;
use Stripe\WebhookEndpoint;

final class BillingCheckCommand extends Command
{
    protected $signature = 'coresmith:billing-check {--live : Also ask Stripe about the prices and the webhook}';

    protected $description = 'Check that this panel is ready to bill Coresmith Cloud teams through Stripe';

    private bool $failed = false;

    public function handle(): int
    {
        if (!$this->check(Edition::current()->isCloud(), 'Coresmith runs as Coresmith Cloud.', 'CORESMITH_EDITION is not cloud, so billing is off.')) {
            return self::FAILURE;
        }

        foreach (['STRIPE_KEY' => 'cashier.key', 'STRIPE_SECRET' => 'cashier.secret', 'STRIPE_WEBHOOK_SECRET' => 'cashier.webhook.secret'] as $name => $key) {
            $this->check($this->filled(config($key)), "{$name} is set.", "{$name} is missing.");
        }

        foreach (Plan::purchasable() as $plan) {
            $this->check($plan->checkoutPrice() !== null, "{$plan->label()} has a price.", 'STRIPE_PRICE_' . strtoupper($plan->value) . ' is missing.');
        }

        if ($this->option('live') && $this->filled(config('cashier.secret'))) {
            $this->live();
        }

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function live(): void
    {
        try {
            foreach (Plan::purchasable() as $plan) {
                $this->price($plan);
            }

            $this->webhook();
        } catch (AuthenticationException) {
            $this->check(false, '', 'Stripe refused STRIPE_SECRET, so the live checks stopped.');
        } catch (ApiErrorException) {
            $this->check(false, '', 'Stripe could not be reached, so the live checks stopped. Try again in a minute.');
        }
    }

    private function price(Plan $plan): void
    {
        $id = $plan->checkoutPrice();

        if ($id === null) {
            return;
        }

        try {
            $price = Cashier::stripe()->prices->retrieve($id);
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeCode() !== 'resource_missing') {
                throw $exception;
            }

            $this->check(false, '', "Stripe has no price {$id} for {$plan->label()}.");

            return;
        }

        $interval = $this->interval($price);
        $currency = strtoupper((string) $price->currency);
        $amount = Formats::cents((int) $price->unit_amount, 'usd');
        $expected = Formats::cents($plan->amount() * 100, 'usd');

        $failure = match (true) {
            !$price->active => "{$plan->label()} uses {$id}, which is archived in Stripe.",
            $interval !== 'month' => "{$plan->label()} uses {$id}, which Stripe bills every {$interval}, not every month.",
            $currency !== 'USD' => "{$plan->label()} uses {$id}, which is in {$currency}, not USD.",
            (int) $price->unit_amount !== $plan->amount() * 100 => "{$plan->label()} costs {$amount} a month in Stripe, but the panel says {$expected}.",
            default => null,
        };

        $this->check($failure === null, "{$plan->label()} costs {$amount} a month in Stripe.", (string) $failure);
    }

    private function webhook(): void
    {
        $url = route('cashier.webhook');
        $endpoint = collect(Cashier::stripe()->webhookEndpoints->all(['limit' => 100])->autoPagingIterator())
            ->first(fn(WebhookEndpoint $endpoint): bool => $endpoint->url === $url);

        if (!$endpoint instanceof WebhookEndpoint) {
            $this->check(false, '', "No Stripe webhook sends to {$url}. Run php artisan cashier:webhook.");

            return;
        }

        $events = (array) $endpoint->enabled_events;
        $missing = in_array('*', $events, true) ? [] : array_values(array_diff((array) config('cashier.webhook.events'), $events));
        $version = $endpoint->api_version ?? 'the account default';

        $failure = match (true) {
            $endpoint->status !== 'enabled' => "The Stripe webhook to {$url} is disabled. Turn it on in the Stripe dashboard.",
            $missing !== [] => "The Stripe webhook to {$url} does not send " . implode(', ', $missing) . '.',
            $version !== Cashier::STRIPE_VERSION => "The Stripe webhook to {$url} uses API version {$version}, but the panel expects " . Cashier::STRIPE_VERSION . '.',
            default => null,
        };

        $this->check($failure === null, "Stripe sends every event the panel needs to {$url}.", (string) $failure);
    }

    private function interval(Price $price): string
    {
        $interval = (string) ($price->recurring->interval ?? 'one-off payment');
        $count = (int) ($price->recurring->interval_count ?? 1);

        return $count > 1 ? "{$count} {$interval}s" : $interval;
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function check(bool $passed, string $success, string $failure): bool
    {
        $this->line($passed ? "✓ {$success}" : "✗ {$failure}");
        $this->failed = $this->failed || !$passed;

        return $passed;
    }
}
