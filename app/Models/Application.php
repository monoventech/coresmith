<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Enums\SourceProviderType;
use App\Jobs\SyncServerFrontDoor;
use App\Jobs\SyncServerTunnel;
use App\Models\Concerns\HasHandle;
use App\Models\Concerns\IgnoresJsonKeyOrder;
use App\Models\Concerns\RecordsWhoAdded;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\Repository;
use App\Services\SafeName;
use App\Services\Server\PortAllocator;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, HasHandle, HasUlids, IgnoresJsonKeyOrder, RecordsWhoAdded, SoftDeletes {
        HasHandle::resolveRouteBindingQuery insteadof HasUlids;
    }

    public const ADDRESS_PORTS = 20000;

    protected $guarded = ['id'];

    protected $hidden = ['env_variables', 'deploy_key_private'];

    protected static function booted(): void
    {
        static::saving(function (self $application): void {
            SafeName::assertSlug($application->slug);
            SafeName::assertSystemUser($application->system_user);

            foreach ((array) $application->processes as $process) {
                if (is_array($process) && isset($process['name'])) {
                    SafeName::assertProcess((string) $process['name']);
                }
            }

            if ($application->isDirty('cron_jobs')) {
                foreach ((array) $application->cron_jobs as $job) {
                    if (is_array($job) && isset($job['schedule'])) {
                        CrontabBuilder::assertSchedule((string) $job['schedule']);
                    }
                }
            }

            foreach ($application->allDomains() as $domain) {
                SafeName::assertDomain($domain);
            }

            if ($application->repository_branch !== null) {
                SafeName::assertBranch($application->repository_branch);
            }

            foreach (['directory', 'web_directory'] as $path) {
                if ($application->{$path} !== null) {
                    SafeName::assertRelativePath($application->{$path});
                }
            }
        });

        static::created(function (self $application): void {
            if ($application->allDomains() !== []) {
                SyncServerTunnel::whenCarrying($application->server);
                SyncServerFrontDoor::whenOpen($application->server);
            }
        });

        static::updated(function (self $application): void {
            if ($application->wasChanged(['domain', 'additional_domains'])) {
                SyncServerTunnel::whenCarrying($application->server);
                SyncServerFrontDoor::whenOpen($application->server);
            }
        });

        static::deleted(function (self $application): void {
            MonitorAlert::closeAllFor($application);
            SyncServerTunnel::whenCarrying($application->server);
            SyncServerFrontDoor::whenOpen($application->server);
        });
    }

    /**
     * @return list<string>
     */
    public function allDomains(): array
    {
        $domains = [];

        if ($this->domain !== null && $this->domain !== '') {
            $domains[] = (string) $this->domain;
        }

        foreach ((array) $this->additional_domains as $domain) {
            if (is_string($domain) && $domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @return array<string, string>
     */
    public function runtimeVersions(): array
    {
        return array_filter([
            'php' => $this->php_version,
            'nodejs' => $this->node_version,
            'bun' => $this->bun_version,
            'deno' => $this->deno_version,
            'ruby' => $this->ruby_version,
            'python' => $this->python_version,
            'go' => $this->go_version,
            'java' => $this->java_version,
        ], fn(?string $version): bool => $version !== null && $version !== '');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function databases(): BelongsToMany
    {
        return $this->belongsToMany(ManagedDatabase::class, 'application_database', 'application_id', 'database_id')->withTimestamps();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return Attribute<list<string>, list<string>>
     */
    protected function tags(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value): array => array_values(array_filter((array) json_decode((string) $value, true), 'is_string')),
            set: fn(array $tags): array => ['tags' => $tags === [] ? null : json_encode(array_values($tags))],
        );
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function deployedBefore(): bool
    {
        return $this->current_release !== null || $this->deployments()->exists();
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(SourceProvider::class)->withTrashed();
    }

    public function githubConnection(): ?SourceProvider
    {
        if ($this->source_provider_id === null) {
            return null;
        }

        $provider = $this->sourceProvider;

        return $provider?->type === SourceProviderType::GitHubApp ? $provider : null;
    }

    public function addressPort(): int
    {
        return self::ADDRESS_PORTS + intdiv((int) $this->base_port - PortAllocator::RANGE_START, PortAllocator::BLOCK_SIZE);
    }

    public function needsRepositoryCheck(): bool
    {
        return $this->repository_check_pending === true;
    }

    public function usesGitHubToken(): bool
    {
        $connection = $this->githubConnection();

        return $connection !== null
            && !$connection->trashed()
            && $connection->connected()
            && app(GitHubApps::class)->for($connection) !== null;
    }

    public function githubProblem(): ?string
    {
        $connection = $this->githubConnection();

        if ($connection === null || $this->usesGitHubToken()) {
            return null;
        }

        return !$connection->trashed() && $connection->suspended_at !== null ? 'suspended' : 'removed';
    }

    public function repositoryFullName(): ?string
    {
        return Repository::slug((string) $this->repository_url);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tailnet' => 'boolean',
            'preview' => 'boolean',
            'env_variables' => Vaulted::class,
            'deploy_key_private' => Vaulted::class,
            'type' => ApplicationType::class,
            'status' => ApplicationStatus::class,
            'power_state' => PowerState::class,
            'additional_domains' => 'array',
            'toggles' => 'array',
            'processes' => 'array',
            'cron_jobs' => 'array',
            'cron_runs' => 'array',
            'cron_written_at' => 'datetime',
            'cron_pending_since' => 'datetime',
            'php_limits' => 'array',
            'php_written_at' => 'datetime',
            'run_migrations' => 'boolean',
            'quick_deploy' => 'boolean',
            'last_request_at' => 'datetime',
            'repository_head_at' => 'datetime',
            'repository_pushed_at' => 'datetime',
            'repository_checked_at' => 'datetime',
            'repository_check_pending' => 'boolean',
            'repository_check' => 'array',
            'domain_reach' => 'array',
            'polled_at' => 'datetime',
            'runtime_pins' => 'array',
            'stack' => 'array',
            'answered_at' => 'datetime',
            'failed_checks' => 'integer',
        ];
    }

    protected function handleFallback(): string
    {
        return 'app';
    }

    protected function handleAlsoBy(): ?string
    {
        return 'slug';
    }
}
