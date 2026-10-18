<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Caddy\CaddyInstaller;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

test('the bootstrap config is seeded through a shell sudo owns, so a non-root ssh user can write it', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'ssh_user' => 'deploy']);
    $transport = (new FakeSSHTransport)
        ->respondTo('caddy version', "v2.8.4\n", 0)
        ->respondTo('curl', "{}\n", 0);

    $connection = (new SSHConnection($server, $transport))->connect();

    app(CaddyInstaller::class)->install($connection);

    $seed = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, CaddyInstaller::CONFIG_FILE) && str_contains($command, 'echo'));

    expect($seed)->not->toBeNull()
        ->and($seed)->toStartWith('sudo sh -c ')
        ->and($seed)->not->toMatch('/^sudo test -f/');
});

test('the acme email is only written when a valid one is known', function (?string $email, string $expected) {
    $caddyfile = (new CaddyInstaller)->caddyfile($email);

    expect($caddyfile)->not->toContain('{$ACME_EMAIL}');

    if ($expected === '') {
        expect($caddyfile)->not->toMatch('/^\s*email\b/m');
    } else {
        expect($caddyfile)->toContain($expected);
    }
})->with([
    'no email' => [null, ''],
    'a valid email' => ['ops@example.com', 'email ops@example.com'],
    'an email that would break the config' => ['ops@example.com} {', ''],
]);
