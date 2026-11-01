<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Edition;
use App\Enums\PlanFeature;
use App\Exceptions\PlanRefusal;
use App\Models\Team;
use App\Models\User;

final class PlanGate
{
    public function __construct(private readonly TeamBilling $billing) {}

    public function ensureWritable(Team $team, User $user): void
    {
        if (Edition::current()->isSelfHosted()) {
            return;
        }

        $status = $this->billing->status($team);

        if ($status->readOnly()) {
            throw PlanRefusal::readOnly($status, $this->canManage($team, $user));
        }
    }

    public function ensureCanAddServer(Team $team, User $user): void
    {
        if (Edition::current()->isSelfHosted()) {
            return;
        }

        $this->ensureWritable($team, $user);

        $status = $this->billing->status($team);
        $limit = $status->serverLimit();
        $count = $team->servers()->count();

        if ($limit !== null && $status->plan !== null && $count >= $limit) {
            throw PlanRefusal::servers($status->plan, $count, $this->canManage($team, $user));
        }
    }

    public function ensureCanAddMember(Team $team, User $user): void
    {
        if (Edition::current()->isSelfHosted()) {
            return;
        }

        $this->ensureWritable($team, $user);

        $status = $this->billing->status($team);
        $limit = $status->memberLimit();

        if ($limit !== null && $status->plan !== null && $team->members()->count() >= $limit) {
            throw PlanRefusal::members($status->plan, $this->canManage($team, $user));
        }
    }

    public function ensureIncludes(Team $team, PlanFeature $feature, User $user): void
    {
        if (Edition::current()->isSelfHosted()) {
            return;
        }

        $this->ensureWritable($team, $user);

        if (!$this->billing->status($team)->allows($feature)) {
            throw PlanRefusal::feature($feature, $this->canManage($team, $user));
        }
    }

    private function canManage(Team $team, User $user): bool
    {
        return $user->can('manageBilling', $team);
    }
}
