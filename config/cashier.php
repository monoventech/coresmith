<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

return [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => 300,
        'events' => [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.updated',
            'customer.deleted',
            'payment_method.automatically_updated',
            'checkout.session.completed',
            'invoice.finalized',
            'invoice.paid',
            'invoice.payment_failed',
            'invoice.voided',
            'invoice.marked_uncollectible',
            'invoice.payment_action_required',
            'invoice.payment_succeeded',
        ],
    ],
    'currency' => 'usd',
    'currency_locale' => 'en',
];
