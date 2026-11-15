<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AppSize;
use App\Jobs\ApplyPhpLimits;
use App\Models\Application;
use App\Models\Server;
use App\Services\Php\PhpLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ApplicationPhpLimitsController extends Controller
{
    public function update(Request $request, Server $server, Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);
        abort_unless($application->type->language()->usesFpm(), 404);

        $current = PhpLimits::for($application);
        $size = ucfirst((AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small)->value);
        $values = $request->validate([
            'uploads' => ['required', 'integer', Rule::in(PhpLimits::UPLOADS)],
            'memory' => ['required', 'integer', Rule::in($current->memoryChoices())],
            'seconds' => ['required', 'integer', Rule::in(PhpLimits::SECONDS)],
            'reload' => ['required', 'string', Rule::in([PhpLimits::RELOAD_DEPLOY, PhpLimits::RELOAD_TIMESTAMPS])],
        ], [
            'memory.in' => "The {$size} size allows up to {$current->memoryCap} MB per process. Pick a larger size for more.",
        ]);

        $chosen = ['uploads' => (int) $values['uploads'], 'memory' => (int) $values['memory'], 'seconds' => (int) $values['seconds'], 'reload' => (string) $values['reload']];

        if ($chosen === $current->toArray()) {
            return back();
        }

        $application->forceFill(['php_limits' => $chosen])->save();

        activity()->performedOn($application)->withProperties(['from' => $current->toArray(), 'to' => $chosen])->log(ApplyPhpLimits::CHANGED);

        ApplyPhpLimits::dispatch($application);

        return back()->with('success', "Saved. PHP on {$server->name} picks these up in a few seconds, without a deploy.");
    }
}
