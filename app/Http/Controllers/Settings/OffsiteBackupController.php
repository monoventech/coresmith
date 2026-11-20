<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Models\BackupDestination;
use App\Models\Team;
use App\Services\Billing\PlanGate;
use App\Services\Database\S3DestinationInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OffsiteBackupController extends Controller
{
    public function update(Request $request, PlanGate $plans): RedirectResponse
    {
        $team = $this->team();

        $plans->ensureIncludes($team, PlanFeature::OffsiteBackups, $request->user());

        $existing = $team->backupDestination()->first();

        $validated = $request->validate([
            ...S3DestinationInput::rules($existing),
            'frequency' => ['nullable', 'in:' . BackupDestination::DAILY . ',' . BackupDestination::HOURLY],
        ], [
            ...S3DestinationInput::messages(),
            'frequency.in' => 'Choose every night or every hour.',
        ]);

        $frequency = $validated['frequency'] ?? $existing?->frequency ?? BackupDestination::DAILY;

        $team->backupDestination()->updateOrCreate([], [...S3DestinationInput::values($validated, $request), 'frequency' => $frequency]);

        activity()->performedOn($team)->causedBy($request->user())->withProperties(['bucket' => $validated['bucket']])->log('offsite_backups_configured');

        return back()->with('success', $frequency === BackupDestination::HOURLY ? 'Saved. Databases go to S3 every hour.' : 'Saved. Databases go to S3 every night at 02:30.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $team = $this->team();

        $team->backupDestination()->delete();

        activity()->performedOn($team)->causedBy($request->user())->log('offsite_backups_removed');

        return back()->with('success', 'Removed. Databases are no longer sent to S3.');
    }

    private function team(): Team
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $this->authorize('update', $team);

        return $team;
    }
}
