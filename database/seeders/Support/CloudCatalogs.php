<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Services\Cloud\CatalogData;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudSize;
use Illuminate\Support\Collection;

final class CloudCatalogs
{
    private const HETZNER_EU = ['fsn1', 'nbg1', 'hel1'];

    public static function hetzner(): CatalogData
    {
        $eu = fn(string $monthly): array => array_fill_keys(self::HETZNER_EU, new CloudPrice($monthly, 'EUR'));

        return new CatalogData(
            new Collection([
                new CloudRegion('fsn1', 'Falkenstein (fsn1)', 'DE'),
                new CloudRegion('nbg1', 'Nuremberg (nbg1)', 'DE'),
                new CloudRegion('hel1', 'Helsinki (hel1)', 'FI'),
                new CloudRegion('ash', 'Ashburn (ash)', 'US'),
            ]),
            new Collection([
                new CloudSize('cx22', 2, 4096, 40, 'x86', null, $eu('3.79'), self::HETZNER_EU),
                new CloudSize('cx32', 4, 8192, 80, 'x86', null, $eu('6.80'), self::HETZNER_EU),
                new CloudSize('cx42', 8, 16384, 160, 'x86', null, $eu('16.40'), self::HETZNER_EU),
                new CloudSize('cpx21', 3, 4096, 80, 'x86', null, [...$eu('7.55'), 'ash' => new CloudPrice('8.49', 'EUR')], [...self::HETZNER_EU, 'ash']),
                new CloudSize('cax11', 2, 4096, 40, 'arm', null, $eu('3.79'), self::HETZNER_EU),
            ]),
            new Collection([
                self::image('ubuntu-24.04', 'ubuntu', '24.04', ['x86', 'arm']),
                self::image('ubuntu-22.04', 'ubuntu', '22.04', ['x86', 'arm']),
                self::image('debian-12', 'debian', '12', ['x86', 'arm']),
            ]),
        );
    }

    public static function digitalocean(): CatalogData
    {
        $size = fn(string $id, int $cpus, int $memory, int $disk, string $monthly): CloudSize => new CloudSize($id, $cpus, $memory, $disk, 'x86', new CloudPrice($monthly, 'USD'));

        return new CatalogData(
            new Collection([
                new CloudRegion('fra1', 'Frankfurt 1 (fra1)', 'DE'),
                new CloudRegion('nyc3', 'New York 3 (nyc3)', 'US'),
                new CloudRegion('sgp1', 'Singapore 1 (sgp1)', 'SG'),
            ]),
            new Collection([
                $size('s-1vcpu-1gb', 1, 1024, 25, '6.00'),
                $size('s-1vcpu-2gb', 1, 2048, 50, '12.00'),
                $size('s-2vcpu-2gb', 2, 2048, 60, '18.00'),
                $size('s-2vcpu-4gb', 2, 4096, 80, '24.00'),
                $size('s-4vcpu-8gb', 4, 8192, 160, '48.00'),
            ]),
            new Collection([
                self::image('ubuntu-24-04-x64', 'ubuntu', '24.04', ['x86']),
                self::image('ubuntu-22-04-x64', 'ubuntu', '22.04', ['x86']),
                self::image('debian-12-x64', 'debian', '12', ['x86']),
            ]),
        );
    }

    /**
     * @param  list<string>  $architectures
     */
    private static function image(string $id, string $family, string $version, array $architectures): CloudImage
    {
        $label = collect((array) config('coresmith.cloud.images'))
            ->first(fn(array $image): bool => $image['family'] === $family && $image['version'] === $version)['label'] ?? '';

        return new CloudImage($id, $family, $version, $architectures, (string) $label);
    }
}
