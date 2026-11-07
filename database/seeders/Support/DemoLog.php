<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Enums\ApplicationType;
use App\Models\Application;
use Carbon\CarbonImmutable;

final class DemoLog
{
    public static function succeeded(Application $application, string $release, string $branch, string $sha, string $author, ?string $tag, CarbonImmutable $started, int $seconds): string
    {
        $scale = $seconds / 113;

        return implode("\n", [
            ...self::preamble($application, $release, $branch, $sha, $author, $tag, $started),
            ...self::dependencies($application, $started, $scale),
            ...self::build($application, $started, $scale),
            ...self::migrations($application, $release, $started, $scale),
            self::at($started, 88 * $scale) . ' health: checking the release before it goes live',
            'health: ' . self::healthTarget($application) . ' present',
            self::at($started, 108 * $scale) . ' swap: current → releases/' . $release,
            'symlink: current → releases/' . $release . ' (atomic swap)',
            'systemd: units reloaded, workers cycled gracefully',
            'cron: crontab refreshed for coresmith-app-' . $application->slug,
            'prune: kept the last 5 releases',
            self::at($started, $seconds) . ' deployed. previous release kept for instant rollback.',
        ]);
    }

    public static function failed(Application $application, string $release, string $branch, string $sha, string $author, CarbonImmutable $started): string
    {
        return implode("\n", [
            ...self::preamble($application, $release, $branch, $sha, $author, null, $started),
            ...self::dependencies($application, $started, 1),
            self::at($started, 44) . ' build: building the release',
            '$ bun run build',
            'error: Cannot resolve "./lib/pricing" from src/routes/cart.svelte',
            'build failed with 1 error',
            'The command "cd /var/coresmith/apps/' . $application->slug . '/releases/' . $release . ' && bun run build" failed with exit code 1.',
            self::at($started, 108) . ' deploy failed. the current release is untouched.',
        ]);
    }

    public static function running(Application $application, string $release, string $branch, string $sha, string $author, CarbonImmutable $started): string
    {
        return implode("\n", [
            ...self::preamble($application, $release, $branch, $sha, $author, 'v2.14.0', $started),
            ...self::dependencies($application, $started, 1),
            ...array_slice(self::build($application, $started, 1), 0, 2),
            self::at($started, 66) . ' migrate: running migrations against release ' . $release,
            '$ php artisan migrate --force --isolated',
            'INFO  Running migrations.',
            '2026_07_12_091412_add_invoices_status_index ............ 96ms DONE',
            '2026_07_28_133005_create_audit_events_table ............ 214ms DONE',
            '2026_08_02_101144_backfill_customer_regions ............ 1.2s DONE',
        ]);
    }

    /**
     * @return list<string>
     */
    private static function preamble(Application $application, string $release, string $branch, string $sha, string $author, ?string $tag, CarbonImmutable $started): array
    {
        return [
            self::at($started, 0) . ' release ' . $release . ' · branch ' . $branch . ' · triggered by Maya Lindqvist',
            self::at($started, 1) . ' clone: fetching via deploy key (ed25519, read-only)',
            'commit ' . $sha . ' by ' . $author . ($tag === null ? '' : ' · tag ' . $tag),
        ];
    }

    /**
     * @return list<string>
     */
    private static function dependencies(Application $application, CarbonImmutable $started, float $scale): array
    {
        return match ($application->type) {
            ApplicationType::Php => [
                self::at($started, 6 * $scale) . ' deps: installing dependencies',
                '$ composer install --no-dev --optimize-autoloader --no-interaction',
                'Installing dependencies from lock file',
                'Package operations: 112 installs, 0 updates, 0 removals',
                'Generating optimized autoload files',
            ],
            ApplicationType::Ruby => [
                self::at($started, 6 * $scale) . ' deps: installing dependencies',
                '$ bundle install --deployment --without development test',
                'Bundle complete! 41 Gemfile dependencies, 128 gems now installed.',
            ],
            default => [
                self::at($started, 6 * $scale) . ' deps: installing dependencies',
                '$ bun install --frozen-lockfile',
                '412 packages installed [6.81s]',
            ],
        };
    }

    /**
     * @return list<string>
     */
    private static function build(Application $application, CarbonImmutable $started, float $scale): array
    {
        return match ($application->type) {
            ApplicationType::Php => [
                self::at($started, 38 * $scale) . ' build: building the release',
                '$ php artisan config:cache',
                'INFO  Configuration cached successfully.',
                '$ php artisan route:cache',
                'INFO  Routes cached successfully.',
            ],
            ApplicationType::Ruby => [
                self::at($started, 38 * $scale) . ' build: building the release',
                '$ RAILS_ENV=production bundle exec rails assets:precompile',
                'Writing public/assets/application-4c1d.css',
            ],
            default => [
                self::at($started, 38 * $scale) . ' build: building the release',
                '$ bun run build',
                'built in 18.4s',
            ],
        };
    }

    /**
     * @return list<string>
     */
    private static function migrations(Application $application, string $release, CarbonImmutable $started, float $scale): array
    {
        return match ($application->type) {
            ApplicationType::Php => [
                self::at($started, 56 * $scale) . ' migrate: running migrations against release ' . $release,
                '$ php artisan migrate --force --isolated',
                'INFO  Running migrations.',
                '2026_08_11_160230_add_webhook_deliveries_unique ........ 88ms DONE',
                'INFO  Migrations complete.',
            ],
            ApplicationType::Ruby => [
                self::at($started, 56 * $scale) . ' migrate: running migrations against release ' . $release,
                '$ RAILS_ENV=production bundle exec rails db:migrate',
                '== 20260811160230 AddDeliveryIdIndex: migrated (0.0841s)',
            ],
            default => [],
        };
    }

    private static function healthTarget(Application $application): string
    {
        return match ($application->type) {
            ApplicationType::Php => 'public/index.php',
            ApplicationType::Ruby => 'Gemfile',
            ApplicationType::Static => 'public/',
            default => 'package.json',
        };
    }

    private static function at(CarbonImmutable $started, float $offset): string
    {
        return '[' . $started->addSeconds((int) round($offset))->format('H:i:s') . ']';
    }
}
