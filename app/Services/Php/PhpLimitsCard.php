<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Php;

use App\Enums\AppSize;
use App\Jobs\ApplyPhpLimits;
use App\Models\Application;
use Spatie\Activitylog\Models\Activity;

final class PhpLimitsCard
{
    /**
     * @return array<string, mixed>|null
     */
    public static function describe(Application $application): ?array
    {
        if (!$application->type->language()->usesFpm()) {
            return null;
        }

        $limits = PhpLimits::for($application);

        return [
            'values' => $limits->toArray(),
            'cells' => [
                ['label' => 'Uploads up to', 'value' => PhpLimits::size($limits->uploads) . ' per request'],
                ['label' => 'Memory', 'value' => PhpLimits::size($limits->memory) . ' per process'],
                ['label' => 'Web requests stop after', 'value' => self::seconds($limits->seconds)],
                ['label' => 'New code loads', 'value' => $limits->reload === PhpLimits::RELOAD_DEPLOY ? 'At each deploy' : 'Every 2 seconds'],
            ],
            'choices' => [
                'uploads' => array_map(fn(int $megabytes): array => ['value' => $megabytes, 'label' => PhpLimits::size($megabytes) . ' per request'], PhpLimits::UPLOADS),
                'memory' => array_map(fn(int $megabytes): array => ['value' => $megabytes, 'label' => PhpLimits::size($megabytes)], $limits->memoryChoices()),
                'seconds' => array_map(fn(int $seconds): array => ['value' => $seconds, 'label' => self::seconds($seconds)], PhpLimits::SECONDS),
            ],
            'size' => ucfirst((AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small)->value),
            'cap' => PhpLimits::size($limits->memoryCap),
            'held' => $limits->held,
            'failure' => self::failure($application),
        ];
    }

    private static function seconds(int $seconds): string
    {
        return $seconds < 120 ? "{$seconds} seconds" : intdiv($seconds, 60) . ' minutes';
    }

    private static function failure(Application $application): ?string
    {
        $failed = Activity::query()
            ->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->where('description', ApplyPhpLimits::FAILED)
            ->latest('id')
            ->first();

        if ($failed === null || ($application->php_written_at !== null && $application->php_written_at->gt($failed->created_at))) {
            return null;
        }

        $error = $failed->properties['error'] ?? null;

        return is_string($error) ? $error : null;
    }
}
