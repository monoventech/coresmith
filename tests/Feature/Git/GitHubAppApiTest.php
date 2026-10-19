<?php

declare(strict_types=1);

use App\Enums\SourceProviderType;
use App\Exceptions\GitHubInstallationException;
use App\Exceptions\GitProviderException;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Services\Git\GitHub\AppJwt;
use App\Services\Git\GitHub\GitHubAppApi;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitProviderFactory;
use App\Services\Git\Providers\GitHubAppClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function githubApiCredentials(): GitHubAppCredentials
{
    return app(GitHubApps::class)->fromModel(GitHubApp::factory()->create(['client_id' => 'Iv1.client', 'client_secret' => 'client-secret']));
}

function githubApiConnection(array $provider = []): SourceProvider
{
    return SourceProvider::factory()->githubApp()->create(array_merge([
        'installation_id' => '51842',
        'access_token' => 'ghs_live',
        'token_expires_at' => now()->addHour(),
    ], $provider));
}

function githubApiClient(array $provider = []): GitHubAppClient
{
    return app(GitProviderFactory::class)->github(githubApiConnection($provider));
}

function githubApiClaims(Request $request): array
{
    preg_match('/^Bearer [\w-]+\.([\w-]+)\.[\w-]+$/', $request->header('Authorization')[0] ?? '', $parts);

    return isset($parts[1]) ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true, 512, JSON_THROW_ON_ERROR) : [];
}

function githubApiThrown(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new LogicException('Nothing was thrown.');
}

function githubApiSha(string $character = 'a'): string
{
    return str_repeat($character, 40);
}

function githubApiTrace(Closure $call): Throwable
{
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        return githubApiThrown($call);
    } finally {
        ini_set('zend.exception_ignore_args', is_string($previous) ? $previous : '1');
    }
}

function githubApiTraceExposes(Throwable $exception, string $secret): bool
{
    $exposes = function (mixed $value) use (&$exposes, $secret): bool {
        if ($value instanceof GitHubAppCredentials) {
            $value = get_object_vars($value);
        }

        if (is_string($value)) {
            return str_contains($value, $secret);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($exposes($item)) {
                    return true;
                }
            }
        }

        return false;
    };

    for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
        foreach ($current->getTrace() as $frame) {
            if ($exposes($frame['args'] ?? [])) {
                return true;
            }
        }
    }

    return false;
}

beforeEach(fn() => Http::preventStrayRequests());

test('an installation failure is a provider failure that carries its kind', function () {
    $exception = new GitHubInstallationException('gone', GitHubInstallationException::UNINSTALLED);

    expect($exception)->toBeInstanceOf(GitProviderException::class)
        ->and($exception->getMessage())->toBe('gone')
        ->and($exception->kind)->toBe('uninstalled')
        ->and([
            GitHubInstallationException::UNINSTALLED,
            GitHubInstallationException::SUSPENDED,
            GitHubInstallationException::REPOSITORY,
            GitHubInstallationException::UNREACHABLE,
        ])->toBe(['uninstalled', 'suspended', 'repository', 'unreachable'])
        ->and(GitHubAppApi::API)->toBe('https://api.github.com')
        ->and(GitHubAppApi::WEB)->toBe('https://github.com');
});

test('a manifest code is converted without credentials and every field the panel keeps comes back', function () {
    Http::fake(['api.github.com/app-manifests/abc123/conversions' => Http::response([
        'id' => 1041207, 'slug' => 'coresmith-monoven', 'name' => 'Coresmith Monoven', 'client_id' => 'Iv1.abc',
        'client_secret' => 'cs', 'webhook_secret' => null, 'pem' => "-----BEGIN RSA PRIVATE KEY-----\nx\n-----END RSA PRIVATE KEY-----",
        'owner' => ['login' => 'monoventech', 'type' => 'Organization'], 'html_url' => 'https://github.com/apps/coresmith-monoven',
        'permissions' => ['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'], 'events' => [],
        'node_id' => 'A_kwHOxyz', 'external_url' => 'https://panel.example.com', 'created_at' => '2026-10-18T10:00:00Z',
    ], 201)]);

    $converted = app(GitHubAppApi::class)->convertManifest('abc123');

    expect($converted)->toBe([
        'app_id' => '1041207',
        'slug' => 'coresmith-monoven',
        'name' => 'Coresmith Monoven',
        'client_id' => 'Iv1.abc',
        'client_secret' => 'cs',
        'webhook_secret' => null,
        'pem' => "-----BEGIN RSA PRIVATE KEY-----\nx\n-----END RSA PRIVATE KEY-----",
        'owner_login' => 'monoventech',
        'owner_type' => 'Organization',
        'html_url' => 'https://github.com/apps/coresmith-monoven',
        'permissions' => ['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'],
        'events' => [],
    ]);
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/app-manifests/abc123/conversions'
        && !$request->hasHeader('Authorization')
        && $request->hasHeader('Accept', 'application/vnd.github+json')
        && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
});

test('a conversion keeps the webhook secret and the events GitHub subscribed', function () {
    Http::fake(['api.github.com/app-manifests/abc123/conversions' => Http::response([
        'id' => 7, 'slug' => 'coresmith', 'name' => 'Coresmith', 'client_id' => 'Iv1.abc', 'client_secret' => 'cs', 'webhook_secret' => 'whs',
        'pem' => 'pem', 'owner' => ['login' => 'oseughu', 'type' => 'User'], 'html_url' => 'https://github.com/apps/coresmith',
        'permissions' => ['contents' => 'read'], 'events' => ['push'],
    ], 201)]);

    $converted = app(GitHubAppApi::class)->convertManifest('abc123');

    expect($converted['webhook_secret'])->toBe('whs')
        ->and($converted['events'])->toBe(['push'])
        ->and($converted['owner_type'])->toBe('User');
});

test('manifest codes made of letters, digits, hyphens and underscores are sent as they are', function (string $code) {
    Http::fake(['api.github.com/app-manifests/*' => Http::response([
        'id' => 7, 'slug' => 'coresmith', 'name' => 'Coresmith', 'client_id' => 'Iv1.abc', 'client_secret' => 'cs',
        'pem' => 'pem', 'owner' => ['login' => 'oseughu', 'type' => 'User'], 'html_url' => 'https://github.com/apps/coresmith',
    ], 201)]);

    app(GitHubAppApi::class)->convertManifest($code);

    Http::assertSent(fn($request) => $request->url() === "https://api.github.com/app-manifests/{$code}/conversions");
})->with(['letters and digits' => 'abc123', 'hyphens and underscores' => 'a_b-C9', 'one character' => 'x', 'a hundred characters' => str_repeat('k', 100)]);

test('a manifest code carrying a path is refused before any request', function (string $code) {
    $exception = githubApiThrown(fn() => app(GitHubAppApi::class)->convertManifest($code));

    expect($exception)->toBeInstanceOf(GitProviderException::class)
        ->and($code === '' || !str_contains($exception->getMessage(), $code))->toBeTrue();
    Http::assertNothingSent();
})->with([
    'a parent directory' => '../app',
    'a slash' => 'a/b',
    'a query' => 'x?y',
    'nothing' => '',
    'a space' => 'abc 123',
    'a trailing newline' => "abc123\n",
    'an encoded slash' => 'a%2Fb',
    'a hundred and one characters' => str_repeat('a', 101),
]);

