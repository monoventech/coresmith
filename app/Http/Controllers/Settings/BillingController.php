<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Plan;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\Billing\BillingPresenter;
use App\Services\Billing\PlanChanges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class BillingController extends Controller
{
    public function show(Request $request, BillingPresenter $billing): Response
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $checkout = $request->query('checkout');

        return Inertia::render('settings/Billing', $billing->page($team, $request->user(), is_string($checkout) ? $checkout : null));
    }

    public function update(Request $request, PlanChanges $changes): HttpResponse
    {
        $team = $this->billingTeam();
        $choice = 'Choose Starter, Pro or Business.';

        $validated = $request->validate(
            ['plan' => ['required', 'string', Rule::in(array_map(fn(Plan $plan): string => $plan->value, Plan::purchasable()))]],
            ['plan.required' => $choice, 'plan.string' => $choice, 'plan.in' => $choice],
        );

        $plan = Plan::from($validated['plan']);
        $url = $changes->change($team, $plan, $request->user());

        if ($url !== null) {
            return Inertia::location($url);
        }

        return back()->with('success', "Saved. You're on {$plan->label()} now. The difference is prorated on your next invoice.");
    }

    public function destroy(Request $request, PlanChanges $changes): RedirectResponse
    {
        $endsAt = $changes->end($this->billingTeam(), $request->user());
        $day = $endsAt->setTimezone((string) config('app.timezone', 'UTC'))->format('M j');

        return back()->with('success', "Saved. The plan ends on {$day}. After that this team is read-only and its servers keep running.");
    }

    public function resume(Request $request, PlanChanges $changes): RedirectResponse
    {
        $changes->resume($this->billingTeam(), $request->user());

        return back()->with('success', 'Saved. The plan continues.');
    }

    public function portal(PlanChanges $changes): HttpResponse
    {
        return Inertia::location($changes->portalUrl($this->billingTeam()));
    }

    private function billingTeam(): Team
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $this->authorize('manageBilling', $team);

        return $team;
    }
}
