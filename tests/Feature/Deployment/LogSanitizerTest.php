<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\LogSanitizer;

test('terminal colour and cursor codes are dropped, so a log reads as plain text', function () {
    $line = "  laravel/tinker \e[90m....\e[39m \e[32;1mDONE\e[39;22m\n\e]0;composer\x07done\e[2K\e[1G";

    expect((new LogSanitizer)->sanitize($line))->toBe("  laravel/tinker .... DONE\ndone");
});

test('text that only looks like a colour code, with no escape before it, is kept', function () {
    expect((new LogSanitizer)->sanitize('cells[90m] and [0m stay'))->toBe('cells[90m] and [0m stay');
});

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

test('a long run of dotted words is sanitised without stalling', function () {
    $line = str_repeat('a.', 100_000) . '@x.io';
    $started = hrtime(true);

    $clean = (new LogSanitizer)->sanitize($line);

    expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(0.5)
        ->and($clean)->toBe($line);
});

test('credentials after a word joined to the scheme are redacted too', function () {
    $clean = (new LogSanitizer)->sanitize('remote_https://deploy:s3cr3t-pass@git.example.com/x.git');

    expect($clean)->not->toContain('s3cr3t-pass')
        ->and($clean)->toContain('[REDACTED]@git.example.com');
});

test('common secret shapes are redacted even when not in the environment', function (string $secret, string $line) {
    expect((new LogSanitizer)->sanitize($line))->not->toContain($secret);
})->with([
    ['sk_live_abcdefghij1234567890', 'error using sk_live_abcdefghij1234567890'],
    ['AKIAIOSFODNN7EXAMPLE', 'aws key AKIAIOSFODNN7EXAMPLE failed'],
    ['ghp_abcdefghijklmnopqrstuvwxyz1234', 'token ghp_abcdefghijklmnopqrstuvwxyz1234 rejected'],
]);

test('secrets in s3 urls and stripe webhook secrets are redacted', function (string $line, string $clean) {
    expect((new LogSanitizer)->sanitize($line))->toBe($clean);
})->with([
    'a presigned s3 url' => [
        'curl https://lab-backups.s3.eu-central-1.amazonaws.com/x.sql.gz?X-Amz-Expires=3600&X-Amz-Signature=0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0 failed',
        'curl https://lab-backups.s3.eu-central-1.amazonaws.com/x.sql.gz?X-Amz-Expires=3600&X-Amz-Signature=[REDACTED] failed',
    ],
    'a stripe webhook secret' => ['secret whsec_9q8P7x6W5v4U3t2S1r0Q9p8O7n6M5l4K refused', 'secret [REDACTED_STRIPE_WEBHOOK_SECRET] refused'],
]);

test('stateless installation tokens and fine-grained tokens are redacted', function (string $line, string $clean) {
    expect((new LogSanitizer)->sanitize($line))->toBe($clean);
})->with([
    'a stateless installation token' => ['token ghs_1041207_eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiIxIn0.c2ln rejected', 'token [REDACTED_GITHUB_TOKEN] rejected'],
    'a fine-grained personal token' => ['using github_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyzAB now', 'using [REDACTED_GITHUB_TOKEN] now'],
]);

test('a digitalocean personal access token is redacted', function () {
    $token = 'dop_v1_' . str_repeat('0a1b2c3d', 8);

    expect((new LogSanitizer)->sanitize("token {$token} rejected"))->toBe('token [REDACTED_DIGITALOCEAN_TOKEN] rejected');
});

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

test('a key block as long as the largest rsa key is still redacted whole', function () {
    $lines = array_map(fn(int $line): string => str_repeat(chr(65 + $line % 26), 70), range(1, 181));
    $key = "-----BEGIN OPENSSH PRIVATE KEY-----\n" . implode("\n", $lines) . "\n-----END OPENSSH PRIVATE KEY-----";

    expect(strlen($key))->toBeGreaterThan(12_800)
        ->and((new LogSanitizer)->sanitize("dumped {$key} oops"))->toBe('dumped [REDACTED_PRIVATE_KEY] oops');
});

test('a key cut off before its end marker does not leave its key lines', function () {
    $lines = array_map(fn(int $line): string => str_repeat(chr(65 + $line % 26), 64), range(1, 30));
    $output = "Fatal: could not read the signing key\n-----BEGIN RSA PRIVATE KEY-----\n" . implode("\n", $lines);

    expect((new LogSanitizer)->sanitize($output))->toBe("Fatal: could not read the signing key\n[REDACTED_PRIVATE_KEY]");
});

test('a key with no end marker hides at most 16 KB after it', function () {
    $output = "-----BEGIN PRIVATE KEY-----\n" . str_repeat("x\n", 10_000) . 'boot step 1';

    $clean = (new LogSanitizer)->sanitize($output);

    expect($clean)->toStartWith('[REDACTED_PRIVATE_KEY]')
        ->toEndWith("\nx\nboot step 1")
        ->and(strlen($clean))->toBeLessThan(4_000);
});

test('a long run of key markers with no end is sanitised without stalling', function () {
    $output = str_repeat('-----BEGIN PRIVATE KEY-----', 20_000);
    $started = hrtime(true);

    $clean = (new LogSanitizer)->sanitize($output);

    expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(0.5)
        ->and(strlen($output))->toBe(540_000)
        ->and($clean)->toContain('[REDACTED_PRIVATE_KEY]')
        ->not->toContain('-----BEGIN');
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