test('a refused conversion explains itself', function () {
    Http::fake(['api.github.com/app-manifests/abc123/conversions' => Http::response(['message' => 'Validation Failed'], 422)]);

    expect(fn() => app(GitHubAppApi::class)->convertManifest('abc123'))->toThrow(GitProviderException::class, 'Validation Failed');
    Http::assertSentCount(1);
});

test('a refusal with no message names the status instead', function () {
    Http::fake(['api.github.com/app-manifests/abc123/conversions' => Http::response('', 404)]);

    expect(fn() => app(GitHubAppApi::class)->convertManifest('abc123'))->toThrow(GitProviderException::class, 'HTTP 404');
});

test('a conversion that is missing a credential is refused instead of saved half empty', function (string $missing) {
    $body = [
        'id' => 7, 'slug' => 'coresmith', 'name' => 'Coresmith', 'client_id' => 'Iv1.abc', 'client_secret' => 'cs',
        'pem' => 'pem', 'owner' => ['login' => 'oseughu', 'type' => 'User'], 'html_url' => 'https://github.com/apps/coresmith',
    ];
    unset($body[$missing]);
    Http::fake(['api.github.com/app-manifests/abc123/conversions' => Http::response($body, 201)]);

    expect(fn() => app(GitHubAppApi::class)->convertManifest('abc123'))->toThrow(GitProviderException::class);
})->with(['id', 'slug', 'client_id', 'client_secret', 'pem']);

test('the app webhook address is read with a jwt and an empty one is null', function (string $configured, ?string $expected) {
    $app = githubApiCredentials();
    Http::fake(['api.github.com/app/hook/config' => Http::response(['url' => $configured, 'content_type' => 'json', 'secret' => '********', 'insecure_ssl' => '0'])]);

    expect(app(GitHubAppApi::class)->hookUrl($app))->toBe($expected);

    Http::assertSent(fn($request) => $request->method() === 'GET'
        && $request->url() === 'https://api.github.com/app/hook/config'
        && preg_match('/^Bearer [\w-]+\.[\w-]+\.[\w-]+$/', $request->header('Authorization')[0] ?? '') === 1
        && githubApiClaims($request)['iss'] === $app->appId
        && $request->hasHeader('Accept', 'application/vnd.github+json')
        && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
})->with([
    'a configured address' => ['https://panel.example.com/api/webhooks/github/01jaj4v0z8x6v3c2c2e8n1q6m7', 'https://panel.example.com/api/webhooks/github/01jaj4v0z8x6v3c2c2e8n1q6m7'],
    'an empty address' => ['', null],
]);

test('an app with no webhook configuration at all has no address', function () {
    Http::fake(['api.github.com/app/hook/config' => Http::response([])]);

    expect(app(GitHubAppApi::class)->hookUrl(githubApiCredentials()))->toBeNull();
});

test('a webhook address github will not read is an error that says why', function () {
    Http::fake(['api.github.com/app/hook/config' => Http::response(['message' => 'A JSON web token could not be decoded'], 401)]);

    expect(fn() => app(GitHubAppApi::class)->hookUrl(githubApiCredentials()))->toThrow(GitProviderException::class, 'A JSON web token could not be decoded');
});

test('the app webhook is replaced with a jwt and a body of exactly four fields', function () {
    $app = githubApiCredentials();
    Http::fake(['api.github.com/app/hook/config' => Http::response(['url' => 'https://panel.example.com/hook', 'content_type' => 'json', 'secret' => '********', 'insecure_ssl' => '0'])]);

    app(GitHubAppApi::class)->updateHook($app, 'https://panel.example.com/hook', 's');

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'PATCH'
        && $request->url() === 'https://api.github.com/app/hook/config'
        && json_decode($request->body(), true) === ['url' => 'https://panel.example.com/hook', 'content_type' => 'json', 'secret' => 's', 'insecure_ssl' => '0']
        && preg_match('/^Bearer [\w-]+\.[\w-]+\.[\w-]+$/', $request->header('Authorization')[0] ?? '') === 1
        && githubApiClaims($request)['iss'] === $app->appId);
});

test('a webhook github will not change says why and does not echo the secret', function () {
    Http::fake(['api.github.com/app/hook/config' => Http::response(['message' => 'Validation Failed'], 422)]);

    $exception = githubApiThrown(fn() => app(GitHubAppApi::class)->updateHook(githubApiCredentials(), 'https://panel.example.com/hook', 'hook-secret-xyz'));

    expect($exception)->toBeInstanceOf(GitProviderException::class)
        ->and($exception->getMessage())->toBe('Validation Failed');
});

