<?php

declare(strict_types=1);

use Tests\Support\TemporaryEnvironment;

function queueTopologyEnvironments(array $values): array
{
    return TemporaryEnvironment::with($values, fn() => require base_path('config/horizon.php'))['environments'];
}

test('every queue from the spec is defined with its tries and timeout', function (string $supervisor, string $queue, int $tries, int $timeout, int $processes) {
    $config = config("horizon.defaults.{$supervisor}");

    expect($config)->not->toBeNull()
        ->and($config['queue'])->toBe([$queue])
        ->and($config['tries'])->toBe($tries)
        ->and($config['timeout'])->toBe($timeout)
        ->and($config['maxProcesses'])->toBe($processes)
        ->and($config['connection'])->toBe('redis');
})->with([
    ['provisioning', 'provisioning', 1, 2760, 2],
    ['deployments', 'deployments', 1, 840, 3],
    ['server-tasks', 'server-tasks', 3, 1320, 5],
    ['default', 'default', 3, 960, 3],
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

test('a production supervisor keeps its workers until the environment raises them', function (string $supervisor, string $name, int $workers) {
    $unset = queueTopologyEnvironments([$name => null]);
    $raised = queueTopologyEnvironments([$name => '12']);

    expect($unset['production'][$supervisor]['maxProcesses'])->toBe($workers)
        ->and(queueTopologyEnvironments([$name => ''])['production'][$supervisor]['maxProcesses'])->toBe($workers)
        ->and($raised['production'][$supervisor]['maxProcesses'])->toBe(12)
        ->and($raised['local'])->toBe($unset['local'])
        ->and(file(base_path('.env.example'), FILE_IGNORE_NEW_LINES))->toContain("{$name}={$workers}");
})->with([
    ['provisioning', 'HORIZON_PROVISIONING_PROCESSES', 2],
    ['deployments', 'HORIZON_DEPLOYMENTS_PROCESSES', 3],
    ['server-tasks', 'HORIZON_SERVER_TASKS_PROCESSES', 5],
    ['default', 'HORIZON_DEFAULT_PROCESSES', 3],
]);

test('raising one supervisor leaves the others alone', function () {
    $production = queueTopologyEnvironments(['HORIZON_DEPLOYMENTS_PROCESSES' => '12'])['production'];

    expect($production['provisioning']['maxProcesses'])->toBe(2)
        ->and($production['deployments']['maxProcesses'])->toBe(12)
        ->and($production['server-tasks']['maxProcesses'])->toBe(5)
        ->and($production['default']['maxProcesses'])->toBe(3);
});

test('a worker count that makes no sense never stops a queue', function (string $value, int $workers) {
    expect(queueTopologyEnvironments(['HORIZON_DEPLOYMENTS_PROCESSES' => $value])['production']['deployments']['maxProcesses'])->toBe($workers);
})->with([
    'zero' => ['0', 3],
    'a word' => ['many', 1],
    'below zero' => ['-2', 1],
]);
