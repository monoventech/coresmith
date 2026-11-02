<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Enums\SourceProviderType;
use App\Models\Application;
use App\Models\SourceProvider;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Git\Providers\GitHubAppClient;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final class GitHubEventHandler
{
    private const HEADS = 'refs/heads/';

    private const SELECTIONS = ['all', 'selected'];

    public function __construct(
        private readonly AutoDeploy $autoDeploy,
        private readonly RepositoryCatalog $catalog,
        private readonly ApplicationRelinker $relinker,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function push(#[\SensitiveParameter] GitHubAppCredentials $app, array $payload, ?string $deliveryId): int
    {
        $connections = $this->connections($app, $payload);
        $observed = $this->observeHead($connections, $payload);

        foreach ($connections as $connection) {
            $connection->forceFill([
                'last_delivery_id' => $deliveryId,
                'last_event' => 'push',
                'last_status' => 202,
                'last_delivered_at' => now(),
            ])->saveQuietly();
        }

        return $observed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function installation(#[\SensitiveParameter] GitHubAppCredentials $app, array $payload): void
    {
        $action = $payload['action'] ?? null;

        if (!in_array($action, ['deleted', 'suspend', 'unsuspend'], true)) {
            return;
        }

        foreach ($this->connections($app, $payload) as $connection) {
            if ($action === 'deleted') {
                $connection->markUninstalled();

                continue;
            }

            $connection->markSuspended($action === 'suspend' ? $this->suspendedAt($payload) : null);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function installationRepositories(#[\SensitiveParameter] GitHubAppCredentials $app, array $payload): void
    {
        $selection = $payload['repository_selection'] ?? null;

        foreach ($this->connections($app, $payload) as $connection) {
            $this->catalog->forget($connection);

            if (in_array($selection, self::SELECTIONS, true)) {
                $connection->forceFill(['repository_selection' => $selection])->save();
            }
        }
    }

    /**
     * @param  Collection<int, SourceProvider>  $connections
     * @param  array<string, mixed>  $payload
     */
    private function observeHead(Collection $connections, array $payload): int
    {
        $branch = $this->pushedBranch($connections, $payload);

        if ($branch === null) {
            return 0;
        }

        $after = (string) $payload['after'];
        $pushedAt = $this->pushedAt($payload);
        $fullName = $this->fullName($payload);
        $repositoryId = (string) $payload['repository']['id'];

        $this->relinker->follow($connections, $repositoryId, $fullName);

        $applications = Application::query()
            ->whereIn('source_provider_id', $connections->modelKeys())
            ->where('repository_id', $repositoryId)
            ->get()
            ->filter(fn(Application $application): bool => $application->repository_branch === $branch && !$this->autoDeploy->stale($application, $after, $pushedAt));

        foreach ($applications as $application) {
            if ($fullName !== null && $fullName !== $application->repositoryFullName()) {
                $application->forceFill(['repository_url' => "https://github.com/{$fullName}.git"])->save();
            }

            $this->autoDeploy->observe($application, $after, 'push', $pushedAt);
        }

        return $applications->count();
    }

    /**
     * @param  Collection<int, SourceProvider>  $connections
     * @param  array<string, mixed>  $payload
     */
    private function pushedBranch(Collection $connections, array $payload): ?string
    {
        $ref = $payload['ref'] ?? null;
        $branchHead = $connections->isNotEmpty()
            && is_string($ref)
            && str_starts_with($ref, self::HEADS)
            && ($payload['deleted'] ?? false) !== true
            && $this->isCommit($payload['after'] ?? null)
            && $this->id($payload['repository']['id'] ?? null) !== null;

        return $branchHead ? substr($ref, strlen(self::HEADS)) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, SourceProvider>
     */
    private function connections(#[\SensitiveParameter] GitHubAppCredentials $app, array $payload): Collection
    {
        $installation = $this->id($payload['installation']['id'] ?? null);

        if ($installation === null) {
            return new Collection;
        }

        return SourceProvider::query()
            ->where('type', SourceProviderType::GitHubApp->value)
            ->where('github_app_id', $app->isEnv() ? null : $app->key)
            ->where('installation_id', $installation)
            ->get();
    }

    private function id(mixed $value): ?string
    {
        return is_int($value) ? (string) $value : null;
    }

    private function isCommit(mixed $sha): bool
    {
        return is_string($sha) && preg_match(AutoDeploy::COMMIT, $sha) === 1 && $sha !== str_repeat('0', 40);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fullName(array $payload): ?string
    {
        $fullName = $payload['repository']['full_name'] ?? null;

        return is_string($fullName) && GitHubAppClient::isFullName($fullName) ? $fullName : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pushedAt(array $payload): ?CarbonImmutable
    {
        $at = $payload['repository']['pushed_at'] ?? null;
        $timestamp = is_string($at) ? strtotime($at) : $at;

        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp, date_default_timezone_get()) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function suspendedAt(array $payload): CarbonInterface
    {
        $at = $payload['installation']['suspended_at'] ?? null;

        return is_string($at) && $at !== '' ? CarbonImmutable::parse($at) : now();
    }
}
