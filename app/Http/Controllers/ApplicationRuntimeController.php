<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Application\UpdateApplicationRuntimeRequest;
use App\Models\Application;
use App\Models\Server;
use App\Services\Runtime\Catalog;
use Illuminate\Http\RedirectResponse;

final class ApplicationRuntimeController extends Controller
{
    public function update(UpdateApplicationRuntimeRequest $request, Server $server, Application $application, Catalog $catalog): RedirectResponse
    {
        abort_unless($application->server_id === $server->id, 404);

        $runtime = $request->runtime();
        abort_if($runtime === null, 404);

        $line = $request->line();
        $application->forceFill(['runtime_pins' => $line === null ? null : [$runtime => $line]])->save();

        activity()->performedOn($application)->withProperties(['runtime' => $runtime, 'line' => $line])->log('runtime_chosen');

        return back()->with('success', $line === null
            ? 'Saved. The next deploy uses the version the repository asks for.'
            : 'Saved. The next deploy uses ' . $catalog->label($runtime) . " {$line}.");
    }
}
