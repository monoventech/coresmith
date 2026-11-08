<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Moves;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Teams\TeamUrls;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TeamExporter
{
    public function __construct(private readonly TeamUrls $urls) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(Team $team, User $owner): array
    {
        $servers = $team->servers()->orderBy('id')->get();
        $applications = $team->applications()->orderBy('id')->get();

        $this->ensureReady($servers->all(), $applications->all());

        $providers = SourceProvider::query()->whereIn('id', $applications->pluck('source_provider_id')->filter()->unique())->orderBy('id')->get();

        return [
            'id' => (string) Str::uuid(),
            'made_at' => now()->toIso8601String(),
            'from' => rtrim($this->urls->canonical(), '/'),
            'team' => ['name' => $team->name, 'slug' => $team->slug],
            'members' => $team->members()->whereKeyNot($owner->getKey())->orderBy('email')->get()
                ->map(fn(User $member): array => ['email' => $member->email, 'role' => (string) $member->pivot->role])->values()->all(),
            'cloud_providers' => CloudProvider::query()->where('team_id', $team->id)->orderBy('id')->get()
                ->map(fn(CloudProvider $account): array => ['ref' => $account->id, ...MoveColumns::read($account, MoveColumns::CLOUD_PROVIDERS)])->values()->all(),
            'servers' => $servers->map(fn(Server $server): array => [
                'ref' => $server->id,
                'cloud_provider' => $server->cloud_provider_id,
                ...MoveColumns::read($server, MoveColumns::SERVERS),
            ])->values()->all(),
            'source_providers' => $providers->map(fn(SourceProvider $provider): array => [
                'ref' => $provider->id,
                'type' => $provider->type->value,
                'name' => $provider->name,
                'custom_host' => $provider->custom_host,
            ])->values()->all(),
            'applications' => $applications->map(fn(Application $application): array => [
                'ref' => $application->id,
                'server' => $application->server_id,
                'source_provider' => $application->source_provider_id,
                ...MoveColumns::read($application, MoveColumns::APPLICATIONS),
            ])->values()->all(),
            'webhook_endpoints' => WebhookEndpoint::query()->whereIn('application_id', $applications->pluck('id'))->orderBy('id')->get()
                ->map(fn(WebhookEndpoint $endpoint): array => ['application' => $endpoint->application_id, ...MoveColumns::read($endpoint, MoveColumns::WEBHOOK_ENDPOINTS)])->values()->all(),
            'databases' => ManagedDatabase::query()->where('team_id', $team->id)->orderBy('id')->get()
                ->map(fn(ManagedDatabase $database): array => ['server' => $database->server_id, ...MoveColumns::read($database, MoveColumns::DATABASES)])->values()->all(),
            'backup_destination' => $team->backupDestination === null ? null : MoveColumns::read($team->backupDestination, MoveColumns::BACKUP_DESTINATION),
        ];
    }

    /**
     * @param  list<Server>  $servers
     * @param  list<Application>  $applications
     */
    private function ensureReady(array $servers, array $applications): void
    {
        foreach ($servers as $server) {
            if ($server->pending_host_key_fingerprint !== null) {
                $this->refuse("{$server->name}'s host key changed. Accept or reject the new key on its page, then download the move file.");
            }

            if ($server->status !== ServerStatus::Active && $server->status !== ServerStatus::Moving) {
                $this->refuse("{$server->name} isn't ready to move. Finish setting it up or remove it, then download the move file.");
            }

            $busy = CloudTask::refusal($server, 'Download the move file');

            if ($busy !== null) {
                $this->refuse($busy);
            }
        }

        $deploying = Deployment::query()
            ->whereIn('application_id', array_map(fn(Application $application): string => $application->id, $applications))
            ->whereIn('status', [DeploymentStatus::Pending->value, DeploymentStatus::Running->value])
            ->with('application')
            ->first();

        if ($deploying !== null) {
            $this->refuse("{$deploying->application?->name} is deploying. Wait for it to finish, then download the move file.");
        }
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['code' => $message]);
    }
}
