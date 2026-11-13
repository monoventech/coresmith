<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\EnvironmentFile;
use App\Services\SSH\SSHConnection;
use Tests\Support\FakeSSHTransport;

function environmentFile(): EnvironmentFile
{
    return new EnvironmentFile;
}

test('plain, quoted and exported lines all become variables', function () {
    $raw = <<<'ENV'
    # comment
    APP_ENV=production
    export APP_DEBUG=false
    APP_NAME="Coresmith Shop"
    GREETING='Hello $USER'
    SPACED = trimmed value   # trailing comment
    EMPTY=
    ENV;

    expect(environmentFile()->parse($raw))->toBe([
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_NAME' => 'Coresmith Shop',
        'GREETING' => 'Hello $USER',
        'SPACED' => 'trimmed value',
        'EMPTY' => '',
    ]);
});

test('a value in Japanese or Chinese survives parsing byte for byte', function () {
    expect(environmentFile()->parse("APP_NAME=\"ダークモード実装\"\nGREETING=你好，装修\nAFTER=1"))->toBe([
        'APP_NAME' => 'ダークモード実装',
        'GREETING' => '你好，装修',
        'AFTER' => '1',
    ]);
});

test('a double quoted value can run over several lines and carry escapes', function () {
    $raw = "KEY=\"-----BEGIN KEY-----\nabc\n-----END KEY-----\"\nNEXT=\"tab\\there \\\"quoted\\\" \\\\ done\"\nAFTER=1";

    expect(environmentFile()->parse($raw))->toBe([
        'KEY' => "-----BEGIN KEY-----\nabc\n-----END KEY-----",
        'NEXT' => "tab\there \"quoted\" \\ done",
        'AFTER' => '1',
    ]);
});

test('earlier variables are filled into later ones the way dotenv does, but not inside single quotes', function () {
    $raw = "APP_NAME=Shop\nMAIL_FROM_NAME=\"\${APP_NAME}\"\nRAW='\${APP_NAME}'\nUNKNOWN=\${MISSING}";

    expect(environmentFile()->parse($raw))->toBe([
        'APP_NAME' => 'Shop',
        'MAIL_FROM_NAME' => 'Shop',
        'RAW' => '${APP_NAME}',
        'UNKNOWN' => '${MISSING}',
    ]);
});

test('a json object of variables is read as well', function () {
    expect(environmentFile()->parse('{"APP_ENV":"production","PORT":3000,"bad key":"x"}'))->toBe(['APP_ENV' => 'production', 'PORT' => '3000']);
});

test('a json key ending in a newline is dropped, so it cannot split a line of the file', function () {
    expect(environmentFile()->parse('{"APP_ENV":"production","BROKEN\n":"x"}'))->toBe(['APP_ENV' => 'production']);
});

test('the file systemd reads quotes every value so nothing in it is expanded or cut short', function () {
    expect(environmentFile()->render(['A' => 'plain', 'B' => 'say "hi" to $HOME and `id` \\ ok', 'C' => "two\nlines"]))
        ->toBe("A=\"plain\"\nB=\"say \\\"hi\\\" to \\\$HOME and \\`id\\` \\\\ ok\"\nC=\"two\nlines\"\n")
        ->and(environmentFile()->render([]))->toBe('');
});

test('a line the editor cannot read is named with what to do about it', function (string $raw, string $error) {
    expect(environmentFile()->firstError($raw))->toBe($error);
})->with([
    'not an assignment' => ["APP_ENV=production\njust some words", 'Line 2 is not KEY=value. Fix it or remove it.'],
    'bad name' => ['1APP=x', 'Line 1 is not KEY=value. Fix it or remove it.'],
    'repeated name' => ["A=1\nB=2\nA=3", 'Line 3 sets A again. Remove one of them.'],
    'unclosed quote' => ["A=1\nB=\"never closed\nC=3", 'Line 2 opens a quote that never closes. Close it or remove it.'],
    'text after quote' => ['A="value" extra', 'Line 1 has text after its closing quote. Remove it or move it inside the quotes.'],
]);

test('valid text has no error', function () {
    expect(environmentFile()->firstError("A=1\n\n# note\nB=\"x\" # why\n"))->toBeNull()
        ->and(environmentFile()->firstError(''))->toBeNull();
});

test('the file is created root only before its contents are written', function () {
    $server = Server::factory()->create(['host_key_fingerprint' => null]);
    $application = Application::factory()->for($server)->create(['slug' => 'shop', 'env_variables' => "APP_ENV=production\n"]);
    $transport = new FakeSSHTransport;

    environmentFile()->write((new SSHConnection($server, $transport))->connect(), $application);

    expect($transport->commands())->toBe([
        "mkdir -p '/etc/coresmith/env' && chmod 700 '/etc/coresmith/env' && install -m 600 -o root -g root /dev/null '/etc/coresmith/env/shop.env'",
        "chown root:root '/etc/coresmith/env/shop.env' && chmod 600 '/etc/coresmith/env/shop.env'",
    ])->and($transport->uploads())->toBe(['/etc/coresmith/env/shop.env' => "APP_ENV=\"production\"\n"]);
});
