<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;

final class Preferences
{
    public const DEPLOY_FAILED = 'deploy_failed';

    public const DISK_PRESSURE = 'disk_pressure';

    public const TOGGLE_CHANGES = 'toggle_changes';

    public const WEEKLY_DIGEST = 'weekly_digest';

    public const DEFAULTS = [
        self::DEPLOY_FAILED => true,
        self::DISK_PRESSURE => true,
        self::TOGGLE_CHANGES => true,
        self::WEEKLY_DIGEST => false,
    ];

    public const LOCKED = [self::DEPLOY_FAILED];

    public const LABELS = [
        self::DEPLOY_FAILED => 'Deploy failed',
        self::DISK_PRESSURE => 'Disk above 80%',
        self::TOGGLE_CHANGES => 'Toggle changes',
        self::WEEKLY_DIGEST => 'Weekly digest',
    ];

    public const DESCRIPTIONS = [
        self::DEPLOY_FAILED => 'Email immediately when any phase fails',
        self::DISK_PRESSURE => 'Server disk warnings, at most once a day',
        self::TOGGLE_CHANGES => 'When someone stops, starts or puts an app into maintenance',
        self::WEEKLY_DIGEST => 'Deploys, rollbacks and warnings, every Monday',
    ];

    /**
     * @return array<string, bool>
     */
    public static function for(User $user): array
    {
        $stored = (array) $user->notification_preferences;
        $preferences = [];

        foreach (self::DEFAULTS as $key => $default) {
            $preferences[$key] = in_array($key, self::LOCKED, true) ? true : (bool) ($stored[$key] ?? $default);
        }

        return $preferences;
    }

    public static function wants(User $user, string $key): bool
    {
        return self::for($user)[$key] ?? false;
    }
}
