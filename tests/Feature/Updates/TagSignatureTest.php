<?php

declare(strict_types=1);

use App\Enums\ReleaseSignature;
use App\Services\Updates\TagSignature;
use App\Services\Updates\UpdatePaths;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    $this->paths = updatesUseTemporaryPaths();
});

afterEach(function () {
    updatesCleanUp();
});

function updatesFakeGpg(string $verifyOutput, int $verifyExit = 0, int $importExit = 0, string $importOutput = '', array $shipped = ['2222222222222222222222222222222222222222', '1111111111111111111111111111111111111111'], int $listExit = 0): stdClass
{
    $seen = (object) ['verify' => null, 'imports' => 0, 'killed' => [], 'listed' => []];

    Process::fake(['*' => function (PendingProcess $process) use ($seen, $verifyOutput, $verifyExit, $importExit, $importOutput, $shipped, $listExit) {
        $command = (array) $process->command;

        if (in_array('--show-keys', $command, true)) {
            $seen->listed[] = $command;
            $contents = trim((string) @file_get_contents((string) end($command)));
            $listed = preg_match('/^[0-9A-F]{40,64}( [0-9A-F]{40,64})*$/', $contents) === 1 ? explode(' ', $contents) : $shipped;
            $lines = array_map(fn(string $fingerprint): string => "fpr:::::::::{$fingerprint}:", $listed);

            return $listExit === 0
                ? Process::result(output: "pub:-:255:22:0123456789ABCDEF:1700000000:::-:::scESC::::::23::0:\n" . implode("\n", $lines) . "\n")
                : Process::result(errorOutput: "gpg: no valid OpenPGP data found.\n", exitCode: $listExit);
        }

        if (in_array('verify-tag', $command, true)) {
            $seen->verify = $process;
            $seen->verifyHome = $process->environment['GNUPGHOME'] ?? null;
            $seen->homeExistedDuring = is_dir((string) $seen->verifyHome);

            return Process::result(errorOutput: $verifyOutput, exitCode: $verifyExit);
        }

        if (in_array('--import', $command, true)) {
            $seen->imports++;

            return Process::result(errorOutput: $importOutput, exitCode: $importExit);
        }

        if (($command[0] ?? null) === 'gpgconf') {
            $seen->killed[] = $command;
        }

        return Process::result();
    }]);

    return $seen;
}

test('without release keys a tag is unchecked and nothing runs', function () {
    Process::fake();

    $result = app(TagSignature::class)->verify('/tmp/repo', 'v1.0.2');

    expect($result->status)->toBe(ReleaseSignature::Unchecked)
        ->and($result->message)->toBe("Release signatures aren't checked: this install has no Coresmith release keys yet.")
        ->and($result->fingerprint)->toBeNull()
        ->and($result->passed())->toBeTrue();

    Process::assertNothingRan();
});

test('a missing keys directory is no keys', function () {
    rmdir($this->paths['keys']);

    expect(app(TagSignature::class)->keys())->toBe([]);
});

test('only armoured key files count, in order', function () {
    file_put_contents("{$this->paths['keys']}/b.asc", 'b');
    file_put_contents("{$this->paths['keys']}/a.asc", 'a');
    file_put_contents("{$this->paths['keys']}/README.md", 'not a key');

    expect(app(TagSignature::class)->keys())->toBe(["{$this->paths['keys']}/a.asc", "{$this->paths['keys']}/b.asc"]);
});

