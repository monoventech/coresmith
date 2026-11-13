<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Jobs;

use App\Events\FleetChanged;
use App\Models\Application;
use App\Services\Address\AppDomainReach;
use App\Services\Address\ReachReport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class CheckDomainReach implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly Application $application)
    {
        $this->onQueue('server-tasks');
    }

    public function uniqueId(): string
    {
        return (string) $this->application->getKey();
    }

    public function handle(AppDomainReach $reach): void
    {
        $application = $this->application->fresh();

        if ($application === null) {
            return;
        }

        $checked = now()->toIso8601String();

        try {
            $readings = array_map(fn(ReachReport $report): array => [
                'ready' => $report->ready,
                'problems' => $report->problems,
                'notes' => $report->notes,
                'records' => $report->records,
                'checked_at' => $checked,
            ], $reach->check($application));
        } catch (Throwable) {
            $server = $application->server->name ?? 'its server';
            $readings = [];

            foreach ($application->allDomains() as $domain) {
                $readings[$domain] = [
                    'ready' => null,
                    'problems' => [],
                    'notes' => ["Coresmith couldn't reach {$server} to check {$domain}. It checks again after the next deploy, or when you press Check again."],
                    'records' => [],
                    'checked_at' => $checked,
                ];
            }
        }

        $application->forceFill(['domain_reach' => $readings])->save();
        FleetChanged::forApplication($application);
    }
}
