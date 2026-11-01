<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BillingController extends Controller
{
    public function show(Request $request, BillingPresenter $billing): Response
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        return Inertia::render('settings/Billing', $billing->page($team, $request->user()));
    }
}
