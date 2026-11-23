<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\ServerStatus;
use App\Exceptions\ThisMachineRefused;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PlanChanges;
use App\Services\Server\ThisMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class TeamMove
{
    public function __construct(
        private readonly MoveCodes $codes,
        private readonly TeamExporter $exporter,
        private readonly MoveFile $files,
        private readonly PlanChanges $plans,
        private readonly MoveReach $reach,
        private readonly ThisMachine $machine,
    ) {}

    public function start(Team $team, User $user, string $code, bool $handOver = false): void
    {
        $target = $this->codes->read($code);
        $refusal = $this->reach->refusal($team, $target, $handOver);

        if ($refusal !== []) {
            throw ValidationException::withMessages($refusal);
        }

        $machine = $team->servers()->where('runs_coresmith', true)->first();

        if ($machine !== null) {
            try {
                $this->machine->handOver($target->addresses);
            } catch (ThisMachineRefused $refused) {
                throw ValidationException::withMessages(['hand_over' => $refused->getMessage()]);
            }
        }

        try {
            $file = $this->files->seal($this->exporter->payload($team, $user, $machine !== null ? MoveReach::machineAddress($machine) : null), $target);
            $subscription = $team->subscription('default');

            if ($subscription instanceof Subscription && $subscription->valid() && !$subscription->onGracePeriod()) {
                $this->plans->end($team, $user);
            }

            DB::transaction(function () use ($team, $target, $file): void {
                $team->forceFill(['moving_at' => now(), 'moving_to' => $target->panel, 'moving_edition' => $target->edition->value, 'move_file' => $file])->save();
                $team->servers()->where('status', ServerStatus::Active->value)->update(['status' => ServerStatus::Moving->value]);
            });
        } catch (Throwable $failed) {
            if ($machine !== null) {
                rescue(fn() => $this->machine->keep(), report: false);
            }

            throw $failed;
        }

        activity()->performedOn($team)->causedBy($user)->withProperties(['to' => $target->panel])->log('team_move_started');
    }

    public function cancel(Team $team, User $user): void
    {
        if ($team->moving_at === null) {
            throw ValidationException::withMessages(['move' => 'This team is not moving, so there is nothing to cancel.']);
        }

        if ($team->servers()->where('runs_coresmith', true)->exists()) {
            try {
                $this->machine->keep();
            } catch (ThisMachineRefused $refused) {
                throw ValidationException::withMessages(['move' => $refused->getMessage()]);
            }
        }

        $subscription = $team->subscription('default');

        if ($subscription instanceof Subscription && $subscription->onGracePeriod()) {
            $this->plans->resume($team, $user);
        }

        DB::transaction(function () use ($team): void {
            $team->forceFill(['moving_at' => null, 'moving_to' => null, 'moving_edition' => null, 'move_file' => null])->save();
            $team->servers()->where('status', ServerStatus::Moving->value)->update(['status' => ServerStatus::Active->value]);
        });

        activity()->performedOn($team)->causedBy($user)->log('team_move_cancelled');
    }
}
