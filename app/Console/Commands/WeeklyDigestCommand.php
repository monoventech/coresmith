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
use App\Services\Teams\TeamAccess;
use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;

final class WeeklyDigestCommand extends Command
{
    public const WARNINGS = ['disk_pressure', 'memory_pressure', 'server_unreachable', 'app_oom_killed', 'certificate_expiring'];

    protected $signature = 'coresmith:weekly-digest';

    protected $description = 'Email the weekly digest to everyone who asked for it';

    public function handle(): int
    {
        $sent = 0;

        User::query()->with('teams')->each(function (User $user) use (&$sent): void {
            if (!Preferences::wants($user, Preferences::WEEKLY_DIGEST) || $user->teams->isEmpty()) {
                return;
            }

            $since = now()->subWeek();
            $access = app(TeamAccess::class);
            $applications = collect();
            $servers = collect();

            foreach ($user->teams as $team) {
                $everything = $access->seesEverything($user, $team);
                $applications = $applications->concat($everything ? Application::withTrashed()->where('team_id', $team->getKey())->pluck('id') : $access->applications($user, $team)->pluck('id'));
                $servers = $servers->concat($everything ? Server::withTrashed()->where('team_id', $team->getKey())->pluck('id') : $access->servers($user, $team)->pluck('id'));
            }

            $deployments = Deployment::query()->whereIn('application_id', $applications)->where('created_at', '>=', $since);

            $user->notify(new WeeklyDigestNotification([
                'deployed' => (clone $deployments)->where('status', DeploymentStatus::Succeeded->value)->count(),
                'failed' => (clone $deployments)->where('status', DeploymentStatus::Failed->value)->count(),
                'rollbacks' => Activity::query()->where('description', 'application_rolled_back')->whereIn('subject_id', $applications)->where('created_at', '>=', $since)->count(),
                'warnings' => Activity::query()->whereIn('description', self::WARNINGS)->whereIn('subject_id', $servers->concat($applications))->where('created_at', '>=', $since)->count(),
            ]));

            $sent++;
        });

        $this->info("Sent {$sent} " . ($sent === 1 ? 'digest' : 'digests') . '.');

        return self::SUCCESS;
    }
}
