<?php

declare(strict_types=1);

use App\Services\Caddy\CaddyApiClient;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function thisMachineInstaller(): string
{
    return (string) file_get_contents(base_path('scripts/install.sh'));
}

function thisMachineHelper(): string
{
    preg_match("/<<'HELPER'\n(.*?)\nHELPER\n/s", thisMachineInstaller(), $matches);

    return $matches[1] ?? '';
}

function thisMachineInstallerFunction(string $name): string
{
    preg_match('/^' . preg_quote($name, '/') . '\(\) \{\n.*?^\}$/ms', thisMachineInstaller(), $matches);

    return $matches[0] ?? '';
}

function thisMachineKey(string $which = 'first'): string
{
    return [
        'first' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFkcNupv+GIYtXekZioi5HlcdNeU1xmZqDdX1IOp8KIo',
        'second' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIK3c2T1iNFs1YD4dSAe0LJ6V8Gt5hhX8ArXQpZ5MA7qC',
    ][$which];
}

function thisMachineBase(): string
{
    $base = realpath(sys_get_temp_dir()) . '/csm-' . bin2hex(random_bytes(4));
    test()->scratchFolders = [...test()->scratchFolders, $base];

    foreach (['exchange', 'ssh/sshd_config.d', 'root-ssh', 'bin', 'tmp'] as $directory) {
        mkdir("{$base}/{$directory}", 0700, true);
    }

    file_put_contents("{$base}/ssh/sshd_config", "Include /etc/ssh/sshd_config.d/*.conf\nPermitRootLogin no\nPasswordAuthentication yes\n");
    file_put_contents("{$base}/root-ssh/authorized_keys", "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOwnerOwnerOwnerOwnerOwnerOwnerOwnerOwnerOw owner@laptop\n");

    return $base;
}

function thisMachineStub(string $base, string $command, string $body): void
{
    file_put_contents("{$base}/bin/{$command}", "#!/bin/sh\nprintf '%s %s\\n' " . escapeshellarg($command) . ' "$*" >>' . escapeshellarg("{$base}/calls") . "\n{$body}\n");
    chmod("{$base}/bin/{$command}", 0755);
}

function thisMachineRun(string $base, ?string $request, array $sshd = []): Process
{
    $sshd += [
        'test' => 0,
        'dump' => 0,
        'effective' => "port 22\npermitrootlogin prohibit-password\nlistenaddress [::]:22\nlistenaddress 0.0.0.0:22\nhostkey /etc/ssh/ssh_host_rsa_key\nhostkey /etc/ssh/ssh_host_ed25519_key\n",
        'active' => 'ssh',
    ];

    if ($request !== null) {
        file_put_contents("{$base}/exchange/request", $request);
    }

    file_put_contents("{$base}/effective", $sshd['effective']);
    thisMachineStub($base, 'sshd', "case \"\$1\" in\n-t) exit {$sshd['test']} ;;\n-T) cat " . escapeshellarg("{$base}/effective") . "; exit {$sshd['dump']} ;;\nesac");
    thisMachineStub($base, 'ssh-keygen', "for last; do :; done\ncase \"\$last\" in\n*ed25519*) echo '256 SHA256:sF9xS9zRItiZWsmi23+Npux21jT/3/SvTr1sIMmAD9I root@machine (ED25519)' ;;\n*rsa*) echo '3072 SHA256:Kw6l7d1oQ0fBo0W3c3sYbmUuQ+F0Y9VZ8u3m1cN2pXo root@machine (RSA)' ;;\n*) exit 1 ;;\nesac");
    thisMachineStub($base, 'systemctl', 'if [ "$1" = is-active ]; then [ "$3" = ' . escapeshellarg($sshd['active']) . ' ]; exit; fi');
    thisMachineStub($base, 'chgrp', ':');

    $script = preg_replace(
        ['/^readonly EXCHANGE=.*$/m', '/^readonly KEYS=.*$/m', '/^readonly SSHD_CONFIG=.*$/m', '/^readonly DROP_IN=.*$/m'],
        [
            "readonly EXCHANGE={$base}/exchange",
            "readonly KEYS={$base}/root-ssh/authorized_keys",
            "readonly SSHD_CONFIG={$base}/ssh/sshd_config",
            "readonly DROP_IN={$base}/ssh/sshd_config.d/00-coresmith-local.conf",
        ],
        thisMachineHelper(),
    );

    $process = new Process(['bash', '-c', $script], null, ['PATH' => "{$base}/bin:/usr/bin:/bin", 'TMPDIR' => "{$base}/tmp"]);
    $process->run();

    return $process;
}

