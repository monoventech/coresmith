<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

return [
    'version' => env('CORESMITH_VERSION', '0.1.0'),
    'billing_enabled' => (bool) env('CORESMITH_BILLING_ENABLED', false),
    'vault_key' => env('CORESMITH_VAULT_KEY'),
    'setup_token_path' => env('CORESMITH_SETUP_TOKEN_PATH', '/var/lib/coresmith/setup-token'),
    'tenant_domain' => env('CORESMITH_TENANT_DOMAIN'),

    'content_security_policy' => (bool) env('CORESMITH_CSP', true),
    'websocket_origin' => env('VITE_REVERB_APP_KEY') && env('VITE_REVERB_HOST')
        ? sprintf(
            '%s://%s:%s',
            env('VITE_REVERB_SCHEME', 'https') === 'https' ? 'wss' : 'ws',
            env('VITE_REVERB_HOST'),
            env('VITE_REVERB_PORT', env('VITE_REVERB_SCHEME', 'https') === 'https' ? 443 : 80),
        )
        : null,

    'sentry' => [
        'browser_dsn' => env('SENTRY_BROWSER_DSN') ?: null,
        'browser_traces_sample_rate' => (float) env('SENTRY_BROWSER_TRACES_SAMPLE_RATE', 0.0),
    ],

    'activity' => [
        'ssh_command_days' => (int) env('CORESMITH_SSH_ACTIVITY_DAYS', 90),
    ],

    'auto_deploy' => [
        'poll_minutes' => (int) env('CORESMITH_POLL_MINUTES', 1),
        'safety_net_minutes' => (int) env('CORESMITH_POLL_SAFETY_NET_MINUTES', 10),
        'server_poll_minutes' => (int) env('CORESMITH_SERVER_POLL_MINUTES', 5),
    ],

    'live' => [
        'refresh_seconds' => max(15, (int) env('CORESMITH_LIVE_REFRESH_SECONDS', 60)),
    ],

    'runtimes' => [
        'php' => [
            'label' => 'PHP',
            'line' => 'minor',
            'recommended' => '8.5',
            'versions' => [
                ['line' => '8.5', 'latest' => '8.5.10', 'active_until' => '2027-12-31', 'eol_at' => '2029-12-31'],
                ['line' => '8.4', 'latest' => '8.4.25', 'active_until' => '2026-12-31', 'eol_at' => '2028-12-31'],
                ['line' => '8.3', 'latest' => '8.3.33', 'active_until' => '2025-12-31', 'eol_at' => '2027-12-31'],
                ['line' => '8.2', 'latest' => '8.2.33', 'active_until' => '2024-12-31', 'eol_at' => '2026-12-31'],
                ['line' => '8.1', 'latest' => '8.1.34', 'active_until' => '2023-11-25', 'eol_at' => '2025-12-31'],
            ],
        ],
        'nodejs' => [
            'label' => 'Node.js',
            'line' => 'major',
            'recommended' => '24',
            'versions' => [
                ['line' => '26', 'latest' => '26.9.0', 'active_until' => '2027-10-27', 'eol_at' => '2029-04-30'],
                ['line' => '24', 'latest' => '24.21.0', 'active_until' => '2026-10-20', 'eol_at' => '2028-04-30'],
                ['line' => '22', 'latest' => '22.23.2', 'active_until' => '2025-10-21', 'eol_at' => '2027-04-30'],
                ['line' => '20', 'latest' => '20.20.2', 'active_until' => '2024-10-22', 'eol_at' => '2026-04-30'],
            ],
        ],
        'bun' => [
            'label' => 'Bun',
            'line' => 'major',
            'recommended' => '1',
            'versions' => [
                ['line' => '1', 'latest' => '1.4.2', 'active_until' => null, 'eol_at' => null],
            ],
        ],
        'deno' => [
            'label' => 'Deno',
            'line' => 'major',
            'recommended' => '2',
            'versions' => [
                ['line' => '2', 'latest' => '2.9.7', 'active_until' => null, 'eol_at' => null],
            ],
        ],
        'ruby' => [
            'label' => 'Ruby',
            'line' => 'minor',
            'recommended' => '3.4',
            'versions' => [
                ['line' => '4.0', 'latest' => '4.0.7', 'active_until' => null, 'eol_at' => '2029-03-31'],
                ['line' => '3.4', 'latest' => '3.4.10', 'active_until' => null, 'eol_at' => '2028-03-31'],
                ['line' => '3.3', 'latest' => '3.3.12', 'active_until' => '2026-03-31', 'eol_at' => '2027-03-31'],
                ['line' => '3.2', 'latest' => '3.2.11', 'active_until' => '2025-03-31', 'eol_at' => '2026-03-31'],
            ],
        ],
    ],
];
