<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Server\CoresmithKey;
use Symfony\Component\Process\Process;

function coresmithKeyHome(?string $keys): string
{
    $home = sys_get_temp_dir() . '/coresmith-key-' . bin2hex(random_bytes(4));
    mkdir("{$home}/.ssh", 0700, true);

    if ($keys !== null) {
        file_put_contents("{$home}/.ssh/authorized_keys", $keys);
        chmod("{$home}/.ssh/authorized_keys", 0644);
    }

    return $home;
}

function coresmithKeyRun(string $home, Server $server): Process
{
    $process = new Process(['bash', '-c', app(CoresmithKey::class)->command($server)], null, ['HOME' => $home]);
    $process->run();

    return $process;
}

function coresmithKeyClean(string $home): void
{
    (new Process(['rm', '-rf', $home]))->run();
}

test("taking Coresmith's key off removes only its line, and the file stays readable by its owner alone", function () {
    $server = Server::factory()->make();
    $home = coresmithKeyHome("ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOWNEROWNEROWNER owner@laptop\n{$server->ssh_public_key}\nssh-rsa AAAAB3NzaC1yc2EAAAADAQABOTHER other@desk\n");

    $process = coresmithKeyRun($home, $server);
    $kept = (string) file_get_contents("{$home}/.ssh/authorized_keys");
    $mode = fileperms("{$home}/.ssh/authorized_keys") & 0777;
    coresmithKeyClean($home);

    expect($process->isSuccessful())->toBeTrue()
        ->and($kept)->toBe("ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOWNEROWNEROWNER owner@laptop\nssh-rsa AAAAB3NzaC1yc2EAAAADAQABOTHER other@desk\n")
        ->and($mode)->toBe(0600);
});

test('a key file that holds only Coresmith\'s key is left empty rather than failing', function () {
    $server = Server::factory()->make();
    $home = coresmithKeyHome("{$server->ssh_public_key}\n");

    $process = coresmithKeyRun($home, $server);
    $kept = (string) file_get_contents("{$home}/.ssh/authorized_keys");
    coresmithKeyClean($home);

    expect($process->isSuccessful())->toBeTrue()
        ->and($kept)->toBe('');
});

test('a key file it cannot read is left exactly as it was, and the attempt fails', function () {
    $server = Server::factory()->make();
    $before = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOWNEROWNEROWNER owner@laptop\n{$server->ssh_public_key}\n";
    $home = coresmithKeyHome($before);
    chmod("{$home}/.ssh/authorized_keys", 0000);

    $process = coresmithKeyRun($home, $server);
    chmod("{$home}/.ssh/authorized_keys", 0600);
    $after = (string) file_get_contents("{$home}/.ssh/authorized_keys");
    $leftovers = glob("{$home}/.ssh/authorized_keys.*") ?: [];
    coresmithKeyClean($home);

    expect($process->isSuccessful())->toBeFalse()
        ->and($after)->toBe($before)
        ->and($leftovers)->toBe([]);
})->skip(fn(): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root reads any file whatever its mode, so an unreadable file cannot be set up');

test('without a key file there is nothing to take off, and nothing is created', function () {
    $home = coresmithKeyHome(null);

    $process = coresmithKeyRun($home, Server::factory()->make());
    $created = file_exists("{$home}/.ssh/authorized_keys");
    coresmithKeyClean($home);

    expect($process->isSuccessful())->toBeTrue()
        ->and($created)->toBeFalse();
});
