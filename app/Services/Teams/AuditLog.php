<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Deployment\LogSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Activity;

final class AuditLog
{
    public const HEADER = ['time', 'actor', 'actor_email', 'event', 'subject_type', 'subject', 'details'];

    public const PERIODS = ['30', '90', '365', 'all'];

    private const CHUNK = 500;

    private const TYPES = [
        Team::class => 'team',
        Server::class => 'server',
        Application::class => 'application',
        ManagedDatabase::class => 'database',
        WebhookEndpoint::class => 'webhook',
    ];

    public function __construct(private readonly LogSanitizer $sanitizer) {}

    /**
     * @return iterable<int, list<string>>
     */
    public function rows(Team $team, ?CarbonImmutable $since): iterable
    {
        $applications = Application::withTrashed()->where('team_id', $team->getKey())->select('id');

        $query = Activity::query()
            ->with(['causer', 'subject' => fn(MorphTo $subject) => $subject->constrain([
                Team::class => fn(Builder $query) => $query->withTrashed(),
                Server::class => fn(Builder $query) => $query->withTrashed(),
                Application::class => fn(Builder $query) => $query->withTrashed(),
                ManagedDatabase::class => fn(Builder $query) => $query->withTrashed(),
                WebhookEndpoint::class => fn(Builder $query) => $query->with(['application' => fn($application) => $application->withTrashed()]),
            ])])
            ->where(fn(Builder $query) => $query
                ->where(fn(Builder $query) => $query->where('subject_type', (new Team)->getMorphClass())->where('subject_id', $team->getKey()))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new Server)->getMorphClass())->whereIn('subject_id', Server::withTrashed()->where('team_id', $team->getKey())->select('id')))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new Application)->getMorphClass())->whereIn('subject_id', $applications))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new ManagedDatabase)->getMorphClass())->whereIn('subject_id', ManagedDatabase::withTrashed()->where('team_id', $team->getKey())->select('id')))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new WebhookEndpoint)->getMorphClass())->whereIn('subject_id', WebhookEndpoint::query()->whereIn('application_id', $applications)->select('id'))))
            ->when($since !== null, fn(Builder $query) => $query->where('created_at', '>=', $since))
            ->orderBy('created_at')
            ->orderBy('id');

        $types = collect(self::TYPES)->mapWithKeys(fn(string $type, string $class): array => [(new $class)->getMorphClass() => $type])->all();

        foreach ($query->lazy(self::CHUNK) as $activity) {
            yield array_map(self::cell(...), $this->row($activity, $types[(string) $activity->subject_type] ?? ''));
        }
    }

    public static function cell(?string $value): string
    {
        $value ??= '';

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'{$value}" : $value;
    }

    /**
     * @return list<?string>
     */
    private function row(Activity $activity, string $type): array
    {
        $causer = $activity->causer;
        $subject = $activity->subject;
        $properties = $activity->properties?->all() ?? [];

        return [
            $activity->created_at?->toImmutable()->utc()->toIso8601ZuluString(),
            $causer instanceof User ? $causer->name : 'Coresmith',
            $causer instanceof User ? $causer->email : '',
            $activity->description,
            $type,
            $this->name($subject),
            $properties === [] ? '' : $this->sanitizer->sanitize((string) json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    private function name(?Model $subject): string
    {
        return match (true) {
            $subject instanceof WebhookEndpoint => (string) $subject->application?->name,
            $subject === null => '',
            default => (string) $subject->getAttribute('name'),
        };
    }
}
