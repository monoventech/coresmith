<?php

declare(strict_types=1);

test('every queue from the spec is defined with its tries and timeout', function (string $supervisor, string $queue, int $tries, int $timeout, int $processes) {
    $config = config("horizon.defaults.{$supervisor}");

    expect($config)->not->toBeNull()
        ->and($config['queue'])->toBe([$queue])
        ->and($config['tries'])->toBe($tries)
        ->and($config['timeout'])->toBe($timeout)
        ->and($config['maxProcesses'])->toBe($processes)
        ->and($config['connection'])->toBe('redis');
})->with([
    ['provisioning', 'provisioning', 1, 1800, 2],
    ['deployments', 'deployments', 1, 600, 3],
    ['server-tasks', 'server-tasks', 3, 120, 5],
    ['default', 'default', 3, 60, 3],
]);

test('provisioning and deployments never auto retry', function () {
    expect(config('horizon.defaults.provisioning.tries'))->toBe(1)
        ->and(config('horizon.defaults.deployments.tries'))->toBe(1);
});

test('provisioning uses simple balancing so a long job is not rebalanced', function () {
    expect(config('horizon.defaults.provisioning.balance'))->toBe('simple');
});

test('both environments cover every supervisor', function (string $environment) {
    expect(array_keys(config("horizon.environments.{$environment}")))
        ->toBe(['provisioning', 'deployments', 'server-tasks', 'default']);
})->with(['production', 'local']);
