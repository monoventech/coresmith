<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\CheckDomainReach;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

final class ApplicationDomainCheckController extends Controller
{
    public function __invoke(Server $server, Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);
        abort_if($application->allDomains() === [], 404);

        CheckDomainReach::dispatch($application);

        return back()->with('success', 'Checking from outside. The results show here in a few seconds.');
    }
}
