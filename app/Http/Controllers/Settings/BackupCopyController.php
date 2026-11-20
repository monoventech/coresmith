<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\Edition;
use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use App\Services\Database\S3DestinationInput;
use App\Services\Panel\BackupCopy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BackupCopyController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $this->authorizeCopy($request);

        $validated = $request->validate(S3DestinationInput::rules(BackupDestination::forCoresmith()), S3DestinationInput::messages());

        BackupDestination::query()->updateOrCreate(['team_id' => null], S3DestinationInput::values($validated, $request));

        activity()->causedBy($request->user())->withProperties(['bucket' => $validated['bucket']])->log('backup_copy_configured');

        return back()->with('success', "Saved. Each backup is copied to S3 without Coresmith's keys.");
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->authorizeCopy($request);

        BackupDestination::query()->whereNull('team_id')->delete();
        BackupCopy::forget();

        activity()->causedBy($request->user())->log('backup_copy_removed');

        return back()->with('success', 'Removed. Backups stay on this machine.');
    }

    private function authorizeCopy(Request $request): void
    {
        abort_if(Edition::current()->isCloud(), 404);
        abort_unless($request->user()->managesInstance(), 403);
    }
}