test('the newest delivery is reported with its status', function () {
    $app = githubApiCredentials();
    Http::fake(['api.github.com/app/hook/deliveries*' => Http::response([
        ['id' => 1, 'delivered_at' => '2026-10-18T10:00:00Z', 'status' => 'OK', 'status_code' => 200, 'event' => 'ping'],
        ['id' => 3, 'delivered_at' => '2026-10-18T12:30:00Z', 'status' => 'Invalid HTTP Response: 502', 'status_code' => 502, 'event' => 'push', 'action' => null],
        ['id' => 2, 'delivered_at' => '2026-10-18T11:15:00Z', 'status' => 'OK', 'status_code' => 200, 'event' => 'push'],
    ])]);

    $latest = app(GitHubAppApi::class)->latestDelivery($app);

    expect(array_keys($latest))->toBe(['status_code', 'status', 'delivered_at', 'event'])
        ->and($latest['status_code'])->toBe(502)
        ->and($latest['status'])->toBe('Invalid HTTP Response: 502')
        ->and($latest['event'])->toBe('push')
        ->and($latest['delivered_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($latest['delivered_at']->toIso8601ZuluString())->toBe('2026-10-18T12:30:00Z');
    Http::assertSent(fn($request) => $request->method() === 'GET'
        && $request->url() === 'https://api.github.com/app/hook/deliveries?per_page=10'
        && githubApiClaims($request)['iss'] === $app->appId);
});

test('a delivery with no time is never the newest', function () {
    Http::fake(['api.github.com/app/hook/deliveries*' => Http::response([
        ['id' => 1, 'delivered_at' => '2026-10-18T10:00:00Z', 'status' => 'OK', 'status_code' => 200, 'event' => 'ping'],
        ['id' => 2, 'delivered_at' => null, 'status' => 'Pending', 'status_code' => 0, 'event' => 'push'],
        ['id' => 3, 'status' => 'Pending', 'status_code' => 0, 'event' => 'push'],
    ])]);

    $latest = app(GitHubAppApi::class)->latestDelivery(githubApiCredentials());

    expect($latest['event'])->toBe('ping')
        ->and($latest['status_code'])->toBe(200);
});

test('no deliveries at all is null', function () {
    Http::fake(['api.github.com/app/hook/deliveries*' => Http::response([])]);

    expect(app(GitHubAppApi::class)->latestDelivery(githubApiCredentials()))->toBeNull();
});

test('a user code is exchanged with the app\'s client credentials', function () {
    Http::fake(['github.com/login/oauth/access_token' => Http::response([
        'access_token' => 'ghu_user', 'token_type' => 'bearer', 'expires_in' => 28800, 'refresh_token' => 'ghr_refresh', 'scope' => '',
    ])]);

    $token = app(GitHubAppApi::class)->exchangeUserCode(githubApiCredentials(), 'c0de', 'https://panel.example.com/settings/source-providers/github_app/callback');

    expect($token)->toBe('ghu_user');
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'POST'
        && $request->url() === 'https://github.com/login/oauth/access_token'
        && $request->isForm()
        && $request->data() === [
            'client_id' => 'Iv1.client',
            'client_secret' => 'client-secret',
            'code' => 'c0de',
            'redirect_uri' => 'https://panel.example.com/settings/source-providers/github_app/callback',
        ]
        && $request->header('Accept') === ['application/json']
        && !$request->hasHeader('Authorization'));
});

test('a user code github will not exchange throws with the reason it gave', function (int $status, array $body, string $message) {
    Http::fake(['github.com/login/oauth/access_token' => Http::response($body, $status)]);

    $exception = githubApiThrown(fn() => app(GitHubAppApi::class)->exchangeUserCode(githubApiCredentials(), 'usercode456', 'https://panel.example.com/cb'));

    expect($exception)->toBeInstanceOf(GitProviderException::class)
        ->and($exception->getMessage())->toBe($message)
        ->and($exception->getMessage())->not->toContain('usercode456')
        ->and($exception->getMessage())->not->toContain('client-secret');
    Http::assertSentCount(1);
})->with([
    'an error in a 200' => [200, ['error' => 'bad_verification_code', 'error_description' => 'The code passed is incorrect or expired.', 'error_uri' => 'https://docs.github.com'], 'The code passed is incorrect or expired.'],
    'an error code alone' => [200, ['error' => 'bad_verification_code'], 'bad_verification_code'],
    'a 200 with no token' => [200, ['token_type' => 'bearer'], 'GitHub did not send an access token.'],
    'a server error' => [500, ['message' => 'Server Error'], 'Server Error'],
    'a server error with no body' => [502, [], 'HTTP 502'],
]);

test('installations the user can reach are listed with their account', function () {
    Http::fake(['api.github.com/user/installations*' => Http::response(['total_count' => 2, 'installations' => [
        ['id' => 51842, 'app_id' => 1041207, 'account' => ['login' => 'monoventech', 'type' => 'Organization', 'id' => 9], 'repository_selection' => 'selected', 'suspended_at' => null],
        ['id' => 51900, 'app_id' => 1041207, 'account' => ['login' => 'oseughu', 'type' => 'User', 'id' => 10], 'repository_selection' => 'all', 'suspended_at' => '2026-10-17T08:00:00Z'],
    ]])]);

    $installations = app(GitHubAppApi::class)->userInstallations('ghu_user');

    expect($installations)->toBe([
        ['id' => '51842', 'account' => 'monoventech', 'account_type' => 'Organization', 'repository_selection' => 'selected', 'suspended_at' => null],
        ['id' => '51900', 'account' => 'oseughu', 'account_type' => 'User', 'repository_selection' => 'all', 'suspended_at' => '2026-10-17T08:00:00Z'],
    ]);
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'GET'
        && $request->url() === 'https://api.github.com/user/installations?per_page=100'
        && $request->hasHeader('Authorization', 'Bearer ghu_user')
        && $request->hasHeader('Accept', 'application/vnd.github+json')
        && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
});

test('a user with no installations gets an empty list and a refusal is an error', function () {
    Http::fake(['api.github.com/user/installations*' => Http::sequence()
        ->push(['total_count' => 0, 'installations' => []])
        ->push(['message' => 'Bad credentials'], 401)]);

    expect(app(GitHubAppApi::class)->userInstallations('ghu_user'))->toBe([])
        ->and(fn() => app(GitHubAppApi::class)->userInstallations('ghu_user'))->toThrow(GitProviderException::class, 'Bad credentials');
});

test('a user token is revoked with the app credentials', function () {
    Http::fake(['api.github.com/applications/Iv1.client/token' => Http::response(null, 204)]);

    app(GitHubAppApi::class)->revokeUserToken(githubApiCredentials(), 'ghu_user');

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/applications/Iv1.client/token'
        && $request->hasHeader('Authorization', 'Basic ' . base64_encode('Iv1.client:client-secret'))
        && json_decode($request->body(), true) === ['access_token' => 'ghu_user']);
});

test('a user token github has already forgotten does not stop the caller', function () {
    Http::fake(['api.github.com/applications/Iv1.client/token' => Http::response(['message' => 'Not Found'], 404)]);

    app(GitHubAppApi::class)->revokeUserToken(githubApiCredentials(), 'ghu_user');

    Http::assertSentCount(1);
});

test('a scoped token reads one repository and nothing else', function () {
    $app = githubApiCredentials();
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response([
        'token' => 'ghs_scoped', 'expires_at' => '2026-10-18T15:30:00Z', 'permissions' => ['contents' => 'read'], 'repository_selection' => 'selected',
    ], 201)]);

    $minted = app(GitHubAppApi::class)->mintToken($app, '51842', ['repository_ids' => [70000001], 'permissions' => ['contents' => 'read']]);

    expect(array_keys($minted))->toBe(['token', 'expires_at'])
        ->and($minted['token'])->toBe('ghs_scoped')
        ->and($minted['expires_at'])->toBeInstanceOf(CarbonImmutable::class)
        ->and($minted['expires_at']->toIso8601ZuluString())->toBe('2026-10-18T15:30:00Z');
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/app/installations/51842/access_tokens'
        && $request->body() === '{"repository_ids":[70000001],"permissions":{"contents":"read"}}'
        && preg_match('/^Bearer [\w-]+\.[\w-]+\.[\w-]+$/', $request->header('Authorization')[0] ?? '') === 1
        && githubApiClaims($request)['iss'] === $app->appId);
});

test('a token with no scope is requested with no body at all', function () {
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response(['token' => 'ghs_wide', 'expires_at' => '2026-10-18T15:30:00Z'], 201)]);

    app(GitHubAppApi::class)->mintToken(githubApiCredentials(), '51842');

    Http::assertSent(fn($request) => $request->method() === 'POST' && $request->body() === '');
});

