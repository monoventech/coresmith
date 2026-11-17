<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\Application;
use App\Services\SafeName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class DomainTwins
{
    public function __construct(private PublicSuffix $suffixes, private DomainClaims $claims) {}

    public function twin(string $domain): ?string
    {
        $domain = strtolower(trim($domain, '.'));

        if (str_starts_with($domain, '*.') || !SafeName::isDomain($domain)) {
            return null;
        }

        $registrable = $this->suffixes->registrable($domain);

        return match (true) {
            $registrable === null => null,
            $domain === $registrable => 'www.' . $domain,
            $domain === 'www.' . $registrable => $registrable,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public function for(Application $application): array
    {
        $own = array_map(strtolower(...), $application->allDomains());
        $twins = [];

        foreach ($own as $domain) {
            $twin = $this->twin($domain);

            if ($twin !== null && !isset($twins[$twin]) && array_intersect(self::covering($twin), $own) === []) {
                $twins[$twin] = $domain;
            }
        }

        if ($twins === []) {
            return [];
        }

        $names = array_merge(...array_map(self::covering(...), array_keys($twins)));
        $taken = [...$this->claims->byApps($application, $names), ...$this->claims->byCoresmith($names)];

        return array_filter($twins, fn(string $twin): bool => array_intersect(self::covering($twin), $taken) === [], ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, Application>
     */
    public function owners(array $names, Application $except): Collection
    {
        $owners = array_values(array_unique(array_filter(array_map(
            fn(string $name): ?string => $this->twin(str_starts_with($name, '*.') ? 'www.' . substr($name, 2) : $name),
            $names,
        ))));

        if ($owners === []) {
            return new Collection;
        }

        return $this->claims->claiming($owners)
            ->whereKeyNot($except->getKey())
            ->where(fn(Builder $query) => DomainClaims::near($query, $except))
            ->whereNotNull('current_release')
            ->get();
    }

    /**
     * @return list<string>
     */
    private static function covering(string $name): array
    {
        $parent = substr($name, (int) strpos($name, '.') + 1);

        return str_contains($parent, '.') ? [$name, '*.' . $parent] : [$name];
    }
}
