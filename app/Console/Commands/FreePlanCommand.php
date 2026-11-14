<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Edition;
use App\Enums\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Services\Billing\TeamBilling;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Throwable;

final class FreePlanCommand extends Command
{
    private const PLANS = [Plan::Starter, Plan::Pro, Plan::Business];

    protected $signature = 'coresmith:free-plan
        {team? : The team\'s address or ID; leave it out to list every free plan}
        {--plan= : starter, pro or business}
        {--until= : The last free day, as YYYY-MM-DD; leave it out for no end}
        {--end : End the team\'s free plan now}';

    protected $description = 'Give a Coresmith Cloud team a plan with nothing to pay, end it, or list them';

    public function handle(TeamBilling $billing): int
    {
        if (Edition::current()->isSelfHosted()) {
            return $this->refuse('Free plans exist only on Coresmith Cloud.');
        }

        $name = $this->argument('team');

        if (!is_string($name) || $name === '') {
            return $this->list();
        }

        $team = Team::query()->where('slug', $name)->orWhere('id', $name)->first();

        if (!$team instanceof Team) {
            return $this->refuse("No team has the address or ID {$name}.");
        }

        $plan = $this->option('plan');
        $until = $this->option('until');

        if ($this->option('end')) {
            return $plan === null ? $this->end($team) : $this->refuse('Pass --plan or --end, not both.');
        }

        if ($plan === null && $until === null) {
            $this->info($this->describe($team));

            return self::SUCCESS;
        }

        return $this->grant($team, $billing, is_string($plan) ? $plan : null, is_string($until) ? $until : null);
    }

    private function grant(Team $team, TeamBilling $billing, ?string $choice, ?string $until): int
    {
        $plan = $choice === null ? $team->free_plan : Plan::tryFrom($choice);

        if ($choice !== null && !in_array($plan, self::PLANS, true)) {
            return $this->refuse('Choose --plan=starter, --plan=pro or --plan=business.');
        }

        if (!$plan instanceof Plan) {
            return $this->refuse("{$team->name} has no free plan. Pass --plan to give it one.");
        }

        $last = $until === null ? null : $this->lastDay($until);

        if ($last === false) {
            return $this->refuse('--until takes a date like 2027-01-31.');
        }

        if ($last?->isPast() === true) {
            return $this->refuse('--until has to be today or later.');
        }

        $subscription = $team->subscription('default');

        if ($subscription instanceof Subscription && $subscription->valid()) {
            $paid = $billing->status($team)->plan?->label() ?? 'a plan';

            return $this->refuse("{$team->name} pays for {$paid} through Stripe. End that plan first, then give it a free one.");
        }

        $team->forceFill(['free_plan' => $plan, 'free_until' => $last])->save();

        activity()->performedOn($team)->withProperties(['plan' => $plan->value, 'until' => $last?->toDateString()])->log('free_plan_granted');

        $this->info($this->describe($team));

        return self::SUCCESS;
    }

    private function end(Team $team): int
    {
        $plan = $team->free_plan;

        if (!$plan instanceof Plan || ($team->free_until !== null && !$team->free_until->isFuture())) {
            return $this->refuse("{$team->name} has no free plan to end.");
        }

        $team->forceFill(['free_until' => CarbonImmutable::now()])->save();

        activity()->performedOn($team)->withProperties(['plan' => $plan->value])->log('free_plan_ended');

        $this->info("{$team->name}'s free plan has ended.");

        return self::SUCCESS;
    }

    private function list(): int
    {
        $teams = Team::query()->whereNotNull('free_plan')->orderBy('name')->get();

        if ($teams->isEmpty()) {
            $this->info('No team has a free plan.');

            return self::SUCCESS;
        }

        $this->table(['Team', 'Address', 'Plan', 'Until'], $teams->map(fn(Team $team): array => [
            $team->name,
            $team->slug,
            $team->free_plan?->label() ?? '',
            $this->until($team),
        ])->all());

        return self::SUCCESS;
    }

    private function describe(Team $team): string
    {
        $plan = $team->free_plan;

        if (!$plan instanceof Plan) {
            return "{$team->name} has no free plan.";
        }

        return match (true) {
            $team->free_until === null => "{$team->name} is on {$plan->label()} for free, with no end.",
            $team->free_until->isFuture() => "{$team->name} is on {$plan->label()} for free until {$this->day($team->free_until)}.",
            default => "{$team->name}'s free plan ended {$this->day($team->free_until)}.",
        };
    }

    private function until(Team $team): string
    {
        return match (true) {
            $team->free_until === null => 'No end',
            $team->free_until->isFuture() => $this->day($team->free_until),
            default => 'Ended ' . $this->day($team->free_until),
        };
    }

    private function lastDay(string $value): CarbonImmutable|false
    {
        $timezone = (string) config('app.timezone', 'UTC');

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        } catch (Throwable) {
            return false;
        }

        if (!$day instanceof CarbonImmutable || $day->format('Y-m-d') !== $value) {
            return false;
        }

        return $day->endOfDay();
    }

    private function day(DateTimeInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->setTimezone((string) config('app.timezone', 'UTC'))->format('M j, Y');
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
