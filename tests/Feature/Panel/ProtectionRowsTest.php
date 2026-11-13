<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Panel\ProtectionRows;

/**
 * @param  array<string, mixed>  $meta
 * @return array<string, array{key: string, label: string, state: string, detail: string, fix: string|null}>
 */
function protectionRows(array $meta = [], bool $ownMachine = false): array
{
    $server = Server::factory()->create(['runs_coresmith' => $ownMachine, 'meta' => $meta]);

    return collect(app(ProtectionRows::class)->for($server)['rows'])->keyBy('key')->all();
}

function protectionReadingMeta(array $overrides = []): array
{
    return ['protections' => [
        'ssh' => 'on', 'fail2ban' => 'on', 'kernel' => 'on', 'kernel_off' => [], 'patches' => 'on', 'controls' => 'on',
        'checked_at' => now()->subMinutes(5)->toIso8601String(),
        ...$overrides,
    ], 'caddy_unknown_hosts' => 'closed'];
}

test('a server that was never checked says so, and when Coresmith checks', function () {
    $server = Server::factory()->create(['meta' => []]);
    $card = app(ProtectionRows::class)->for($server);
    $rows = collect($card['rows'])->keyBy('key');

    expect($card['checked'])->toBeNull()
        ->and($rows->pluck('key')->all())->toBe(['ssh', 'fail2ban', 'kernel', 'patches', 'controls', 'unknown_hosts'])
        ->and($rows['fail2ban']['state'])->toBe('pending')
        ->and($rows['fail2ban']['detail'])->toBe('Coresmith checks this within the hour.')
        ->and($rows['kernel']['state'])->toBe('pending')
        ->and($rows['patches']['state'])->toBe('pending');
});

test('a server whose protections all read as on says what each one does, with no fix to offer', function () {
    $rows = protectionRows(protectionReadingMeta());

    expect(collect($rows)->pluck('state')->unique()->all())->toBe(['on'])
        ->and(collect($rows)->pluck('fix')->filter()->all())->toBe([])
        ->and($rows['fail2ban']['detail'])->toBe('An address that fails to sign in 5 times in 10 minutes is blocked for an hour.')
        ->and($rows['patches']['detail'])->toBe('The server installs security updates every day.');
});

test('a protection that reads as off says so and offers to turn it back on', function () {
    $rows = protectionRows(protectionReadingMeta(['fail2ban' => 'off', 'kernel' => 'off', 'kernel_off' => ['kernel.dmesg_restrict', 'fs.protected_symlinks'], 'patches' => 'off']));

    expect($rows['fail2ban'])->toMatchArray(['state' => 'off', 'detail' => "It isn't running on this server.", 'fix' => 'Turn it back on'])
        ->and($rows['kernel'])->toMatchArray(['state' => 'off', 'detail' => '2 of 15 settings changed since Coresmith set them.', 'fix' => 'Set them again'])
        ->and($rows['patches'])->toMatchArray(['state' => 'off', 'detail' => "Security updates aren't installing on their own.", 'fix' => 'Turn them back on']);
});

test('a fix that is running says so instead of offering it again', function () {
    $rows = protectionRows([...protectionReadingMeta(['fail2ban' => 'off']), 'protections_restoring' => ['fail2ban' => now()->toIso8601String()]]);

    expect($rows['fail2ban'])->toMatchArray(['state' => 'busy', 'detail' => 'Turning it back on…', 'fix' => null]);
});

test('a fix that started long ago and never finished is offered again', function () {
    $rows = protectionRows([...protectionReadingMeta(['fail2ban' => 'off']), 'protections_restoring' => ['fail2ban' => now()->subMinutes(16)->toIso8601String()]]);

    expect($rows['fail2ban']['state'])->toBe('off');
});

test('a fix that failed says why, and offers to try again', function () {
    $rows = protectionRows([...protectionReadingMeta(['patches' => 'off']), 'protections_errors' => ['patches' => 'E: Unable to locate package unattended-upgrades']]);

    expect($rows['patches'])->toMatchArray(['state' => 'off', 'detail' => "Coresmith couldn't turn them back on: E: Unable to locate package unattended-upgrades", 'fix' => 'Try again']);
});

test('password sign-in that came back is off, with the fix', function () {
    $rows = protectionRows(protectionReadingMeta(['ssh' => 'off']));

    expect($rows['ssh'])->toMatchArray(['state' => 'off', 'detail' => 'Password sign-in is on again.', 'fix' => 'Turn it off']);
});

test('before the first check, SSH and the routing controls go by what Coresmith already did', function () {
    $rows = protectionRows(['ssh_hardening' => 'verified', 'caddy_admin' => 'socket']);

    expect($rows['ssh'])->toMatchArray(['state' => 'on', 'detail' => 'Password sign-in is off.'])
        ->and($rows['controls'])->toMatchArray(['state' => 'on', 'detail' => "Apps on this server can't reach the controls that route traffic to them."]);
});

test('routing controls Coresmith could not close yet say why and offer to try now', function () {
    $rows = protectionRows(['caddy_admin_error' => 'curl: (7) Failed to connect to localhost port 2019']);

    expect($rows['controls'])->toMatchArray([
        'state' => 'off',
        'detail' => "Apps on this server can still reach the controls that route traffic. Coresmith's last try to close them failed: curl: (7) Failed to connect to localhost port 2019",
        'fix' => 'Try again now',
    ]);
});

test('SSH that Coresmith could not lock down yet says what it found', function () {
    $rows = protectionRows(['ssh_hardening_error' => 'sshd reports passwordauthentication yes']);

    expect($rows['ssh'])->toMatchArray(['state' => 'off', 'detail' => "Coresmith couldn't turn off password sign-in yet: sshd reports passwordauthentication yes", 'fix' => 'Try again now']);
});

test('on Coresmith\'s own machine SSH is left to its owner and unknown addresses are never answered, with nothing to fix', function (array $meta, string $detail) {
    $rows = protectionRows(['ssh_hardening' => 'owner', ...$meta, ...protectionReadingMeta(['ssh' => null])], ownMachine: true);

    expect($rows['ssh'])->toMatchArray(['state' => 'yours', 'detail' => $detail, 'fix' => null])
        ->and($rows['unknown_hosts'])->toMatchArray(['state' => 'on', 'fix' => null]);
})->with([
    'passwords off' => [['ssh_passwords' => 'off'], "Password sign-in is off in this machine's own SSH settings."],
    'passwords on' => [['ssh_passwords' => 'on'], "Password sign-in is on in this machine's own SSH settings. See SSH access below."],
]);

test('the card says when the protections were last read', function () {
    $this->travelTo(now()->setTime(14, 40));
    $server = Server::factory()->create(['meta' => ['protections' => ['fail2ban' => 'on', 'checked_at' => now()->setTime(14, 30)->toIso8601String()]]]);

    expect(app(ProtectionRows::class)->for($server)['checked'])->toBe('today 14:30');
});
