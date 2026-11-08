<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DatabaseType;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;

test('server status covers every spec value', function () {
    expect(array_column(ServerStatus::cases(), 'value'))
        ->toBe(['pending', 'provisioning', 'active', 'failed', 'disconnected', 'creating', 'moving']);
});

test('application status covers every spec value', function () {
    expect(array_column(ApplicationStatus::cases(), 'value'))
        ->toBe(['pending', 'deploying', 'active', 'failed', 'stopped']);
});

test('application type covers every spec value', function () {
    expect(array_column(ApplicationType::cases(), 'value'))->toBe(['php', 'js', 'ruby', 'static']);
});

test('power state covers every spec value', function () {
    expect(array_column(PowerState::cases(), 'value'))->toBe(['active', 'sleeping', 'stopped']);
});

test('deployment status covers every spec value', function () {
    expect(array_column(DeploymentStatus::cases(), 'value'))
        ->toBe(['pending', 'running', 'succeeded', 'failed', 'cancelled']);
});

test('database type covers every spec value', function () {
    expect(array_column(DatabaseType::cases(), 'value'))->toBe(['mysql', 'postgresql', 'redis']);
});

test('team role covers every spec value', function () {
    expect(array_column(TeamRole::cases(), 'value'))->toBe(['owner', 'admin', 'member']);
});