test('a refused token says why', function (int $status, array $body, array $headers, string $kind) {
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response($body, $status, $headers)]);

    $exception = githubApiThrown(fn() => app(GitHubAppApi::class)->mintToken(githubApiCredentials(), '51842'));

    expect($exception)->toBeInstanceOf(GitHubInstallationException::class)
        ->and($exception)->toBeInstanceOf(GitProviderException::class)
        ->and($exception->kind)->toBe($kind)
        ->and($exception->getMessage())->toContain($body['message'] ?? 'HTTP ' . $status);
    Http::assertSentCount(1);
})->with([
    'uninstalled' => [404, ['message' => 'Not Found'], [], 'uninstalled'],
    'suspended' => [403, ['message' => 'This installation has been suspended'], [], 'suspended'],
    'a repository that is not granted' => [422, ['message' => 'The repository with id 70000001 is not accessible to this installation'], [], 'repository'],
    'a server error' => [500, ['message' => 'Server Error'], [], 'unreachable'],
    'a bad token for the app' => [401, ['message' => 'A JSON web token could not be decoded'], [], 'unreachable'],
    'too many requests' => [429, ['message' => 'Too Many Requests'], [], 'unreachable'],
    'no message at all' => [503, [], [], 'unreachable'],
    'a primary rate limit' => [403, ['message' => 'API rate limit exceeded for installation ID 51842.'], [], 'unreachable'],
    'a secondary rate limit' => [403, ['message' => 'You have exceeded a secondary rate limit. Please wait a few minutes before you try again.'], [], 'unreachable'],
    'a forbidden with a retry after' => [403, ['message' => 'Forbidden'], ['Retry-After' => '60'], 'unreachable'],
    'a forbidden with no calls left' => [403, ['message' => 'Forbidden'], ['X-RateLimit-Remaining' => '0'], 'unreachable'],
]);

test('a token request that gets no answer is unreachable', function () {
    Http::fake(fn() => throw new ConnectionException('down'));

    $exception = githubApiThrown(fn() => app(GitHubAppApi::class)->mintToken(githubApiCredentials(), '51842'));

    expect($exception)->toBeInstanceOf(GitHubInstallationException::class)
        ->and($exception->kind)->toBe('unreachable')
        ->and($exception->getMessage())->toBe('GitHub did not answer.');
});

test('an answer with no token in it is unreachable rather than a token to keep', function (array $body) {
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response($body, 201)]);

    $exception = githubApiThrown(fn() => app(GitHubAppApi::class)->mintToken(githubApiCredentials(), '51842'));

    expect($exception)->toBeInstanceOf(GitHubInstallationException::class)
        ->and($exception->kind)->toBe('unreachable');
})->with([
    'no token' => [['expires_at' => '2026-10-18T15:30:00Z']],
    'an empty token' => [['token' => '', 'expires_at' => '2026-10-18T15:30:00Z']],
    'no expiry' => [['token' => 'ghs_x']],
]);

test('an installation id that is not a number is refused before any request', function (string $installation) {
    $exception = githubApiThrown(fn() => app(GitHubAppApi::class)->mintToken(githubApiCredentials(), $installation));

    expect($exception)->toBeInstanceOf(GitProviderException::class)
        ->and($exception)->not->toBeInstanceOf(GitHubInstallationException::class);
    Http::assertNothingSent();
})->with([
    'letters' => 'abc',
    'a path' => '51842/../x',
    'a query' => '51842?x=1',
    'nothing' => '',
    'a space' => '518 42',
    'a trailing newline' => "51842\n",
    'a negative number' => '-5',
    'twenty one digits' => '123456789012345678901',
]);

test('an installation id of up to twenty digits is sent as it is', function () {
    Http::fake(['api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => '2026-10-18T15:30:00Z'], 201)]);

    app(GitHubAppApi::class)->mintToken(githubApiCredentials(), '12345678901234567890');

    Http::assertSent(fn($request) => $request->url() === 'https://api.github.com/app/installations/12345678901234567890/access_tokens');
});

test('an installation token is revoked with that token and not the connection\'s own', function () {
    Http::fake(['api.github.com/installation/token' => Http::response(null, 204)]);

    app(GitHubAppApi::class)->revokeToken('ghs_to_revoke');

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.github.com/installation/token'
        && $request->hasHeader('Authorization', 'Bearer ghs_to_revoke')
        && $request->hasHeader('Accept', 'application/vnd.github+json')
        && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
});

test('a token github has already expired does not stop the revoke', function () {
    Http::fake(['api.github.com/installation/token' => Http::response(['message' => 'Bad credentials'], 401)]);

    app(GitHubAppApi::class)->revokeToken('ghs_expired');

    Http::assertSentCount(1);
});

test('a revoke that gets no answer is a provider error with no token in it', function () {
    Http::fake(fn() => throw new ConnectionException('down'));

    $exception = githubApiThrown(fn() => app(GitHubAppApi::class)->revokeToken('ghs_to_revoke'));

    expect($exception)->toBeInstanceOf(GitProviderException::class)
        ->and($exception->getMessage())->toBe('GitHub did not answer.');
});

test('a request to github that gets no answer is a provider error for every app level call', function () {
    Http::fake(fn() => throw new ConnectionException('down'));
    $api = app(GitHubAppApi::class);
    $app = githubApiCredentials();

    foreach ([
        fn() => $api->convertManifest('abc123'),
        fn() => $api->hookUrl($app),
        fn() => $api->updateHook($app, 'https://panel.example.com/hook', 's'),
        fn() => $api->latestDelivery($app),
        fn() => $api->exchangeUserCode($app, 'c0de', 'https://panel.example.com/cb'),
        fn() => $api->userInstallations('ghu_user'),
        fn() => $api->revokeUserToken($app, 'ghu_user'),
        fn() => $api->revokeToken('ghs_x'),
    ] as $call) {
        $exception = githubApiThrown($call);

        expect($exception)->toBeInstanceOf(GitProviderException::class)
            ->and($exception->getMessage())->toBe('GitHub did not answer.');
    }
});

test('an unanswered call is a provider error with one sentence wherever it is made', function () {
    Http::fake(['api.github.com/*' => Http::response(['ok' => true])]);
    $api = app(GitHubAppApi::class);

    $answered = $api->call(fn() => Http::get('https://api.github.com/zen'));
    $silent = githubApiThrown(fn() => $api->call(fn() => throw new ConnectionException('down')));
    $other = githubApiThrown(fn() => $api->call(fn() => throw new RuntimeException('boom')));

    expect($answered->json('ok'))->toBeTrue()
        ->and($silent)->toBeInstanceOf(GitProviderException::class)
        ->and($silent->getMessage())->toBe('GitHub did not answer.')
        ->and($silent->getPrevious())->toBeNull()
        ->and($other)->toBeInstanceOf(RuntimeException::class)
        ->and($other)->not->toBeInstanceOf(GitProviderException::class)
        ->and($other->getMessage())->toBe('boom');
});

test('the installation client and the api say a refusal in the same words', function () {
    $refusal = ['error' => 'access_denied', 'error_description' => 'The token was revoked'];
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response($refusal, 401),
        'api.github.com/repos/monoven/atlas/commits/heads/main' => Http::response($refusal, 403),
        'api.github.com/app/hook/config' => Http::response($refusal, 401),
    ]);
    $client = githubApiClient();

    expect(githubApiThrown(fn() => $client->installationRepositories())->getMessage())->toBe('GitHub rejected the request: The token was revoked')
        ->and(githubApiThrown(fn() => $client->branchHead('monoven/atlas', 'main', null))->getMessage())->toBe('GitHub refused the check. The token was revoked')
        ->and(githubApiThrown(fn() => app(GitHubAppApi::class)->hookUrl(githubApiCredentials()))->getMessage())->toBe('The token was revoked');
});