test('a good signature from a shipped key is verified', function () {
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');
    $seen = updatesFakeGpg("[GNUPG:] NEWSIG\n[GNUPG:] GOODSIG 0123456789ABCDEF Coresmith Releases <releases@coresmith.io>\n[GNUPG:] VALIDSIG 1111111111111111111111111111111111111111 2026-10-18 1792310400 0 4 0 22 10 00 2222222222222222222222222222222222222222\n[GNUPG:] TRUST_UNDEFINED 0 pgp\n");

    $result = app(TagSignature::class)->verify('/srv/repo', 'v1.0.2');

    expect($result->status)->toBe(ReleaseSignature::Verified)
        ->and($result->fingerprint)->toBe('2222222222222222222222222222222222222222')
        ->and($result->message)->toBe('Signed by 2222 2222 2222 2222 2222  2222 2222 2222 2222 2222.')
        ->and($result->passed())->toBeTrue()
        ->and($seen->imports)->toBe(1)
        ->and($seen->verifyHome)->toStartWith(rtrim(sys_get_temp_dir(), '/') . '/')
        ->and(strlen($seen->verifyHome))->toBeLessThanOrEqual(80)
        ->and($seen->homeExistedDuring)->toBeTrue()
        ->and(is_dir($seen->verifyHome))->toBeFalse()
        ->and($seen->verify->path)->toBe('/srv/repo')
        ->and($seen->verify->environment)->toMatchArray(['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null'])
        ->and($seen->killed)->toHaveCount(1);
});

test('a good signature from a key that is not one of the shipped keys is refused', function () {
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');
    $seen = updatesFakeGpg("[GNUPG:] GOODSIG 0123456789ABCDEF Someone\n[GNUPG:] VALIDSIG 3333333333333333333333333333333333333333 2026-10-18 1792310400 0 4 0 22 10 00 4444444444444444444444444444444444444444\n");

    $result = app(TagSignature::class)->verify('/srv/repo', 'v1.0.2');

    expect($result->status)->toBe(ReleaseSignature::Invalid)
        ->and($result->message)->toBe("v1.0.2 is signed with a key this install doesn't trust.")
        ->and($seen->listed)->toHaveCount(1)
        ->and($seen->listed[0])->toContain('--with-colons')
        ->and(end($seen->listed[0]))->toBe("{$this->paths['keys']}/coresmith.asc");
});

test('every shipped key file is trusted, not only the last one', function () {
    file_put_contents("{$this->paths['keys']}/a.asc", '2222222222222222222222222222222222222222');
    file_put_contents("{$this->paths['keys']}/b.asc", '5555555555555555555555555555555555555555');
    updatesFakeGpg("[GNUPG:] GOODSIG 0123456789ABCDEF Coresmith\n[GNUPG:] VALIDSIG 1111111111111111111111111111111111111111 2026-10-18 1792310400 0 4 0 22 10 00 2222222222222222222222222222222222222222\n");

    expect(app(TagSignature::class)->verify('/srv/repo', 'v1.0.2')->status)->toBe(ReleaseSignature::Verified);
});

test('a signature from a version 5 key is matched by its longer fingerprint', function () {
    $fingerprint = str_repeat('6', 64);
    file_put_contents("{$this->paths['keys']}/coresmith.asc", $fingerprint);
    updatesFakeGpg("[GNUPG:] GOODSIG 0123456789ABCDEF Coresmith\n[GNUPG:] VALIDSIG {$fingerprint} 2026-10-18 1792310400 0 5 0 22 10 00 {$fingerprint}\n");

    expect(app(TagSignature::class)->verify('/srv/repo', 'v1.0.2'))
        ->status->toBe(ReleaseSignature::Verified)
        ->fingerprint->toBe($fingerprint);
});

test('shipped keys that cannot be listed make every tag invalid', function () {
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');
    updatesFakeGpg("[GNUPG:] GOODSIG 0123456789ABCDEF Coresmith\n[GNUPG:] VALIDSIG 1111111111111111111111111111111111111111 2026-10-18 1792310400 0 4 0 22 10 00 2222222222222222222222222222222222222222\n", listExit: 2);

    expect(app(TagSignature::class)->verify('/srv/repo', 'v1.0.2'))
        ->status->toBe(ReleaseSignature::Invalid)
        ->message->toBe("The release keys in {$this->paths['keys']} couldn't be read: gpg: no valid OpenPGP data found.");
});

