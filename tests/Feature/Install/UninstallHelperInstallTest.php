<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

function uninstallHelperInstaller(): string
{
    return (string) file_get_contents(base_path('scripts/install.sh'));
}

function uninstallHelperFunction(): string
{
    return str(uninstallHelperInstaller())->after("\ninstall_uninstall_helper() {\n")->before("\n}\n\n")->toString();
}

function uninstallHelperUnit(string $unit): string
{
    return str(uninstallHelperInstaller())->after("cat >/etc/systemd/system/{$unit} <<UNIT\n")->before("\nUNIT\n")->toString();
}

test('the uninstall helper is written for root from the installer itself, never from a release', function () {
    $install = uninstallHelperFunction();

    expect(uninstallHelperInstaller())->toContain('readonly UNINSTALL_HELPER="/usr/local/libexec/coresmith/uninstall"')
        ->and(uninstallHelperInstaller())->toContain('readonly UNINSTALL_DIR="/var/lib/coresmith-uninstall"')
        ->and($install)->toContain("cat >\"\$tmp\" <<-'UNINSTALLER'\n")
        ->and($install)->toContain("chown root:root \"\$tmp\"\n    chmod 0755 \"\$tmp\"\n    mv -f \"\$tmp\" \"\$UNINSTALL_HELPER\"")
        ->and($install)->toContain('refuse_link "${UNINSTALL_HELPER%/*}" "$UNINSTALL_HELPER" "$UNINSTALL_DIR"')
        ->and($install)->not->toContain('RELEASE_DIR')
        ->and($install)->not->toContain('scripts/uninstall.sh')
        ->and(preg_match_all('/^main\(\) \{$/m', uninstallHelperInstaller()))->toBe(1)
        ->and(str(uninstallHelperInstaller())->after("\ncreate_systemd_services() {\n")->before("\n}\n")->toString())->toContain("    install_uninstall_helper\n");
});

test('the embedded uninstaller is the same as scripts/uninstall.sh', function () {
    $body = str(uninstallHelperFunction())->after("<<-'UNINSTALLER'\n")->before("\n\tUNINSTALLER\n")->toString();
    $lines = array_map(fn(string $line): string => str_starts_with($line, "\t") ? substr($line, 1) : $line, explode("\n", $body));

    expect(implode("\n", $lines) . "\n")->toBe((string) file_get_contents(base_path('scripts/uninstall.sh')), "Copy scripts/uninstall.sh into install.sh's UNINSTALLER heredoc, each line indented by one tab.")
        ->and(collect(explode("\n", $body))->every(fn(string $line): bool => $line === '' || str_starts_with($line, "\t")))->toBeTrue();
});

test('requests reach the uninstall helper through a folder Coresmith can add to but not rewrite', function () {
    expect(uninstallHelperFunction())->toContain("chown root:coresmith \"\$UNINSTALL_DIR\"\n    chmod 1770 \"\$UNINSTALL_DIR\"")
        ->and(uninstallHelperInstaller())->toContain('php_admin_value[open_basedir] = ${RELEASES_DIR}:${SHARED_DIR}:${DATA_DIR}:${THIS_MACHINE_DIR}:${UNINSTALL_DIR}:/proc/net/if_inet6:/tmp');
});

test('its service runs as root without a sandbox, so it can remove what the installer made, and its path unit is enabled', function () {
    $service = uninstallHelperUnit('coresmith-uninstall.service');
    $path = uninstallHelperUnit('coresmith-uninstall.path');

    expect($service)->toContain("[Service]\nType=oneshot\nExecStart=\${UNINSTALL_HELPER} --request \${UNINSTALL_DIR}\nTimeoutStartSec=30min")
        ->and($service)->not->toContain('User=')
        ->and($service)->not->toContain('Protect')
        ->and($path)->toContain("[Path]\nPathExists=\${UNINSTALL_DIR}/request\nUnit=coresmith-uninstall.service")
        ->and($path)->toContain("[Install]\nWantedBy=multi-user.target")
        ->and(uninstallHelperInstaller())->toContain('run systemctl enable --now coresmith-uninstall.path');
});

test('the helper the installer writes is the uninstaller, byte for byte', function () {
    $base = realpath(sys_get_temp_dir()) . '/uninstall-helper-' . bin2hex(random_bytes(4));
    mkdir($base);

    try {
        $script = "set -Eeuo pipefail\n"
            . "UNINSTALL_HELPER='{$base}/libexec/coresmith/uninstall'\nUNINSTALL_DIR='{$base}/lib/coresmith-uninstall'\n"
            . "refuse_link() { :; }\nchown() { :; }\n"
            . "install_uninstall_helper() {\n" . uninstallHelperFunction() . "\n}\ninstall_uninstall_helper\n";
        $process = new Process(['bash', '-c', $script]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_get_contents("{$base}/libexec/coresmith/uninstall"))->toBe(file_get_contents(base_path('scripts/uninstall.sh')))
            ->and(substr(sprintf('%o', fileperms("{$base}/libexec/coresmith/uninstall")), -4))->toBe('0755')
            ->and(substr(sprintf('%o', fileperms("{$base}/lib/coresmith-uninstall")), -4))->toBe('1770');
    } finally {
        (new Process(['rm', '-rf', $base]))->run();
    }
});
