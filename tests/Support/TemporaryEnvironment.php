<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;

final class TemporaryEnvironment
{
    /**
     * @param  array<string, string|null>  $values
     */
    public static function with(array $values, Closure $callback): mixed
    {
        $saved = [];

        foreach ($values as $name => $value) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            self::put($name, $value, $value, $value ?? false);
        }

        try {
            return $callback();
        } finally {
            foreach ($saved as $name => [$env, $server, $process]) {
                self::put($name, $env, $server, $process);
            }
        }
    }

    private static function put(string $name, ?string $env, ?string $server, string|false $process): void
    {
        if ($env === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $env;
        }

        if ($server === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $server;
        }

        putenv($process === false ? $name : "{$name}={$process}");
    }
}
