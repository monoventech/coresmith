<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Monitors;

use App\Enums\ApplicationStatus;
use App\Enums\MonitorKind;
use App\Enums\PowerState;
use App\Events\FleetChanged;
use App\Models\AppCheckDay;
use App\Models\Application;
use App\Models\MonitorAlert;
use App\Models\Server;
use App\Notifications\AppAnsweringNotification;
use App\Notifications\AppNotAnsweringNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use App\Services\Panel\SmartToggles;
use App\Services\Server\ServerReachability;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AppAnswers
{
    public const EVERY_MINUTES = 5;

    public const TIMEOUT_SECONDS = 10;

    public const CONNECT_SECONDS = 5;

    public const USER_AGENT = 'Coresmith uptime check';

    public function __construct(private readonly MonitorLimits $limits) {}

    public function check(Server $server): void
    {
        $limit = $this->limits->for($server, MonitorKind::Apps);
        [$checked, $resting] = $server->applications()->get()->partition(fn(Application $application): bool => $limit->enabled && $this->checkable($application));

        foreach ($resting as $application) {
            $this->rest($application);
        }

        if ($checked->isEmpty() || $server->connection_failures >= ServerReachability::FAILURES_BEFORE_UNREACHABLE) {
            return;
        }

        $answers = Http::pool(fn(Pool $pool): array => $checked->map(fn(Application $application) => $pool->as((string) $application->getKey())
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_SECONDS)
            ->withoutRedirecting()
            ->withUserAgent(self::USER_AGENT)
            ->withOptions(['curl' => [CURLOPT_RESOLVE => self::resolve($server, self::domain($application))]])
            ->get('https://' . self::domain($application) . '/'))->values()->all());

        foreach ($checked as $application) {
            $answer = $answers[(string) $application->getKey()] ?? null;
            $status = $answer instanceof Response ? $answer->status() : null;

            $this->record($server, $application, $limit, $status !== null && $status < (int) $limit->threshold, $status);
        }
    }

    public static function domain(Application $application): string
    {
        foreach ($application->allDomains() as $domain) {
            if (!str_starts_with($domain, '*.')) {
                return $domain;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    public static function resolve(Server $server, string $domain): array
    {
        $address = (string) $server->ip_address;

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return [];
        }

        return [$domain . ':443:' . (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$address}]" : $address)];
    }

    private function checkable(Application $application): bool
    {
        return self::domain($application) !== ''
            && !in_array($application->status, [ApplicationStatus::Pending, ApplicationStatus::Stopped], true)
            && !in_array($application->power_state, [PowerState::Sleeping, PowerState::Stopped], true)
            && !$application->tailnet
            && !$application->preview
            && !(bool) (((array) $application->toggles)[SmartToggles::MAINTENANCE] ?? false);
    }

    private function record(Server $server, Application $application, MonitorLimit $limit, bool $answered, ?int $status): void
    {
        if ($answered) {
            AppCheckDay::count($application, answered: true);
            Application::query()->whereKey($application->getKey())->toBase()->update(['answered_at' => now(), 'failed_checks' => 0]);
            $this->recover($server, $application, $application->failed_checks * self::EVERY_MINUTES);

            return;
        }

        if ($application->answered_at === null) {
            return;
        }

        $failed = $application->failed_checks + 1;

        AppCheckDay::count($application, answered: false);
        Application::query()->whereKey($application->getKey())->toBase()->update(['failed_checks' => $failed]);

        if ($failed >= $limit->duration && MonitorAlert::current($server, MonitorKind::Apps, $application) === null) {
            $this->open($server, $application, $limit, $status);
        }
    }

    private function open(Server $server, Application $application, MonitorLimit $limit, ?int $status): void
    {
        try {
            $alert = DB::transaction(fn(): MonitorAlert => MonitorAlert::open($server, MonitorKind::Apps, $application, peak: $status === null ? null : (float) $status));
        } catch (UniqueConstraintViolationException) {
            return;
        }

        $domain = self::domain($application);

        if (!$this->notify($application, new AppNotAnsweringNotification($application, $domain, $status, $limit->duration))) {
            $alert->delete();

            return;
        }

        activity()->performedOn($application)->withProperties(['domain' => $domain, 'status' => $status, 'checks' => $limit->duration])->log('app_not_answering');
        FleetChanged::forApplication($application);
    }

    private function recover(Server $server, Application $application, int $minutes): void
    {
        $alert = MonitorAlert::current($server, MonitorKind::Apps, $application);

        if ($alert === null || !$alert->close()) {
            return;
        }

        $minutes = max(1, $minutes);

        $this->notify($application, new AppAnsweringNotification($application, $minutes));
        activity()->performedOn($application)->withProperties(['minutes' => $minutes])->log('app_answering');
        FleetChanged::forApplication($application);
    }

    private function rest(Application $application): void
    {
        if ($application->failed_checks > 0) {
            Application::query()->whereKey($application->getKey())->toBase()->update(['failed_checks' => 0]);
        }

        MonitorAlert::closeAllFor($application);
    }

    private function notify(Application $application, Notification $notification): bool
    {
        try {
            app(TeamNotifier::class)->send($application->team, Preferences::SERVER_ALERTS, $notification, about: $application);
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
