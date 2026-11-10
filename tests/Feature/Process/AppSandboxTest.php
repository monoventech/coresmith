<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Process\AppSandbox;

const APP_SANDBOX_SLICE = 'Slice=coresmith-apps.slice';

const APP_SANDBOX_HIDDEN = 'InaccessiblePaths=-/opt/coresmith -/var/lib/coresmith -/var/lib/coresmith-this-machine -/etc/caddy -/run/caddy-admin -/var/log/coresmith-install.log -/var/log/coresmith-horizon.log -/var/log/coresmith-reverb.log -/var/log/coresmith-fpm-error.log';

test('apps on every other server keep the units they have', function () {
    expect(AppSandbox::properties(Server::factory()->make(['runs_coresmith' => false, 'meta' => ['systemd' => 255]])))->toBe([])
        ->and(AppSandbox::properties(null))->toBe([]);
});

test("on Coresmith's machine apps run in their own slice, out of sight of Coresmith's files, logs and processes", function () {
    expect(AppSandbox::properties(Server::factory()->make(['runs_coresmith' => true, 'meta' => ['systemd' => 255]])))
        ->toBe([APP_SANDBOX_SLICE, APP_SANDBOX_HIDDEN, 'ProtectProc=invisible']);
});

test('a systemd too old for a property, or one Coresmith never read, is only given what it understands', function (?int $systemd, array $properties) {
    $server = Server::factory()->make(['runs_coresmith' => true, 'meta' => $systemd === null ? [] : ['systemd' => $systemd]]);

    expect(AppSandbox::properties($server))->toBe($properties);
})->with([
    'debian 11, the first with ProtectProc' => [247, [APP_SANDBOX_SLICE, APP_SANDBOX_HIDDEN, 'ProtectProc=invisible']],
    'ubuntu 20.04' => [245, [APP_SANDBOX_SLICE, APP_SANDBOX_HIDDEN]],
    'rocky 8' => [239, [APP_SANDBOX_SLICE, APP_SANDBOX_HIDDEN]],
    'amazon linux 2' => [219, [APP_SANDBOX_SLICE]],
    'never read' => [null, [APP_SANDBOX_SLICE]],
]);
