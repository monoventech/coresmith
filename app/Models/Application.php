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
use App\Services\SafeName;
use Database\Factories\ApplicationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Application extends Model
{
    /** @use HasFactory<ApplicationFactory> */
    use HasFactory, HasUlids, SoftDeletes;

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

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'env_variables' => Vaulted::class,
            'deploy_key_private' => Vaulted::class,
            'type' => ApplicationType::class,
            'status' => ApplicationStatus::class,
            'power_state' => PowerState::class,
            'additional_domains' => 'array',
            'toggles' => 'array',
            'processes' => 'array',
            'cron_jobs' => 'array',
            'run_migrations' => 'boolean',
            'quick_deploy' => 'boolean',
            'last_request_at' => 'datetime',
            'runtime_pins' => 'array',
            'stack' => 'array',
        ];
    }
}