test('the installation client sends the media type, api version and timeout the api does', function () {
    $seen = [];
    Http::fake(function (Request $request, array $options) use (&$seen) {
        $seen[] = [$request->header('Accept'), $request->header('X-GitHub-Api-Version'), $request->header('Authorization'), $options['timeout'] ?? null];

        return Http::response([], 200);
    });
    $client = githubApiClient();

    $client->branches('monoven/atlas');
    $client->postDeployStatus('monoven/atlas', githubApiSha(), 'success', 'https://panel.test/d', 'Live', 'coresmith/atlas');
    $client->deleteWebhook('monoven/atlas', '12');
    $client->installationRepositories();

    expect($seen)->toHaveCount(4);

    foreach ($seen as [$accept, $version, $authorization, $timeout]) {
        expect($accept)->toBe(['application/vnd.github+json'])
            ->and($version)->toBe(['2022-11-28'])
            ->and($authorization)->toBe(['Bearer ghs_live'])
            ->and($timeout)->toBe(20);
    }
});

test('every request waits at most twenty seconds and a post is never sent twice', function () {
    $timeouts = [];
    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response(['message' => 'Server Error'], 500);
    });
    $api = app(GitHubAppApi::class);
    $app = githubApiCredentials();

    foreach ([
        fn() => $api->convertManifest('abc123'),
        fn() => $api->hookUrl($app),
        fn() => $api->updateHook($app, 'https://panel.example.com/hook', 's'),
        fn() => $api->latestDelivery($app),
        fn() => $api->exchangeUserCode($app, 'c0de', 'https://panel.example.com/cb'),
        fn() => $api->userInstallations('ghu_user'),
        fn() => $api->revokeUserToken($app, 'ghu_user'),
        fn() => $api->mintToken($app, '51842'),
        fn() => $api->revokeToken('ghs_x'),
    ] as $call) {
        try {
            $call();
        } catch (GitProviderException) {
        }
    }

    expect($timeouts)->toHaveCount(9)
        ->and(array_unique($timeouts))->toBe([20]);
    Http::assertSentCount(9);
});

test('no credential reaches an exception message', function (string $failure) {
    $app = githubApiCredentials();
    $api = app(GitHubAppApi::class);
    $secrets = ['manifestcode123', 'hook-secret-xyz', 'usercode456', 'ghu_secret_user', 'ghs_secret_install', 'client-secret', 'PRIVATE KEY', $app->privateKey, $app->clientSecret];
    Http::fake($failure === 'silence'
        ? fn() => throw new ConnectionException('down')
        : fn() => Http::response(['message' => 'boom'], 500));
    $messages = [];

    foreach ([
        fn() => $api->convertManifest('manifestcode123'),
        fn() => $api->updateHook($app, 'https://panel.example.com/hook', 'hook-secret-xyz'),
        fn() => $api->exchangeUserCode($app, 'usercode456', 'https://panel.example.com/cb'),
        fn() => $api->userInstallations('ghu_secret_user'),
        fn() => $api->mintToken($app, '51842'),
        fn() => $api->revokeToken('ghs_secret_install'),
    ] as $call) {
        try {
            $call();
        } catch (GitProviderException $exception) {
            $messages[] = $exception->getMessage();
        }
    }

    expect(count($messages))->toBeGreaterThanOrEqual(5);

    foreach ($messages as $message) {
        foreach ($secrets as $secret) {
            expect($message)->not->toContain($secret);
        }
    }
})->with(['a server error' => 'error', 'silence' => 'silence']);

test('a refused webhook leaves the app credentials and the new secret out of the trace', function () {
    Http::fake(['api.github.com/app/hook/config' => Http::response(['message' => 'Validation Failed'], 422)]);
    $app = githubApiCredentials();

    $failure = githubApiTrace(fn() => app(GitHubAppApi::class)->updateHook($app, 'https://panel.example.com/hook', 'hook-secret-xyz'));
    $frame = collect($failure->getTrace())->firstWhere('function', 'updateHook');

    expect($failure)->toBeInstanceOf(GitProviderException::class)
        ->and($frame['args'][0])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($frame['args'][1])->toBe('https://panel.example.com/hook')
        ->and($frame['args'][2])->toBeInstanceOf(SensitiveParameterValue::class);

    foreach ([explode("\n", $app->privateKey)[1], $app->clientSecret, 'hook-secret-xyz'] as $secret) {
        expect(githubApiTraceExposes($failure, $secret))->toBeFalse();
    }
});

test('an unreadable app key never reaches the trace of the failure it causes', function () {
    $app = new GitHubAppCredentials(
        'env', '1041207', 'coresmith', 'Coresmith', 'Iv1.client', 'CLIENTSECRETMARKER',
        "-----BEGIN PRIVATE KEY-----\nUNREADABLEKEYMARKER0a1b2c3d\n-----END PRIVATE KEY-----", 'WEBHOOKSECRETMARKER', null, null, null, null,
    );

    $failure = githubApiTrace(fn() => app(AppJwt::class)->for($app));
    $control = githubApiTrace(function () use ($app) {
        (function (GitHubAppCredentials $unmarked) {
            throw new GitProviderException('unmarked');
        })($app);
    });

    expect($failure)->toBeInstanceOf(GitProviderException::class)
        ->and($failure->getMessage())->toBe('This GitHub connection\'s App private key could not be read. Check the App credentials.')
        ->and($failure->getPrevious())->toBeNull()
        ->and($failure->getTrace()[0]['function'])->toBe('for')
        ->and($failure->getTrace()[0]['args'][0])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and(githubApiTraceExposes($control, 'UNREADABLEKEYMARKER'))->toBeTrue();

    foreach (['UNREADABLEKEYMARKER', 'BEGIN PRIVATE KEY', 'CLIENTSECRETMARKER', 'WEBHOOKSECRETMARKER'] as $secret) {
        expect(githubApiTraceExposes($failure, $secret))->toBeFalse();
    }
});

test('the credentials constructor keeps its secrets out of the trace of its own failure', function () {
    $failure = githubApiTrace(fn() => new GitHubAppCredentials(
        'env', '1041207', 'coresmith', 'Coresmith', 'Iv1.client', 'CLIENTSECRETMARKER', 'PRIVATEKEYMARKER', 'WEBHOOKSECRETMARKER', null, null, null, 12345,
    ));
    $arguments = $failure->getTrace()[0]['args'];

    expect($failure)->toBeInstanceOf(TypeError::class)
        ->and($failure->getTrace()[0]['function'])->toBe('__construct')
        ->and($arguments)->toHaveCount(12)
        ->and($arguments[0])->toBe('env')
        ->and($arguments[4])->toBe('Iv1.client')
        ->and($arguments[5])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($arguments[6])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($arguments[7])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($arguments[11])->toBe(12345);

    foreach (['CLIENTSECRETMARKER', 'PRIVATEKEYMARKER', 'WEBHOOKSECRETMARKER'] as $secret) {
        expect(githubApiTraceExposes($failure, $secret))->toBeFalse();
    }
});

