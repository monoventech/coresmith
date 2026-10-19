<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git\GitHub;

use App\Models\SourceProvider;
use App\Services\Git\GitProviderFactory;
use Illuminate\Support\Facades\Cache;

final class RepositoryCatalog
{
    public const MINUTES = 5;

    public const BRANCH_MINUTES = 2;

    public const MAX_PAGES = 10;

    public const NOT_VISIBLE = 'GitHub does not give Coresmith access to that repository. Pick another, or add it under Manage repositories on GitHub.';

    public const UNREACHABLE = 'Coresmith could not reach GitHub to check that repository. Try again in a minute.';

    private const REFETCH_SECONDS = 60;

    public function __construct(private readonly GitProviderFactory $clients) {}

    public static function key(SourceProvider $provider): string
    {
        return 'github:repositories:' . config('app.env') . ':' . $provider->getKey();
    }

    public static function branchesKey(SourceProvider $provider, string $repositoryId): string
    {
        return self::key($provider) . ':branches:' . $repositoryId;
    }

    /**
     * @return array{total: int, truncated: bool, repositories: list<array<string, mixed>>}
     */
    public function for(SourceProvider $provider): array
    {
        return $this->cached($provider) ?? $this->fetch($provider);
    }

    /**
     * @return array{total: int, truncated: bool, repositories: list<array<string, mixed>>}|null
     */
    public function cached(SourceProvider $provider): ?array
    {
        $catalog = Cache::get(self::key($provider));

        if (!is_array($catalog) || !is_int($catalog['total'] ?? null) || !is_bool($catalog['truncated'] ?? null) || !is_array($catalog['repositories'] ?? null)) {
            return null;
        }

        return $catalog;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(SourceProvider $provider, string $repositoryId): ?array
    {
        if (preg_match('/^[0-9]{1,20}\z/', $repositoryId) !== 1) {
            return null;
        }

        $cached = $this->cached($provider);

        if ($cached === null) {
            return $this->entry($this->fetch($provider), $repositoryId);
        }

        $known = $this->entry($cached, $repositoryId);

        if ($known !== null || !Cache::add(self::refetchKey($provider), true, self::REFETCH_SECONDS)) {
            return $known;
        }

        return $this->entry($this->fetch($provider), $repositoryId);
    }

    /**
     * @param  array<string, mixed>  $repository
     * @return list<string>
     */
    public function branches(SourceProvider $provider, array $repository): array
    {
        return Cache::remember(
            self::branchesKey($provider, (string) $repository['id']),
            self::BRANCH_MINUTES * 60,
            fn(): array => array_values(array_unique([
                (string) $repository['default_branch'],
                ...$this->clients->github($provider)->branches((string) $repository['full_name']),
            ])),
        );
    }

    public function forget(SourceProvider $provider): void
    {
        Cache::forget(self::key($provider));
    }

    private static function refetchKey(SourceProvider $provider): string
    {
        return self::key($provider) . ':refetched';
    }

    /**
     * @return array{total: int, truncated: bool, repositories: list<array<string, mixed>>}
     */
    private function fetch(SourceProvider $provider): array
    {
        $listing = $this->clients->github($provider)->installationRepositories(self::MAX_PAGES);
        $catalog = [
            'total' => $listing['total'],
            'truncated' => $listing['total'] > count($listing['repositories']),
            'repositories' => $listing['repositories'],
        ];

        Cache::put(self::key($provider), $catalog, self::MINUTES * 60);

        return $catalog;
    }

    /**
     * @param  array{total: int, truncated: bool, repositories: list<array<string, mixed>>}  $catalog
     * @return array<string, mixed>|null
     */
    private function entry(array $catalog, string $repositoryId): ?array
    {
        foreach ($catalog['repositories'] as $repository) {
            if (is_array($repository) && (string) ($repository['id'] ?? '') === $repositoryId) {
                return $repository;
            }
        }

        return null;
    }
}
