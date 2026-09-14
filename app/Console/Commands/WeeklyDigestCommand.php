<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Notifications\Preferences;
use App\Notifications\WeeklyDigestNotification;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

final class WeeklyDigestCommand extends Command
{
    protected $signature = 'coresmith:weekly-digest';

    protected $description = 'Email the weekly digest to everyone who asked for it';

    public function handle(): int
    {
        $sent = 0;

        User::query()->with('teams')->each(function (User $user) use (&$sent): void {
            if (!Preferences::wants($user, Preferences::WEEKLY_DIGEST) || $user->teams->isEmpty()) {
                return;
            }

            $teams = $user->teams->modelKeys();
            $since = now()->subWeek();
            $applications = Application::withTrashed()->whereIn('team_id', $teams)->pluck('id');
            $servers = Server::withTrashed()->whereIn('team_id', $teams)->pluck('id');

            $deployments = Deployment::query()->whereIn('application_id', $applications)->where('created_at', '>=', $since);

            $user->notify(new WeeklyDigestNotification([
                'deployed' => (clone $deployments)->where('status', DeploymentStatus::Succeeded->value)->count(),
                'failed' => (clone $deployments)->where('status', DeploymentStatus::Failed->value)->count(),
                'rollbacks' => Activity::query()->where('description', 'application_rolled_back')->whereIn('subject_id', $applications)->where('created_at', '>=', $since)->count(),
                'warnings' => Activity::query()->where('description', 'disk_pressure')->whereIn('subject_id', $servers)->where('created_at', '>=', $since)->count(),
            ]));

            $sent++;
        });

        $this->info("Sent {$sent} " . ($sent === 1 ? 'digest' : 'digests') . '.');

        return self::SUCCESS;
    }
}
