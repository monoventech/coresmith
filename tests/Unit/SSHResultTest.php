<?php

declare(strict_types=1);

use App\Exceptions\SSHCommandFailedException;
use App\Services\SSH\SSHResult;

test('a zero exit code is successful', function () {
    $result = new SSHResult('ok', 0);

    expect($result->successful())->toBeTrue()->and($result->failed())->toBeFalse();
});

test('a non-zero exit code has failed', function () {
    $result = new SSHResult('boom', 1);

    expect($result->successful())->toBeFalse()->and($result->failed())->toBeTrue();
});

test('throw returns self when successful', function () {
    $result = new SSHResult('ok', 0);

    expect($result->throw())->toBe($result);
});

test('throw raises when failed', function () {
    (new SSHResult('permission denied', 13))->throw();
})->throws(SSHCommandFailedException::class);

test('the failure exception carries the exit code and output', function () {
    try {
        (new SSHResult('permission denied', 13))->throw();
    } catch (SSHCommandFailedException $e) {
        expect($e->exitCode())->toBe(13)->and($e->output())->toBe('permission denied');
    }
});
