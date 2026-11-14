<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

return [
    'version' => is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : '0.0.0',

    'updates' => [
        'enabled' => (bool) env('CORESMITH_UPDATES_ENABLED', false),
        'check_url' => env('CORESMITH_UPDATE_CHECK_URL', 'https://get.coresmith.io/v1/version'),
        'probe_url' => env('CORESMITH_UPDATE_PROBE_URL', 'http://127.0.0.1:8484/api/up/release'),
        'check_disabled' => (bool) env('CORESMITH_DISABLE_UPDATE_CHECK', false),
        'repository' => env('CORESMITH_REPOSITORY', 'https://github.com/monoventech/coresmith.git'),
        'issues_url' => env('CORESMITH_ISSUES_URL', 'https://github.com/monoventech/coresmith/issues'),
        'root' => env('CORESMITH_ROOT', '/opt/coresmith'),
        'state_path' => env('CORESMITH_UPDATE_STATE_PATH', '/var/lib/coresmith/updates'),
        'keys_path' => env('CORESMITH_RELEASE_KEYS_PATH', 'resources/release-keys'),
        'web_user' => 'caddy',
        'min_free_bytes' => 2 * 1024 ** 3,
        'drain_timeout' => 300,
        'restart_timeout' => 90,
        'health_timeout' => 60,
    ],

    'release_feed' => [
        'enabled' => (bool) env('CORESMITH_RELEASE_FEED', false),
        'channel' => env('CORESMITH_RELEASE_FEED_CHANNEL', 'stable'),
        'minimum' => env('CORESMITH_RELEASE_FEED_MINIMUM'),
        'withdrawn' => (string) env('CORESMITH_RELEASE_FEED_WITHDRAWN', ''),
        'changelog_url' => env('CORESMITH_RELEASE_FEED_CHANGELOG_URL', 'https://github.com/monoventech/coresmith/releases/tag/v{version}'),
    ],

    'edition' => env('CORESMITH_EDITION', 'self-hosted'),

    'billing' => [
        'trial_days' => (int) env('CORESMITH_TRIAL_DAYS', 14),
        'signups_per_hour' => (int) env('CORESMITH_SIGNUPS_PER_HOUR', 10),
        'stripe_api_base' => env('CORESMITH_STRIPE_API_BASE'),
        'plans' => [
            'starter' => ['amount' => 9, 'prices' => env('STRIPE_PRICE_STARTER')],
            'pro' => ['amount' => 19, 'prices' => env('STRIPE_PRICE_PRO')],
            'business' => ['amount' => 39, 'prices' => env('STRIPE_PRICE_BUSINESS')],
        ],
    ],
    'vault_key' => env('CORESMITH_VAULT_KEY'),
    'setup_token_path' => env('CORESMITH_SETUP_TOKEN_PATH', '/var/lib/coresmith/setup-token'),
    'tenant_domain' => env('CORESMITH_TENANT_DOMAIN'),

    'teams' => [
        'invitations_per_hour' => (int) env('CORESMITH_INVITATIONS_PER_HOUR', 20),
    ],

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

    'address' => [
        'site' => (string) env('CORESMITH_CADDY_SITE', ''),
        'caddy_admin_socket' => (string) env('CORESMITH_CADDY_ADMIN_SOCKET', ''),
        'caddyfile' => '/etc/caddy/Caddyfile',
        'apps_config' => '/etc/caddy/caddy-config.json',
        'tailscaled_defaults' => '/etc/default/tailscaled',
        'state_path' => env('CORESMITH_ADDRESS_STATE_PATH', '/var/lib/coresmith/address'),
        'reach_url' => (string) env('CORESMITH_REACH_URL', 'https://get.coresmith.io/v1/reach'),
        'cloudflare_api' => (string) env('CORESMITH_CLOUDFLARE_API', 'https://api.cloudflare.com/client/v4'),
    ],

    'this_machine' => [
        'state_path' => env('CORESMITH_THIS_MACHINE_STATE_PATH', '/var/lib/coresmith-this-machine'),
    ],

    'removal' => [
        'state_path' => env('CORESMITH_REMOVAL_STATE_PATH', '/var/lib/coresmith-uninstall'),
        'answer_path' => env('CORESMITH_REMOVAL_ANSWER_PATH', '/run/coresmith-uninstall'),
    ],

    'live' => [
        'refresh_seconds' => max(15, (int) env('CORESMITH_LIVE_REFRESH_SECONDS', 60)),
        'socket' => [
            'key' => (string) env('VITE_REVERB_APP_KEY', ''),
            'host' => (string) env('VITE_REVERB_HOST', ''),
            'port' => (int) env('VITE_REVERB_PORT', 443),
            'scheme' => (string) env('VITE_REVERB_SCHEME', 'https'),
        ],
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
        'python' => [
            'label' => 'Python',
            'line' => 'minor',
            'recommended' => '3.14',
            'prefer_recommended' => true,
            'versions' => [
                ['line' => '3.15', 'latest' => '3.15.2', 'active_until' => '2028-10-31', 'eol_at' => '2031-10-31'],
                ['line' => '3.14', 'latest' => '3.14.10', 'active_until' => '2027-10-31', 'eol_at' => '2030-10-31'],
                ['line' => '3.13', 'latest' => '3.13.18', 'active_until' => '2026-10-31', 'eol_at' => '2029-10-31'],
                ['line' => '3.12', 'latest' => '3.12.16', 'active_until' => '2025-04-30', 'eol_at' => '2028-10-31'],
                ['line' => '3.11', 'latest' => '3.11.18', 'active_until' => '2024-04-30', 'eol_at' => '2027-10-31'],
            ],
        ],
        'go' => [
            'label' => 'Go',
            'line' => 'minor',
            'recommended' => '1.27',
            'versions' => [
                ['line' => '1.27', 'latest' => '1.27.4', 'active_until' => '2027-08-31', 'eol_at' => '2027-08-31'],
                ['line' => '1.26', 'latest' => '1.26.11', 'active_until' => '2027-02-28', 'eol_at' => '2027-02-28'],
                ['line' => '1.25', 'latest' => '1.25.14', 'active_until' => '2026-08-19', 'eol_at' => '2026-08-19'],
            ],
        ],
    ],

    'uv' => [
        'version' => '0.13.6',
        'sha256' => [
            'x86_64' => 'f29c3e94ffabd7e6f3ba521dba169a9b7ec12100bf3257087f0f24f6123f3645',
            'aarch64' => '229e194255c7958d86d56e8790d969a6f74736592133f0dfd0076f754c642c1d',
        ],
    ],

    'cloud' => [
        'catalog_seconds' => 600,
        'min_memory_mb' => 1024,
        'min_disk_gb' => 10,
        'images' => [
            ['family' => 'ubuntu', 'version' => '24.04', 'label' => 'Ubuntu 24.04'],
            ['family' => 'ubuntu', 'version' => '22.04', 'label' => 'Ubuntu 22.04'],
            ['family' => 'debian', 'version' => '12', 'label' => 'Debian 12'],
        ],
        'step_seconds' => [
            'create' => 900, 'boot' => 600, 'ssh' => 600, 'settle' => 900, 'delete' => 600,
            'power_off' => 300, 'wait_off' => 300, 'change' => 300, 'wait_change' => 1200,
            'power_on' => 300, 'wait_on' => 300, 'reconnect' => 600, 'recover' => 300,
        ],
    ],
];