function thisMachineAnswer(string $base): ?array
{
    $file = "{$base}/exchange/result.json";

    return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
}

function thisMachineCalls(string $base): array
{
    return array_values(array_filter(explode("\n", (string) @file_get_contents("{$base}/calls"))));
}

function thisMachineBlock(string $key): string
{
    return "# coresmith-this-machine begin\nfrom=\"127.0.0.1,::1\",no-agent-forwarding,no-port-forwarding,no-X11-forwarding {$key} coresmith-this-machine\n# coresmith-this-machine end\n";
}

function thisMachineRule(): string
{
    return "Match User root Address 127.0.0.1,::1\n    PermitRootLogin prohibit-password\n    AllowUsers root\n    AllowGroups root\nMatch all\n";
}

beforeEach(function () {
    $this->scratchFolders = [];
});

afterEach(function () {
    foreach ($this->scratchFolders as $folder) {
        File::deleteDirectory($folder);
    }
});

test("Coresmith's key is let in as root from this machine only, beside the keys already there", function () {
    $base = thisMachineBase();
    $owner = (string) file_get_contents("{$base}/root-ssh/authorized_keys");
    $config = (string) file_get_contents("{$base}/ssh/sshd_config");

    $process = thisMachineRun($base, '01JC5Z8Y2M3N4P5Q6R7S8T9V0W add ' . thisMachineKey() . "\n");

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput() . $process->getOutput())
        ->and(file_get_contents("{$base}/root-ssh/authorized_keys"))->toBe($owner . thisMachineBlock(thisMachineKey()))
        ->and(substr(sprintf('%o', fileperms("{$base}/root-ssh/authorized_keys")), -3))->toBe('600')
        ->and(file_get_contents("{$base}/ssh/sshd_config.d/00-coresmith-local.conf"))->toBe(thisMachineRule())
        ->and(file_get_contents("{$base}/ssh/sshd_config"))->toBe($config)
        ->and(file_exists("{$base}/exchange/request"))->toBeFalse()
        ->and(thisMachineCalls($base))->toContain('sshd -t', 'sshd -T -C user=root,host=localhost,addr=127.0.0.1', 'systemctl reload ssh')
        ->and(thisMachineAnswer($base))->toBe([
            'id' => '01JC5Z8Y2M3N4P5Q6R7S8T9V0W',
            'ok' => true,
            'port' => 22,
            'host_keys' => [
                'RSA' => 'SHA256:Kw6l7d1oQ0fBo0W3c3sYbmUuQ+F0Y9VZ8u3m1cN2pXo',
                'ED25519' => 'SHA256:sF9xS9zRItiZWsmi23+Npux21jT/3/SvTr1sIMmAD9I',
            ],
        ])
        ->and(glob("{$base}/tmp/*"))->toBe([]);
});

test("asking again replaces Coresmith's key rather than adding a second", function () {
    $base = thisMachineBase();
    $owner = (string) file_get_contents("{$base}/root-ssh/authorized_keys");

    thisMachineRun($base, 'first add ' . thisMachineKey());
    $process = thisMachineRun($base, 'second add ' . thisMachineKey('second'));

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents("{$base}/root-ssh/authorized_keys"))->toBe($owner . thisMachineBlock(thisMachineKey('second')))
        ->and(file_get_contents("{$base}/ssh/sshd_config.d/00-coresmith-local.conf"))->toBe(thisMachineRule())
        ->and(thisMachineAnswer($base)['id'])->toBe('second');
});

test('where sshd reads no drop-in folder, the rule goes at the end of sshd_config between markers, and comes out again', function () {
    $base = thisMachineBase();
    $config = "PermitRootLogin no\nMatch User deploy\n    X11Forwarding yes\n";
    file_put_contents("{$base}/ssh/sshd_config", $config);

    $added = thisMachineRun($base, 'one add ' . thisMachineKey());
    $afterAdd = (string) file_get_contents("{$base}/ssh/sshd_config");
    thisMachineRun($base, 'two add ' . thisMachineKey('second'));
    $afterSecond = (string) file_get_contents("{$base}/ssh/sshd_config");
    $removed = thisMachineRun($base, 'three remove');

    expect($added->getExitCode())->toBe(0, $added->getErrorOutput())
        ->and($afterAdd)->toBe($config . "# coresmith-this-machine begin\n" . thisMachineRule() . "# coresmith-this-machine end\n")
        ->and($afterSecond)->toBe($afterAdd)
        ->and(file_exists("{$base}/ssh/sshd_config.d/00-coresmith-local.conf"))->toBeFalse()
        ->and($removed->getExitCode())->toBe(0, $removed->getErrorOutput())
        ->and(file_get_contents("{$base}/ssh/sshd_config"))->toBe($config);
});