test('every parameter that carries a credential is marked sensitive', function () {
    $named = ['code', 'secret', 'token', 'userToken', 'clientSecret', 'webhookSecret', 'privateKey', 'payload'];
    $unmarked = [];

    foreach ([GitHubAppApi::class, GitHubAppClient::class, AppJwt::class, GitHubAppCredentials::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $carries = ($type instanceof ReflectionNamedType && $type->getName() === GitHubAppCredentials::class)
                    || in_array($parameter->getName(), $named, true);

                if ($carries && $parameter->getAttributes(SensitiveParameter::class) === []) {
                    $unmarked[] = "{$class}::{$method->getName()} \${$parameter->getName()}";
                }
            }
        }
    }

    expect($unmarked)->toBe([]);
});

test('installation repositories stop at the page limit and report the total', function () {
    $page = fn(int $first, int $last, string $month): array => ['total_count' => 2345, 'repositories' => array_map(fn(int $i): array => [
        'id' => 70000000 + $i,
        'full_name' => "monoven/repo-{$i}",
        'private' => $i % 2 === 0,
        'default_branch' => $i % 3 === 0 ? 'trunk' : 'main',
        'archived' => $i % 10 === 0,
        'pushed_at' => $i === 200 ? null : sprintf('2026-%s-01T%02d:%02d:00Z', $month, intdiv($i, 60), $i % 60),
        'ssh_url' => "git@github.com:monoven/repo-{$i}.git",
        'owner' => ['login' => 'monoven'],
    ], range($first, $last))];
    Http::fake(['api.github.com/installation/repositories*' => Http::sequence()
        ->push($page(1, 100, '03'))
        ->push($page(101, 200, '09'))]);

    $result = githubApiClient()->installationRepositories(maxPages: 2);

    expect($result['total'])->toBe(2345)
        ->and($result['repositories'])->toHaveCount(200)
        ->and(array_keys($result))->toBe(['total', 'repositories'])
        ->and($result['repositories'][0])->toBe([
            'id' => '70000199',
            'full_name' => 'monoven/repo-199',
            'private' => false,
            'default_branch' => 'main',
            'archived' => false,
            'pushed_at' => '2026-09-01T03:19:00Z',
        ])
        ->and(array_column($result['repositories'], 'id')[199])->toBe('70000200')
        ->and($result['repositories'][199]['pushed_at'])->toBeNull()
        ->and($result['repositories'][99]['id'])->toBe('70000100')
        ->and(collect($result['repositories'])->pluck('id')->every(fn($id): bool => is_string($id)))->toBeTrue();

    $pushed = array_filter(array_column($result['repositories'], 'pushed_at'));
    $sorted = $pushed;
    rsort($sorted);

    expect(array_values($pushed))->toBe($sorted);
    Http::assertSentCount(2);
    Http::assertSent(fn($request) => $request->url() === 'https://api.github.com/installation/repositories?per_page=100&page=1');
    Http::assertSent(fn($request) => $request->url() === 'https://api.github.com/installation/repositories?per_page=100&page=2');
});

test('installation repositories stop once the total has been read', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(['total_count' => 2, 'repositories' => [
        ['id' => 1, 'full_name' => 'monoven/a', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => '2026-10-01T00:00:00Z'],
        ['id' => 2, 'full_name' => 'monoven/b', 'private' => false, 'default_branch' => 'trunk', 'archived' => true, 'pushed_at' => '2026-10-02T00:00:00Z'],
    ]])]);

    $result = githubApiClient()->installationRepositories();

    expect($result['total'])->toBe(2)
        ->and(array_column($result['repositories'], 'full_name'))->toBe(['monoven/b', 'monoven/a'])
        ->and($result['repositories'][0]['archived'])->toBeTrue();
    Http::assertSentCount(1);
});

test('ten pages are read by default and no more', function () {
    $page = ['total_count' => 2345, 'repositories' => array_map(fn(int $i): array => [
        'id' => $i, 'full_name' => "monoven/repo-{$i}", 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => null,
    ], range(1, 100))];
    Http::fake(['api.github.com/installation/repositories*' => Http::response($page)]);

    $result = githubApiClient()->installationRepositories();

    expect($result['repositories'])->toHaveCount(1000)
        ->and($result['total'])->toBe(2345);
    Http::assertSentCount(10);
    Http::assertSent(fn($request) => str_ends_with($request->url(), 'page=10'));
    Http::assertNotSent(fn($request) => str_ends_with($request->url(), 'page=11'));
});

test('an installation that can see nothing has no repositories', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(['total_count' => 0, 'repositories' => []])]);

    expect(githubApiClient()->installationRepositories())->toBe(['total' => 0, 'repositories' => []]);
    Http::assertSentCount(1);
});

test('the contract lists repositories newest first through the same call', function () {
    Http::fake(['api.github.com/installation/repositories*' => Http::response(['total_count' => 2, 'repositories' => [
        ['id' => 1, 'full_name' => 'monoven/old', 'private' => true, 'default_branch' => 'main', 'archived' => false, 'pushed_at' => '2026-01-01T00:00:00Z'],
        ['id' => 2, 'full_name' => 'monoven/new', 'private' => false, 'default_branch' => 'trunk', 'archived' => false, 'pushed_at' => '2026-06-01T00:00:00Z'],
    ]])]);

    $repositories = githubApiClient()->listRepositories();

    expect($repositories->map->fullName->all())->toBe(['monoven/new', 'monoven/old'])
        ->and($repositories->first()->sshUrl)->toBe('git@github.com:monoven/new.git')
        ->and($repositories->first()->defaultBranch)->toBe('trunk')
        ->and($repositories->first()->private)->toBeFalse();
});

test('branches are the first hundred names GitHub lists', function () {
    Http::fake(['api.github.com/repos/monoven/atlas/branches*' => Http::response([
        ['name' => 'main', 'commit' => ['sha' => githubApiSha(), 'url' => 'https://api.github.com/x'], 'protected' => true],
        ['name' => 'release/2.0', 'commit' => ['sha' => githubApiSha('b')], 'protected' => false],
        ['name' => 'feature/login-form', 'commit' => ['sha' => githubApiSha('c')], 'protected' => false],
    ])]);

    expect(githubApiClient()->branches('monoven/atlas'))->toBe(['main', 'release/2.0', 'feature/login-form']);
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'GET'
        && $request->url() === 'https://api.github.com/repos/monoven/atlas/branches?per_page=100'
        && $request->hasHeader('Authorization', 'Bearer ghs_live'));
});

test('a repository with no branches has an empty list', function () {
    Http::fake(['api.github.com/repos/monoven/atlas/branches*' => Http::response([])]);

    expect(githubApiClient()->branches('monoven/atlas'))->toBe([]);
});

test('a repository whose branches github will not list says why', function () {
    Http::fake(['api.github.com/repos/monoven/atlas/branches*' => Http::response(['message' => 'Not Found'], 404)]);

    expect(fn() => githubApiClient()->branches('monoven/atlas'))->toThrow(GitProviderException::class, 'Not Found');
});

test('a repository name that climbs out of its path is refused before any request', function (string $repository) {
    $client = githubApiClient();

    foreach ([
        fn() => $client->branches($repository),
        fn() => $client->branchHead($repository, 'main', null),
        fn() => $client->postDeployStatus($repository, githubApiSha(), 'success', 'https://panel.test/d', 'Live', 'coresmith/atlas'),
    ] as $call) {
        expect(githubApiThrown($call))->toBeInstanceOf(GitProviderException::class);
    }

    Http::assertNothingSent();
})->with(['../x', 'x/..', '../..', './.', 'monoven', "monoven/atlas\n", 'monoven/at las', 'monoven/atlas/extra']);

