<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\LogSanitizer;

test('values from the application environment are redacted by name', function () {
    $application = Application::factory()->create([
        'env_variables' => "APP_KEY=base64:sup3rs3cr3tvalue\nDB_PASSWORD=hunter2long\n",
    ]);

    $clean = (new LogSanitizer)->sanitize('connecting with base64:sup3rs3cr3tvalue and hunter2long', $application);

    expect($clean)->not->toContain('sup3rs3cr3tvalue')
        ->and($clean)->not->toContain('hunter2long')
        ->and($clean)->toContain('[REDACTED:APP_KEY]')
        ->and($clean)->toContain('[REDACTED:DB_PASSWORD]');
});

test('ordinary settings stay readable, so a log still says which database file is missing', function () {
    $application = Application::factory()->create([
        'env_variables' => "SESSION_DRIVER=database\nAPP_ENV=production\nDB_DATABASE=/var/coresmith/apps/shop/shared/database.sqlite\n",
    ]);

    $line = 'Database file at path [/var/coresmith/apps/shop/shared/database.sqlite] does not exist. Running in production.';

    expect((new LogSanitizer)->sanitize($line, $application))->toBe($line);
});

test('a value shaped like a credential is redacted whatever its name', function () {
    $application = Application::factory()->create(['env_variables' => "STRIPE=sk9a8b7c6d5e4f3g2h1i0jklmnop\n"]);

    expect((new LogSanitizer)->sanitize('charging with sk9a8b7c6d5e4f3g2h1i0jklmnop', $application))
        ->toBe('charging with [REDACTED:STRIPE]');
});

test('a secret that contains another value is redacted whole', function () {
    $application = Application::factory()->create(['env_variables' => "DB_PASSWORD=hunter2long\nAPI_TOKEN=hunter2long-and-more\n"]);

    expect((new LogSanitizer)->sanitize('token hunter2long-and-more', $application))->toBe('token [REDACTED:API_TOKEN]');
});

test('very short environment values are left alone', function () {
    $application = Application::factory()->create(['env_variables' => "APP_ENV=prod\n"]);

    expect((new LogSanitizer)->sanitize('running in prod mode', $application))->toContain('prod');
});

test('credentials embedded in a url are redacted', function () {
    $clean = (new LogSanitizer)->sanitize('cloning https://oauth2:ghp_abcdefghijklmnopqrstuvwxyz123456@github.com/x/y.git');

    expect($clean)->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz123456')
        ->and($clean)->toContain('[REDACTED]@github.com');
});

test('a single token userinfo with no colon is redacted too', function () {
    $clean = (new LogSanitizer)->sanitize('cloning https://ghp_xxx@github.com/x.git');

    expect($clean)->not->toContain('ghp_xxx')
        ->and($clean)->toContain('[REDACTED]@github.com');
});

test('common secret shapes are redacted even when not in the environment', function (string $secret, string $line) {
    expect((new LogSanitizer)->sanitize($line))->not->toContain($secret);
})->with([
    ['sk_live_abcdefghij1234567890', 'error using sk_live_abcdefghij1234567890'],
    ['AKIAIOSFODNN7EXAMPLE', 'aws key AKIAIOSFODNN7EXAMPLE failed'],
    ['ghp_abcdefghijklmnopqrstuvwxyz1234', 'token ghp_abcdefghijklmnopqrstuvwxyz1234 rejected'],
]);

test('a bearer token is redacted', function () {
    expect((new LogSanitizer)->sanitize('Authorization: Bearer abc.def.ghi'))
        ->toContain('Bearer [REDACTED]')
        ->not->toContain('abc.def.ghi');
});

test('a key value pair with a sensitive name is redacted', function () {
    $clean = (new LogSanitizer)->sanitize('running with DB_PASSWORD=s3cr3tvalue now');

    expect($clean)->not->toContain('s3cr3tvalue')->and($clean)->toContain('[REDACTED]');
});

test('a private key block is redacted whole', function () {
    $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nabc123\n-----END OPENSSH PRIVATE KEY-----";

    expect((new LogSanitizer)->sanitize("dumped {$key} oops"))
        ->not->toContain('abc123')
        ->toContain('[REDACTED_PRIVATE_KEY]');
});

test('ordinary build output is untouched', function () {
    $output = "Installing dependencies\n38 packages installed\nBuild complete in 4.2s";

    expect((new LogSanitizer)->sanitize($output))->toBe($output);
});

test('deployment output is sanitised before it reaches the database', function () {
    $application = Application::factory()->create(['env_variables' => "DB_PASSWORD=leaky-password-value\n"]);
    $deployment = Deployment::factory()->for($application)->create();

    $sanitised = (new LogSanitizer)->sanitize('boom: leaky-password-value', $application);
    $deployment->forceFill(['log' => $sanitised])->save();

    expect(DB::table('deployments')->where('id', $deployment->id)->value('log'))
        ->not->toContain('leaky-password-value');
});
