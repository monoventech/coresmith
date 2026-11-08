<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\ServerStatus;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PlanChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TeamMove
{
    public function __construct(
        private readonly MoveCodes $codes,
        private readonly TeamExporter $exporter,
        private readonly MoveFile $files,
        private readonly PlanChanges $plans,
    ) {}

    public function start(Team $team, User $user, string $code): void
    {
        $target = $this->codes->read($code);
        $file = $this->files->seal($this->exporter->payload($team, $user), $target);
        $subscription = $team->subscription('default');

        if ($subscription instanceof Subscription && $subscription->valid() && !$subscription->onGracePeriod()) {
            $this->plans->end($team, $user);
        }

        DB::transaction(function () use ($team, $target, $file): void {
            $team->forceFill(['moving_at' => now(), 'moving_to' => $target->panel, 'move_file' => $file])->save();
            $team->servers()->where('status', ServerStatus::Active->value)->update(['status' => ServerStatus::Moving->value]);
        });

        activity()->performedOn($team)->causedBy($user)->withProperties(['to' => $target->panel])->log('team_move_started');
    }

    public function cancel(Team $team, User $user): void
    {
        if ($team->moving_at === null) {
            throw ValidationException::withMessages(['move' => 'This team is not moving, so there is nothing to cancel.']);
        }

        $subscription = $team->subscription('default');

        if ($subscription instanceof Subscription && $subscription->onGracePeriod()) {
            $this->plans->resume($team, $user);
        }

        DB::transaction(function () use ($team): void {
            $team->forceFill(['moving_at' => null, 'moving_to' => null, 'move_file' => null])->save();
            $team->servers()->where('status', ServerStatus::Moving->value)->update(['status' => ServerStatus::Active->value]);
        });

        activity()->performedOn($team)->causedBy($user)->log('team_move_cancelled');
    }
}