test('branch heads with slashes keep their slashes in the path', function () {
    Http::fake(['api.github.com/repos/monoven/atlas/commits/heads/*' => Http::response(githubApiSha('c'))]);

    $head = githubApiClient()->branchHead('monoven/atlas', 'release/2.0', null);

    expect($head)->toBe(githubApiSha('c'));
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'GET'
        && $request->url() === 'https://api.github.com/repos/monoven/atlas/commits/heads/release/2.0'
        && $request->header('Accept') === ['application/vnd.github.sha']
        && !$request->hasHeader('If-None-Match')
        && $request->hasHeader('Authorization', 'Bearer ghs_live')
        && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
});

test('a branch name is encoded one segment at a time', function () {
    Http::fake(['api.github.com/repos/monoven/atlas/commits/heads/*' => Http::response(githubApiSha())]);

    githubApiClient()->branchHead('monoven/atlas', 'feature/fix #12 & more?', null);

    Http::assertSent(fn($request) => $request->url() === 'https://api.github.com/repos/monoven/atlas/commits/heads/feature/fix%20%2312%20%26%20more%3F');
});

test('a branch name that would climb out of the path is refused before any request', function (string $branch) {
    expect(githubApiThrown(fn() => githubApiClient()->branchHead('monoven/atlas', $branch, null)))->toBeInstanceOf(GitProviderException::class);
    Http::assertNothingSent();
})->with(['a parent directory' => '..', 'a nested parent' => 'release/../../x', 'a dot' => '.', 'an empty segment' => 'release//2.0', 'nothing' => '', 'a leading slash' => '/main', 'a trailing slash' => 'main/']);

test('an unchanged branch head is a not modified', function () {
    $known = githubApiSha('d');
    Http::fake(['api.github.com/repos/monoven/atlas/commits/heads/main' => Http::response('', 304)]);

    expect(githubApiClient()->branchHead('monoven/atlas', 'main', $known))->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->hasHeader('If-None-Match', '"' . $known . '"')
        && $request->header('Accept') === ['application/vnd.github.sha']);
});

test('a known sha that is not a sha is not sent as a validator', function (string $known) {
    Http::fake(['api.github.com/repos/monoven/atlas/commits/heads/main' => Http::response(githubApiSha())]);

    githubApiClient()->branchHead('monoven/atlas', 'main', $known);

    Http::assertSent(fn($request) => !$request->hasHeader('If-None-Match'));
})->with(['empty' => '', 'short' => 'abc123', 'header injection' => "abc\r\nX-Evil: 1", 'uppercase' => 'ABCDEFABCDEFABCDEFABCDEFABCDEFABCDEFABCD']);

test('a moved branch head returns the new commit', function () {
    $new = githubApiSha('e');
    Http::fake(['api.github.com/repos/monoven/atlas/commits/heads/main' => Http::response($new . "\n")]);

    expect(githubApiClient()->branchHead('monoven/atlas', 'main', githubApiSha('d')))->toBe($new);
    Http::assertSent(fn($request) => $request->hasHeader('If-None-Match', '"' . githubApiSha('d') . '"'));
});

test('an answer that is not a commit sha throws', function (string $body) {
    Http::fake(['api.github.com/repos/monoven/atlas/commits/heads/main' => Http::response($body)]);

    $exception = githubApiThrown(fn() => githubApiClient()->branchHead('monoven/atlas', 'main', null));

    expect($exception)->toBeInstanceOf(GitProviderException::class)
        ->and($exception->getMessage())->toBe('GitHub did not answer.');
})->with(['html' => '<html>Sign in</html>', 'json' => '{"sha":"abc"}', 'short' => 'abc123', 'empty' => '', 'too long' => str_repeat('a', 41)]);

test('a missing branch, a refusal and silence each say what happened', function (int|string $outcome, array $body, string $message) {
    Http::fake($outcome === 'silence'
        ? fn() => throw new ConnectionException('down')
        : ['api.github.com/repos/monoven/atlas/commits/heads/main' => Http::response($body, (int) $outcome)]);

    $exception = githubApiThrown(fn() => githubApiClient()->branchHead('monoven/atlas', 'main', githubApiSha()));

    expect($exception)->toBeInstanceOf(GitProviderException::class)
        ->and($exception)->not->toBeInstanceOf(GitHubInstallationException::class)
        ->and($exception->getMessage())->toBe($message);

    if ($outcome !== 'silence') {
        Http::assertSentCount(1);
    }
})->with([
    'a missing branch' => [404, ['message' => 'Not Found'], 'GitHub has no branch main in monoven/atlas that this app can see.'],
    'a ref github cannot resolve' => [422, ['message' => 'No commit found for SHA: heads/main'], 'GitHub has no branch main in monoven/atlas that this app can see.'],
    'a refusal' => [403, ['message' => 'Resource not accessible by integration'], 'GitHub refused the check. Resource not accessible by integration'],
    'a bad token' => [401, ['message' => 'Bad credentials'], 'GitHub refused the check. Bad credentials'],
    'a server error with no message' => [500, [], 'GitHub refused the check. HTTP 500'],
    'silence' => ['silence', [], 'GitHub did not answer.'],
]);

test('a deploy status names its own context', function () {
    $sha = githubApiSha('f');
    Http::fake(['api.github.com/repos/monoven/atlas/statuses/*' => Http::response(['id' => 1, 'state' => 'success'], 201)]);

    githubApiClient()->postDeployStatus('monoven/atlas', $sha, 'success', 'https://panel.test/d', 'Live on forge-01 as 20261018-1402', 'coresmith/atlas');

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'POST'
        && $request->url() === "https://api.github.com/repos/monoven/atlas/statuses/{$sha}"
        && json_decode($request->body(), true) === [
            'state' => 'success',
            'target_url' => 'https://panel.test/d',
            'description' => 'Live on forge-01 as 20261018-1402',
            'context' => 'coresmith/atlas',
        ]
        && $request->hasHeader('Authorization', 'Bearer ghs_live'));
});

test('a deploy status github refuses is an error and is not sent twice', function () {
    Http::fake(['api.github.com/repos/monoven/atlas/statuses/*' => Http::response(['message' => 'Validation Failed'], 422)]);

    expect(fn() => githubApiClient()->postDeployStatus('monoven/atlas', githubApiSha(), 'success', 'https://panel.test/d', 'Live', 'coresmith/atlas'))
        ->toThrow(GitProviderException::class, 'Validation Failed');
    Http::assertSentCount(1);
});

test('the contract posts its commit status under the deploy context', function () {
    Http::fake(['api.github.com/repos/monoven/atlas/statuses/*' => Http::response(['id' => 1], 201)]);

    githubApiClient()->updateCommitStatus('monoven/atlas', githubApiSha(), 'pending', 'https://panel.test/d', 'Deploying to forge-01');

    Http::assertSent(fn($request) => json_decode($request->body(), true) === [
        'state' => 'pending',
        'target_url' => 'https://panel.test/d',
        'description' => 'Deploying to forge-01',
        'context' => 'coresmith/deploy',
    ]);
});