test("anything but one ed25519 key or a removal is refused without touching sshd or root's keys", function (string $request) {
    $base = thisMachineBase();
    $keys = (string) file_get_contents("{$base}/root-ssh/authorized_keys");

    $process = thisMachineRun($base, $request);

    expect($process->getExitCode())->toBe(1)
        ->and(thisMachineAnswer($base)['ok'] ?? null)->toBeFalse()
        ->and(thisMachineAnswer($base)['error'] ?? null)->toBe("The request wasn't one Coresmith's helper accepts, so nothing changed.")
        ->and(file_get_contents("{$base}/root-ssh/authorized_keys"))->toBe($keys)
        ->and(file_exists("{$base}/ssh/sshd_config.d/00-coresmith-local.conf"))->toBeFalse()
        ->and(file_exists("{$base}/exchange/request"))->toBeFalse()
        ->and(collect(thisMachineCalls($base))->reject(fn(string $call) => str_starts_with($call, 'chgrp '))->values()->all())->toBe([]);
})->with([
    'an rsa key' => ['one add ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQDAUeOkQERcH6RLv6q58sG3'],
    'a key with a comment' => ['one add ' . 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFkcNupv+GIYtXekZioi5HlcdNeU1xmZqDdX1IOp8KIo me@laptop'],
    'options of its own' => ['one add command="sh" ' . 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFkcNupv+GIYtXekZioi5HlcdNeU1xmZqDdX1IOp8KIo'],
    'a short key' => ['one add ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFkcNupv'],
    'two keys' => ["one add ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFkcNupv+GIYtXekZioi5HlcdNeU1xmZqDdX1IOp8KIo\ntwo add ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIK3c2T1iNFs1YD4dSAe0LJ6V8Gt5hhX8ArXQpZ5MA7qC"],
    'no request id' => ['add ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFkcNupv+GIYtXekZioi5HlcdNeU1xmZqDdX1IOp8KIo'],
    'an id with a quote' => ['o"ne remove'],
    'a command it does not know' => ['one restart'],
    'nothing' => [''],
]);

test('a request that is a symbolic link is refused and never read', function () {
    $base = thisMachineBase();
    file_put_contents("{$base}/planted", 'one add ' . thisMachineKey());
    symlink("{$base}/planted", "{$base}/exchange/request");
    $keys = (string) file_get_contents("{$base}/root-ssh/authorized_keys");

    $process = thisMachineRun($base, null);

    expect($process->getExitCode())->toBe(1)
        ->and(thisMachineAnswer($base)['error'] ?? null)->toBe("The request wasn't one Coresmith's helper accepts, so nothing changed.")
        ->and(file_get_contents("{$base}/root-ssh/authorized_keys"))->toBe($keys)
        ->and(is_link("{$base}/exchange/request"))->toBeFalse()
        ->and(file_get_contents("{$base}/planted"))->toBe('one add ' . thisMachineKey());
});

test('an sshd that refuses the new settings gets the old ones back, and Coresmith is told why', function () {
    $base = thisMachineBase();
    $keys = (string) file_get_contents("{$base}/root-ssh/authorized_keys");

    $process = thisMachineRun($base, 'one add ' . thisMachineKey(), ['test' => 255]);

    expect($process->getExitCode())->toBe(1)
        ->and(thisMachineAnswer($base))->toBe(['id' => 'one', 'ok' => false, 'error' => 'sshd refused the new settings, so the old ones are back. Run sshd -t to see why.'])
        ->and(file_get_contents("{$base}/root-ssh/authorized_keys"))->toBe($keys)
        ->and(file_exists("{$base}/ssh/sshd_config.d/00-coresmith-local.conf"))->toBeFalse()
        ->and(thisMachineCalls($base))->not->toContain('systemctl reload ssh')
        ->and(glob("{$base}/tmp/*"))->toBe([]);
});

test('an sshd that still turns root away from this machine, or never listens on it, gets the old settings back', function (string $effective, string $error) {
    $base = thisMachineBase();
    file_put_contents("{$base}/ssh/sshd_config.d/00-coresmith-local.conf", "# the owner's own\n");
    $keys = (string) file_get_contents("{$base}/root-ssh/authorized_keys");

    $process = thisMachineRun($base, 'one add ' . thisMachineKey(), ['effective' => $effective]);

    expect($process->getExitCode())->toBe(1)
        ->and(thisMachineAnswer($base)['error'] ?? null)->toBe($error)
        ->and(file_get_contents("{$base}/root-ssh/authorized_keys"))->toBe($keys)
        ->and(file_get_contents("{$base}/ssh/sshd_config.d/00-coresmith-local.conf"))->toBe("# the owner's own\n")
        ->and(thisMachineCalls($base))->not->toContain('systemctl reload ssh');
})->with([
    'root refused' => [
        "port 22\npermitrootlogin no\nlistenaddress 0.0.0.0:22\nhostkey /etc/ssh/ssh_host_ed25519_key\n",
        'sshd still turns root away from this machine, so the old settings are back. Check PermitRootLogin in /etc/ssh/sshd_config.',
    ],
    'listening elsewhere' => [
        "port 22\npermitrootlogin prohibit-password\nlistenaddress 100.64.0.7:22\nlistenaddress [::]:22\nhostkey /etc/ssh/ssh_host_ed25519_key\n",
        "sshd doesn't listen on 127.0.0.1, so Coresmith can't reach it from this machine and the old settings are back. Check ListenAddress in /etc/ssh/sshd_config.",
    ],
]);

test('a guest list or deny list that still keeps root out from this machine gets the old settings back, and says which', function (string $lists, string $error) {
    $base = thisMachineBase();
    $keys = (string) file_get_contents("{$base}/root-ssh/authorized_keys");

    $process = thisMachineRun($base, 'one add ' . thisMachineKey(), ['effective' => "port 22\npermitrootlogin prohibit-password\nlistenaddress 0.0.0.0:22\n{$lists}hostkey /etc/ssh/ssh_host_ed25519_key\n"]);

    expect($process->getExitCode())->toBe(1)
        ->and(thisMachineAnswer($base)['error'] ?? null)->toBe($error)
        ->and(file_get_contents("{$base}/root-ssh/authorized_keys"))->toBe($keys)
        ->and(file_exists("{$base}/ssh/sshd_config.d/00-coresmith-local.conf"))->toBeFalse()
        ->and(thisMachineCalls($base))->not->toContain('systemctl reload ssh');
})->with([
    'a guest list without root' => ["allowusers oseughu\n", "sshd's AllowUsers still leaves root out on this machine, so the old settings are back. Check AllowUsers in /etc/ssh/sshd_config and the files it includes."],
    'a group list without root' => ["allowgroups ssh-users\n", "sshd's AllowGroups still leaves root out on this machine, so the old settings are back. Check AllowGroups in /etc/ssh/sshd_config and the files it includes."],
    'root denied by name' => ["denyusers root\n", "sshd's DenyUsers turns root away, so the old settings are back. Take root out of DenyUsers in /etc/ssh/sshd_config or the files it includes, then try again."],
    'everyone denied by a pattern' => ["denyusers *@*\n", "sshd's DenyUsers turns root away, so the old settings are back. Take root out of DenyUsers in /etc/ssh/sshd_config or the files it includes, then try again."],
    "root's group denied" => ["denygroups root\n", "sshd's DenyGroups turns root's group away, so the old settings are back. Take root out of DenyGroups in /etc/ssh/sshd_config or the files it includes, then try again."],
]);

test('a guest list for people still lets Coresmith in as root from this machine, through its own rule', function () {
    $base = thisMachineBase();
    file_put_contents("{$base}/ssh/sshd_config.d/99-hardening.conf", "PermitRootLogin no\nAllowUsers oseughu\n");

    $process = thisMachineRun($base, 'one add ' . thisMachineKey(), ['effective' => "port 22\npermitrootlogin without-password\nlistenaddress 0.0.0.0:22\nallowusers root\nallowgroups root\ndenyusers nobody\nhostkey /etc/ssh/ssh_host_ed25519_key\n"]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(thisMachineAnswer($base)['ok'] ?? null)->toBeTrue()
        ->and(file_get_contents("{$base}/ssh/sshd_config.d/00-coresmith-local.conf"))->toBe(thisMachineRule())
        ->and(file_get_contents("{$base}/ssh/sshd_config.d/99-hardening.conf"))->toBe("PermitRootLogin no\nAllowUsers oseughu\n");
});

test('Coresmith is told the port sshd listens on for this machine', function () {
    $base = thisMachineBase();

    thisMachineRun($base, 'one add ' . thisMachineKey(), ['effective' => "port 2222\npermitrootlogin prohibit-password\nlistenaddress 127.0.0.1:2222\nhostkey /etc/ssh/ssh_host_ed25519_key\n"]);

    expect(thisMachineAnswer($base))->toBe([
        'id' => 'one',
        'ok' => true,
        'port' => 2222,
        'host_keys' => ['ED25519' => 'SHA256:sF9xS9zRItiZWsmi23+Npux21jT/3/SvTr1sIMmAD9I'],
    ]);
});

test("removing takes out Coresmith's key and its sshd rule and leaves everything else", function () {
    $base = thisMachineBase();
    $keys = (string) file_get_contents("{$base}/root-ssh/authorized_keys");
    $config = (string) file_get_contents("{$base}/ssh/sshd_config");
    thisMachineRun($base, 'one add ' . thisMachineKey());
    file_put_contents("{$base}/root-ssh/authorized_keys", "ssh-ed25519 AAAAlater added@later\n", FILE_APPEND);

    $process = thisMachineRun($base, 'two remove');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents("{$base}/root-ssh/authorized_keys"))->toBe($keys . "ssh-ed25519 AAAAlater added@later\n")
        ->and(file_exists("{$base}/ssh/sshd_config.d/00-coresmith-local.conf"))->toBeFalse()
        ->and(file_get_contents("{$base}/ssh/sshd_config"))->toBe($config)
        ->and(thisMachineAnswer($base))->toBe(['id' => 'two', 'ok' => true])
        ->and(array_count_values(thisMachineCalls($base))['systemctl reload ssh'] ?? 0)->toBe(2);
});

test('the answer is readable by the coresmith group and replaces a file planted in its place rather than writing through it', function () {
    $base = thisMachineBase();
    file_put_contents("{$base}/target", "untouched\n");
    symlink("{$base}/target", "{$base}/exchange/result.json");

    thisMachineRun($base, 'one add ' . thisMachineKey());

    expect(file_get_contents("{$base}/target"))->toBe("untouched\n")
        ->and(is_link("{$base}/exchange/result.json"))->toBeFalse()
        ->and(thisMachineAnswer($base)['ok'] ?? null)->toBeTrue()
        ->and(substr(sprintf('%o', fileperms("{$base}/exchange/result.json")), -3))->toBe('640')
        ->and(collect(thisMachineCalls($base))->first(fn(string $call) => str_starts_with($call, 'chgrp ')))->toStartWith("chgrp coresmith {$base}/exchange/");
});

test("a socket-started sshd that isn't running is left to read the new settings when it starts", function () {
    $base = thisMachineBase();

    $process = thisMachineRun($base, 'one add ' . thisMachineKey(), ['active' => 'nothing']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(thisMachineAnswer($base)['ok'] ?? null)->toBeTrue()
        ->and(collect(thisMachineCalls($base))->filter(fn(string $call) => str_starts_with($call, 'systemctl reload'))->all())->toBe([]);
});

test('with no request waiting, the helper changes nothing', function () {
    $base = thisMachineBase();

    $process = thisMachineRun($base, null);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(thisMachineAnswer($base))->toBeNull()
        ->and(thisMachineCalls($base))->toBe([]);
});

test('the helper passes shellcheck', function () {
    $shellcheck = new Process(['which', 'shellcheck']);
    $shellcheck->run();

    if ($shellcheck->getExitCode() !== 0) {
        $this->markTestSkipped('shellcheck is not installed on this machine; CI installs it.');
    }

    $file = realpath(sys_get_temp_dir()) . '/csm-' . bin2hex(random_bytes(4)) . '/this-machine';
    File::ensureDirectoryExists(dirname($file));
    test()->scratchFolders = [...test()->scratchFolders, dirname($file)];
    file_put_contents($file, thisMachineHelper());
    $process = new Process(['shellcheck', '-S', 'style', '-s', 'bash', $file]);
    $process->run();

    expect(thisMachineHelper())->toStartWith("#!/bin/bash\n")
        ->and($process->getExitCode())->toBe(0, $process->getOutput());
});

function thisMachineInstallFunction(): string
{
    return str(thisMachineInstaller())->after("\ninstall_this_machine_helper() {\n")->before("\n}\n\nsecure_logs() {")->toString();
}

test('the helper is installed for root from the installer itself, never from a release', function () {
    $install = thisMachineInstallFunction();

    expect(thisMachineInstaller())->toContain('readonly THIS_MACHINE_HELPER="/usr/local/libexec/coresmith/this-machine"')
        ->and(preg_match_all('/^main\(\) \{$/m', thisMachineInstaller()))->toBe(1)
        ->and($install)->toContain("cat >\"\$tmp\" <<'HELPER'\n")
        ->and($install)->toContain("chown root:root \"\$tmp\"\n    chmod 0755 \"\$tmp\"\n    mv -f \"\$tmp\" \"\$THIS_MACHINE_HELPER\"")
        ->and($install)->toContain('chown root:root "${THIS_MACHINE_HELPER%/*}"')
        ->and($install)->not->toContain('RELEASE_DIR')
        ->and(thisMachineInstallerFunction('create_systemd_services'))->toContain("    install_this_machine_helper\n");
});

test("requests reach the helper through a folder Coresmith's user can add to but not rewrite", function () {
    $body = thisMachineInstaller();
    $install = thisMachineInstallFunction();
    $service = str($body)->after("cat >/etc/systemd/system/coresmith-this-machine.service <<UNIT\n")->before("\nUNIT\n")->toString();
    $path = str($body)->after("cat >/etc/systemd/system/coresmith-this-machine.path <<UNIT\n")->before("\nUNIT\n")->toString();

    expect($body)->toContain('readonly THIS_MACHINE_DIR="/var/lib/coresmith-this-machine"')
        ->and($install)->toContain("chown root:coresmith \"\$THIS_MACHINE_DIR\"\n    chmod 1770 \"\$THIS_MACHINE_DIR\"")
        ->and($install)->toContain('[ -d /root/.ssh ] || install -d -m 0700 /root/.ssh')
        ->and(thisMachineHelper())->toContain('readonly EXCHANGE=/var/lib/coresmith-this-machine')
        ->and($path)->toContain("[Path]\nPathExists=\${THIS_MACHINE_DIR}/request\nUnit=coresmith-this-machine.service")
        ->and($service)->toContain("Type=oneshot\nExecStart=\${THIS_MACHINE_HELPER}\n")
        ->and($service)->not->toContain('User=')
        ->and($service)->toContain("NoNewPrivileges=true\nPrivateTmp=true\nProtectSystem=strict\nReadWritePaths=/root/.ssh /etc/ssh \${THIS_MACHINE_DIR}\nRuntimeDirectory=sshd\nRuntimeDirectoryPreserve=yes")
        ->and($body)->toContain('run systemctl enable --now coresmith-this-machine.path')
        ->and($body)->toContain('ReadWritePaths=${SHARED_DIR}/storage ${DATA_DIR} -${THIS_MACHINE_DIR}');
});

function thisMachineSliceRun(int $megabytes, string $driver, string $host, int $systemd): array
{
    $base = thisMachineBase();
    thisMachineStub($base, 'free', "printf '               total        used        free\\nMem:            {$megabytes}         980        1287\\nSwap:              0           0           0\\n'");
    thisMachineStub($base, 'systemctl', "printf 'systemd {$systemd} ({$systemd}.4-1)\\n+PAM +AUDIT\\n'");

    $script = "set -euo pipefail\nAPPS_SLICE=" . escapeshellarg("{$base}/coresmith-apps.slice") . "\nDB_DRIVER={$driver}\nDB_HOST={$host}\n"
        . thisMachineInstallerFunction('create_apps_slice') . "\ncreate_apps_slice\n";
    $process = new Process(['bash', '-c', $script], null, ['PATH' => "{$base}/bin:/usr/bin:/bin"]);
    $process->run();

    return [$process, (string) @file_get_contents("{$base}/coresmith-apps.slice")];
}

test("apps on Coresmith's machine may use the memory Coresmith doesn't need, and never less than half", function (int $megabytes, string $driver, string $host, int $systemd, string $cap) {
    [$process, $slice] = thisMachineSliceRun($megabytes, $driver, $host, $systemd);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($slice)->toBe("[Unit]\nDescription=Apps Coresmith deploys on the machine it runs on\n\n[Slice]\n{$cap}\n");
})->with([
    'four gigabytes on SQLite' => [3905, 'sqlite', '127.0.0.1', 255, 'MemoryMax=2881M'],
    'two gigabytes on SQLite' => [1985, 'sqlite', '127.0.0.1', 255, 'MemoryMax=992M'],
    'one gigabyte on SQLite' => [965, 'sqlite', '127.0.0.1', 255, 'MemoryMax=482M'],
    'PostgreSQL on this machine' => [3905, 'pgsql', '127.0.0.1', 255, 'MemoryMax=2369M'],
    'MySQL on this machine by name' => [3905, 'mysql', 'localhost', 252, 'MemoryMax=2369M'],
    'PostgreSQL elsewhere' => [3905, 'pgsql', 'db.internal', 255, 'MemoryMax=2881M'],
    'a systemd older than MemoryMax' => [3905, 'sqlite', '127.0.0.1', 219, 'MemoryLimit=2881M'],
]);

test('the apps slice is written with the other units and is never one the installer starts', function () {
    $units = thisMachineInstallerFunction('create_systemd_services');

    expect(thisMachineInstaller())->toContain('readonly APPS_SLICE="/etc/systemd/system/coresmith-apps.slice"')
        ->and($units)->toContain("    create_apps_slice\n")
        ->and(thisMachineInstaller())->not->toMatch('/systemctl[^\n]*(coresmith-apps\.slice|APPS_SLICE)/');
});

function thisMachineCaddyDropInRun(?string $contents): string
{
    $base = thisMachineBase();
    $dropIn = "{$base}/caddy.service.d/coresmith.conf";

    if ($contents !== null) {
        mkdir(dirname($dropIn));
        file_put_contents($dropIn, $contents);
    }

    $script = "set -euo pipefail\nCADDY_DROP_IN=" . escapeshellarg($dropIn) . "\n" . thisMachineInstallerFunction('remove_legacy_caddy_drop_in') . "\nremove_legacy_caddy_drop_in\n";
    (new Process(['bash', '-c', $script]))->mustRun();

    return $base;
}

test("the installer leaves Caddy's service to provisioning, and takes out only the drop-in older installers wrote", function () {
    $legacy = "[Service]\nExecStartPost=/bin/bash -c 'sleep 2 && grep -qs \"\\\"apps\\\"\" /etc/caddy/caddy-config.json && curl -fsS --unix-socket /run/caddy-admin/admin.sock -X POST http://localhost/load -H \"Content-Type: application/json\" -d @/etc/caddy/caddy-config.json || true'\n";
    $managed = "[Service]\nExecStart=\nExecStart=/usr/bin/caddy run --environ --config /etc/caddy/caddy-config.json\n";

    $old = thisMachineCaddyDropInRun($legacy);
    $provisioned = thisMachineCaddyDropInRun($managed);
    thisMachineCaddyDropInRun(null);

    expect(file_exists("{$old}/caddy.service.d"))->toBeFalse()
        ->and(file_get_contents("{$provisioned}/caddy.service.d/coresmith.conf"))->toBe($managed)
        ->and(thisMachineInstaller())->toContain('readonly CADDY_DROP_IN="/etc/systemd/system/caddy.service.d/coresmith.conf"')
        ->and(thisMachineInstallerFunction('create_systemd_services'))->toContain("    remove_legacy_caddy_drop_in\n")
        ->and(thisMachineInstallerFunction('create_systemd_services'))->not->toContain('caddy.service.d')
        ->and(thisMachineInstallerFunction('create_systemd_services'))->not->toContain("ExecStartPost=/bin/bash -c 'sleep 2");
});

function thisMachinePersist(): string
{
    preg_match("/<<'PERSIST'\n(.*?)\nPERSIST\n/s", thisMachineInstaller(), $matches);

    return $matches[1] ?? '';
}

function thisMachinePersistRun(string $base, string $dropIn, string $live, int $curlExit = 0): Process
{
    mkdir("{$base}/caddy", 0700, true);
    file_put_contents("{$base}/caddy/caddy-config.json", "{\"old\":true}\n");
    file_put_contents("{$base}/drop-in.conf", $dropIn);
    thisMachineStub($base, 'curl', 'printf %s ' . escapeshellarg($live) . "\nexit {$curlExit}");
    thisMachineStub($base, 'flock', ':');

    $script = preg_replace(
        ['/^readonly CONFIG=.*$/m', '/^readonly DROP_IN=.*$/m', '/^readonly LOCK=.*$/m'],
        ["readonly CONFIG={$base}/caddy/caddy-config.json", "readonly DROP_IN={$base}/drop-in.conf", "readonly LOCK={$base}/caddy-config.lock"],
        thisMachinePersist(),
    );
    $process = new Process(['bash', '-c', $script], null, ['PATH' => "{$base}/bin:/usr/bin:/bin"]);
    $process->run();

    return $process;
}

test('where Caddy runs the managed config with Coresmith inside it, the live config is saved over the old one, in one step', function () {
    $base = thisMachineBase();

    $process = thisMachinePersistRun($base, "[Service]\nExecStart=\nExecStart=/usr/bin/caddy run --environ --config /etc/caddy/caddy-config.json\n", '{"apps":{"http":{}}}');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(file_get_contents("{$base}/caddy/caddy-config.json"))->toBe('{"apps":{"http":{}}}')
        ->and(substr(sprintf('%o', fileperms("{$base}/caddy/caddy-config.json")), -3))->toBe('644')
        ->and(glob("{$base}/caddy/.caddy-config.*"))->toBe([])
        ->and(thisMachineCalls($base))->toContain('flock -w 60 9', 'curl -fsS --max-time 30 --unix-socket /run/caddy-admin/admin.sock http://localhost/config/');
});

test('where Caddy runs Coresmith from its Caddyfile, nothing is saved', function () {
    $base = thisMachineBase();

    $process = thisMachinePersistRun($base, '', '{"apps":{}}');

    expect($process->getExitCode())->toBe(0)
        ->and(file_get_contents("{$base}/caddy/caddy-config.json"))->toBe("{\"old\":true}\n")
        ->and(thisMachineCalls($base))->toBe([]);
});

test("a config Caddy doesn't hand over leaves the saved one as it was", function (string $live, int $exit) {
    $base = thisMachineBase();

    $process = thisMachinePersistRun($base, "ExecStart=/usr/bin/caddy run --environ --config /etc/caddy/caddy-config.json\n", $live, $exit);

    expect($process->getExitCode())->not->toBe(0)
        ->and(file_get_contents("{$base}/caddy/caddy-config.json"))->toBe("{\"old\":true}\n")
        ->and(glob("{$base}/caddy/.caddy-config.*"))->toBe([]);
})->with(['caddy down' => ['', 7], 'an empty answer' => ['', 0], 'no config at all' => ['null', 0]]);

test('the saving script is installed for root from the installer, and every change on this machine ends with it', function () {
    $body = thisMachineInstaller();
    $address = str($body)->after("cat >/etc/systemd/system/coresmith-address.service <<UNIT\n")->before("\nUNIT\n")->toString();
    $finalize = thisMachineInstallerFunction('finalize');

    expect($body)->toContain('readonly CADDY_PERSIST="' . CaddyApiClient::THIS_MACHINE_PERSIST . '"')
        ->and(thisMachineInstallFunction())->toContain("cat >\"\$tmp\" <<'PERSIST'\n")
        ->and(thisMachineInstallFunction())->toContain('mv -f "$tmp" "$CADDY_PERSIST"')
        ->and(thisMachinePersist())->toStartWith("#!/bin/bash\n")
        ->and($address)->toContain('ExecStartPost=+${CADDY_PERSIST}')
        ->and($finalize)->toContain("    if caddy_runs_managed_config; then\n        wait_for_caddy_admin\n        run sudo -u coresmith php \"\$INSTALL_DIR/artisan\" coresmith:caddy-sync\n        run \"\$CADDY_PERSIST\"\n    fi\n")
        ->and(strpos($finalize, 'coresmith:caddy-sync'))->toBeGreaterThan(strpos($finalize, 'run systemctl restart caddy'))
        ->and(thisMachineInstallerFunction('caddy_runs_managed_config'))->toContain('grep -qsF -- "--config /etc/caddy/caddy-config.json" "$CADDY_DROP_IN"');
});

test('the saving script passes shellcheck', function () {
    $shellcheck = new Process(['which', 'shellcheck']);
    $shellcheck->run();

    if ($shellcheck->getExitCode() !== 0) {
        $this->markTestSkipped('shellcheck is not installed on this machine; CI installs it.');
    }

    $file = realpath(sys_get_temp_dir()) . '/csm-' . bin2hex(random_bytes(4)) . '/persist';
    File::ensureDirectoryExists(dirname($file));
    test()->scratchFolders = [...test()->scratchFolders, dirname($file)];
    file_put_contents($file, thisMachinePersist());
    $process = new Process(['shellcheck', '-S', 'style', '-s', 'bash', $file]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput());
});

test("after Caddy restarts on Coresmith's machine, the installer waits for its admin socket before syncing, and says where to look if it never answers", function (string $curl, int $exit, string $output) {
    $base = thisMachineBase();
    thisMachineStub($base, 'curl', $curl);
    thisMachineStub($base, 'sleep', ':');

    $script = "set -euo pipefail\nCADDY_ADMIN_SOCKET=/run/caddy-admin/admin.sock\nfatal() { printf '%s\\n' \"\$1\"; exit 1; }\n" . thisMachineInstallerFunction('wait_for_caddy_admin') . "\nwait_for_caddy_admin\necho waited\n";
    $process = new Process(['bash', '-c', $script], null, ['PATH' => "{$base}/bin:/usr/bin:/bin"]);
    $process->run();

    expect($process->getExitCode())->toBe($exit)
        ->and(trim($process->getOutput()))->toBe($output);
})->with([
    'answering' => ['exit 0', 0, 'waited'],
    'never answering' => ['exit 7', 1, "Caddy didn't answer on its admin socket after it restarted. See journalctl -u caddy, then run the installer again."],
]);
