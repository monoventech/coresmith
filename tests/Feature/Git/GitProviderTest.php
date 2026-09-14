<?php

declare(strict_types=1);

use App\Enums\SourceProviderType;
use App\Exceptions\GitProviderException;
use App\Models\SourceProvider;
use App\Services\Git\GitProviderFactory;
use App\Services\Git\ProviderHost;
use App\Services\Git\Providers\BitbucketClient;
use App\Services\Git\Providers\GitHubAppClient;
use App\Services\Git\Providers\GitLabClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function provider(SourceProviderType $type, array $attributes = []): SourceProvider
{
    return SourceProvider::factory()->create(array_merge([
        'type' => $type->value,
        'access_token' => 'token-' . $type->value,
        'token_expires_at' => now()->addHour(),
    ], $attributes));
}

function client(SourceProvider $source): object
{
    return app(GitProviderFactory::class)->for($source);
}

test('each provider type resolves to its own client', function (SourceProviderType $type, string $expected) {
    expect(client(provider($type)))->toBeInstanceOf($expected);
})->with([
    [SourceProviderType::GitHubApp, GitHubAppClient::class],
    [SourceProviderType::GitLab, GitLabClient::class],
    [SourceProviderType::Bitbucket, BitbucketClient::class],
]);

test('a custom provider has no api client because it is cloned over ssh', function () {
    client(provider(SourceProviderType::Custom, ['access_token' => null]));
})->throws(GitProviderException::class);

test('github lists the repositories the app is installed on', function () {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response([
            'total_count' => 2,
            'repositories' => [
                ['full_name' => 'monoven/coresmith', 'ssh_url' => 'git@github.com:monoven/coresmith.git', 'default_branch' => 'main', 'private' => true],
                ['full_name' => 'monoven/site', 'ssh_url' => 'git@github.com:monoven/site.git', 'default_branch' => 'trunk', 'private' => false],
            ],
        ]),
    ]);

    $repositories = client(provider(SourceProviderType::GitHubApp))->listRepositories();

    expect($repositories)->toHaveCount(2)
        ->and($repositories->first()->fullName)->toBe('monoven/coresmith')
        ->and($repositories->first()->sshUrl)->toBe('git@github.com:monoven/coresmith.git')
        ->and($repositories->last()->defaultBranch)->toBe('trunk')
        ->and($repositories->last()->private)->toBeFalse();
});

test('github deploy keys are always created read only', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 42])]);

    $id = client(provider(SourceProviderType::GitHubApp))
        ->createDeployKey('monoven/coresmith', 'ssh-ed25519 AAAA', 'Coresmith — storefront');

    expect($id)->toBe('42');

    Http::assertSent(fn($request) => $request['read_only'] === true);
});

test('github webhooks are created with tls verification on and a secret', function () {
    Http::fake(['api.github.com/*' => Http::response(['id' => 7])]);

    client(provider(SourceProviderType::GitHubApp))
        ->createWebhook('monoven/coresmith', 'https://coresmith.io/webhooks/x', 's3cret');

    Http::assertSent(fn($request) => $request['config']['insecure_ssl'] === '0'
        && $request['config']['secret'] === 's3cret'
        && $request['events'] === ['push']);
});

test('a repository name carrying a path escape is refused before any request', function (string $repository) {
    Http::fake();

    client(provider(SourceProviderType::GitHubApp))->createDeployKey($repository, 'ssh-ed25519 AAAA', 'x');
})->with([
    '../../admin/settings',
    'monoven/coresmith/../../other',
    'monoven',
    'monoven/core smith',
])->throws(GitProviderException::class);

test('a live github token is reused rather than reminted', function () {
    Http::fake(['api.github.com/*' => Http::response(['repositories' => [], 'total_count' => 0])]);

    client(provider(SourceProviderType::GitHubApp, ['token_expires_at' => now()->addHour()]))->listRepositories();

    Http::assertNotSent(fn($request) => str_contains($request->url(), 'access_tokens'));
});

test('an expiring github token is reminted from the app private key', function () {
    Http::fake([
        'api.github.com/app/installations/*' => Http::response(['token' => 'ghs_fresh', 'expires_at' => now()->addHour()->toIso8601String()]),
        'api.github.com/*' => Http::response(['repositories' => [], 'total_count' => 0]),
    ]);

    $source = provider(SourceProviderType::GitHubApp, [
        'token_expires_at' => now()->addMinute(),
        'app_id' => '12345',
        'app_private_key' => openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem) ? $pem : '',
    ]);

    client($source)->listRepositories();

    expect($source->fresh()->access_token)->toBe('ghs_fresh');
    Http::assertSent(fn($request) => str_contains($request->url(), 'access_tokens'));
});

test('a github connection with no app credentials fails loudly instead of clone failing later', function () {
    Http::fake();

    client(provider(SourceProviderType::GitHubApp, ['token_expires_at' => now()->subHour(), 'app_id' => null]))
        ->listRepositories();
})->throws(GitProviderException::class);

test('gitlab refreshes an expiring token and stores the rotated refresh token', function () {
    Http::fake([
        'gitlab.com/oauth/token' => Http::response(['access_token' => 'new-access', 'refresh_token' => 'rotated', 'expires_in' => 7200]),
        'gitlab.com/api/v4/*' => Http::response([]),
    ]);

    $source = provider(SourceProviderType::GitLab, [
        'access_token' => 'stale',
        'refresh_token' => 'original',
        'token_expires_at' => now()->addMinute(),
    ]);

    client($source)->listRepositories();

    expect($source->fresh()->access_token)->toBe('new-access')
        ->and($source->fresh()->refresh_token)->toBe('rotated')
        ->and($source->fresh()->token_expires_at->isFuture())->toBeTrue();
});

