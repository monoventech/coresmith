<?php

declare(strict_types=1);

use App\Models\GitHubApp;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\WebhookMode;
use App\Services\Network\AddressResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function healthApp(array $attributes = []): GitHubAppCredentials
{
    return app(GitHubApps::class)->fromModel(GitHubApp::factory()->create($attributes));
}

function healthEnvApp(): GitHubAppCredentials
{
    config(['services.github' => [
        'app_id' => '1041207',
        'app_slug' => 'coresmith',
        'client_id' => 'Iv1.env',
        'client_secret' => 'env-secret',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----",
        'webhook_secret' => 'env-webhook-secret',
    ]]);

    return app(GitHubApps::class)->env();
}

function healthResolving(array $map): void
{
    app()->instance(AddressResolver::class, new class($map) implements AddressResolver
    {
        public function __construct(private readonly array $map) {}

        public function addresses(string $host): array
        {
            return $this->map[$host] ?? [];
        }
    });
}

function healthAt(string $moment, Closure $record): void
{
    test()->travelTo(CarbonImmutable::parse($moment));

    $record(app(GitHubAppHealth::class));

    test()->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
}

function healthDelivery(int $code, string $status, string $at): array
{
    return ['status_code' => $code, 'status' => $status, 'delivered_at' => CarbonImmutable::parse($at), 'event' => 'push'];
}

function healthMode(GitHubAppCredentials $app): WebhookMode
{
    return app(GitHubAppHealth::class)->mode($app);
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
    config(['app.url' => 'https://panel.example.com']);
    healthResolving(['panel.example.com' => ['140.82.112.3']]);
});

test('an app without a webhook on a private address polls and says why', function () {
    config(['app.url' => 'https://192.168.0.248']);
    $app = healthApp(['hook_url' => null, 'webhook_secret' => null]);

    $mode = healthMode($app);

    expect($mode)->toBeInstanceOf(WebhookMode::class)
        ->and($mode->instant)->toBeFalse()
        ->and($mode->reason)->toBe('unreachable')
        ->and($mode->sentence)->toBe('GitHub can\'t reach 192.168.0.248, because it isn\'t a public address.');
});

test('an app without a webhook on a public address is told to point one here', function () {
    $app = healthApp(['hook_url' => null, 'webhook_secret' => null]);

    $mode = healthMode($app);

    expect($mode->instant)->toBeFalse()
        ->and($mode->reason)->toBe('none')
        ->and($mode->sentence)->toBe('The GitHub app has no webhook yet. Point it here to get pushes as they happen.');
});

test('an app without a webhook says so whatever arrived before', function () {
    $app = healthApp(['hook_url' => null, 'webhook_secret' => null]);
    healthAt('2026-10-18 14:02:00', fn(GitHubAppHealth $health) => $health->received($app, 'push', 'delivery-1'));
    app(GitHubAppHealth::class)->checked($app, healthDelivery(200, 'OK', '2026-10-18T14:03:00Z'));

    expect(healthMode($app)->reason)->toBe('none');
});

test('a failing delivery newer than the last one received means polling', function (?string $received) {
    $app = healthApp();

    if ($received !== null) {
        healthAt($received, fn(GitHubAppHealth $health) => $health->received($app, 'push', 'delivery-1'));
    }

    app(GitHubAppHealth::class)->checked($app, ['status_code' => 0, 'status' => 'timed out', 'delivered_at' => CarbonImmutable::parse('2026-10-18T14:00:00Z')]);
    $mode = healthMode($app);

    expect($mode->instant)->toBeFalse()
        ->and($mode->reason)->toBe('failing')
        ->and($mode->sentence)->toBe("GitHub's last delivery here failed Oct 18, 2026 at 14:00 UTC: timed out.");
})->with([
    'received earlier' => ['2026-10-18 13:00:00'],
    'never received' => [null],
]);

test('a delivery received means webhooks work', function () {
    $app = healthApp();
    healthAt('2026-10-18 14:02:00', fn(GitHubAppHealth $health) => $health->received($app, 'push', 'delivery-1'));

    $mode = healthMode($app);

    expect($mode->instant)->toBeTrue()
        ->and($mode->reason)->toBe('received')
        ->and($mode->sentence)->toBe('GitHub reached Coresmith 3 minutes ago.');
});

test('a failure older than the last delivery received is already over', function () {
    $app = healthApp();
    healthAt('2026-10-18 14:02:00', fn(GitHubAppHealth $health) => $health->received($app, 'push', 'delivery-1'));
    app(GitHubAppHealth::class)->checked($app, healthDelivery(502, 'Invalid HTTP Response: 502', '2026-10-18T13:30:00Z'));

    expect(healthMode($app)->instant)->toBeTrue()
        ->and(healthMode($app)->sentence)->toBe('GitHub reached Coresmith 3 minutes ago.');
});

