<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Team;
use App\Models\TeamInvoice;
use Carbon\CarbonImmutable;

final class InvoiceMirror
{
    public function record(Team $team, array $invoice, int $eventCreated): ?TeamInvoice
    {
        if (!is_string($invoice['id'] ?? null) || ($invoice['status'] ?? 'draft') === 'draft') {
            return null;
        }

        $updated = CarbonImmutable::createFromTimestamp($eventCreated);
        $row = TeamInvoice::query()->where('stripe_id', $invoice['id'])->first();

        if ($row?->stripe_updated_at?->gt($updated)) {
            return $row;
        }

        $row ??= new TeamInvoice(['stripe_id' => $invoice['id']]);

        $row->fill([
            'team_id' => $team->getKey(),
            'number' => $invoice['number'] ?? null,
            'status' => (string) $invoice['status'],
            'total' => (int) ($invoice['total'] ?? 0),
            'currency' => strtolower((string) ($invoice['currency'] ?? 'usd')),
            'period_start' => $this->moment($invoice['lines']['data'][0]['period']['start'] ?? $invoice['period_start'] ?? $invoice['created'] ?? null),
            'paid_at' => $this->moment($invoice['status_transitions']['paid_at'] ?? null),
            'next_attempt_at' => $this->moment($invoice['next_payment_attempt'] ?? null),
            'attempt_count' => (int) ($invoice['attempt_count'] ?? 0),
            'hosted_url' => $invoice['hosted_invoice_url'] ?? null,
            'pdf_url' => $invoice['invoice_pdf'] ?? null,
            'stripe_updated_at' => $updated,
        ])->save();

        return $row;
    }

    private function moment(mixed $timestamp): ?CarbonImmutable
    {
        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }
}
