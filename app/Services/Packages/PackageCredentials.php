<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Packages;

use App\Enums\PackageSource;
use App\Models\Application;
use App\Models\PackageCredential;

final class PackageCredentials
{
    /**
     * @return list<PackageCredential>
     */
    public function forApplication(Application $application): array
    {
        $project = $application->project_id;

        $credentials = PackageCredential::query()
            ->where('team_id', $application->team_id)
            ->where(fn($query) => $query->whereNull('project_id')->when($project !== null, fn($query) => $query->orWhere('project_id', $project)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->sortBy(fn(PackageCredential $credential): int => $credential->project_id === null ? 1 : 0);

        $chosen = [];

        foreach ($credentials as $credential) {
            $chosen[self::key($credential)] ??= $credential;
        }

        return array_values($chosen);
    }

    public static function label(PackageCredential $credential): string
    {
        $target = (string) $credential->target;

        return match ($credential->source) {
            PackageSource::Npm => (string) parse_url(StepSecretsBuilder::registry($target), PHP_URL_HOST),
            PackageSource::Maven => $target,
            default => StepSecretsBuilder::host($target),
        };
    }

    public static function key(PackageCredential $credential): string
    {
        $target = (string) $credential->target;

        return $credential->source->value . '|' . match ($credential->source) {
            PackageSource::Npm => StepSecretsBuilder::registry($target),
            PackageSource::Maven => $target,
            default => StepSecretsBuilder::host($target),
        };
    }
}
