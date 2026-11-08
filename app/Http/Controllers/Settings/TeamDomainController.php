<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Models\TeamDomain;
use App\Services\Billing\PlanGate;
use App\Services\Teams\TeamContext;
use App\Services\Teams\TeamDomains;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TeamDomainController extends Controller
{
    public function store(Request $request, TeamContext $context, TeamDomains $domains, PlanGate $plans): RedirectResponse
    {
        $team = $context->teamOrFail();

        $this->authorize('update', $team);

        $plans->ensureIncludes($team, PlanFeature::CustomDomain, $request->user());

        $request->merge(['hostname' => $domains->normalise((string) $request->input('hostname'))]);

        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail) use ($domains, $team): void {
                $refusal = $domains->refusal((string) $value, $team);

                if ($refusal !== null) {
                    $fail($refusal);
                }
            }],
        ]);

        $domain = $domains->attach($team, $validated['hostname']);

        activity()->performedOn($team)->withProperties(['hostname' => $domain->hostname])->log('team_domain_added');

        return back()->with('success', 'Saved. Add the two DNS records below, then check them.');
    }

    public function verify(Request $request, TeamContext $context, TeamDomains $domains, PlanGate $plans): RedirectResponse
    {
        $team = $context->teamOrFail();

        $this->authorize('update', $team);

        $plans->ensureIncludes($team, PlanFeature::CustomDomain, $request->user());

        $domain = $team->domain()->first();

        abort_unless($domain instanceof TeamDomain, 404);

        if ($domains->verify($domain)) {
            activity()->performedOn($team)->withProperties(['hostname' => $domain->hostname])->log('team_domain_verified');

            return back()->with('success', 'Verified. ' . $domain->hostname . ' now opens ' . $team->name . '.');
        }

        return back()->with('error', $domain->failure === TeamDomains::WRONG_VALUE
            ? 'The TXT record is there but its value does not match. Copy it again from this page.'
            : 'The TXT record is not visible yet. DNS can take a few minutes, check again shortly.');
    }

    public function destroy(TeamContext $context): RedirectResponse
    {
        $team = $context->teamOrFail();

        $this->authorize('update', $team);

        $domain = $team->domain()->first();

        abort_unless($domain instanceof TeamDomain, 404);

        $domain->delete();
        $team->unsetRelation('domain');

        activity()->performedOn($team)->withProperties(['hostname' => $domain->hostname])->log('team_domain_removed');

        return back()->with('success', 'Removed. ' . $domain->hostname . ' no longer opens this team.');
    }
}
