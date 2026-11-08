<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\ServerStatus;
use App\Enums\SourceProviderType;
use App\Enums\TeamRole;
use App\Jobs\TakeOverServer;
use App\Models\Application;
use App\Models\BackupDestination;
use App\Models\CloudProvider;
use App\Models\ManagedDatabase;
use App\Models\MoveCode;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Teams\TeamInvitations;
use App\Services\Teams\TeamSlug;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeamImporter
{
    public const GITHUB_PLACEHOLDER = 'Coresmith Cloud';

    public function __construct(private readonly TeamInvitations $invitations) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{team: string, from: string, servers: list<string>, applications: int, databases: int, members: int, github: int, reconnect: array<string, int>, webhooks: int}
     */
    public function preview(array $payload): array
    {
        $types = $this->providerTypes($payload);
        $applications = collect($this->records($payload, 'applications'));
        $typeOf = fn(array $application): ?SourceProviderType => $types[(string) ($application['source_provider'] ?? '')] ?? null;

        return [
            'team' => (string) ($payload['team']['name'] ?? ''),
            'from' => (string) ($payload['from'] ?? ''),
            'servers' => array_map(fn(array $server): string => (string) ($server['name'] ?? ''), $this->records($payload, 'servers')),
            'applications' => $applications->count(),
            'databases' => count($this->records($payload, 'databases')),
            'members' => count($this->records($payload, 'members')),
            'github' => $applications->filter(fn(array $application): bool => $typeOf($application) === SourceProviderType::GitHubApp)->count(),
            'reconnect' => $applications->map($typeOf)
                ->filter(fn(?SourceProviderType $type): bool => $type === SourceProviderType::GitLab || $type === SourceProviderType::Bitbucket)
                ->countBy(fn(SourceProviderType $type): string => $type->label())
                ->all(),
            'webhooks' => count($this->records($payload, 'webhook_endpoints')),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function import(array $payload, MoveCode $code, User $owner): Team
    {
        $this->ensureFits($payload);

        [$team, $servers] = DB::transaction(fn(): array => $this->create($payload, $code, $owner));

        foreach ($this->records($payload, 'members') as $member) {
            $email = Str::lower(trim((string) ($member['email'] ?? '')));

            if ($email !== '' && $email !== Str::lower((string) $owner->email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $this->invitations->invite($team, $email, TeamRole::tryFrom((string) ($member['role'] ?? '')) ?? TeamRole::Member, $owner);
            }
        }

        foreach ($servers as $server) {
            TakeOverServer::dispatch($server);
        }

        return $team;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: Team, 1: list<Server>}
     */
    private function create(array $payload, MoveCode $code, User $owner): array
    {
        $name = (string) ($payload['team']['name'] ?? '') ?: 'Moved team';
        $slug = (string) ($payload['team']['slug'] ?? '');

        $team = new Team;
        $team->forceFill(['name' => $name, 'owner_id' => $owner->getKey(), 'slug' => TeamSlug::isAvailable($slug) ? $slug : TeamSlug::generate($name)])->save();
        $team->members()->attach($owner->getKey(), ['role' => TeamRole::Owner->value]);

        $accounts = [];
        foreach ($this->records($payload, 'cloud_providers') as $record) {
            $account = new CloudProvider;
            $account->forceFill(['team_id' => $team->id, ...MoveColumns::pick($record, MoveColumns::CLOUD_PROVIDERS)])->save();
            $accounts[(string) $record['ref']] = $account->id;
        }

        $servers = [];
        foreach ($this->records($payload, 'servers') as $record) {
            $server = new Server;
            $server->forceFill([
                'team_id' => $team->id,
                'cloud_provider_id' => $accounts[(string) ($record['cloud_provider'] ?? '')] ?? null,
                ...MoveColumns::pick($record, MoveColumns::SERVERS),
                'status' => ServerStatus::Moving,
                'takeover' => ['state' => 'waiting', 'cloud_key' => (string) $record['ssh_public_key'], 'attempts' => 0, 'error' => null],
            ])->save();
            $servers[(string) $record['ref']] = $server;
        }

        $providers = [];
        foreach ($this->records($payload, 'source_providers') as $record) {
            $type = SourceProviderType::tryFrom((string) ($record['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $provider = new SourceProvider;
            $provider->forceFill(['team_id' => $team->id, 'type' => $type, ...match ($type) {
                SourceProviderType::GitHubApp => ['name' => self::GITHUB_PLACEHOLDER, 'installation_id' => null, 'access_token' => null],
                SourceProviderType::Custom => ['name' => (string) ($record['name'] ?? 'Custom'), 'custom_host' => $record['custom_host'] ?? null, 'access_token' => null],
                default => ['name' => (string) ($record['name'] ?? $type->label()), 'access_token' => null],
            }])->save();
            $providers[(string) $record['ref']] = $provider->id;
        }

        $applications = [];
        foreach ($this->records($payload, 'applications') as $record) {
            $application = new Application;
            $application->forceFill([
                'team_id' => $team->id,
                'server_id' => $servers[(string) $record['server']]->id,
                'source_provider_id' => $providers[(string) ($record['source_provider'] ?? '')] ?? null,
                ...MoveColumns::pick($record, MoveColumns::APPLICATIONS),
                'last_request_at' => now(),
            ])->save();
            $applications[(string) $record['ref']] = $application->id;
        }

        foreach ($this->records($payload, 'webhook_endpoints') as $record) {
            if (isset($applications[(string) ($record['application'] ?? '')])) {
                (new WebhookEndpoint)->forceFill(['application_id' => $applications[(string) $record['application']], ...MoveColumns::pick($record, MoveColumns::WEBHOOK_ENDPOINTS)])->save();
            }
        }

        foreach ($this->records($payload, 'databases') as $record) {
            (new ManagedDatabase)->forceFill(['team_id' => $team->id, 'server_id' => $servers[(string) $record['server']]->id, ...MoveColumns::pick($record, MoveColumns::DATABASES)])->save();
        }

        if (is_array($payload['backup_destination'] ?? null)) {
            (new BackupDestination)->forceFill(['team_id' => $team->id, ...MoveColumns::pick($payload['backup_destination'], MoveColumns::BACKUP_DESTINATION)])->save();
        }

        $code->forceFill(['imported_at' => now(), 'team_id' => $team->id, 'secret_key' => null, 'file' => null])->save();
        $owner->forceFill(['current_team_id' => $team->id])->save();

        activity()->performedOn($team)->causedBy($owner)->withProperties(['from' => $payload['from'] ?? null, 'servers' => count($servers)])->log('team_moved_in');

        return [$team, array_values($servers)];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ensureFits(array $payload): void
    {
        $servers = $this->records($payload, 'servers');
        $refs = array_map(fn(array $server): string => (string) ($server['ref'] ?? ''), $servers);
        $complete = $servers !== [] && collect($servers)->every(fn(array $server): bool => filled($server['ref'] ?? null) && filled($server['ip_address'] ?? null) && filled($server['ssh_private_key'] ?? null) && filled($server['ssh_public_key'] ?? null) && filled($server['host_key_fingerprint'] ?? null))
            && collect([...$this->records($payload, 'applications'), ...$this->records($payload, 'databases')])->every(fn(array $record): bool => in_array((string) ($record['server'] ?? ''), $refs, true));

        if (!is_array($payload['team'] ?? null) || !$complete) {
            $this->refuse('This move file is missing parts of the team. Download it again from Coresmith Cloud.');
        }

        $taken = Server::query()->whereIn('ip_address', array_column($servers, 'ip_address'))->value('ip_address');

        if ($taken !== null) {
            $this->refuse("{$taken} is already a server here. Remove it there, then upload the file again.");
        }

        $slug = Application::withTrashed()->whereIn('slug', array_column($this->records($payload, 'applications'), 'slug'))->value('slug');

        if ($slug !== null) {
            $this->refuse("An app called {$slug} is already here. Remove it there, then upload the file again.");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, SourceProviderType>
     */
    private function providerTypes(array $payload): array
    {
        $types = [];

        foreach ($this->records($payload, 'source_providers') as $record) {
            $type = SourceProviderType::tryFrom((string) ($record['type'] ?? ''));

            if ($type !== null) {
                $types[(string) ($record['ref'] ?? '')] = $type;
            }
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function records(array $payload, string $key): array
    {
        return array_values(array_filter(is_array($payload[$key] ?? null) ? $payload[$key] : [], 'is_array'));
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
