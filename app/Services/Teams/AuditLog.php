<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Teams;

use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\GitHubApp;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Deployment\LogSanitizer;
use App\Services\Panel\Formats;
use App\Services\SSH\SSHConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class AuditLog
{
    public const HEADER = ['time', 'actor', 'actor_email', 'event', 'subject_type', 'subject', 'details'];

    public const PERIODS = ['1', '7', '30', '90', '365', 'all'];

    public const KINDS = ['changes', 'everything', 'servers', 'apps', 'databases', 'team', 'commands'];

    public const DEFAULTS = ['period' => '90', 'who' => 'anyone', 'what' => 'changes'];

    public const PAGE = 50;

    private const CHUNK = 500;

    private const LABELS = [
        'team' => 'Team',
        'server' => 'Server',
        'application' => 'App',
        'database' => 'Database',
        'webhook' => 'Webhook',
        'cloud_provider' => 'Cloud account',
        'source_provider' => 'Git connection',
        'github_app' => 'GitHub App',
    ];

    private const WORDS = [
        'api' => 'API',
        'apparmor' => 'AppArmor',
        'application' => 'app',
        'applications' => 'apps',
        'cloudflare' => 'Cloudflare',
        'cpu' => 'CPU',
        'csv' => 'CSV',
        'dns' => 'DNS',
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'http' => 'HTTP',
        'ipv4' => 'IPv4',
        'ipv6' => 'IPv6',
        'mysql' => 'MySQL',
        'oom' => 'OOM',
        'panel' => 'Coresmith',
        'php' => 'PHP',
        'postgresql' => 'PostgreSQL',
        'redis' => 'Redis',
        's3' => 'S3',
        'ssh' => 'SSH',
        'tls' => 'TLS',
        'url' => 'URL',
        'wireguard' => 'WireGuard',
    ];

    private const TYPES = [
        Team::class => 'team',
        Server::class => 'server',
        Application::class => 'application',
        ManagedDatabase::class => 'database',
        WebhookEndpoint::class => 'webhook',
        CloudProvider::class => 'cloud_provider',
        SourceProvider::class => 'source_provider',
        GitHubApp::class => 'github_app',
    ];

    public function __construct(private readonly LogSanitizer $sanitizer) {}

    /**
     * @param  array<array-key, mixed>  $query
     * @return array{period: string, who: string, what: string}
     */
    public static function filters(array $query): array
    {
        $pick = fn(string $key, array $allowed): string => in_array($query[$key] ?? null, $allowed, true) ? (string) $query[$key] : self::DEFAULTS[$key];
        $who = $query['who'] ?? null;

        return [
            'period' => $pick('period', self::PERIODS),
            'who' => is_string($who) && ($who === 'coresmith' || Str::isUlid($who)) ? $who : self::DEFAULTS['who'],
            'what' => $pick('what', self::KINDS),
        ];
    }

    /**
     * @param  array{period: string, who: string, what: string}  $filters
     * @return array<string, string>
     */
    public static function exportQuery(array $filters): array
    {
        return ['period' => $filters['period'], ...array_filter(['who' => $filters['who'], 'what' => $filters['what']], fn(string $value, string $key): bool => $value !== self::DEFAULTS[$key], ARRAY_FILTER_USE_BOTH)];
    }

    public static function event(string $description): string
    {
        $words = implode(' ', array_map(fn(string $word): string => self::WORDS[$word] ?? $word, preg_split('/[_\s.]+/', strtolower(trim($description))) ?: []));

        return mb_strtoupper(mb_substr($words, 0, 1)) . mb_substr($words, 1);
    }

    /**
     * @return Builder<Activity>
     */
    public function forTeam(Team $team): Builder
    {
        $applications = Application::withTrashed()->where('team_id', $team->getKey())->select('id');

        return Activity::query()
            ->where(fn(Builder $query) => $query
                ->where(fn(Builder $query) => $query->where('subject_type', (new Team)->getMorphClass())->where('subject_id', $team->getKey()))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new Server)->getMorphClass())->whereIn('subject_id', Server::withTrashed()->where('team_id', $team->getKey())->select('id')))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new Application)->getMorphClass())->whereIn('subject_id', $applications))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new ManagedDatabase)->getMorphClass())->whereIn('subject_id', ManagedDatabase::withTrashed()->where('team_id', $team->getKey())->select('id')))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new WebhookEndpoint)->getMorphClass())->whereIn('subject_id', WebhookEndpoint::query()->whereIn('application_id', $applications)->select('id')))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new CloudProvider)->getMorphClass())->whereIn('subject_id', CloudProvider::withTrashed()->where('team_id', $team->getKey())->select('id')))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new SourceProvider)->getMorphClass())->whereIn('subject_id', SourceProvider::withTrashed()->where('team_id', $team->getKey())->select('id')))
                ->orWhere(fn(Builder $query) => $query->where('subject_type', (new GitHubApp)->getMorphClass())->where('properties->team_id', $team->getKey())));
    }

    /**
     * @param  array{period: string, who: string, what: string}  $filters
     * @return Builder<Activity>
     */
    public function filtered(Team $team, array $filters): Builder
    {
        $morph = fn(string $class): string => (new $class)->getMorphClass();
        $command = SSHConnection::COMMAND_ACTIVITY;
        $what = $filters['what'];

        return $this->forTeam($team)
            ->when($filters['period'] !== 'all', fn(Builder $query) => $query->where('created_at', '>=', now()->toImmutable()->subDays((int) $filters['period'])))
            ->when($filters['who'] === 'coresmith', fn(Builder $query) => $query->whereNull('causer_id'))
            ->when(!in_array($filters['who'], ['anyone', 'coresmith'], true), fn(Builder $query) => $query->where('causer_type', $morph(User::class))->where('causer_id', $filters['who']))
            ->when($what === 'commands', fn(Builder $query) => $query->where('description', $command))
            ->when(!in_array($what, ['everything', 'commands'], true), fn(Builder $query) => $query->where('description', '!=', $command))
            ->when($what === 'servers', fn(Builder $query) => $query->where('subject_type', $morph(Server::class)))
            ->when($what === 'apps', fn(Builder $query) => $query->whereIn('subject_type', [$morph(Application::class), $morph(WebhookEndpoint::class)]))
            ->when($what === 'databases', fn(Builder $query) => $query->where('subject_type', $morph(ManagedDatabase::class)))
            ->when($what === 'team', fn(Builder $query) => $query->whereIn('subject_type', [$morph(Team::class), $morph(CloudProvider::class), $morph(SourceProvider::class), $morph(GitHubApp::class)]));
    }

    /**
     * @param  array{period: string, who: string, what: string}  $filters
     * @return array{events: list<array<string, mixed>>, more: bool}
     */
    public function page(Team $team, array $filters, int $page, User $viewer): array
    {
        $activities = $this->withSubjects($this->filtered($team, $filters))
            ->latest('created_at')
            ->latest('id')
            ->skip((max(1, $page) - 1) * self::PAGE)
            ->limit(self::PAGE + 1)
            ->get();
        $types = $this->types();

        return [
            'events' => $activities->take(self::PAGE)->map(fn(Activity $activity): array => $this->present($activity, $types[(string) $activity->subject_type] ?? '', $viewer))->values()->all(),
            'more' => $activities->count() > self::PAGE,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function people(Team $team): array
    {
        return $team->members()->orderBy('name')->get(['users.id', 'users.name'])
            ->map(fn(User $user): array => ['value' => (string) $user->getKey(), 'label' => (string) $user->name])
            ->values()
            ->all();
    }

    /**
     * @param  array{period: string, who: string, what: string}  $filters
     * @return iterable<int, list<string>>
     */
    public function rows(Team $team, array $filters): iterable
    {
        $query = $this->withSubjects($this->filtered($team, $filters))
            ->orderBy('created_at')
            ->orderBy('id');

        $types = $this->types();

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
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function withSubjects(Builder $query): Builder
    {
        return $query->with(['causer', 'subject' => fn(MorphTo $subject) => $subject->constrain([
            Team::class => fn(Builder $query) => $query->withTrashed(),
            Server::class => fn(Builder $query) => $query->withTrashed(),
            Application::class => fn(Builder $query) => $query->withTrashed(),
            ManagedDatabase::class => fn(Builder $query) => $query->withTrashed(),
            CloudProvider::class => fn(Builder $query) => $query->withTrashed(),
            SourceProvider::class => fn(Builder $query) => $query->withTrashed(),
            WebhookEndpoint::class => fn(Builder $query) => $query->with(['application' => fn($application) => $application->withTrashed()]),
        ])]);
    }

    /**
     * @return array<string, string>
     */
    private function types(): array
    {
        return collect(self::TYPES)->mapWithKeys(fn(string $type, string $class): array => [(new $class)->getMorphClass() => $type])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Activity $activity, string $type, User $viewer): array
    {
        $properties = $activity->properties?->all() ?? [];

        return [
            'id' => (int) $activity->getKey(),
            'when' => Formats::when($activity->created_at),
            'full' => Formats::absolute($activity->created_at),
            'actor' => ['name' => $this->actor($activity), 'email' => $activity->causer instanceof User ? $activity->causer->email : null],
            'event' => self::event((string) $activity->description),
            'subject' => ['kind' => self::LABELS[$type] ?? null, 'name' => $this->name($activity->subject, $properties), 'href' => $this->href($activity->subject, $viewer)],
            'details' => array_map(fn(string|int $key): array => ['key' => str_replace('_', ' ', (string) $key), 'value' => $this->sanitizer->sanitize($this->value($properties[$key]))], array_keys($properties)),
        ];
    }

    private function actor(Activity $activity): string
    {
        return match (true) {
            $activity->causer instanceof User => (string) $activity->causer->name,
            $activity->causer_id !== null => 'A removed account',
            default => 'Coresmith',
        };
    }

    private function value(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value), $value === null => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    private function href(?Model $subject, User $viewer): ?string
    {
        $alive = fn(Model $model): bool => !method_exists($model, 'trashed') || !$model->trashed();
        $application = $subject instanceof WebhookEndpoint ? $subject->application : $subject;

        return match (true) {
            $subject instanceof Server => $alive($subject) && $viewer->can('view', $subject) ? route('servers.show', $subject) : null,
            $application instanceof Application => $alive($application) && $viewer->can('view', $application) ? route('servers.applications.show', [app(RouteKeys::class)->server($application->server_id), $application]) : null,
            $subject instanceof ManagedDatabase => $alive($subject) && $viewer->can('view', $subject) ? route('servers.databases.show', [app(RouteKeys::class)->server($subject->server_id), $subject]) : null,
            $subject instanceof CloudProvider => $alive($subject) ? route('cloud-providers.index') : null,
            $subject instanceof SourceProvider, $subject instanceof GitHubApp => route('source-providers.index'),
            $subject instanceof Team => route('teams.index'),
            default => null,
        };
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
            $this->actor($activity),
            $causer instanceof User ? $causer->email : '',
            $activity->description,
            $type,
            $this->name($subject, $properties),
            $properties === [] ? '' : $this->sanitizer->sanitize((string) json_encode($properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function name(?Model $subject, array $properties): string
    {
        return match (true) {
            $subject instanceof WebhookEndpoint => (string) $subject->application?->name,
            $subject === null => (string) ($properties['name'] ?? ''),
            default => (string) $subject->getAttribute('name'),
        };
    }
}