test('a gitlab connection that cannot be refreshed tells the user to reconnect', function () {
    Http::fake();

    client(provider(SourceProviderType::GitLab, ['refresh_token' => null, 'token_expires_at' => now()->subHour()]))
        ->listRepositories();
})->throws(GitProviderException::class, 'Reconnect it from Settings → Source providers.');

test('gitlab deploy keys cannot push', function () {
    Http::fake(['gitlab.com/api/v4/*' => Http::response(['id' => 9])]);

    client(provider(SourceProviderType::GitLab))->createDeployKey('group/project', 'ssh-ed25519 AAAA', 'Coresmith');

    Http::assertSent(fn($request) => $request['can_push'] === false);
});

test('gitlab commit states are mapped to gitlab vocabulary', function (string $given, string $expected) {
    Http::fake(['gitlab.com/api/v4/*' => Http::response([])]);

    client(provider(SourceProviderType::GitLab))
        ->updateCommitStatus('group/project', 'abc123', $given, 'https://coresmith.io/d/1', 'Deploying');

    Http::assertSent(fn($request) => $request['state'] === $expected);
})->with([
    ['failure', 'failed'],
    ['error', 'failed'],
    ['pending', 'running'],
    ['success', 'success'],
]);

test('bitbucket prefers the ssh clone link the api returns', function () {
    Http::fake([
        'api.bitbucket.org/*' => Http::response([
            'values' => [[
                'full_name' => 'monoven/coresmith',
                'is_private' => true,
                'mainbranch' => ['name' => 'main'],
                'links' => ['clone' => [
                    ['name' => 'https', 'href' => 'https://bitbucket.org/monoven/coresmith.git'],
                    ['name' => 'ssh', 'href' => 'git@bitbucket.org:monoven/coresmith.git'],
                ]],
            ]],
        ]),
    ]);

    $repositories = client(provider(SourceProviderType::Bitbucket))->listRepositories();

    expect($repositories->first()->sshUrl)->toBe('git@bitbucket.org:monoven/coresmith.git');
});

test('bitbucket commit states are mapped to bitbucket vocabulary', function (string $given, string $expected) {
    Http::fake(['api.bitbucket.org/*' => Http::response([])]);

    client(provider(SourceProviderType::Bitbucket))
        ->updateCommitStatus('monoven/coresmith', 'abc123', $given, 'https://coresmith.io/d/1', 'Deploying');

    Http::assertSent(fn($request) => $request['state'] === $expected);
})->with([
    ['failure', 'FAILED'],
    ['pending', 'INPROGRESS'],
    ['success', 'SUCCESSFUL'],
]);

test('a failing provider response is reported with the reason the provider gave', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    client(provider(SourceProviderType::GitHubApp))->listRepositories();
})->throws(GitProviderException::class, 'Bad credentials');

test('provider tokens are encrypted at rest', function () {
    $source = provider(SourceProviderType::GitLab, ['access_token' => 'plaintext-token', 'refresh_token' => 'plaintext-refresh']);

    $raw = DB::table('source_providers')->where('id', $source->getKey())->first();

    expect($raw->access_token)->not->toContain('plaintext-token')
        ->and($raw->refresh_token)->not->toContain('plaintext-refresh')
        ->and($source->fresh()->access_token)->toBe('plaintext-token');
});

test('a self hosted host must be https and must not point at the local network', function (string $host) {
    ProviderHost::normalise($host);
})->with([
    'http://gitlab.internal',
    'https://localhost',
    'https://127.0.0.1',
    'https://169.254.169.254',
    'https://[::1]',
    'https://metadata.google.internal',
    'gitlab.internal',
])->throws(GitProviderException::class);

test('a legitimate self hosted host is kept and normalised', function (string $given, string $expected) {
    expect(ProviderHost::normalise($given))->toBe($expected);
})->with([
    ['https://gitlab.example.com', 'https://gitlab.example.com'],
    ['https://gitlab.example.com/', 'https://gitlab.example.com'],
    ['https://gitlab.example.com:8443/api/v4', 'https://gitlab.example.com:8443'],
    ['https://git.internal.example.org', 'https://git.internal.example.org'],
]);

test('a host pointing at the metadata service is rejected when saving the model', function () {
    SourceProvider::factory()->create(['type' => 'gitlab', 'custom_host' => 'https://169.254.169.254']);
})->throws(GitProviderException::class);

test('a hostname is rejected when it resolves into a blocked range', function () {
    ProviderHost::normalise('https://localtest.me');
})->throws(GitProviderException::class, 'it resolves to 127.0.0.1');

test('a trailing dot cannot be used to slip past the blocked host list', function () {
    ProviderHost::normalise('https://metadata.google.internal.');
})->throws(GitProviderException::class);

test('a host that cannot be resolved at all is left to fail at request time', function () {
    expect(ProviderHost::normalise('https://gitlab.nonexistent.invalid'))->toBe('https://gitlab.nonexistent.invalid');
});

test('provider api calls do not follow redirects, so a permitted host cannot hop to the metadata service', function () {
    $client = client(provider(SourceProviderType::GitLab));

    $method = new ReflectionMethod($client, 'request');
    $pending = $method->invoke($client);

    $options = new ReflectionProperty($pending, 'options');

    expect($options->getValue($pending))->toHaveKey('allow_redirects', false);
});

test('the token refresh call does not follow redirects either', function () {
    Http::fake([
        'gitlab.com/oauth/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'b', 'expires_in' => 7200]),
        'gitlab.com/api/v4/*' => Http::response([]),
    ]);

    $source = provider(SourceProviderType::GitLab, ['refresh_token' => 'r', 'token_expires_at' => now()->addMinute()]);

    client($source)->listRepositories();

    expect($source->fresh()->access_token)->toBe('a');
});
