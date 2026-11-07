<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Services\Billing\PlanGate;
use App\Services\Teams\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditLogController extends Controller
{
    public function __invoke(Request $request, AuditLog $log, PlanGate $plans): StreamedResponse
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $this->authorize('update', $team);

        $period = $request->validate(['period' => ['nullable', Rule::in(AuditLog::PERIODS)]])['period'] ?? '90';

        $plans->ensureIncludes($team, PlanFeature::AuditLogExport, $request->user());

        activity()->performedOn($team)->causedBy($request->user())->withProperties(['period' => $period])->log('audit_log_exported');

        $since = $period === 'all' ? null : now()->toImmutable()->subDays((int) $period);

        return response()->streamDownload(function () use ($log, $team, $since): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, AuditLog::HEADER, ',', '"', '');

            foreach ($log->rows($team, $since) as $row) {
                fputcsv($output, $row, ',', '"', '');
            }

            fclose($output);
        }, "coresmith-{$team->slug}-audit-" . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
