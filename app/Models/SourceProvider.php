<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use App\Enums\SourceProviderType;
use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitProviderException;
use App\Models\Concerns\IgnoresJsonKeyOrder;
use App\Services\Git\ProviderHost;
use App\Services\Teams\TeamAccess;
use Carbon\CarbonInterface;
use Database\Factories\SourceProviderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SourceProvider extends Model
{
    /** @use HasFactory<SourceProviderFactory> */
    use HasFactory, HasUlids, IgnoresJsonKeyOrder, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected static function booted(): void
    {
        static::saving(function (self $provider): void {
            $provider->custom_host = ProviderHost::normalise($provider->custom_host);
        });
    }

    public function usableBy(User $user, Team $team): bool
    {
        return $this->team_id === $team->getKey() && app(TeamAccess::class)->seesEverything($user, $team);
    }

    public function connected(): bool
    {
        return match ($this->type) {
            SourceProviderType::GitHubApp => filled($this->installation_id) && $this->suspended_at === null && !$this->trashed(),
            SourceProviderType::Custom => true,
            SourceProviderType::GitLab, SourceProviderType::Bitbucket => $this->access_token !== null,
        };
    }

    public function disconnect(): void
    {
        $this->forceFill(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null])->save();

        $this->delete();
    }

    public function markUninstalled(): void
    {
        $this->disconnect();

        activity()->performedOn($this)->log('github_installation_removed');
    }

    public function markSuspended(?CarbonInterface $at): void
    {
        $this->forceFill(['suspended_at' => $at])->save();
    }

    public function markRefused(GitProviderException $refusal): bool
    {
        $kind = $refusal instanceof GitHubInstallationException ? $refusal->kind : null;

        if ($kind === GitHubInstallationException::UNINSTALLED) {
            $this->markUninstalled();

            return true;
        }

        if ($kind === GitHubInstallationException::SUSPENDED) {
            $this->markSuspended(now());

            return true;
        }

        return false;
    }

    public function manageUrl(): ?string
    {
        if (blank($this->installation_id)) {
            return null;
        }

        $installation = rawurlencode((string) $this->installation_id);

        return match ($this->account_type) {
            'Organization' => 'https://github.com/organizations/' . rawurlencode((string) $this->name) . "/settings/installations/{$installation}",
            'User' => "https://github.com/settings/installations/{$installation}",
            default => null,
        };
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function githubApp(): BelongsTo
    {
        return $this->belongsTo(GitHubApp::class, 'github_app_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SourceProviderType::class,
            'access_token' => Vaulted::class,
            'refresh_token' => Vaulted::class,
            'scopes' => 'array',
            'token_expires_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_status' => 'integer',
            'last_delivered_at' => 'datetime',
        ];
    }
}
