<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Enums\SourceProviderType;
use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitProviderException;
use App\Models\Application;
use App\Models\SourceProvider;
use App\Services\Git\DeployKeyManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ApplicationRelinker
{
    private const STRANDING = [GitHubInstallationException::UNINSTALLED, GitHubInstallationException::SUSPENDED, GitHubInstallationException::REPOSITORY];

    public function __construct(
        private readonly RepositoryCatalog $catalog,
        private readonly GitHubApps $apps,
        private readonly DeployKeyManager $keys,
    ) {}

    public function usable(?SourceProvider $connection): bool
    {
        return $connection !== null
            && $connection->type === SourceProviderType::GitHubApp
            && $connection->connected()
            && $this->apps->for($connection) !== null;
    }

    public function adopt(SourceProvider $connection): void
    {
        if (!$this->usable($connection)) {
            return;
        }

        $stranded = $this->linked($connection)->reject(fn(Application $application): bool => $this->usable($application->sourceProvider));

        if ($stranded->isEmpty()) {
            return;
        }

        try {
            $covered = collect($this->catalog->for($connection)['repositories'])->keyBy(fn(array $repository): string => (string) $repository['id']);
        } catch (GitProviderException $e) {
            report_if(app()->hasDebugModeEnabled(), $e);

            return;
        }

        foreach ($stranded as $application) {
            $repository = $covered->get((string) $application->repository_id);

            if ($repository !== null) {
                $this->move($application, $connection, (string) $repository['full_name']);
            }
        }
    }

    public function relink(Application $application): ?SourceProvider
    {
        $current = $application->githubConnection();
        $repositoryId = (string) $application->repository_id;

        if ($current === null || $repositoryId === '' || $this->keys->usesDeployKey($application)) {
            return null;
        }

        $candidates = SourceProvider::query()
            ->where('team_id', $application->team_id)
            ->where('type', SourceProviderType::GitHubApp->value)
            ->whereKeyNot($current->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn(SourceProvider $candidate): bool => $this->usable($candidate));

        foreach ($candidates as $candidate) {
            try {
                $repository = $this->catalog->find($candidate, $repositoryId);
            } catch (GitProviderException) {
                continue;
            }

            if ($repository === null) {
                continue;
            }

            if ($this->move($application, $candidate, (string) $repository['full_name'])) {
                return $candidate;
            }

            $application->refresh();

            return $application->usesGitHubToken() ? $application->githubConnection() : null;
        }

        return null;
    }

    public function relinkAfter(Application $application, GitProviderException $refusal): ?SourceProvider
    {
        return $refusal instanceof GitHubInstallationException && in_array($refusal->kind, self::STRANDING, true)
            ? $this->relink($application)
            : null;
    }

    /**
     * @param  Collection<int, SourceProvider>  $connections
     */
    public function follow(Collection $connections, string $repositoryId, ?string $fullName): void
    {
        foreach ($connections as $connection) {
            if (!$this->usable($connection)) {
                continue;
            }

            foreach ($this->linked($connection, $repositoryId) as $application) {
                if ($this->leaves($application->sourceProvider, $connection)) {
                    $this->move($application, $connection, $fullName ?? (string) $application->repositoryFullName());
                }
            }
        }
    }

    /**
     * @return Collection<int, Application>
     */
    private function linked(SourceProvider $connection, ?string $repositoryId = null): Collection
    {
        return Application::query()
            ->where('team_id', $connection->team_id)
            ->whereNotNull('repository_id')
            ->when($repositoryId !== null, fn(Builder $query): Builder => $query->where('repository_id', $repositoryId))
            ->where('source_provider_id', '!=', $connection->getKey())
            ->whereHas('sourceProvider', fn(Builder $provider): Builder => $provider->where('type', SourceProviderType::GitHubApp->value))
            ->with('sourceProvider')
            ->orderBy('id')
            ->get()
            ->reject(fn(Application $application): bool => $this->keys->usesDeployKey($application))
            ->values();
    }

    private function leaves(?SourceProvider $from, SourceProvider $to): bool
    {
        return !$this->usable($from) || strcasecmp((string) $from?->name, (string) $to->name) !== 0;
    }

    private function move(Application $application, SourceProvider $to, string $repository): bool
    {
        $from = $application->source_provider_id;

        $moved = Application::query()
            ->whereKey($application->getKey())
            ->where('source_provider_id', $from)
            ->update(['source_provider_id' => $to->getKey(), 'updated_at' => now()]);

        if ($moved === 0) {
            return false;
        }

        $application->forceFill(['source_provider_id' => $to->getKey()])->syncOriginalAttribute('source_provider_id');
        $application->setRelation('sourceProvider', $to);

        activity()
            ->performedOn($application)
            ->withProperties(['from' => $from, 'to' => $to->getKey(), 'repository' => $repository])
            ->log('application_relinked');

        return true;
    }
}
