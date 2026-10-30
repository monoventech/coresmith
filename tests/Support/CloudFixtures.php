<?php

declare(strict_types=1);

namespace Tests\Support;

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
}
