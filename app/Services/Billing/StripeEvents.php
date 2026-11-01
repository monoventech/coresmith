<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\StripeEvent;
use Closure;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class StripeEvents
{
    public function once(array $payload, Closure $handle): Response
    {
        $id = (string) $payload['id'];

        return Cache::lock('stripe-event:' . $id, 120)->block(30, function () use ($payload, $id, $handle): Response {
            if (StripeEvent::query()->whereKey($id)->exists()) {
                return new Response('Already handled', 200);
            }

            $response = $handle() ?? new Response('Webhook Handled', 200);

            if ($response->getStatusCode() < 300) {
                StripeEvent::query()->create(['id' => $id, 'type' => (string) $payload['type'], 'processed_at' => now()]);
            }

            return $response;
        });
    }
}
