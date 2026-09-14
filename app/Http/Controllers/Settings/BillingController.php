<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BillingController extends Controller
{
    public function show(Request $request): Response
    {
        $team = $this->currentTeam();

        return Inertia::render('settings/Billing', [
            'cloud' => (bool) config('coresmith.billing_enabled'),
            'servers' => $team?->servers()->count() ?? 0,
            'team' => $team?->name,
        ]);
    }
}
