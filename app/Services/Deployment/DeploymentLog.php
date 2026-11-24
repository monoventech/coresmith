<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Enums\ApplicationStatus;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Events\DeploymentLogAppended;
use App\Events\DeploymentUpdated;
use App\Models\Deployment;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DeploymentLog
{
    /** @var array<string, true> */
    private static array $unsent = [];

    public function __construct(private readonly LogSanitizer $sanitizer) {}

    public function stamp(): string
    {
        return '[' . now()->format('H:i:s') . ']';
    }

    public function append(Deployment $deployment, string $output): void
    {
        if ($this->write($deployment, $output)) {
            $this->send($deployment, new DeploymentUpdated($deployment));
        }
    }

    public function stream(Deployment $deployment, string $output): void
    {
        $this->write($deployment, $output);
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

    public function failIfRunning(Deployment $deployment, string $reason, bool $swapped = false): bool
    {
        $application = $deployment->application;

        $failed = DB::transaction(function () use ($deployment, $application, $reason, $swapped): int {
            $failed = Deployment::query()
                ->whereKey($deployment->getKey())
                ->where('status', DeploymentStatus::Running->value)
                ->update([
                    'status' => DeploymentStatus::Failed->value,
                    'finished_at' => now(),
                    'log' => $this->failedLog($deployment, $reason, $swapped),
                    'failure_reason' => $this->sanitizer->sanitize($reason, $application),
                ]);

            if ($failed > 0) {
                $application?->forceFill([
                    'status' => $application->current_release === null ? ApplicationStatus::Failed : ApplicationStatus::Active,
                ])->save();
            }

            return $failed;
        });

        if ($failed === 0) {
            return false;
        }

        $deployment->refresh();

        DeploymentUpdated::dispatch($deployment);
        DeploymentFailed::dispatch($deployment);

        return true;
    }

    public function failIfNeverStarted(Deployment $deployment, string $reason): bool
    {
        $failed = Deployment::query()
            ->whereKey($deployment->getKey())
            ->where('status', DeploymentStatus::Pending->value)
            ->whereNull('started_at')
            ->update([
                'status' => DeploymentStatus::Failed->value,
                'finished_at' => now(),
                'log' => trim((string) $deployment->log . "\n" . $reason),
                'failure_reason' => $reason,
            ]);

        if ($failed === 0) {
            return false;
        }

        $deployment->refresh();

        DeploymentUpdated::dispatch($deployment);
        DeploymentFailed::dispatch($deployment);

        return true;
    }

    private function write(Deployment $deployment, string $output): bool
    {
        if ($output === '') {
            return false;
        }

        $before = (string) $deployment->log;
        $clean = $this->sanitizer->sanitize(mb_scrub($output, 'UTF-8'), $deployment->application);
        $added = $before === '' ? trim($clean) : rtrim("\n" . $clean);

        if ($added === '') {
            return false;
        }

        $deployment->forceFill(['log' => $before . $added])->save();
        $this->broadcast($deployment, strlen($before), $added);

        return true;
    }

    private function broadcast(Deployment $deployment, int $offset, string $text): void
    {
        $deploymentId = (string) $deployment->getKey();
        $start = 0;

        while ($start < strlen($text)) {
            $bytes = DeploymentLogAppended::PIECE_BYTES;
            $piece = new DeploymentLogAppended($deploymentId, $offset + $start, mb_strcut($text, $start, $bytes, 'UTF-8'));

            while (!$piece->fits() && $bytes > 4) {
                $bytes = max(4, intdiv($bytes, 2));
                $piece = new DeploymentLogAppended($deploymentId, $offset + $start, mb_strcut($text, $start, $bytes, 'UTF-8'));
            }

            $this->send($deployment, $piece);
            $start += strlen($piece->text);
        }
    }

    private function send(Deployment $deployment, object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            if (!isset(self::$unsent[(string) $deployment->getKey()])) {
                self::$unsent[(string) $deployment->getKey()] = true;
                report($e);
            }
        }
    }

    private function writeFailure(Deployment $deployment, string $message, ?string $reason, bool $swapped): void
    {
        $application = $deployment->application;

        $deployment->forceFill([
            'status' => DeploymentStatus::Failed,
            'finished_at' => now(),
            'log' => $this->failedLog($deployment, $message, $swapped),
            'failure_reason' => $reason === null ? null : $this->sanitizer->sanitize($reason, $application),
        ])->save();
    }

    private function failedLog(Deployment $deployment, string $message, bool $swapped): string
    {
        $outcome = $swapped
            ? ' deploy failed after the symlink swap. releases/' . $deployment->release . ' is live, check its processes.'
            : ' deploy failed. the current release is untouched.';

        return trim((string) $deployment->log . "\n" . $this->sanitizer->sanitize($message, $deployment->application) . "\n" . $this->stamp() . $outcome);
    }
}
