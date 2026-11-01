<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use Laravel\Cashier\Console\WebhookCommand;

return [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => 300,
        'events' => WebhookCommand::DEFAULT_EVENTS,
    ],
    'currency' => 'usd',
    'currency_locale' => 'en',
];
