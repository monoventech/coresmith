<?php

declare(strict_types=1);

use App\Services\SSH\AuthorizedKeyCommand;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

const AUTHORIZED_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBDM8wq+md+2E0qeap7wfRMYvnuR0H46XNljwSLGFDCX coresmith@outpost-02';

function authorizedKeyRunLocally(string $script, string $home): void
{
    Process::run(['sh', '-c', str_replace('~root/', $home . '/', $script)])->throw();
}

test('the script adds the key for root once, with the permissions ssh insists on', function () {
    $home = sys_get_temp_dir() . '/coresmith-authorized-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($home);
    $script = AuthorizedKeyCommand::script('root', AUTHORIZED_KEY);

    try {
        authorizedKeyRunLocally($script, $home);
        authorizedKeyRunLocally($script, $home);

        expect(file_get_contents("{$home}/.ssh/authorized_keys"))->toBe(AUTHORIZED_KEY . "\n")
            ->and(fileperms("{$home}/.ssh") & 0777)->toBe(0700)
            ->and(fileperms("{$home}/.ssh/authorized_keys") & 0777)->toBe(0600);
    } finally {
        File::deleteDirectory($home);
    }
});

test('the script keeps keys that were already there', function () {
    $home = sys_get_temp_dir() . '/coresmith-authorized-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$home}/.ssh");
    file_put_contents("{$home}/.ssh/authorized_keys", "ssh-ed25519 AAAAexisting maya@laptop\n");

    try {
        authorizedKeyRunLocally(AuthorizedKeyCommand::script('root', AUTHORIZED_KEY), $home);

        expect(file_get_contents("{$home}/.ssh/authorized_keys"))->toBe("ssh-ed25519 AAAAexisting maya@laptop\n" . AUTHORIZED_KEY . "\n");
    } finally {
        File::deleteDirectory($home);
    }
});

test('for another user the script checks the user exists and hands the files to them', function () {
    $script = AuthorizedKeyCommand::script('deploy', AUTHORIZED_KEY);

    expect($script)->toStartWith('k="' . AUTHORIZED_KEY . '"; id -u deploy >/dev/null && mkdir -p ~deploy/.ssh && cd ~deploy/.ssh')
        ->and($script)->toContain('chown deploy: . authorized_keys');
});

test('a key comment that could break out of the quotes is left off', function () {
    $script = AuthorizedKeyCommand::script('root', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBDM8wq evil"; rm -rf / #');

    expect($script)->toStartWith('k="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBDM8wq";')
        ->and($script)->not->toContain('rm -rf');
});

test('a user name that is not a linux user is refused', function () {
    AuthorizedKeyCommand::script('root; reboot', AUTHORIZED_KEY);
})->throws(InvalidArgumentException::class);

test('something that is not a public key is refused', function () {
    AuthorizedKeyCommand::script('root', '-----BEGIN OPENSSH PRIVATE KEY-----');
})->throws(InvalidArgumentException::class);

test('what a person pastes is one line that runs the same script through sudo', function () {
    $line = AuthorizedKeyCommand::forPerson('root', AUTHORIZED_KEY);

    expect($line)->toBe("sudo sh -c '" . AuthorizedKeyCommand::script('root', AUTHORIZED_KEY) . "'")
        ->and($line)->not->toContain("\n");
});
