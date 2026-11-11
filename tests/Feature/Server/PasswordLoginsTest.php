<?php

declare(strict_types=1);

use App\Services\Server\PasswordLogins;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function passwordLoginsSettings(string $password = 'no', string $root = 'without-password', string $keyboard = 'no'): string
{
    return "port 22\npermitrootlogin {$root}\npubkeyauthentication yes\npasswordauthentication {$password}\nkbdinteractiveauthentication {$keyboard}\n";
}

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $files
 */
function passwordLoginsOutput(string $settings, array $files = []): string
{
    return $settings . "== files\n" . implode('', array_map(fn(array $row): string => implode("\t", $row) . "\n", $files));
}

test('each login that sshd reports on is named, with the first file that turns it on', function () {
    $logins = PasswordLogins::parse(passwordLoginsOutput(passwordLoginsSettings('yes', 'yes'), [
        ['/etc/ssh/sshd_config.d/50-cloud-init.conf', 'passwordauthentication', 'yes'],
        ['/etc/ssh/sshd_config', 'passwordauthentication', 'no'],
        ['/etc/ssh/sshd_config', 'permitrootlogin', 'yes'],
    ]));

    expect($logins?->on)->toBe(['password', 'root'])
        ->and($logins?->sources)->toBe(['password' => '/etc/ssh/sshd_config.d/50-cloud-init.conf', 'root' => '/etc/ssh/sshd_config'])
        ->and($logins?->off())->toBeFalse();
});

test('a login left on by sshd itself, or by a file that is overruled, has no file', function () {
    $logins = PasswordLogins::parse(passwordLoginsOutput(passwordLoginsSettings('yes'), [
        ['/etc/ssh/sshd_config.d/01-mine.conf', 'permitrootlogin', 'no'],
    ]));

    expect($logins?->on)->toBe(['password'])
        ->and($logins?->sources)->toBe([]);
});

test('keyboard-interactive login counts under either of its names', function () {
    $logins = PasswordLogins::parse(passwordLoginsOutput("permitrootlogin no\npasswordauthentication no\nkbdinteractiveauthentication no\nchallengeresponseauthentication yes\n", [
        ['/etc/ssh/sshd_config', 'challengeresponseauthentication', 'yes'],
    ]));

    expect($logins?->on)->toBe(['keyboard'])
        ->and($logins?->sources)->toBe(['keyboard' => '/etc/ssh/sshd_config']);
});

test('with everything off there is nothing to fix', function () {
    $logins = PasswordLogins::parse(passwordLoginsOutput(passwordLoginsSettings()));

    expect($logins?->off())->toBeTrue()
        ->and($logins?->lines())->toBe([]);
});

test('only what is on goes in the fix, in a fixed order', function () {
    expect((new PasswordLogins(['root', 'password', 'keyboard'], []))->lines())->toBe(['PasswordAuthentication no', 'KbdInteractiveAuthentication no', 'PermitRootLogin prohibit-password'])
        ->and((new PasswordLogins(['password'], []))->lines())->toBe(['PasswordAuthentication no'])
        ->and((new PasswordLogins(['root'], []))->lines())->toBe(['PermitRootLogin prohibit-password']);
});

test('an sshd that reports nothing is no answer', function (string $output) {
    expect(PasswordLogins::parse($output))->toBeNull();
})->with([
    'nothing at all' => [''],
    'only the file list' => ["== files\n/etc/ssh/sshd_config\tpasswordauthentication\tyes\n"],
    'settings without the ones that matter' => ["port 22\n== files\n"],
]);

test('the file list ignores settings inside Match blocks and starts afresh in each file', function () {
    $folder = sys_get_temp_dir() . '/password-logins-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($folder);
    file_put_contents("{$folder}/00-coresmith-local.conf", "Match User root Address 127.0.0.1,::1\n    PasswordAuthentication yes\n    PermitRootLogin prohibit-password\n");
    file_put_contents("{$folder}/50-cloud-init.conf", "# PasswordAuthentication no\n  PasswordAuthentication yes\n");
    file_put_contents("{$folder}/sshd_config", "Include /etc/ssh/sshd_config.d/*.conf\nPermitRootLogin = yes\nMatch Group sftp\n    PasswordAuthentication no\nMatch all\nKbdInteractiveAuthentication \"no\"\n");

    try {
        $rows = collect(['00-coresmith-local.conf', '50-cloud-init.conf', 'sshd_config'])
            ->map(fn(string $name): string => (new Process(['awk', '-v', "f={$folder}/{$name}", PasswordLogins::AWK, "{$folder}/{$name}"]))->mustRun()->getOutput())
            ->implode('');
    } finally {
        File::deleteDirectory($folder);
    }

    expect($rows)->toBe("{$folder}/50-cloud-init.conf\tpasswordauthentication\tyes\n{$folder}/sshd_config\tpermitrootlogin\tyes\n{$folder}/sshd_config\tkbdinteractiveauthentication\tno\n");
});

test('the check only reads: it lists sshd settings and each file in the order sshd reads them', function () {
    $command = PasswordLogins::command();

    expect($command)->toStartWith('sshd -T; echo ')
        ->and($command)->toContain('for f in /etc/ssh/sshd_config.d/*.conf /etc/ssh/sshd_config; do if [ -f "$f" ]; then awk -v f="$f" ')
        ->and($command)->not->toContain('reload')
        ->and($command)->not->toContain('tee ')
        ->and($command)->not->toMatch('/>\s*[\/"$]/');
});

test('state survives a round trip through server meta', function () {
    $this->freezeTime();
    $on = new PasswordLogins(['password', 'root'], ['password' => '/etc/ssh/sshd_config.d/50-cloud-init.conf']);

    expect($on->toMeta(now()))->toBe([
        'ssh_passwords' => 'on',
        'ssh_password_logins' => ['password', 'root'],
        'ssh_password_sources' => ['password' => '/etc/ssh/sshd_config.d/50-cloud-init.conf'],
        'ssh_passwords_checked_at' => now()->toIso8601String(),
    ])
        ->and(PasswordLogins::fromMeta($on->toMeta(now())))->toEqual($on)
        ->and((new PasswordLogins([], []))->toMeta(now()))->toBe(['ssh_passwords' => 'off', 'ssh_passwords_checked_at' => now()->toIso8601String()])
        ->and(PasswordLogins::fromMeta(['ssh_passwords' => 'off'])?->off())->toBeTrue()
        ->and(PasswordLogins::fromMeta(['ssh_hardening' => 'owner']))->toBeNull();
});
