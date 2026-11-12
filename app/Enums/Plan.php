<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Enums;

enum Plan: string
{
    case Trial = 'trial';
    case Starter = 'starter';
    case Pro = 'pro';
    case Business = 'business';
    case SelfHosted = 'self-hosted';

    /**
     * @return list<self>
     */
    public static function purchasable(): array
    {
        return [self::Starter, self::Pro, self::Business];
    }

    public static function fromPriceId(?string $price): ?self
    {
        if ($price === null || $price === '') {
            return null;
        }

        foreach (self::purchasable() as $plan) {
            if (in_array($price, $plan->priceIds(), true)) {
                return $plan;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Starter => 'Starter',
            self::Pro => 'Pro',
            self::Business => 'Business',
            self::SelfHosted => 'Self-hosted',
        };
    }

    public function amount(): int
    {
        return $this->isPurchasable() ? (int) config("coresmith.billing.plans.{$this->value}.amount", 0) : 0;
    }

    /**
     * @return list<string>
     */
    public function priceIds(): array
    {
        if (!$this->isPurchasable()) {
            return [];
        }

        $prices = config("coresmith.billing.plans.{$this->value}.prices");

        return array_values(array_filter(
            array_map('trim', explode(',', is_string($prices) ? $prices : '')),
            fn(string $price): bool => $price !== '',
        ));
    }

    public function checkoutPrice(): ?string
    {
        return $this->priceIds()[0] ?? null;
    }

    public function serverLimit(): ?int
    {
        return match ($this) {
            self::Trial => 1,
            self::Starter => 3,
            default => null,
        };
    }

    public function memberLimit(): ?int
    {
        return $this === self::Starter ? 1 : null;
    }

    public function includes(PlanFeature $feature): bool
    {
        return match ($this) {
            self::SelfHosted, self::Business => true,
            self::Pro, self::Trial => in_array($feature, [PlanFeature::TeamRoles, PlanFeature::CustomDomain], true),
            self::Starter => false,
        };
    }

    public function summary(?string $price = null): string
    {
        $price ??= "\${$this->amount()}";

        return match ($this) {
            self::Starter => "{$price} per month · {$this->serverLimit()} servers · one person",
            self::Pro => "{$price} per month · unlimited servers · teammates and roles · custom domain",
            self::Business => "{$price} per month · everything in Pro · single sign-on · database backups to S3 · audit log export",
            self::SelfHosted => 'On your own server · you install, update and back it up',
            self::Trial => 'Pro features on ' . $this->serverLimit() . ' server for ' . (int) config('coresmith.billing.trial_days', 14) . ' days',
        };
    }

    private function isPurchasable(): bool
    {
        return in_array($this, self::purchasable(), true);
    }
}