test('the signing subkey stands in when gpg names no primary key', function () {
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');
    updatesFakeGpg("[GNUPG:] GOODSIG 0123456789ABCDEF Coresmith\n[GNUPG:] VALIDSIG 1111111111111111111111111111111111111111 2026-10-18 1792310400 0 4 0 22 10 00\n");

    expect(app(TagSignature::class)->verify('/srv/repo', 'v1.0.2')->fingerprint)->toBe('1111111111111111111111111111111111111111');
});

test('anything short of a good signature is refused', function (string $output, int $exit, string $message) {
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'key');
    $seen = updatesFakeGpg($output, $exit);

    $result = app(TagSignature::class)->verify('/srv/repo', 'v1.0.2');

    expect($result->status)->toBe(ReleaseSignature::Invalid)
        ->and($result->message)->toBe($message)
        ->and($result->passed())->toBeFalse()
        ->and(is_dir((string) $seen->verifyHome))->toBeFalse();
})->with([
    'unsigned' => ["error: no signature found\n", 1, 'v1.0.2 is not signed. This install only accepts releases signed with a Coresmith release key.'],
    'a lightweight tag' => ["error: v1.0.2: cannot verify a non-tag object of type commit.\n", 1, 'v1.0.2 is not signed. This install only accepts releases signed with a Coresmith release key.'],
    'an unknown key' => ["[GNUPG:] ERRSIG 0123456789ABCDEF 22 10 00 1792310400 9 -\n[GNUPG:] NO_PUBKEY 0123456789ABCDEF\n", 1, "v1.0.2 is signed with a key this install doesn't trust."],
    'tampered' => ["[GNUPG:] BADSIG 0123456789ABCDEF Someone\n", 1, "v1.0.2's signature doesn't match its contents. It may have been tampered with."],
    'an expired key' => ["[GNUPG:] EXPKEYSIG 0123456789ABCDEF Someone\n", 1, 'v1.0.2 is signed with a key that has expired or been revoked.'],
    'a revoked key' => ["[GNUPG:] REVKEYSIG 0123456789ABCDEF Someone\n", 1, 'v1.0.2 is signed with a key that has expired or been revoked.'],
    'an expired signature' => ["[GNUPG:] EXPSIG 0123456789ABCDEF Someone\n", 1, 'v1.0.2 is signed with a key that has expired or been revoked.'],
    'a good signature git still refused' => ["[GNUPG:] GOODSIG 0123456789ABCDEF Someone\n[GNUPG:] VALIDSIG 1111111111111111111111111111111111111111 2026-10-18 1792310400 0 4 0 22 10 00\n", 1, "Coresmith couldn't verify v1.0.2's signature: [GNUPG:] VALIDSIG 1111111111111111111111111111111111111111 2026-10-18 1792310400 0 4 0 22 10 00"],
    'something else' => ["gpg: something else\n", 2, "Coresmith couldn't verify v1.0.2's signature: gpg: something else"],
    'a good signature without its details' => ["[GNUPG:] GOODSIG 0123456789ABCDEF Someone\n", 0, "Coresmith couldn't verify v1.0.2's signature: [GNUPG:] GOODSIG 0123456789ABCDEF Someone"],
]);

test('keys that cannot be imported make every tag invalid', function () {
    file_put_contents("{$this->paths['keys']}/coresmith.asc", 'not a key');
    $seen = updatesFakeGpg('', importExit: 2, importOutput: "gpg: no valid OpenPGP data found.\n");

    $result = app(TagSignature::class)->verify('/srv/repo', 'v1.0.2');

    expect($result->status)->toBe(ReleaseSignature::Invalid)
        ->and($result->message)->toBe('The release keys in ' . app(UpdatePaths::class)->keys() . " couldn't be read: gpg: no valid OpenPGP data found.")
        ->and($seen->verify)->toBeNull();
});

