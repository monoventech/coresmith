<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use PHPUnit\Framework\Assert;
use ReflectionFunction;
use Throwable;

final class TraceSecrets
{
    public static function thrownBy(Closure $call): Throwable
    {
        $before = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $call();
        } catch (Throwable $error) {
            return $error;
        } finally {
            ini_set('zend.exception_ignore_args', $before === false ? '1' : $before);
        }

        Assert::fail('The call was expected to throw.');
    }

    public static function held(Throwable $error, string $secret): bool
    {
        for ($at = $error; $at !== null; $at = $at->getPrevious()) {
            foreach ($at->getTrace() as $frame) {
                if (self::inside($frame['args'] ?? [], $secret, 0)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function inside(mixed $value, string $secret, int $depth): bool
    {
        if ($depth > 8) {
            return false;
        }

        if (is_string($value)) {
            return str_contains($value, $secret);
        }

        if ($value instanceof Closure) {
            $value = (new ReflectionFunction($value))->getStaticVariables();
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (self::inside($item, $secret, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