test('a delivery received is the evidence named even when github logged a success too', function () {
    $app = healthApp();
    healthAt('2026-10-18 14:02:00', fn(GitHubAppHealth $health) => $health->received($app, 'push', 'delivery-1'));
    app(GitHubAppHealth::class)->checked($app, healthDelivery(200, 'OK', '2026-10-18T13:50:00Z'));

    expect(healthMode($app)->sentence)->toBe('GitHub reached Coresmith 3 minutes ago.');
});

test('github\'s own log of a success is enough', function () {
    $app = healthApp();
    app(GitHubAppHealth::class)->checked($app, healthDelivery(200, 'OK', '2026-10-18T13:50:00Z'));

    $mode = healthMode($app);

    expect($mode->instant)->toBeTrue()
        ->and($mode->reason)->toBe('received')
        ->and($mode->sentence)->toBe('GitHub reached Coresmith 15 minutes ago.');
});

test('github\'s log counts a delivery answered with 200 to 399 as delivered', function (int $code, bool $instant) {
    $app = healthApp();
    app(GitHubAppHealth::class)->checked($app, healthDelivery($code, "Status {$code}", '2026-10-18T13:50:00Z'));

    expect(healthMode($app)->instant)->toBe($instant);
})->with([
    'no answer' => [0, false],
    'below the range' => [199, false],
    'ok' => [200, true],
    'a redirect' => [302, true],
    'the top of the range' => [399, true],
    'a client error' => [400, false],
    'a server error' => [500, false],
]);

test('a delivery github logged a moment ahead of this panel\'s clock reads as just now', function () {
    $app = healthApp();
    app(GitHubAppHealth::class)->checked($app, healthDelivery(200, 'OK', '2026-10-18T14:05:04Z'));

    expect(healthMode($app)->sentence)->toBe('GitHub reached Coresmith just now.');
});

test('a moved panel is told to point the webhook here', function () {
    $app = healthApp(['hook_url' => 'https://old.example.com/api/webhooks/github/x']);

    $mode = healthMode($app);

    expect($mode->instant)->toBeFalse()
        ->and($mode->reason)->toBe('moved')
        ->and($mode->sentence)->toBe('Coresmith\'s address changed after the GitHub app was created. Point its webhook here.');
});

test('deliveries that arrive count for more than an address that moved', function (string $evidence) {
    $app = healthApp(['hook_url' => 'https://old.example.com/api/webhooks/github/x']);
    $health = app(GitHubAppHealth::class);

    match ($evidence) {
        'received here' => $health->received($app, 'push', 'delivery-1'),
        'logged by github' => $health->checked($app, healthDelivery(200, 'OK', '2026-10-18T13:50:00Z')),
    };

    expect($health->mode($app)->instant)->toBeTrue();
})->with(['received here', 'logged by github']);

test('with no evidence at all the panel polls', function () {
    $app = healthApp();
    app(GitHubAppHealth::class)->checked($app, null);

    $mode = healthMode($app);

    expect($mode->instant)->toBeFalse()
        ->and($mode->reason)->toBe('unproven')
        ->and($mode->sentence)->toBe('No delivery from GitHub has reached Coresmith yet.');
});

test('the environment app is judged by its deliveries alone', function () {
    config(['app.url' => 'https://192.168.0.248']);
    $app = healthEnvApp();
    $health = app(GitHubAppHealth::class);

    expect($app->hookUrl)->toBeNull()
        ->and($health->mode($app)->reason)->toBe('unproven');

    $health->checked($app, null);

    expect($health->mode($app)->reason)->toBe('unproven')
        ->and($health->mode($app)->sentence)->toBe('No delivery from GitHub has reached Coresmith yet.');

    healthAt('2026-10-18 14:02:00', fn(GitHubAppHealth $health) => $health->received($app, 'ping', 'delivery-1'));

    expect($health->mode($app)->instant)->toBeTrue()
        ->and($health->mode($app)->sentence)->toBe('GitHub reached Coresmith 3 minutes ago.');

    $health->checked($app, healthDelivery(500, 'Invalid HTTP Response: 500', '2026-10-18T14:04:00Z'));

    expect($health->mode($app)->reason)->toBe('failing')
        ->and($health->mode($app)->sentence)->toBe("GitHub's last delivery here failed Oct 18, 2026 at 14:04 UTC: Invalid HTTP Response: 500.");
});

test('each app\'s webhook belongs at its own address on this panel', function () {
    $app = healthApp();
    $health = app(GitHubAppHealth::class);

    expect($health->expectedHookUrl($app))->toBe("https://panel.example.com/api/webhooks/github/{$app->key}")
        ->and($health->expectedHookUrl(healthEnvApp()))->toBe('https://panel.example.com/api/webhooks/github');
});

