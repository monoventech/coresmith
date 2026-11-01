<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\InstanceSetting;
use App\Services\Panel\PanelUpdatesPresenter;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdateRequest;
use App\Services\Updates\UpdateState;
use App\Services\Updates\Version;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PanelUpdateController extends Controller
{
    public function status(Request $request, PanelUpdatesPresenter $presenter): JsonResponse
    {
        abort_unless($request->user()->managesInstance(), 403);

        return response()->json(['run' => $presenter->run()]);
    }

    public function check(Request $request, UpdateChecker $checker): RedirectResponse
    {
        $this->guard($request);

        $check = $checker->check();

        if (!$check->ok()) {
            return back()->with('error', (string) $check->error);
        }

        $available = $check->available(Version::current());

        return back()->with('success', $available === null ? "You're up to date." : "Coresmith v{$available} is available.");
    }

    public function store(Request $request, UpdateState $state, ReleaseLayout $layout, PanelUpdatesPresenter $presenter): RedirectResponse
    {
        $this->guard($request);

        $validated = $request->validate(['to' => ['required', 'string', 'max:64']]);

        if ($state->pendingRequest() !== null || $state->status()?->isRunning() === true || $state->isLocked()) {
            return back()->with('error', 'An update is already under way.');
        }

        if (!$layout->isManaged()) {
            return back()->with('error', "This install can't update itself yet: it doesn't use release directories.");
        }

        $available = $presenter->available();

        if ($available === null) {
            return back()->with('error', "There's no update to install.");
        }

        if (Version::tryParse($validated['to'])?->equals($available) !== true) {
            return back()->with('error', "A newer check found v{$available}. Review it, then update.");
        }

        $state->request(new UpdateRequest($available, 'panel', (string) $request->user()->getKey(), CarbonImmutable::now()));

        return back()->with('success', 'Update requested. It starts in a few seconds.');
    }

    public function cancel(Request $request, UpdateState $state): RedirectResponse
    {
        $this->guard($request);

        return $state->cancelRequest()
            ? back()->with('success', 'Request cancelled.')
            : back()->with('error', "The update has already started, so it can't be cancelled.");
    }

    public function recover(Request $request, UpdateState $state): RedirectResponse
    {
        $this->guard($request);

        if (!$state->interrupted()) {
            return back()->with('error', "There's nothing to recover.");
        }

        $state->request(new UpdateRequest(null, 'recover', (string) $request->user()->getKey(), CarbonImmutable::now()));

        return back()->with('success', 'Recovering. This takes about a minute.');
    }

    public function auto(Request $request): RedirectResponse
    {
        $this->guard($request);

        $validated = $request->validate(['on' => ['required', 'boolean']]);

        InstanceSetting::put('auto_update', $validated['on'] ? '1' : '0');

        return back()->with('success', 'Saved.');
    }

    public function log(Request $request, UpdateState $state, string $run): JsonResponse
    {
        $this->guard($request);

        $lines = $state->log($run);

        abort_if($lines === [], 404);

        return response()->json(['lines' => $lines]);
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->managesInstance(), 403);
        abort_unless((bool) config('coresmith.updates.enabled'), 404);
    }
}
