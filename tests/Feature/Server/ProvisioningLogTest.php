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

test('a line written through another copy of the server is kept', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 14:05:09'));
    $server = Server::factory()->create(['provisioning_log' => null]);
    $other = Server::query()->findOrFail($server->getKey());

    ProvisioningLog::append($server, 'first-boot setup finished, checking the server before provisioning');
    ProvisioningLog::append($other, 'phase 1 of 8, Operating system: started');
    ProvisioningLog::append($server, "couldn't remove the uploaded key from Hetzner Cloud");
    $other->forceFill(['name' => 'outpost-03'])->save();

    $written = "[14:05:09] first-boot setup finished, checking the server before provisioning\n[14:05:09] phase 1 of 8, Operating system: started\n[14:05:09] couldn't remove the uploaded key from Hetzner Cloud";

    expect($server->fresh()->provisioning_log)->toBe($written)
        ->and($server->provisioning_log)->toBe($written)
        ->and($server->isDirty('provisioning_log'))->toBeFalse()
        ->and($other->provisioning_log)->toBe("[14:05:09] first-boot setup finished, checking the server before provisioning\n[14:05:09] phase 1 of 8, Operating system: started");
});

test('a detached server still has its log written', function () {
    $server = Server::factory()->create(['provisioning_log' => null]);
    $server->delete();

    ProvisioningLog::append($server, 'phase 3 of 8, Runtimes: started');

    expect(Server::withTrashed()->findOrFail($server->getKey())->provisioning_log)->toEndWith('phase 3 of 8, Runtimes: started');
});