test('evidence belongs to one app in one environment', function () {
    $first = healthApp();
    $second = healthApp();
    $health = app(GitHubAppHealth::class);

    $health->checked($first, healthDelivery(502, 'Invalid HTTP Response: 502', '2026-10-18T13:00:00Z'));
    $health->received($first, 'push', 'delivery-1');

    expect($health->key($first))->toBe("github:app-health:testing:{$first->key}")
        ->and($health->key($second))->toBe("github:app-health:testing:{$second->key}")
        ->and(Cache::get($health->key($first)))->toBe([
            'received_at' => now()->getTimestamp(),
            'event' => 'push',
            'delivery_id' => 'delivery-1',
            'checked_at' => now()->getTimestamp(),
            'latest' => ['status_code' => 502, 'status' => 'Invalid HTTP Response: 502', 'delivered_at' => CarbonImmutable::parse('2026-10-18T13:00:00Z')->getTimestamp(), 'event' => 'push'],
            'sends_push' => true,
            'repointed_at' => null,
        ])
        ->and($health->mode($first)->instant)->toBeTrue()
        ->and($health->mode($second)->reason)->toBe('unproven')
        ->and(Cache::has($health->key($second)))->toBeFalse();

    config(['app.env' => 'production']);

    expect($health->key($first))->toBe("github:app-health:production:{$first->key}")
        ->and($health->mode($first)->reason)->toBe('unproven');
});

test('a repoint clears what was received and logged before it, and every later write keeps it', function () {
    $app = healthApp();
    $health = app(GitHubAppHealth::class);
    $health->received($app, 'push', 'delivery-1');
    $health->checked($app, healthDelivery(502, 'Invalid HTTP Response: 502', '2026-10-18T13:00:00Z'));
    $health->repointed($app);

    expect(Cache::get($health->key($app)))->toBe([
        'received_at' => null,
        'event' => null,
        'delivery_id' => null,
        'checked_at' => now()->getTimestamp(),
        'latest' => null,
        'sends_push' => null,
        'repointed_at' => now()->getTimestamp(),
    ]);

    healthAt('2026-10-18 14:10:00', fn(GitHubAppHealth $health) => $health->checked($app, healthDelivery(502, 'Invalid HTTP Response: 502', '2026-10-18T13:00:00Z')));

    expect(Cache::get($health->key($app))['repointed_at'])->toBe(now()->getTimestamp())
        ->and($health->mode($app)->reason)->toBe('unproven');

    healthAt('2026-10-18 14:12:00', fn(GitHubAppHealth $health) => $health->received($app, 'ping', 'delivery-2'));

    expect(Cache::get($health->key($app))['repointed_at'])->toBe(now()->getTimestamp())
        ->and($health->mode($app)->instant)->toBeTrue();
});

test('evidence the cache cannot read is no evidence at all', function (mixed $garbage) {
    $app = healthApp();
    $health = app(GitHubAppHealth::class);
    Cache::forever($health->key($app), $garbage);

    expect($health->mode($app)->reason)->toBe('unproven');

    $health->received($app, 'push', 'delivery-1');

    expect($health->mode($app)->sentence)->toBe('GitHub reached Coresmith just now.');
})->with([
    'a string' => ['nonsense'],
    'times that are not times' => [['received_at' => 'yesterday', 'latest' => ['status_code' => 500, 'status' => 'x', 'delivered_at' => 'today']]],
    'a delivery without a status code' => [['latest' => ['status' => 'x', 'delivered_at' => 1792332000]]],
]);

test('an app github says sends no push events is never called instant', function () {
    $app = healthApp();
    $health = app(GitHubAppHealth::class);
    $health->received($app, 'installation', 'delivery-1');

    expect(healthMode($app)->instant)->toBeTrue();

    $health->checked($app, healthDelivery(200, 'OK', '2026-10-18T14:03:00Z'), ['installation', 'installation_repositories']);

    expect(healthMode($app)->instant)->toBeFalse()
        ->and(healthMode($app)->reason)->toBe(WebhookMode::NO_PUSH)
        ->and(healthMode($app)->sentence)->toBe('The GitHub app does not send push events, so Coresmith checks for new commits instead. Turn on the Push event in the app\'s settings on GitHub.');

    $health->checked($app, healthDelivery(200, 'OK', '2026-10-18T14:04:00Z'), ['push', 'installation']);

    expect(healthMode($app)->instant)->toBeTrue();
});

test('a push that arrives proves the app sends them', function () {
    $app = healthApp();
    $health = app(GitHubAppHealth::class);
    $health->checked($app, null, ['installation']);

    expect(healthMode($app)->reason)->toBe(WebhookMode::NO_PUSH);

    $health->received($app, 'push', 'delivery-1');

    expect(healthMode($app)->instant)->toBeTrue();
});

test('a check github cannot answer about events leaves what is known alone', function () {
    $app = healthApp();
    $health = app(GitHubAppHealth::class);
    $health->checked($app, null, ['installation']);
    $health->checked($app, null, null);

    expect(healthMode($app)->reason)->toBe(WebhookMode::NO_PUSH);
});

test('a repoint forgets what github said about the app\'s events', function () {
    $app = healthApp();
    $health = app(GitHubAppHealth::class);
    $health->checked($app, null, ['installation']);
    $health->repointed($app);
    $health->received($app, 'ping', 'delivery-1');

    expect(healthMode($app)->instant)->toBeTrue();
});
