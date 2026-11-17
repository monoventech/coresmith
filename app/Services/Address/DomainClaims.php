<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\Application;
use App\Models\TeamDomain;
use App\Services\Teams\TeamUrls;
use Illuminate\Database\Eloquent\Builder;

final readonly class DomainClaims
{
    public function __construct(private TeamUrls $urls) {}

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    public function byApps(Application $application, array $names): array
    {
        $used = $this->claiming($names)
            ->when($application->exists, fn(Builder $query) => $query->whereKeyNot($application->getKey()))
            ->where(fn(Builder $query) => self::near($query, $application))
            ->get(['id', 'domain', 'additional_domains'])
            ->flatMap(fn(Application $other): array => array_map(strtolower(...), $other->allDomains()))
            ->all();

        return array_values(array_intersect($names, $used));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    public function byCoresmith(array $names): array
    {
        $used = [$this->urls->mainHost(), ...TeamDomain::query()->whereIn('hostname', $names)->pluck('hostname')->all()];

        return array_values(array_intersect($names, $used));
    }

    /**
     * @param  list<string>  $names
     * @return Builder<Application>
     */
    public function claiming(array $names): Builder
    {
        return Application::query()->where(function (Builder $query) use ($names): void {
            $query->whereIn('domain', $names);

            foreach ($names as $name) {
                $query->orWhereJsonContains('additional_domains', $name);
            }
        });
    }

    /**
     * @param  Builder<Application>  $query
     * @return Builder<Application>
     */
    public static function near(Builder $query, Application $application): Builder
    {
        return $query->where('server_id', $application->server_id)->orWhere('team_id', $application->team_id);
    }
}
