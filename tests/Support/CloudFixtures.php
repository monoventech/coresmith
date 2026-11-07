<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudSize;
use InvalidArgumentException;

final class CloudFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function json(string $path): array
    {
        $file = dirname(__DIR__) . "/Fixtures/Cloud/{$path}.json";

        if (!is_file($file)) {
            throw new InvalidArgumentException("There is no cloud fixture at tests/Fixtures/Cloud/{$path}.json.");
        }

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function with(string $path, array $changes = []): array
    {
        $body = self::json($path);

        foreach ($changes as $key => $value) {
            data_set($body, $key, $value);
        }

        return $body;
    }

    public static function catalogue(FakeCloudApi $api): FakeCloudApi
    {
        return $api->catalog(
            [new CloudRegion('fsn1', 'Falkenstein (fsn1)', 'DE')],
            [
                new CloudSize('cx22', 2, 4096, 40, 'x86', null, ['fsn1' => new CloudPrice('3.79', 'EUR')], ['fsn1']),
                new CloudSize('cax11', 2, 4096, 40, 'arm', null, ['fsn1' => new CloudPrice('4.49', 'EUR')], ['fsn1']),
            ],
            [
                new CloudImage('ubuntu-24.04', 'ubuntu', '24.04', ['x86', 'arm'], 'Ubuntu 24.04'),
                new CloudImage('debian-12', 'debian', '12', ['x86'], 'Debian 12'),
            ],
        );
    }
}
