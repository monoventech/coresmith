<?php

declare(strict_types=1);

use App\Services\Address\CertificateSource;
use App\Services\Address\InstallationAddress;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

const INSTALL_ADDRESS_REFUSAL = 'beelink.coresmith.io points at 151.226.169.196, but nothing reaches this server on ports 80 and 443 from the internet. Forward TCP ports 80 and 443 on your router to 192.168.0.248, then check again.';

const INSTALL_ADDRESS_NOTE = "Inside your own network, your router may not loop back to 151.226.169.196. If beelink.coresmith.io doesn't load there, add 192.168.0.248 beelink.coresmith.io to your local DNS or /etc/hosts.";

function installAddressScript(): string
{
    return (string) file_get_contents(base_path('scripts/install.sh'));
}

function installAddressFunctions(string ...$names): string
{
    return collect($names)->map(function (string $name): string {
        preg_match('/^' . preg_quote($name, '/') . '\(\) \{.*?^\}$/ms', installAddressScript(), $matches);

        return $matches[0] ?? '';
    })->implode("\n");
}

/**
 * @param  array<string, string>  $variables
 */
function installAddressRun(string $body, array $variables = [], ?string $env = null): Process
{
    $directory = realpath(sys_get_temp_dir()) . '/csa-' . bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);

    if ($env !== null) {
        file_put_contents($directory . '/.env', $env);
    }

    $script = "set -euo pipefail\n"
        . 'SHARED_DIR=' . escapeshellarg($directory) . ' INSTALL_DIR=' . escapeshellarg($directory) . "\n"
        . "BOLD='' GREEN='' YELLOW='' CYAN='' NC=''\n"
        . "DB_DRIVER=sqlite RELEASE_DIR=/opt/coresmith/releases/1 DATA_DIR=/var/lib/coresmith HORIZON_LOG=/var/log/coresmith/horizon.log\n"
        . "SERVER_IP=192.168.0.248 DOMAIN='' PANEL_HOST='' PANEL_URL='' TARGET_DOMAIN='' ADDRESS_LINES=''\n"
        . collect($variables)->map(fn(string $value, string $name): string => $name . '=' . escapeshellarg($value) . "\n")->implode('')
        . "log() { printf 'log: %s\\n' \"\$1\"; }\n"
        . "success() { printf 'ok: %s\\n' \"\$1\"; }\n"
        . "warn() { printf 'warn: %s\\n' \"\$1\"; }\n"
        . installAddressFunctions('env_value', 'is_ip_address', 'caddy_signs_itself', 'choose_address', 'move_to_domain', 'link_loads', 'print_wrapped', 'print_summary') . "\n"
        . $body;

    $process = new Process(['bash', '-c', $script]);
    $process->run();
    File::deleteDirectory($directory);

    return $process;
}

function installAddressFlat(Process $process): string
{
    return (string) preg_replace('/\s+/', ' ', $process->getOutput());
}

