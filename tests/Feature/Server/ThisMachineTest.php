<?php

declare(strict_types=1);

use App\Exceptions\ThisMachineRefused;
use App\Models\Server;
use App\Services\Server\ThisMachine;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

function thisMachineServiceFolder(): string
{
    $folder = sys_get_temp_dir() . '/coresmith-this-machine-' . bin2hex(random_bytes(4));
    test()->scratchFolders = [...test()->scratchFolders, $folder];
    File::ensureDirectoryExists($folder);
    config(['coresmith.this_machine.state_path' => $folder]);

    return $folder;
}

function thisMachineServiceHelper(string $folder, Closure $answer): void
{
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(function (CarbonInterval $duration) use ($folder, $answer): void {
        $request = @file_get_contents("{$folder}/request");

        if ($request === false) {
            return;
        }

        [$id, $what] = explode(' ', trim($request), 2);
        $reply = $answer($what);

        if ($reply !== null) {
            unlink("{$folder}/request");
            file_put_contents("{$folder}/result.json", json_encode(['id' => $id, ...$reply]));
        }
    });
}

function thisMachineServiceServer(): Server
{
    return Server::factory()->create(['runs_coresmith' => true, 'ip_address' => '127.0.0.1', 'ssh_user' => 'root', 'ssh_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFkcNupv+GIYtXekZioi5HlcdNeU1xmZqDdX1IOp8KIo coresmith@this-machine']);
}

beforeEach(function () {
    $this->scratchFolders = [];
});

afterEach(function () {
    foreach ($this->scratchFolders as $folder) {
        File::deleteDirectory($folder);
    }
});

test('Coresmith asks the helper for root on this machine with its own key, and pins the host key the helper reports', function () {
    $folder = thisMachineServiceFolder();
    $asked = null;
    thisMachineServiceHelper($folder, function (string $what) use (&$asked): array {
        $asked = $what;

        return ['ok' => true, 'port' => 2222, 'host_keys' => ['RSA' => 'SHA256:Kw6l7d1oQ0fBo0W3c3sYbmUuQ+F0Y9VZ8u3m1cN2pXo', 'ED25519' => 'SHA256:sF9xS9zRItiZWsmi23+Npux21jT/3/SvTr1sIMmAD9I']];
    });

    $access = app(ThisMachine::class)->add(thisMachineServiceServer());

    expect($asked)->toBe('add ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFkcNupv+GIYtXekZioi5HlcdNeU1xmZqDdX1IOp8KIo')
        ->and($access->port)->toBe(2222)
        ->and($access->fingerprint)->toBe('SHA256:sF9xS9zRItiZWsmi23+Npux21jT/3/SvTr1sIMmAD9I');
});

test('a machine whose sshd has no ed25519 host key gets its RSA key pinned, the next one phpseclib accepts', function () {
    $folder = thisMachineServiceFolder();
    thisMachineServiceHelper($folder, fn(): array => ['ok' => true, 'port' => 22, 'host_keys' => ['ECDSA' => 'SHA256:ZxRVtUymWuzAs8MOHMq72XAKshlYKPY7Cuo2R6TZ8Ew', 'RSA' => 'SHA256:Kw6l7d1oQ0fBo0W3c3sYbmUuQ+F0Y9VZ8u3m1cN2pXo']]);

    expect(app(ThisMachine::class)->add(thisMachineServiceServer())->fingerprint)->toBe('SHA256:Kw6l7d1oQ0fBo0W3c3sYbmUuQ+F0Y9VZ8u3m1cN2pXo');
});

test("the helper refusing is passed on in the helper's words", function () {
    $folder = thisMachineServiceFolder();
    thisMachineServiceHelper($folder, fn(): array => ['ok' => false, 'error' => "sshd doesn't listen on 127.0.0.1, so Coresmith can't reach it from this machine and the old settings are back. Check ListenAddress in /etc/ssh/sshd_config."]);

    expect(fn() => app(ThisMachine::class)->add(thisMachineServiceServer()))
        ->toThrow(ThisMachineRefused::class, "sshd doesn't listen on 127.0.0.1, so Coresmith can't reach it from this machine and the old settings are back. Check ListenAddress in /etc/ssh/sshd_config.");
});

