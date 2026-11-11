<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Edition;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Address\InstallationAddress;
use App\Services\Install\Removal;
use App\Services\Server\ThisMachine;

final class RemovalPresenter
{
    public function __construct(
        private readonly Removal $removal,
        private readonly InstallationAddress $address,
    ) {}

    /**
     * @return array{address: string, servers: int, other_teams: list<string>, this_machine_apps: bool, database: string, database_name: string|null, two_factor: bool, helper: bool, installer: string}|null
     */
    public function present(User $user, ?Team $current): ?array
    {
        if (Edition::current()->isCloud() || !$user->managesInstance()) {
            return null;
        }

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");

        return [
            'address' => $this->address->host(),
            'servers' => Server::query()->count(),
            'other_teams' => Team::query()->whereKeyNot($current?->getKey())->orderBy('name')->pluck('name')->map(fn(mixed $name): string => (string) $name)->values()->all(),
            'this_machine_apps' => Server::query()->where('runs_coresmith', true)->whereHas('applications')->exists(),
            'database' => $driver,
            'database_name' => $driver === 'sqlite' ? null : (string) config("database.connections.{$connection}.database"),
            'two_factor' => $user->two_factor_confirmed_at !== null,
            'helper' => $this->removal->installed(),
            'installer' => ThisMachine::INSTALLER,
        ];
    }
}
