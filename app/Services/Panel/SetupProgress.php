<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Models\Deployment;
use App\Models\Team;

final class SetupProgress
{
    public const STEPS = ['Connect GitHub', 'Add a server', 'Provision', 'First deploy'];

    /** @var list<bool>|null */
    private ?array $done = null;

    public function __construct(private readonly Team $team) {}

    /**
     * @return list<bool>
     */
    public function done(): array
    {
        return $this->done ??= [
            $this->team->sourceProviders()->exists(),
            $this->team->servers()->exists(),
            $this->team->servers()->where('status', ServerStatus::Active->value)->exists(),
            Deployment::query()
                ->whereIn('application_id', $this->team->applications()->select('id'))
                ->where('status', DeploymentStatus::Succeeded->value)
                ->exists(),
        ];
    }

    public function complete(): bool
    {
        return !in_array(false, $this->done(), true);
    }

    public function current(): int
    {
        $index = array_search(false, $this->done(), true);

        return $index === false ? count(self::STEPS) - 1 : (int) $index;
    }

    /**
     * @return array{complete: bool, current: int, total: int}
     */
    public function summary(): array
    {
        return [
            'complete' => $this->complete(),
            'current' => $this->current(),
            'total' => count(self::STEPS),
        ];
    }
}