test('a real signed tag is verified and a stranger\'s is refused', function () {
    if ((new SymfonyProcess(['gpg', '--version']))->run() !== 0) {
        $this->markTestSkipped('gpg is not installed on this machine; the CI runners ship it.');
    }

    $base = rtrim(sys_get_temp_dir(), '/') . '/csr-' . bin2hex(random_bytes(3));
    $signer = "{$base}/s";
    $repository = "{$base}/repo";
    mkdir($signer, 0700, true);
    mkdir($repository, 0700, true);
    file_put_contents("{$base}/gitconfig", '');
    $env = ['GNUPGHOME' => $signer, 'GIT_CONFIG_GLOBAL' => "{$base}/gitconfig", 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_AUTHOR_NAME' => 'Coresmith Test', 'GIT_AUTHOR_EMAIL' => 'test@coresmith.test', 'GIT_COMMITTER_NAME' => 'Coresmith Test', 'GIT_COMMITTER_EMAIL' => 'test@coresmith.test'];
    $run = function (array $command, ?string $cwd = null) use ($env): string {
        $process = new SymfonyProcess($command, $cwd, $env, null, 120);
        $process->mustRun();

        return $process->getOutput();
    };
    $fingerprint = function (string $uid) use ($run, $signer): string {
        preg_match('/^fpr:+([0-9A-F]{40}):/m', $run(['gpg', '--homedir', $signer, '--with-colons', '--list-keys', $uid]), $match);

        return $match[1];
    };

    try {
        $run(['gpg', '--homedir', $signer, '--batch', '--passphrase', '', '--quick-gen-key', 'Release One <one@coresmith.test>', 'ed25519', 'sign', 'never']);
        $run(['gpg', '--homedir', $signer, '--batch', '--passphrase', '', '--quick-gen-key', 'Stranger <two@coresmith.test>', 'ed25519', 'sign', 'never']);
        $one = $fingerprint('one@coresmith.test');
        $two = $fingerprint('two@coresmith.test');

        $run(['git', 'init', '--quiet'], $repository);
        file_put_contents("{$repository}/VERSION", "1.0.2\n");
        $run(['git', 'add', 'VERSION'], $repository);
        $run(['git', 'commit', '--quiet', '-m', 'Release'], $repository);
        $run(['git', '-c', "user.signingkey={$one}", 'tag', '-s', 'v1.0.2', '-m', 'v1.0.2'], $repository);
        $run(['git', '-c', "user.signingkey={$two}", 'tag', '-s', 'v1.0.3', '-m', 'v1.0.3'], $repository);
        $run(['git', 'tag', '-a', 'v1.0.4', '-m', 'v1.0.4'], $repository);
        $run(['git', 'tag', 'v1.0.5'], $repository);
        file_put_contents("{$this->paths['keys']}/coresmith.asc", $run(['gpg', '--homedir', $signer, '--armor', '--export', $one]));

        $signature = app(TagSignature::class);

        expect($signature->verify($repository, 'v1.0.2'))
            ->status->toBe(ReleaseSignature::Verified)
            ->fingerprint->toBe($one)
            ->and($signature->verify($repository, 'v1.0.3'))
            ->status->toBe(ReleaseSignature::Invalid)
            ->message->toBe("v1.0.3 is signed with a key this install doesn't trust.")
            ->and($signature->verify($repository, 'v1.0.4'))
            ->status->toBe(ReleaseSignature::Invalid)
            ->message->toBe('v1.0.4 is not signed. This install only accepts releases signed with a Coresmith release key.')
            ->and($signature->verify($repository, 'v1.0.5'))
            ->status->toBe(ReleaseSignature::Invalid)
            ->message->toBe('v1.0.5 is not signed. This install only accepts releases signed with a Coresmith release key.');
    } finally {
        (new SymfonyProcess(['gpgconf', '--homedir', $signer, '--kill', 'all']))->run();
        File::deleteDirectory($base);
    }
});
