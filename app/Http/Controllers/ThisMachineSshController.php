<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\CheckThisMachineSsh;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;

final class ThisMachineSshController extends Controller
{
    public function store(Server $server): RedirectResponse
    {
        abort_unless($server->sshLeftToOwner(), 404);
        $this->authorize('update', $server);

        CheckThisMachineSsh::dispatch($server);

        return back()->with('success', 'Checking now.');
    }
}
