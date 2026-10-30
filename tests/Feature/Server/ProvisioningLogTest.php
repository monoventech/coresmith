<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Server\ProvisioningLog;
use Carbon\CarbonImmutable;

test('a line is stamped with the time and added below the earlier ones', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 14:05:09'));
    $server = Server::factory()->create(['provisioning_log' => null]);

    ProvisioningLog::append($server, 'creating outpost-02 at Hetzner Cloud');
    ProvisioningLog::append($server, "E: Could not get lock\n", stamped: false);

    expect($server->fresh()->provisioning_log)->toBe("[14:05:09] creating outpost-02 at Hetzner Cloud\nE: Could not get lock");
});

test('a secret in a line is redacted before it is stored', function () {
    $server = Server::factory()->create(['provisioning_log' => null]);

    ProvisioningLog::append($server, 'fetching https://deploy:hunter2-token@example.test/repo.git with Bearer sk-abcdef1234567890 attached');

    expect($server->fresh()->provisioning_log)
        ->toContain('https://[REDACTED]@example.test/repo.git')
        ->toContain('Bearer [REDACTED] attached')
        ->not->toContain('hunter2-token')
        ->not->toContain('sk-abcdef1234567890');
});
