<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BackupDestination;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupDestination>
 */
class BackupDestinationFactory extends Factory
{
    protected $model = BackupDestination::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'endpoint' => null,
            'region' => 'eu-central-1',
            'bucket' => 'coresmith-backups',
            'prefix' => null,
            'access_key_id' => 'AKIAEXAMPLE0000000000',
            'secret_access_key' => 'an-example-secret-access-key',
            'path_style' => false,
        ];
    }
}
