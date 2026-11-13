<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\Edition;
use App\Models\InstanceSetting;
use App\Models\User;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\TagSignature;
use App\Services\Updates\UpdateCheck;
use App\Services\Updates\UpdateChecker;
use App\Services\Updates\UpdateRequest;
use App\Services\Updates\UpdateState;
use App\Services\Updates\UpdateStatus;
use App\Services\Updates\Version;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

final class PanelUpdatesPresenter
{
    public const LOG_LINES = 12;

    public const STALL_SECONDS = 30;

    public const START_STALL_SECONDS = 300;

    private const ACTIVE = ['requested', 'starting', 'running'];

    private const FAILED = [UpdateStatus::ROLLED_BACK, UpdateStatus::FAILED, 'interrupted'];

    public function __construct(
        private readonly UpdateState $state,
        private readonly UpdateChecker $checker,
        private readonly ReleaseLayout $layout,
        private readonly TagSignature $signatures,
    ) {}

    public function mode(): string
    {
        return match (true) {
            Edition::current()->isCloud() => 'cloud',
            !(bool) config('coresmith.updates.enabled') => 'unmanaged',
            default => 'self-hosted',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $current = Version::current();
        $mode = $this->mode();
        $shape = [
            'mode' => $mode,
            'current' => (string) $current,
            'channel' => 'stable',
            'line' => "Coresmith v{$current} · stable channel" . $this->installed(),
            'badge' => null,
            'check' => ['enabled' => false, 'when' => null, 'error' => null],
            'available' => null,
            'notices' => [],
            'auto' => false,
            'managed' => false,
            'run' => null,
            'status_href' => route('settings.updates.status'),
        ];

        if ($mode === 'cloud') {
            return [...$shape, 'line' => "Coresmith v{$current} · Coresmith Cloud · updates are managed for you"];
        }

        if ($mode === 'unmanaged') {
            return [...$shape, 'notices' => [$this->muted("This copy of Coresmith wasn't installed by the Coresmith installer, so it can't update itself. Update it the way you installed it.")]];
        }

        $check = $this->checker->latest();
        $run = $this->run();
        $available = $check?->available($current);
        $succeeded = $this->state->lastSucceeded();
        $channel = $check?->channel() ?? 'stable';

        return [
            ...$shape,
            'channel' => $channel,
            'line' => "Coresmith v{$current} · {$channel} channel" . ($succeeded?->finishedAt === null ? $this->installed() : ' · updated ' . $this->local($succeeded->finishedAt)->format('M j, g:i A')),
            'badge' => $this->badge($run, $check, $available),
            'check' => ['enabled' => $this->checker->enabled(), 'when' => Formats::when($check?->checkedAt), 'error' => $check?->error],
            'available' => $available === null ? null : [
                'version' => (string) $available,
                'summary' => self::readable($check?->feed?->summary),
                'notes_url' => $check?->feed?->changelogUrl,
                'released' => $check?->feed?->releasedAt?->format('M j'),
            ],
            'notices' => $this->notices($current, $check, $available),
            'auto' => InstanceSetting::get('auto_update') === '1',
            'managed' => $this->layout->isManaged(),
            'run' => $user->managesInstance() ? $run : null,
        ];
    }

    public static function stalled(UpdateRequest $request, bool $taken): bool
    {
        return $request->requestedAt->diffInSeconds(CarbonImmutable::now(), true) > ($taken ? self::START_STALL_SECONDS : self::STALL_SECONDS);
    }

    public function available(): ?Version
    {
        return $this->checker->latest()?->available(Version::current());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function run(): ?array
    {
        if ($this->mode() !== 'self-hosted') {
            return null;
        }

        $request = $this->state->pendingRequest();

        if ($request !== null) {
            $taken = $this->state->requestTaken();

            return [
                ...$this->empty(),
                'state' => $taken ? 'starting' : 'requested',
                'from' => (string) Version::current(),
                'to' => $request->to === null ? null : (string) $request->to,
                'started' => Formats::when($request->requestedAt),
                'stalled' => self::stalled($request, $taken),
            ];
        }

        $status = $this->state->status();

        if ($status === null) {
            return null;
        }

        return [
            ...$this->empty(),
            'id' => $status->id,
            'state' => $this->state->interrupted() ? 'interrupted' : $status->state,
            'from' => $status->fromVersion,
            'to' => $status->toVersion,
            'step' => $status->step,
            'label' => $status->stepLabel(),
            'number' => $status->stepNumber(),
            'started' => Formats::when($status->startedAt),
            'duration' => Formats::duration($status->startedAt, $status->finishedAt ?? CarbonImmutable::now()),
            'message' => $status->message,
            'failed_label' => $status->failedStep === null ? null : lcfirst(UpdateStatus::STEPS[$status->failedStep] ?? $status->failedStep),
            'log' => $this->state->log($status->id, self::LOG_LINES),
            'log_href' => route('settings.updates.log', $status->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'id' => null,
            'state' => null,
            'from' => null,
            'to' => null,
            'step' => null,
            'label' => null,
            'number' => null,
            'total' => UpdateStatus::COUNTED,
            'started' => null,
            'duration' => null,
            'message' => null,
            'failed_label' => null,
            'log' => [],
            'log_href' => null,
            'stalled' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $run
     * @return array{label: string, tone: string}
     */
    private function badge(?array $run, ?UpdateCheck $check, ?Version $available): array
    {
        return match (true) {
            in_array($run['state'] ?? null, self::ACTIVE, true) => ['label' => 'Updating', 'tone' => 'accent'],
            in_array($run['state'] ?? null, self::FAILED, true) => ['label' => 'Update failed', 'tone' => 'destructive'],
            $available !== null => ['label' => 'Update available', 'tone' => 'default'],
            $check?->error !== null && $this->checker->enabled() => ['label' => 'Check failed', 'tone' => 'warning'],
            !$this->checker->enabled() => ['label' => 'Checks off', 'tone' => 'default'],
            $check === null => ['label' => 'Not checked yet', 'tone' => 'default'],
            default => ['label' => 'Up to date', 'tone' => 'success'],
        };
    }

    /**
     * @return list<array{tone: string, text: string}>
     */
    private function notices(Version $current, ?UpdateCheck $check, ?Version $available): array
    {
        $notices = [];

        if ($check?->currentWithdrawn($current) === true) {
            $notices[] = $this->warning($available === null
                ? "{$current->tag()} was withdrawn. Watch this page for the next release."
                : "{$current->tag()} was withdrawn. Update to {$available->tag()} as soon as you can.");
        }

        if ($check?->unsupported($current) === true) {
            $notices[] = $this->warning("{$current->tag()} is past its support window. Update to keep getting fixes.");
        }

        if ($this->signatures->keys() === []) {
            $notices[] = $this->muted("Signatures aren't checked yet: no Coresmith release key has been published.");
        }

        if (!$this->checker->enabled()) {
            $notices[] = $this->muted('Update checks are off on this install.');
        } elseif ($check?->error !== null && $available !== null) {
            $notices[] = $this->muted("The last check failed: {$check->error}");
        }

        if (!$this->layout->isManaged()) {
            $notices[] = $this->muted("This install can't update itself yet: it doesn't use release directories. Run the installer for the version you want.");
        }

        return $notices;
    }

    private function installed(): string
    {
        $installed = InstanceSetting::get('installed_at');

        try {
            return is_string($installed) && $installed !== '' ? ' · installed ' . CarbonImmutable::parse($installed)->format('M j, Y') : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function local(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * @return array{tone: string, text: string}
     */
    private function warning(string $text): array
    {
        return ['tone' => 'warning', 'text' => $text];
    }

    /**
     * @return array{tone: string, text: string}
     */
    private function muted(string $text): array
    {
        return ['tone' => 'muted', 'text' => $text];
    }

    public static function readable(?string $summary): ?string
    {
        if ($summary === null || trim($summary) === '') {
            return null;
        }

        return implode("\n", array_map(fn(string $line): string => match (true) {
            str_starts_with($line, '### ') => substr($line, 4),
            preg_match('/^- (?:[a-z]+(?:\([^)]*\))?!?: )?(.+)$/', $line, $matches) === 1 => '• ' . Str::ucfirst($matches[1]),
            default => $line,
        }, preg_split('/\r\n|\n|\r/', trim($summary)) ?: []));
    }
}
