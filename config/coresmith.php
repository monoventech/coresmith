<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

return [
    'billing_enabled' => (bool) env('CORESMITH_BILLING_ENABLED', false),
    'vault_key' => env('CORESMITH_VAULT_KEY'),
];
