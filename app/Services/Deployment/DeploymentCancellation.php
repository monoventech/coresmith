<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationStatus;
use App\Enums\CancelOutcome;
use App\Enums\DeploymentStatus;
use App\Events\FleetChanged;
use App\Jobs\PostCommitStatus;
use App\Jobs\StopDeployBuild;
use App\Models\Deployment;
use App\Models\User;
use App\Services\AutoDeploy\AutoDeploy;
use Illuminate\Support\Str;

final class DeploymentCancellation
{
    private const PAST_MIGRATIONS = '/^\[\d{2}:\d{2}:\d{2}\] (migrate|health|swap):/m';

    private const BLUEPRINT = '/^\[\d{2}:\d{2}:\d{2}\] build: running the custom blueprint$/m';

    private const ASKED = '/^cancel: (.+ )?asked to stop the deploy$/m';

    public function __construct(
        private readonly DeploymentLog $log,
        private readonly DeploymentPhases $phases,
    ) {}

    public function request(Deployment $deployment, User $user): CancelOutcome
    {
        $deployment->refresh();

        if ($deployment->status === DeploymentStatus::Pending) {
            $this->rememberHead($deployment);

            if ($this->cancelQueued($deployment, $user)) {
                return CancelOutcome::Cancelled;
            }

            $deployment->refresh();
        }

        if ($deployment->status !== DeploymentStatus::Running) {
            return CancelOutcome::Finished;
        }

        if ($deployment->cancel_requested_at !== null) {
            return CancelOutcome::AlreadyRequested;
        }

        if (preg_match(self::PAST_MIGRATIONS, (string) $deployment->log) === 1) {
            return CancelOutcome::TooLate;
        }

        if (preg_match(self::BLUEPRINT, (string) $deployment->log) === 1) {
            return CancelOutcome::Blueprint;
        }

        $this->rememberHead($deployment);

        $asked = Deployment::query()
            ->whereKey($deployment->getKey())
            ->where('status', DeploymentStatus::Running->value)
            ->whereNull('cancel_requested_at')
            ->update(['cancel_requested_at' => now(), 'cancelled_by' => $user->getKey()]);

        if ($asked === 0) {
            return $deployment->fresh()?->status === DeploymentStatus::Running ? CancelOutcome::AlreadyRequested : CancelOutcome::Finished;
        }

        rescue(function () use ($deployment): void {
            StopDeployBuild::dispatch($deployment);
        }, report: true);

        return CancelOutcome::Requested;
    }

    public static function requested(Deployment $deployment): bool
    {
        return Deployment::query()->whereKey($deployment->getKey())->whereNotNull('cancel_requested_at')->exists();
    }

    public static function cancellable(Deployment $deployment): bool
    {
        return match ($deployment->status) {
            DeploymentStatus::Pending => true,
            DeploymentStatus::Running => $deployment->cancel_requested_at === null && self::beforeMigrations($deployment),
            default => false,
        };
    }

    public static function endsCancelled(Deployment $deployment): bool
    {
        return $deployment->cancel_requested_at !== null && self::beforeMigrations($deployment);
    }

    public static function beforeMigrations(Deployment $deployment): bool
    {
        $log = (string) $deployment->log;

        return preg_match(self::PAST_MIGRATIONS, $log) !== 1 && preg_match(self::BLUEPRINT, $log) !== 1;
    }

    public function note(Deployment $deployment): void
    {
        if (preg_match(self::ASKED, (string) $deployment->log) === 1) {
            return;
        }

        $name = $this->asker($deployment)?->name;

        $this->log->append($deployment, $name === null ? 'cancel: asked to stop the deploy' : 'cancel: ' . Str::squish($name) . ' asked to stop the deploy');
    }

    public function finish(Deployment $deployment): void
    {
        $deployment->refresh();

        $application = $deployment->application;
        $asker = $deployment->cancelledBy;

        $deployment->forceFill([
            'status' => DeploymentStatus::Cancelled,
            'finished_at' => $deployment->finished_at ?? now(),
        ])->save();

        if ($application !== null && $deployment->started_at !== null) {
            $application->forceFill([
                'status' => $application->current_release === null ? ApplicationStatus::Pending : ApplicationStatus::Active,
            ])->save();
        }

        $this->log->append($deployment, $this->log->stamp() . ' deploy cancelled. the current release is untouched.');

        if ($application !== null) {
            activity()
                ->performedOn($application)
                ->causedBy($asker)
                ->withProperties(['by' => $asker?->name, 'phase' => $this->stoppedIn($deployment)])
                ->log('deployment_cancelled');
        }

        PostCommitStatus::dispatchFor($deployment);
        FleetChanged::forDeployment($deployment);
    }

    public function followUp(Deployment $deployment): void
    {
        rescue(fn() => app(AutoDeploy::class)->afterDeployment($deployment), report: true);
    }

    private function cancelQueued(Deployment $deployment, User $user): bool
    {
        $now = now();

        $cancelled = Deployment::query()
            ->whereKey($deployment->getKey())
            ->where('status', DeploymentStatus::Pending->value)
            ->update([
                'status' => DeploymentStatus::Cancelled->value,
                'finished_at' => $now,
                'cancel_requested_at' => $now,
                'cancelled_by' => $user->getKey(),
            ]);

        if ($cancelled === 0) {
            return false;
        }

        try {
            $deployment->refresh();

            $this->note($deployment);
            $this->finish($deployment);
        } finally {
            $this->followUp($deployment);
        }

        return true;
    }

    private function rememberHead(Deployment $deployment): void
    {
        $head = $deployment->application?->repository_head;

        if ($head === null || $deployment->commit_hash !== null || $deployment->requested_commit !== null) {
            return;
        }

        Deployment::query()
            ->whereKey($deployment->getKey())
            ->whereNull('commit_hash')
            ->whereNull('requested_commit')
            ->update(['requested_commit' => $head]);
    }

    private function asker(Deployment $deployment): ?User
    {
        $id = Deployment::query()->whereKey($deployment->getKey())->value('cancelled_by');

        return $id === null ? null : User::query()->find($id);
    }

    private function stoppedIn(Deployment $deployment): ?string
    {
        foreach ($this->phases->states($deployment) as $phase) {
            if ($phase['state'] === 'cancelled') {
                return $phase['key'];
            }
        }

        return null;
    }
}