test('a fresh install comes up on the machine\'s own address, and only a different domain is moved to', function (string $domain, string $target) {
    $process = installAddressRun("choose_address\nprintf '%s|%s|%s' \"\$PANEL_HOST\" \"\$PANEL_URL\" \"\$TARGET_DOMAIN\"", ['DOMAIN' => $domain]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("192.168.0.248|https://192.168.0.248|{$target}");
})->with([
    'no domain' => ['', ''],
    'a domain' => ['beelink.coresmith.io', 'beelink.coresmith.io'],
    'a domain in capitals' => ['Beelink.Coresmith.IO', 'beelink.coresmith.io'],
    'the machine\'s own address' => ['192.168.0.248', ''],
]);

test('a re-run keeps the address Coresmith answers on, and moves to a new domain the way Settings does', function (string $domain, string $target) {
    $process = installAddressRun("choose_address\nprintf '%s|%s|%s' \"\$PANEL_HOST\" \"\$PANEL_URL\" \"\$TARGET_DOMAIN\"", ['DOMAIN' => $domain], "APP_URL=https://coresmith.example.com\n");

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe("coresmith.example.com|https://coresmith.example.com|{$target}");
})->with([
    'the address it has' => ['coresmith.example.com', ''],
    'a new domain' => ['beelink.coresmith.io', 'beelink.coresmith.io'],
]);

test('the move asks for the domain from the install, as the coresmith user, and waits for the answer', function () {
    $process = installAddressRun(<<<'BASH'
        sudo() { printf '%s|%s' "$([ "$PWD" = "$INSTALL_DIR" ] && echo install || echo elsewhere)" "$*" >"$SHARED_DIR/called"; }
        move_to_domain >/dev/null
        cat "$SHARED_DIR/called"
        BASH, ['PANEL_HOST' => '192.168.0.248', 'TARGET_DOMAIN' => 'beelink.coresmith.io']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe('install|-u coresmith php artisan coresmith:address-request beelink.coresmith.io --wait');
});

test('a domain that works becomes the address, keeping the notes that came with it', function () {
    $process = installAddressRun(<<<'BASH'
        sudo() { printf '%s\n' 'Switching to beelink.coresmith.io.' "$NOTE" 'Coresmith now answers on beelink.coresmith.io.'; }
        move_to_domain >/dev/null
        printf '%s|%s|%s' "$PANEL_HOST" "$PANEL_URL" "$ADDRESS_LINES"
        BASH, ['PANEL_HOST' => '192.168.0.248', 'PANEL_URL' => 'https://192.168.0.248', 'TARGET_DOMAIN' => 'beelink.coresmith.io', 'NOTE' => INSTALL_ADDRESS_NOTE]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe('beelink.coresmith.io|https://beelink.coresmith.io|' . INSTALL_ADDRESS_NOTE);
});

test('a domain that does not work yet leaves Coresmith where it works, with the reason, and the install carries on', function (string $output, string $reason) {
    $process = installAddressRun(<<<'BASH'
        sudo() { printf '%s' "$OUTPUT"; return 1; }
        move_to_domain >/dev/null
        printf '%s|%s|%s' "$PANEL_HOST" "$PANEL_URL" "$ADDRESS_LINES"
        BASH, ['PANEL_HOST' => '192.168.0.248', 'PANEL_URL' => 'https://192.168.0.248', 'TARGET_DOMAIN' => 'beelink.coresmith.io', 'OUTPUT' => $output]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe('192.168.0.248|https://192.168.0.248|' . $reason);
})->with([
    'refused before switching' => [INSTALL_ADDRESS_REFUSAL . "\n", INSTALL_ADDRESS_REFUSAL],
    'no certificate came' => ["Switching to beelink.coresmith.io.\nNo certificate for beelink.coresmith.io after 3 minutes.\n", 'No certificate for beelink.coresmith.io after 3 minutes.'],
    'still moving' => ["Switching to beelink.coresmith.io.\nCoresmith is still moving to beelink.coresmith.io. Settings, General shows how it went.\n", 'Coresmith is still moving to beelink.coresmith.io. Settings, General shows how it went.'],
    'nothing said' => ['', "Coresmith couldn't move to beelink.coresmith.io, and didn't say why."],
]);

test('a domain that is not ready yet: the summary gives the working link, the reason, and where to move later', function () {
    $process = installAddressRun(<<<'BASH'
        link_loads() { [ "$1" = 192.168.0.248 ]; }
        print_summary "https://192.168.0.248/install?token=abc" true false
        BASH, ['PANEL_HOST' => '192.168.0.248', 'PANEL_URL' => 'https://192.168.0.248', 'TARGET_DOMAIN' => 'beelink.coresmith.io', 'ADDRESS_LINES' => INSTALL_ADDRESS_REFUSAL]);

    $output = installAddressFlat($process);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($output)->toContain('Coresmith installed successfully!')
        ->toContain('Address: https://192.168.0.248 ')
        ->toContain('https://192.168.0.248/install?token=abc')
        ->toContain('Your browser will warn about the certificate at https://192.168.0.248, because Caddy signs it itself. Continue past the warning to open Coresmith.')
        ->toContain("Coresmith isn't on beelink.coresmith.io yet: " . INSTALL_ADDRESS_REFUSAL . ' Once that is fixed, move Coresmith to beelink.coresmith.io in Settings, General.')
        ->and(strpos($output, 'install?token=abc'))->toBeLessThan(strpos($output, "isn't on beelink.coresmith.io"));
});

test('a domain that works is the address the summary gives, with its notes and no certificate warning', function () {
    $process = installAddressRun(<<<'BASH'
        link_loads() { [ "$1" = beelink.coresmith.io ]; }
        print_summary "https://beelink.coresmith.io/install?token=abc" true false
        BASH, ['PANEL_HOST' => 'beelink.coresmith.io', 'PANEL_URL' => 'https://beelink.coresmith.io', 'TARGET_DOMAIN' => 'beelink.coresmith.io', 'ADDRESS_LINES' => INSTALL_ADDRESS_NOTE]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(installAddressFlat($process))->toContain('Coresmith installed successfully!')
        ->toContain('Address: https://beelink.coresmith.io ')
        ->toContain(INSTALL_ADDRESS_NOTE)
        ->not->toContain("isn't on")
        ->not->toContain('warn about the certificate');
});

test('an install on its address alone warns about the certificate once, and says nothing about domains', function () {
    $process = installAddressRun(<<<'BASH'
        link_loads() { return 0; }
        print_summary "https://192.168.0.248/install?token=abc" true false
        BASH, ['PANEL_HOST' => '192.168.0.248', 'PANEL_URL' => 'https://192.168.0.248']);

    $output = installAddressFlat($process);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(substr_count($output, 'warn about the certificate'))->toBe(1)
        ->and($output)->not->toContain("isn't on");
});

test('the summary warns about the certificate wherever Caddy signs it itself, by the rule Coresmith uses', function (string $host) {
    $process = installAddressRun(<<<'BASH'
        link_loads() { return 0; }
        print_summary "$PANEL_URL/install?token=abc" true false
        BASH, ['PANEL_HOST' => $host, 'PANEL_URL' => "https://{$host}"]);

    $warns = str_contains(installAddressFlat($process), "Your browser will warn about the certificate at https://{$host}, because Caddy signs it itself.");

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($warns)->toBe(InstallationAddress::certificate($host) === CertificateSource::Internal);
})->with(['192.168.0.248', 'cs-r1.internal', 'panel.local', 'panel.home.arpa', 'panel.localhost', 'localhost', 'beelink.coresmith.io', 'panel.tailnet.ts.net', 'internal.example.com']);

test('the summary only says installed successfully when the link it prints loads', function () {
    $process = installAddressRun(<<<'BASH'
        link_loads() { return 1; }
        print_summary "https://192.168.0.248/install?token=abc" true false
        BASH, ['PANEL_HOST' => '192.168.0.248', 'PANEL_URL' => 'https://192.168.0.248']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(installAddressFlat($process))->not->toContain('successfully')
        ->toContain("Coresmith is installed, but isn't answering")
        ->toContain("https://192.168.0.248 didn't load from this server. Check Caddy's log with journalctl -u caddy -n 50.");
});

test('the link loads when Coresmith answers it on this server, whatever DNS or the router do', function (string $host, ?string $resolve, int $answer) {
    $process = installAddressRun(<<<'BASH'
        curl() { printf '%s' "$*" >"$SHARED_DIR/called"; return "$ANSWER"; }
        code=0
        link_loads "$HOST" || code=$?
        printf '%s|%s' "$code" "$(cat "$SHARED_DIR/called")"
        BASH, ['HOST' => $host, 'ANSWER' => (string) $answer]);

    [$status, $arguments] = explode('|', $process->getOutput());

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and((int) $status)->toBe($answer)
        ->and($arguments)->toEndWith("https://{$host}/up");

    if ($resolve === null) {
        expect($arguments)->not->toContain('--resolve');
    } else {
        expect($arguments)->toContain($resolve);
    }
})->with([
    'a domain, asked on this server' => ['beelink.coresmith.io', '--resolve beelink.coresmith.io:443:127.0.0.1', 0],
    'a domain that does not answer' => ['beelink.coresmith.io', '--resolve beelink.coresmith.io:443:127.0.0.1', 7],
    'an address, asked on itself since it carries no name for Caddy to pick a certificate by' => ['192.168.0.248', null, 0],
]);

test('the installer moves Coresmith with the command whose checks Settings shares', function () {
    expect(Artisan::all())->toHaveKey('coresmith:address-request')
        ->and(Artisan::all()['coresmith:address-request']->getDefinition()->hasOption('wait'))->toBeTrue()
        ->and(installAddressFunctions('move_to_domain'))->toContain('sudo -u coresmith php artisan coresmith:address-request "$TARGET_DOMAIN" --wait');
});

test('the address is chosen once the domain is known, before anything is configured with it', function () {
    expect(installAddressScript())->toContain("    adopt_existing_install\n    interactive_prompts\n    choose_address\n    ensure_database_password\n")
        ->and(installAddressFunctions('configure_environment'))->not->toContain('PANEL_HOST=');
});

test('the move waits for healthy services, and the first sign-in link comes after it, on whichever address works', function () {
    $finalize = installAddressFunctions('finalize');

    expect($finalize)->toContain("if \$healthy; then\n            move_to_domain")
        ->and(strpos($finalize, 'wait_for_service coresmith-reverb'))->toBeLessThan(strpos($finalize, 'move_to_domain'))
        ->and(strpos($finalize, 'move_to_domain'))->toBeLessThan(strpos($finalize, 'coresmith:setup-token --force'));
});

test('a re-run refreshes Coresmith\'s own Caddy site once the services are up, so the redirect from an earlier move survives it', function () {
    $finalize = installAddressFunctions('finalize');

    expect($finalize)->toContain('run sudo -u coresmith php "$INSTALL_DIR/artisan" coresmith:site-refresh || warn ')
        ->and(strpos($finalize, 'wait_for_service coresmith-reverb'))->toBeLessThan(strpos($finalize, 'coresmith:site-refresh'))
        ->and(strpos($finalize, 'coresmith:site-refresh'))->toBeLessThan(strpos($finalize, 'move_to_domain'));
});
