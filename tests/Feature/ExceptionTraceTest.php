<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;

function exceptionTraceFrame(bool $debug): array
{
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        config(['app.debug' => $debug]);
        (new AppServiceProvider(app()))->boot();

        try {
            exceptionTraceThrow('ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl');
        } catch (RuntimeException $e) {
            return $e->getTrace()[0];
        }
    } finally {
        ini_set('zend.exception_ignore_args', is_string($previous) ? $previous : '0');
    }
}

function exceptionTraceThrow(string $token): never
{
    throw new RuntimeException('The request failed.');
}

test('call arguments stay out of exception traces when debug mode is off', function () {
    $frame = exceptionTraceFrame(debug: false);

    expect($frame['function'])->toBe('exceptionTraceThrow')
        ->and($frame)->not->toHaveKey('args');
});

test('debug mode leaves trace arguments as php is configured', function () {
    $frame = exceptionTraceFrame(debug: true);

    expect($frame['function'])->toBe('exceptionTraceThrow')
        ->and($frame['args'])->toBe(['ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.c2lnbmF0dXJl']);
});
