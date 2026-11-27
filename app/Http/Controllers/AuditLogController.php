<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanFeature;
use App\Services\Billing\BillingPresenter;
use App\Services\Billing\PlanGate;
use App\Services\Panel\Paging;
use App\Services\Teams\AuditLog;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AuditLogController extends Controller
{
    public function index(Request $request, AuditLog $log, BillingPresenter $billing): Response|RedirectResponse
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $this->authorize('update', $team);

        $filters = AuditLog::filters($request->query());
        $page = Paging::page($request->query('page'));
        $query = Arr::except($request->query(), ['page']);
        $shown = $log->page($team, $filters, $page, $request->user());

        if ($shown['events'] === [] && $page > 1) {
            return redirect()->route('audit-log.index', $query);
        }

        $note = $billing->featureNote($team, PlanFeature::AuditLogExport);

        return Inertia::render('audit-log/Index', [
            'events' => $shown['events'],
            'pages' => Paging::feed($page, $shown['more'], fn(int $number): string => route('audit-log.index', [...$query, 'page' => $number])),
            'filters' => $filters,
            'people' => $log->people($team),
            'export' => ['href' => $note === null ? route('audit-log.export', AuditLog::exportQuery($filters)) : null, 'note' => $note],
        ]);
    }

    public function export(Request $request, AuditLog $log, PlanGate $plans): StreamedResponse
    {
        $team = $this->currentTeam();

        abort_if($team === null, 404);

        $this->authorize('update', $team);

        $request->validate([
            'period' => ['nullable', Rule::in(AuditLog::PERIODS)],
            'what' => ['nullable', Rule::in(AuditLog::KINDS)],
            'who' => ['nullable', 'string', fn(string $attribute, mixed $value, Closure $fail) => in_array($value, ['anyone', 'coresmith'], true) || Str::isUlid((string) $value) ? null : $fail('Choose someone from the list.')],
        ]);

        $filters = AuditLog::filters($request->query());

        $plans->ensureIncludes($team, PlanFeature::AuditLogExport, $request->user());

        activity()->performedOn($team)->causedBy($request->user())->withProperties(AuditLog::exportQuery($filters))->log('audit_log_exported');

        return response()->streamDownload(function () use ($log, $team, $filters): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, AuditLog::HEADER, ',', '"', '');

            foreach ($log->rows($team, $filters) as $row) {
                fputcsv($output, $row, ',', '"', '');
            }

            fclose($output);
        }, "coresmith-{$team->slug}-audit-" . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function legacy(Request $request): RedirectResponse
    {
        return redirect()->route('audit-log.export', [...$request->query(), 'what' => 'everything']);
    }
}
