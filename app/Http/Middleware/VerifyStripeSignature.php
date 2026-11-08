<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

final class VerifyStripeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('cashier.webhook.secret');

        if (!is_string($secret) || $secret === '') {
            Log::critical('A Stripe webhook arrived but STRIPE_WEBHOOK_SECRET is not set, so it was refused.');

            return new Response('Stripe webhooks are not configured here.', 503);
        }

        try {
            WebhookSignature::verifyHeader($request->getContent(), (string) $request->header('Stripe-Signature'), $secret, (int) config('cashier.webhook.tolerance', 300));
        } catch (SignatureVerificationException) {
            return new Response('Invalid signature.', 403);
        }

        return $next($request);
    }
}
