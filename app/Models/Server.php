<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Models;

use App\Casts\Vaulted;
use App\Enums\ServerStatus;
use App\Models\Concerns\IgnoresJsonKeyOrder;
use App\Models\Concerns\RecordsWhoAdded;
use App\Services\Runtime\AsdfManager;
use App\Services\Server\SecurityHardening;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, HasUlids, IgnoresJsonKeyOrder, RecordsWhoAdded, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['ssh_private_key'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function cloudProvider(): BelongsTo
    {
        return $this->belongsTo(CloudProvider::class)->withTrashed();
    }

    public function fromProvider(): bool
    {
        return $this->cloud_provider_id !== null;
    }

    public function sshAddress(): ?string
    {
        if ($this->ip_address === null) {
            return null;
        }

        return "{$this->ssh_user}@{$this->ip_address}:{$this->ssh_port}";
    }

    public function caddyAdminOnSocket(): bool
    {
        return (((array) $this->meta)['caddy_admin'] ?? null) === 'socket';
    }

    public function sshHardened(): bool
    {
        return (((array) $this->meta)['ssh_hardening'] ?? null) === 'verified';
    }

    public function sshSettled(): bool
    {
        return $this->runs_coresmith ? $this->sshLeftToOwner() : $this->sshHardened();
    }

    public function sshLeftToOwner(): bool
    {
        return $this->runs_coresmith && (((array) $this->meta)['ssh_hardening'] ?? null) === SecurityHardening::LEFT_TO_OWNER;
    }

    public function sshPasswordsOff(): bool
    {
        return (((array) $this->meta)['ssh_passwords'] ?? null) === SecurityHardening::PASSWORDS_OFF;
    }

    public function sshPasswordsOn(): bool
    {
        return (((array) $this->meta)['ssh_passwords'] ?? null) === SecurityHardening::PASSWORDS_ON;
    }

    public function systemdVersion(): ?int
    {
        $version = ((array) $this->meta)['systemd'] ?? null;

        return is_int($version) ? $version : null;
    }

    public function unknownHostsClosed(): bool
    {
        return !$this->runs_coresmith && (((array) $this->meta)['caddy_unknown_hosts'] ?? null) === 'closed';
    }

    public function runtimeUser(): string
    {
        return $this->runs_coresmith ? AsdfManager::CORESMITH_HOST_USER : AsdfManager::SYSTEM_USER;
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(ManagedDatabase::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    protected function ipAddress(): Attribute
    {
        return Attribute::make(
            get: fn(?string $value): ?string => $value === null || $value === '' ? null : $value,
            set: fn(?string $value): string => $value ?? '',
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ssh_private_key' => Vaulted::class,
            'status' => ServerStatus::class,
            'ssh_port' => 'integer',
            'installed_runtimes' => 'array',
            'meta' => 'array',
            'audit' => 'array',
            'takeover' => 'array',
            'audit_logging_enabled' => 'boolean',
            'runs_coresmith' => 'boolean',
            'last_connected_at' => 'datetime',
            'unreachable_since' => 'datetime',
            'unreachable_alerted_at' => 'datetime',
            'unalerted_outages' => 'integer',
            'connection_failures' => 'integer',
            'connection_answers' => 'integer',
            'host_key_changed_at' => 'datetime',
            'booted_at' => 'datetime',
            'provider_task' => 'array',
            'provider_monthly_cost' => 'decimal:2',
            'public_addresses' => 'array',
        ];
    }
}
