<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud;

use App\Models\CloudProvider;
use App\Services\Cloud\Contracts\CloudApiFactory;
use ErrorException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use TypeError;

final class CloudCatalog
{
    public function __construct(private readonly CloudApiFactory $apis) {}

    public static function put(CloudProvider $account, CatalogData $catalog, int $seconds): void
    {
        Cache::put(self::cacheKey($account), $catalog->toArray(), $seconds);
    }

    public static function cacheKey(CloudProvider $account): string
    {
        return 'cloud-catalog:' . app()->environment() . ':' . $account->getKey();
    }

    public function for(CloudProvider $account): CatalogData
    {
        $key = self::cacheKey($account);
        $stored = Cache::get($key);
        $cached = is_array($stored) ? self::read($stored) : null;

        if ($cached !== null) {
            return $cached;
        }

        if ($stored !== null) {
            Cache::forget($key);
        }

        $fetched = $this->fetch($account)->toArray();
        Cache::put($key, $fetched, (int) config('coresmith.cloud.catalog_seconds'));

        return CatalogData::fromArray($fetched);
    }

    public function forget(CloudProvider $account): void
    {
        Cache::forget(self::cacheKey($account));
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private static function read(array $stored): ?CatalogData
    {
        try {
            return CatalogData::fromArray($stored);
        } catch (ErrorException | TypeError) {
            return null;
        }
    }

    private function fetch(CloudProvider $account): CatalogData
    {
        $api = $this->apis->for($account);
        $regions = $api->regions();
        $sizes = $this->sizes($api->sizes());

        return new CatalogData($this->regions($regions, $sizes), $sizes, $this->images($api->images()));
    }

    /**
     * @param  Collection<int, CloudRegion>  $regions
     * @param  Collection<int, CloudSize>  $sizes
     * @return Collection<int, CloudRegion>
     */
    private function regions(Collection $regions, Collection $sizes): Collection
    {
        return $regions
            ->filter(fn(CloudRegion $region): bool => $sizes->contains(fn(CloudSize $size): bool => $size->availableIn($region->id)))
            ->sort(fn(CloudRegion $a, CloudRegion $b): int => strcasecmp(Str::ascii($a->label), Str::ascii($b->label)))
            ->values();
    }

    /**
     * @param  Collection<int, CloudSize>  $sizes
     * @return Collection<int, CloudSize>
     */
    private function sizes(Collection $sizes): Collection
    {
        $memoryMb = (int) config('coresmith.cloud.min_memory_mb');
        $diskGb = (int) config('coresmith.cloud.min_disk_gb');

        return $sizes
            ->filter(fn(CloudSize $size): bool => $size->memoryMb >= $memoryMb
                && $size->diskGb >= $diskGb
                && self::lowestPrice($size) !== null)
            ->sort(self::bySize(...))
            ->values();
    }

    /**
     * @param  Collection<int, CloudImage>  $images
     * @return Collection<int, CloudImage>
     */
    private function images(Collection $images): Collection
    {
        return (new Collection((array) config('coresmith.cloud.images')))
            ->map(fn(array $offered): ?CloudImage => $images
                ->first(fn(CloudImage $image): bool => $image->family === $offered['family'] && $image->version === $offered['version'])
                ?->withLabel($offered['label']))
            ->filter()
            ->values();
    }

    private static function lowestPrice(CloudSize $size): ?CloudPrice
    {
        $offered = $size->regions === [] ? [] : [$size->price];

        foreach ($size->prices as $region => $price) {
            if ($size->availableIn((string) $region)) {
                $offered[] = $price;
            }
        }

        return (new Collection($offered))
            ->filter()
            ->sortBy(fn(CloudPrice $price): float => CatalogData::amount($price))
            ->first();
    }

    private static function bySize(CloudSize $a, CloudSize $b): int
    {
        return [CatalogData::amount(self::lowestPrice($a)), $a->memoryMb, self::rank($a->architecture)] <=> [CatalogData::amount(self::lowestPrice($b)), $b->memoryMb, self::rank($b->architecture)]
            ?: strcmp($a->id, $b->id);
    }

    private static function rank(string $architecture): int
    {
        return match ($architecture) {
            'x86' => 0,
            'arm' => 1,
            default => 2,
        };
    }
}
