<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Events\FleetChanged;
use App\Models\Application;
use App\Notifications\CertificateExpiringNotification;
use App\Notifications\Preferences;
use App\Notifications\TeamNotifier;
use App\Services\Panel\Formats;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;
use Throwable;

final class CertificateCheck
{
    public const WARN_DAYS = 7;

    public const CACHE_DAYS = 3;

    private const BUDGET_SECONDS = 450;

    private const STALE_HOURS = 6;

    private const MISSING_STALE_MINUTES = 10;

    public function __construct(private readonly CertificateProbe $probe) {}

    public static function cacheKey(Application $application): string
    {
        return 'certificates:' . $application->getKey();
    }

    public static function expiry(int $days): string
    {
        return match (true) {
            $days < 0 => 'has expired',
            $days === 0 => 'expires today',
            $days === 1 => 'expires tomorrow',
            default => "expires in {$days} days",
        };
    }

    /**
     * @return list<string>
     */
    public static function domains(Application $application): array
    {
        return array_values(array_filter(
            $application->allDomains(),
            fn(string $domain): bool => !str_starts_with($domain, '*.') && SafeName::isDomain($domain),
        ));
    }

    public function run(SSHConnection $connection): void
    {
        $applications = $connection->server()->applications()->get()
            ->filter(fn(Application $application): bool => self::domains($application) !== [])
            ->values();
        $domains = array_values(array_unique($applications->flatMap(fn(Application $application): array => self::domains($application))->all()));
        $started = CarbonImmutable::now();
        $found = [];

        try {
            foreach ($this->probe->commands($domains) as $command) {
                if ($started->diffInSeconds(CarbonImmutable::now()) >= self::BUDGET_SECONDS) {
                    break;
                }

                $found = array_merge($found, $this->probe->parse($connection->execute($command, timeout: CertificateProbe::COMMAND_SECONDS)->output, $domains));
            }
        } finally {
            if ($found !== []) {
                foreach ($applications as $application) {
                    $this->record($application, $found);
                }
            }
        }
    }

    /**
     * @return array{checked_at: string, domains: array<string, array{state: string, expires_at: ?string, days: ?int, label: string, title: string}>}|null
     */
    public function forApplication(Application $application): ?array
    {
        $stored = $this->stored($application);

        if ($stored === []) {
            return null;
        }

        $ip = (string) $application->server?->ip_address;
        $domains = [];

        foreach ($stored as $domain => $entry) {
            $domains[$domain] = $this->describe($domain, $entry, $ip);
        }

        return [
            'checked_at' => collect($stored)->pluck('checked_at')->sort()->first(),
            'domains' => $domains,
        ];
    }

    public function stale(Application $application): bool
    {
        $stored = $this->stored($application);

        foreach (self::domains($application) as $domain) {
            $entry = $stored[$domain] ?? null;

            if ($entry === null) {
                return true;
            }

            $checked = CarbonImmutable::parse($entry['checked_at']);

            if ($checked->lt(now()->subHours(self::STALE_HOURS)) || ($entry['expires_at'] === null && $checked->lt(now()->subMinutes(self::MISSING_STALE_MINUTES)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, CarbonImmutable|null>  $found
     */
    private function record(Application $application, array $found): void
    {
        $entries = $this->stored($application);
        $now = CarbonImmutable::now();
        $reached = [];

        foreach (self::domains($application) as $domain) {
            if (array_key_exists($domain, $found)) {
                $entries[$domain] = ['expires_at' => $found[$domain]?->toIso8601String(), 'checked_at' => $now->toIso8601String()];
                $reached[] = $domain;
            }
        }

        if ($reached === []) {
            return;
        }

        $entries = array_intersect_key($entries, array_flip(self::domains($application)));

        Cache::put(self::cacheKey($application), ['domains' => $entries], $now->addDays(self::CACHE_DAYS));

        foreach ($reached as $domain) {
            $days = $this->days($entries[$domain]['expires_at']);

            if ($days !== null && $days <= self::WARN_DAYS) {
                $this->alert($application, $domain, $days);
            }
        }
    }

    private function alert(Application $application, string $domain, int $days): void
    {
        $today = Activity::query()
            ->where('subject_type', $application->getMorphClass())
            ->where('subject_id', $application->getKey())
            ->where('description', 'certificate_expiring')
            ->where('properties->domain', $domain)
            ->where('created_at', '>=', now()->startOfDay())
            ->exists();

        if ($today) {
            return;
        }

        try {
            app(TeamNotifier::class)->send($application->team, Preferences::SERVER_ALERTS, new CertificateExpiringNotification($application, $domain, $days), about: $application);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        activity()
            ->performedOn($application)
            ->withProperties(['domain' => $domain, 'days' => $days])
            ->log('certificate_expiring');

        FleetChanged::forApplication($application);
    }

    /**
     * @param  array{expires_at: ?string, checked_at: string}  $entry
     * @return array{state: string, expires_at: ?string, days: ?int, label: string, title: string}
     */
    private function describe(string $domain, array $entry, string $ip): array
    {
        $days = $this->days($entry['expires_at']);
        $renew = "This should have renewed by now. Check {$domain} still points at {$ip}.";

        [$state, $label, $title] = match (true) {
            $days === null => ['none', 'No certificate', "There's no certificate for this name yet. Point its DNS at {$ip} and one is requested."],
            $days < 0 => ['expired', 'Expired', $renew],
            $days <= self::WARN_DAYS => ['expiring', ucfirst(self::expiry($days)), $renew],
            default => ['valid', 'TLS', 'Valid until ' . Formats::absolute($this->moment((string) $entry['expires_at'])) . ', checked ' . Formats::when($this->moment($entry['checked_at']))],
        };

        return ['state' => $state, 'expires_at' => $entry['expires_at'], 'days' => $days, 'label' => $label, 'title' => $title];
    }

    private function moment(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
    }

    private function days(?string $expiresAt): ?int
    {
        return $expiresAt === null ? null : (int) floor(CarbonImmutable::now()->diffInDays(CarbonImmutable::parse($expiresAt)));
    }

    /**
     * @return array<string, array{expires_at: ?string, checked_at: string}>
     */
    private function stored(Application $application): array
    {
        $cached = Cache::get(self::cacheKey($application));

        return is_array($cached) && is_array($cached['domains'] ?? null) ? $cached['domains'] : [];
    }
}