test("an answer missing the port or a host key Coresmith can pin isn't used", function (array $answer) {
    $folder = thisMachineServiceFolder();
    thisMachineServiceHelper($folder, fn(): array => $answer);

    expect(fn() => app(ThisMachine::class)->add(thisMachineServiceServer()))
        ->toThrow(ThisMachineRefused::class, "Coresmith's helper answered without the machine's SSH port or a host key Coresmith can check. Run the installer again, then try again.");
})->with([
    'no port' => [['ok' => true, 'host_keys' => ['ED25519' => 'SHA256:sF9xS9zRItiZWsmi23+Npux21jT/3/SvTr1sIMmAD9I']]],
    'no usable key' => [['ok' => true, 'port' => 22, 'host_keys' => ['ECDSA' => 'SHA256:ZxRVtUymWuzAs8MOHMq72XAKshlYKPY7Cuo2R6TZ8Ew']]],
]);

test('a helper that never answers is given up on, and Coresmith says where to look', function () {
    $folder = thisMachineServiceFolder();
    thisMachineServiceHelper($folder, fn(): ?array => null);

    expect(fn() => app(ThisMachine::class)->add(thisMachineServiceServer()))
        ->toThrow(ThisMachineRefused::class, "Coresmith's helper didn't answer within 30 seconds. Check it with journalctl -u coresmith-this-machine, then try again.");
});

test('an older answer still sitting there is never taken for the answer to a new request', function () {
    $folder = thisMachineServiceFolder();
    file_put_contents("{$folder}/result.json", json_encode(['id' => 'someoneelse', 'ok' => true, 'port' => 2200, 'host_keys' => ['ED25519' => 'SHA256:sF9xS9zRItiZWsmi23+Npux21jT/3/SvTr1sIMmAD9I']]));
    $polls = 0;
    thisMachineServiceHelper($folder, function () use (&$polls): ?array {
        return ++$polls < 3 ? null : ['ok' => true, 'port' => 22, 'host_keys' => ['ED25519' => 'SHA256:sF9xS9zRItiZWsmi23+Npux21jT/3/SvTr1sIMmAD9I']];
    });

    expect(app(ThisMachine::class)->add(thisMachineServiceServer())->port)->toBe(22);
});

test('removing asks the helper to take the key and the rule off', function () {
    $folder = thisMachineServiceFolder();
    $asked = null;
    thisMachineServiceHelper($folder, function (string $what) use (&$asked): array {
        $asked = $what;

        return ['ok' => true];
    });

    app(ThisMachine::class)->remove();

    expect($asked)->toBe('remove');
});

test("an install whose installer predates the helper can't use this machine yet", function () {
    config(['coresmith.this_machine.state_path' => sys_get_temp_dir() . '/coresmith-this-machine-missing-' . bin2hex(random_bytes(4))]);

    expect(app(ThisMachine::class)->installed())->toBeFalse();

    thisMachineServiceFolder();

    expect(app(ThisMachine::class)->installed())->toBeTrue();
});

test('a php-fpm pool from before the helper, which may not open its folder, reads it as not installed instead of failing the page', function () {
    $folder = thisMachineServiceFolder();
    $ask = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode(app(App\\Services\\Server\\ThisMachine::class)->installed());';

    $pool = new Process([PHP_BINARY, '-d', 'open_basedir=' . base_path(), '-r', $ask], base_path(), ['CORESMITH_THIS_MACHINE_STATE_PATH' => $folder]);
    $pool->run();

    expect($pool->getOutput())->toBe('false');
});

test('the server that is this machine is found across every team, and a deleted one no longer counts', function () {
    $server = thisMachineServiceServer();
    Server::factory()->create();

    expect(app(ThisMachine::class)->server()?->is($server))->toBeTrue();

    $server->delete();

    expect(app(ThisMachine::class)->server())->toBeNull();
});