test('the contract reads the latest commit through the branch head', function () {
    Http::fake(['api.github.com/repos/monoven/atlas/commits/heads/main' => Http::response(githubApiSha('9'))]);

    expect(githubApiClient()->latestCommit('monoven/atlas', 'main'))->toBe(['sha' => githubApiSha('9'), 'message' => '', 'author' => '']);
    Http::assertSent(fn($request) => $request->header('Accept') === ['application/vnd.github.sha'] && !$request->hasHeader('If-None-Match'));
});

test('a clone token reads one repository and is not kept on the connection', function () {
    $connection = githubApiConnection(['installation_id' => '51842', 'access_token' => 'ghs_live', 'token_expires_at' => now()->addHour()]);
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response(['token' => 'ghs_clone', 'expires_at' => '2026-10-18T15:30:00Z'], 201)]);

    $token = app(GitProviderFactory::class)->github($connection)->cloneToken('70000001');

    expect($token)->toBe('ghs_clone')
        ->and($connection->fresh()->access_token)->toBe('ghs_live');
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/app/installations/51842/access_tokens'
        && $request->body() === '{"repository_ids":[70000001],"permissions":{"contents":"read"}}'
        && githubApiClaims($request)['iss'] === $connection->githubApp->app_id);
});

test('a clone token github refuses carries why it was refused', function (int $status, string $kind) {
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response(['message' => 'No'], $status)]);

    $exception = githubApiThrown(fn() => githubApiClient()->cloneToken('70000001'));

    expect($exception)->toBeInstanceOf(GitHubInstallationException::class)
        ->and($exception->kind)->toBe($kind);
})->with([[404, 'uninstalled'], [403, 'suspended'], [422, 'repository'], [500, 'unreachable']]);

test('a clone token for a repository id that is not a number is refused before any request', function (string $repository) {
    expect(githubApiThrown(fn() => githubApiClient()->cloneToken($repository)))->toBeInstanceOf(GitProviderException::class);
    Http::assertNothingSent();
})->with(['letters' => 'atlas', 'nothing' => '', 'a negative number' => '-1', 'a decimal' => '7.5']);

test('a connection with no installation cannot mint a token', function () {
    $client = githubApiClient(['installation_id' => null, 'token_expires_at' => now()->subHour()]);

    expect(fn() => $client->cloneToken('70000001'))->toThrow(GitProviderException::class, 'no installation')
        ->and(fn() => $client->token())->toThrow(GitProviderException::class, 'no installation');
    Http::assertNothingSent();
});

test('the connection token is minted through the api without a scope, stored and then reused', function () {
    $connection = githubApiConnection(['access_token' => 'ghs_old', 'token_expires_at' => now()->subMinute()]);
    $expires = now()->addHour()->toIso8601ZuluString();
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response(['token' => 'ghs_fresh', 'expires_at' => $expires], 201)]);
    $client = app(GitProviderFactory::class)->github($connection);

    expect($client->token())->toBe('ghs_fresh')
        ->and($client->token())->toBe('ghs_fresh')
        ->and($connection->fresh()->access_token)->toBe('ghs_fresh')
        ->and($connection->fresh()->token_expires_at->toIso8601ZuluString())->toBe($expires);
    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/app/installations/51842/access_tokens'
        && $request->body() === ''
        && githubApiClaims($request)['iss'] === $connection->githubApp->app_id);
});

test('a refused connection token keeps the old one and says why', function () {
    $connection = githubApiConnection(['access_token' => 'ghs_old', 'token_expires_at' => now()->subMinute()]);
    Http::fake(['api.github.com/app/installations/51842/access_tokens' => Http::response(['message' => 'Not Found'], 404)]);

    $exception = githubApiThrown(fn() => app(GitProviderFactory::class)->github($connection)->listRepositories());

    expect($exception)->toBeInstanceOf(GitHubInstallationException::class)
        ->and($exception->kind)->toBe('uninstalled')
        ->and($connection->fresh()->access_token)->toBe('ghs_old');
    Http::assertSentCount(1);
});

test('a revoke goes through the api with the stored token and only when one is live', function () {
    Http::fake(['api.github.com/installation/token' => Http::response(null, 204)]);

    githubApiClient(['access_token' => 'ghs_stored', 'token_expires_at' => now()->addHour()])->revoke();
    githubApiClient(['access_token' => 'ghs_stale', 'token_expires_at' => now()->subHour()])->revoke();

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->method() === 'DELETE' && $request->hasHeader('Authorization', 'Bearer ghs_stored'));
});

test('a github request that gets no answer is a provider error with the same words', function () {
    Http::fake(fn() => throw new ConnectionException('down'));
    $client = githubApiClient();

    foreach ([
        fn() => $client->installationRepositories(),
        fn() => $client->listRepositories(),
        fn() => $client->branches('monoven/atlas'),
        fn() => $client->postDeployStatus('monoven/atlas', githubApiSha(), 'success', 'https://panel.test/d', 'Live', 'coresmith/atlas'),
        fn() => $client->createDeployKey('monoven/atlas', 'ssh-ed25519 AAAA', 'Coresmith'),
        fn() => $client->deleteWebhook('monoven/atlas', '12'),
    ] as $call) {
        expect(githubApiThrown($call)->getMessage())->toBe('GitHub did not answer.');
    }
});

test('a post to the installation is never sent twice', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Server Error'], 500)]);
    $client = githubApiClient();

    githubApiThrown(fn() => $client->createWebhook('monoven/atlas', 'https://panel.test/hook', 's'));
    githubApiThrown(fn() => $client->createDeployKey('monoven/atlas', 'ssh-ed25519 AAAA', 'Coresmith'));
    githubApiThrown(fn() => $client->postDeployStatus('monoven/atlas', githubApiSha(), 'success', 'https://panel.test/d', 'Live', 'coresmith/atlas'));

    Http::assertSentCount(3);
});

test('the factory hands out the github client for a github connection and refuses every other kind', function (string $type) {
    $factory = app(GitProviderFactory::class);

    if ($type === 'github_app') {
        $connection = githubApiConnection();

        expect($factory->github($connection))->toBeInstanceOf(GitHubAppClient::class)
            ->and($factory->for($connection))->toBeInstanceOf(GitHubAppClient::class);

        return;
    }

    $connection = SourceProvider::factory()->create(['type' => $type, 'access_token' => 'token']);

    expect(fn() => $factory->github($connection))->toThrow(GitProviderException::class, 'not a GitHub App');
})->with([SourceProviderType::GitHubApp->value, SourceProviderType::GitLab->value, SourceProviderType::Bitbucket->value, SourceProviderType::Custom->value]);

test('the factory refuses a github connection whose app is gone', function () {
    $connection = githubApiConnection();
    $connection->githubApp->delete();

    expect(fn() => app(GitProviderFactory::class)->github($connection->fresh()))->toThrow(GitProviderException::class, 'Connect GitHub again from Settings, Git.');
});
