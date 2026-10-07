<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;

final class DeploymentLog
{
    public function __construct(private readonly LogSanitizer $sanitizer) {}

    public function stamp(): string
    {
        return '[' . now()->format('H:i:s') . ']';
    }

    public function append(Deployment $deployment, string $output): void
    {
        if ($output === '') {
            return;
        }

        $clean = $this->sanitizer->sanitize($output, $deployment->application);

        $deployment->forceFill(['log' => trim((string) $deployment->log . "\n" . $clean)])->save();

        DeploymentUpdated::dispatch($deployment);
    }

    public function fail(Deployment $deployment, string $message, ?string $reason, bool $swapped = false, bool $keepFailed = false): void
    {
        $application = $deployment->application;

        $this->writeFailure($deployment, $message, $reason, $swapped);

        $application->forceFill([
            'status' => $application->current_release === null || $keepFailed ? ApplicationStatus::Failed : ApplicationStatus::Active,
        ])->save();

        DeploymentUpdated::dispatch($deployment);
        DeploymentFailed::dispatch($deployment);
    }

    public function failLeavingApplication(Deployment $deployment, string $message, ?string $reason, bool $swapped = false): void
    {
        $this->writeFailure($deployment, $message, $reason, $swapped);

        DeploymentUpdated::dispatch($deployment);
        DeploymentFailed::dispatch($deployment);
    }

    private function writeFailure(Deployment $deployment, string $message, ?string $reason, bool $swapped): void
    {
        $application = $deployment->application;

        $outcome = $swapped
            ? ' deploy failed after the symlink swap. releases/' . $deployment->release . ' is live, check its processes.'
            : ' deploy failed. the current release is untouched.';

        $deployment->forceFill([
            'status' => DeploymentStatus::Failed,
            'finished_at' => now(),
            'log' => trim((string) $deployment->log . "\n" . $this->sanitizer->sanitize($message, $application) . "\n" . $this->stamp() . $outcome),
            'failure_reason' => $reason === null ? null : $this->sanitizer->sanitize($reason, $application),
        ])->save();
    }
}
