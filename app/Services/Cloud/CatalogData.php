<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Cloud;

use Illuminate\Support\Collection;

final readonly class CatalogData
{
    private const BUILD_MEMORY_MB = 2048;

    /** @var list<CloudRegion> */
    private array $regions;

    /** @var list<CloudSize> */
    private array $sizes;

    /** @var list<CloudImage> */
    private array $images;

    /**
     * @param  Collection<int, CloudRegion>  $regions
     * @param  Collection<int, CloudSize>  $sizes
     * @param  Collection<int, CloudImage>  $images
     */
    public function __construct(Collection $regions, Collection $sizes, Collection $images)
    {
        $this->regions = $regions->values()->all();
        $this->sizes = $sizes->values()->all();
        $this->images = $images->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            new Collection(array_map(
                fn(array $region): CloudRegion => new CloudRegion($region['id'], $region['label'], $region['country']),
                $data['regions'],
            )),
            new Collection(array_map(
                fn(array $size): CloudSize => new CloudSize(
                    $size['id'],
                    $size['cpus'],
                    $size['memory_mb'],
                    $size['disk_gb'],
                    $size['architecture'],
                    self::priceFromArray($size['price']),
                    array_map(self::priceFromArray(...), $size['prices']),
                    $size['regions'],
                ),
                $data['sizes'],
            )),
            new Collection(array_map(
                fn(array $image): CloudImage => new CloudImage($image['id'], $image['family'], $image['version'], $image['architectures'], $image['label']),
                $data['images'],
            )),
        );
    }

    public static function amount(?CloudPrice $price): float
    {
        return $price === null ? INF : (float) $price->monthly;
    }

    /**
     * @return Collection<int, CloudRegion>
     */
    public function regions(): Collection
    {
        return new Collection($this->regions);
    }

    /**
     * @return Collection<int, CloudSize>
     */
    public function sizes(): Collection
    {
        return new Collection($this->sizes);
    }

    /**
     * @return Collection<int, CloudImage>
     */
    public function images(): Collection
    {
        return new Collection($this->images);
    }

    public function region(string $id): ?CloudRegion
    {
        return $this->regions()->first(fn(CloudRegion $region): bool => $region->id === $id);
    }

    public function size(string $id): ?CloudSize
    {
        return $this->sizes()->first(fn(CloudSize $size): bool => $size->id === $id);
    }

    public function image(string $id): ?CloudImage
    {
        return $this->images()->first(fn(CloudImage $image): bool => $image->id === $id);
    }

    /**
     * @return Collection<int, CloudSize>
     */
    public function sizesIn(string $region): Collection
    {
        return $this->sizes()->filter(fn(CloudSize $size): bool => $size->availableIn($region))->values();
    }

    /**
     * @return Collection<int, CloudImage>
     */
    public function imagesFor(CloudSize $size): Collection
    {
        return $this->images()->filter(fn(CloudImage $image): bool => $image->supports($size->architecture))->values();
    }

    /**
     * @return array{region: ?string, size: ?string, image: ?string}
     */
    public function defaults(): array
    {
        $region = null;
        $widest = -1;

        foreach ($this->regions as $candidate) {
            $offered = $this->sizesIn($candidate->id)->count();

            if ($offered > $widest) {
                [$region, $widest] = [$candidate, $offered];
            }
        }

        $sizes = $region === null ? new Collection : $this->sizesIn($region->id);
        $size = $sizes->first(fn(CloudSize $size): bool => $size->memoryMb >= self::BUILD_MEMORY_MB) ?? $sizes->first();
        $image = $size === null ? null : $this->imagesFor($size)->first();

        return ['region' => $region?->id, 'size' => $size?->id, 'image' => $image?->id];
    }

    /**
     * @return Collection<int, CloudSize>
     */
    public function resizeOptions(CloudSize $current, string $region, bool $upOnly): Collection
    {
        $paying = $current->priceIn($region);

        return $this->sizesIn($region)
            ->filter(fn(CloudSize $size): bool => $size->id !== $current->id
                && $size->architecture === $current->architecture
                && $size->diskGb >= $current->diskGb
                && (!$upOnly || self::costsMore($size->priceIn($region), $paying)))
            ->sortBy(fn(CloudSize $size): float => self::amount($size->priceIn($region)))
            ->values();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function toArray(): array
    {
        return [
            'regions' => array_map(fn(CloudRegion $region): array => [
                'id' => $region->id,
                'label' => $region->label,
                'country' => $region->country,
            ], $this->regions),
            'sizes' => array_map(fn(CloudSize $size): array => [
                'id' => $size->id,
                'cpus' => $size->cpus,
                'memory_mb' => $size->memoryMb,
                'disk_gb' => $size->diskGb,
                'architecture' => $size->architecture,
                'price' => self::priceToArray($size->price),
                'prices' => array_map(self::priceToArray(...), $size->prices),
                'regions' => $size->regions,
            ], $this->sizes),
            'images' => array_map(fn(CloudImage $image): array => [
                'id' => $image->id,
                'family' => $image->family,
                'version' => $image->version,
                'architectures' => $image->architectures,
                'label' => $image->label,
            ], $this->images),
        ];
    }

    private static function costsMore(?CloudPrice $price, ?CloudPrice $than): bool
    {
        return $price !== null && $than !== null && self::amount($price) > self::amount($than);
    }

    /**
     * @return array{monthly: string, currency: string}|null
     */
    private static function priceToArray(?CloudPrice $price): ?array
    {
        return $price === null ? null : ['monthly' => $price->monthly, 'currency' => $price->currency];
    }

    /**
     * @param  array{monthly: string, currency: string}|null  $price
     */
    private static function priceFromArray(?array $price): ?CloudPrice
    {
        return $price === null ? null : new CloudPrice($price['monthly'], $price['currency']);
    }
}
