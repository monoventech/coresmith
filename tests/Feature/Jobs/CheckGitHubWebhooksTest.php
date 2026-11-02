<?php

declare(strict_types=1);

use App\Jobs\CheckGitHubInstallation;
use App\Jobs\CheckGitHubWebhooks;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Services\Git\GitHub\GitHubAppApi;
use App\Services\Git\GitHub\GitHubAppCredentials;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\WebhookMode;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function webhookCheckPem(): string
{
    static $pem = null;

    if ($pem === null) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    }

    return $pem;
}

function webhookCheckEnv(?string $webhookSecret = 'env-webhook-secret'): GitHubAppCredentials
{
    config(['services.github' => [
        'app_id' => '1041207',
        'app_slug' => 'coresmith',
        'client_id' => 'Iv1.env',
        'client_secret' => 'env-secret',
        'private_key' => webhookCheckPem(),
        'webhook_secret' => $webhookSecret,
    ]]);

    return app(GitHubApps::class)->env();
}

function webhookCheckIssuer(Request $request): ?string
{
    preg_match('/^Bearer [\w-]+\.([\w-]+)\.[\w-]+$/', $request->header('Authorization')[0] ?? '', $parts);
    $claims = isset($parts[1]) ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : null;

    return is_array($claims) && isset($claims['iss']) ? (string) $claims['iss'] : null;
}

function webhookCheckDeliveries(int $code, string $status, string $at): array
{
    return [
        ['id' => 1, 'guid' => 'older', 'delivered_at' => '2026-10-18T09:00:00Z', 'status' => 'OK', 'status_code' => 200, 'event' => 'ping'],
        ['id' => 2, 'guid' => 'newest', 'delivered_at' => $at, 'status' => $status, 'status_code' => $code, 'event' => 'push'],
    ];
}

function webhookCheckRun(): void
{
    (new CheckGitHubWebhooks)->handle(app(GitHubApps::class), app(GitHubAppApi::class), app(GitHubAppHealth::class));
}

function webhookCheckMode(GitHubApp|GitHubAppCredentials $app): WebhookMode
{
    return app(GitHubAppHealth::class)->mode($app instanceof GitHubApp ? app(GitHubApps::class)->fromModel($app) : $app);
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
    config([
        'app.url' => 'https://panel.example.com',
        'services.github' => ['app_id' => null, 'app_slug' => 'coresmith', 'client_id' => null, 'client_secret' => null, 'private_key' => null, 'webhook_secret' => null],
    ]);
});

test('each registered app with a webhook has its newest delivery recorded', function () {
    $first = GitHubApp::factory()->create(['app_id' => '2000001']);
    GitHubApp::factory()->withoutWebhook()->create(['app_id' => '2000002']);
    Http::fake(['api.github.com/app/hook/deliveries*' => Http::response(webhookCheckDeliveries(502, 'Invalid HTTP Response: 502', '2026-10-18T14:00:00Z'))]);

    webhookCheckRun();

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.github.com/app/hook/deliveries?per_page=10'
        && webhookCheckIssuer($request) === '2000001');

    $mode = webhookCheckMode($first);

    expect($mode->instant)->toBeFalse()
        ->and($mode->reason)->toBe('failing')
        ->and($mode->sentence)->toBe("GitHub's last delivery here failed Oct 18, 2026 at 14:00 UTC: Invalid HTTP Response: 502.");
});

test('a check with no deliveries to show records that it looked', function () {
    $app = GitHubApp::factory()->create();
    Http::fake(['api.github.com/app/hook/deliveries*' => Http::response([])]);

    webhookCheckRun();

    $health = app(GitHubAppHealth::class);
    $evidence = Cache::get($health->key(app(GitHubApps::class)->fromModel($app)));

    expect($evidence['checked_at'])->toBe(now()->getTimestamp())
        ->and($evidence['latest'])->toBeNull()
        ->and(webhookCheckMode($app)->reason)->toBe('unproven');
});

test('the environment app also reports where its webhook points', function () {
    $env = webhookCheckEnv();
    Http::fake([
        'api.github.com/app/hook/config' => Http::response(['url' => 'https://panel.example.com/api/webhooks/github', 'content_type' => 'json', 'secret' => '********', 'insecure_ssl' => '0']),
        'api.github.com/app/hook/deliveries*' => Http::response(webhookCheckDeliveries(200, 'OK', '2026-10-18T13:50:00Z')),
    ]);

    webhookCheckRun();

    $evidence = Cache::get(app(GitHubAppHealth::class)->key($env));

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->url() === 'https://api.github.com/app/hook/config' && webhookCheckIssuer($request) === '1041207');
    Http::assertSent(fn(Request $request) => str_starts_with($request->url(), 'https://api.github.com/app/hook/deliveries') && webhookCheckIssuer($request) === '1041207');

    expect($evidence['hook_url'])->toBe('https://panel.example.com/api/webhooks/github')
        ->and($evidence['checked_at'])->toBe(now()->getTimestamp())
        ->and(webhookCheckMode($env)->instant)->toBeTrue()
        ->and(webhookCheckMode($env)->sentence)->toBe('GitHub reached this panel 15 minutes ago.');
});

test('an environment app without a webhook is not asked about one', function () {
    webhookCheckEnv(null);
    Http::fake();

    webhookCheckRun();

    Http::assertNothingSent();
});

test('one app github refuses does not stop the next', function (int $refused) {
    $apps = [GitHubApp::factory()->create(['app_id' => '2000001']), GitHubApp::factory()->create(['app_id' => '2000002'])];
    $refusedApp = $apps[$refused];
    $checkedApp = $apps[1 - $refused];
    Http::fake(fn(Request $request) => webhookCheckIssuer($request) === $refusedApp->app_id
        ? Http::response(['message' => 'Server Error'], 500)
        : Http::response(webhookCheckDeliveries(200, 'OK', '2026-10-18T13:50:00Z')));

    webhookCheckRun();

    Http::assertSentCount(2);

    expect(webhookCheckMode($checkedApp)->instant)->toBeTrue()
        ->and(webhookCheckMode($refusedApp)->reason)->toBe('unproven')
        ->and(Cache::has(app(GitHubAppHealth::class)->key(app(GitHubApps::class)->fromModel($refusedApp))))->toBeFalse();
})->with([
    'the first app is refused' => [0],
    'the second app is refused' => [1],
]);

test('an app github refuses keeps the evidence it had', function () {
    $app = GitHubApp::factory()->create();
    $credentials = app(GitHubApps::class)->fromModel($app);
    app(GitHubAppHealth::class)->received($credentials, 'push', 'delivery-1');
    $before = Cache::get(app(GitHubAppHealth::class)->key($credentials));
    Http::fake(['api.github.com/app/hook/deliveries*' => Http::response(['message' => 'Integration not found'], 404)]);

    webhookCheckRun();

    expect(Cache::get(app(GitHubAppHealth::class)->key($credentials)))->toBe($before)
        ->and(webhookCheckMode($app)->instant)->toBeTrue();
});

test('the environment app records nothing when github will not say where its webhook points', function () {
    $env = webhookCheckEnv();
    Http::fake([
        'api.github.com/app/hook/config' => Http::response(['message' => 'A JSON web token could not be decoded'], 401),
        'api.github.com/app/hook/deliveries*' => Http::response(webhookCheckDeliveries(200, 'OK', '2026-10-18T13:50:00Z')),
    ]);

    webhookCheckRun();

    expect(Cache::has(app(GitHubAppHealth::class)->key($env)))->toBeFalse();
});

test('every suspended connection is asked about again, so one unsuspended on github is connected again', function () {
    $app = GitHubApp::factory()->withoutWebhook()->create(['app_id' => '2000001']);
    $connection = fn(string $installation, array $attributes = []): SourceProvider => SourceProvider::factory()->for($app->team)->create([
        'type' => 'github_app',
        'github_app_id' => $app->id,
        'name' => "account-{$installation}",
        'installation_id' => $installation,
        'suspended_at' => now()->subDay(),
        ...$attributes,
    ]);
    $unsuspended = $connection('51842');
    $suspended = $connection('51843');
    $live = $connection('51844', ['suspended_at' => null]);
    $removed = $connection('51845', ['deleted_at' => now()]);
    Http::fake(['api.github.com/app/installations/*' => fn(Request $request) => Http::response([
        'id' => (int) basename($request->url()),
        'suspended_at' => str_ends_with($request->url(), '/51843') ? '2026-10-18T09:00:00Z' : null,
    ])]);

    webhookCheckRun();

    expect($unsuspended->fresh()->suspended_at)->toBeNull()
        ->and($unsuspended->fresh()->connected())->toBeTrue()
        ->and($suspended->fresh()->suspended_at)->not->toBeNull()
        ->and($suspended->fresh()->connected())->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->url() === 'https://api.github.com/app/installations/51842' && webhookCheckIssuer($request) === '2000001');
    Http::assertSent(fn(Request $request) => $request->url() === 'https://api.github.com/app/installations/51843');
    Http::assertNotSent(fn(Request $request) => in_array(basename($request->url()), [$live->installation_id, $removed->installation_id], true));
});

test('only suspended github connections are queued for a check', function () {
    Queue::fake();
    $app = GitHubApp::factory()->withoutWebhook()->create();
    SourceProvider::factory()->for($app->team)->create(['type' => 'github_app', 'github_app_id' => $app->id, 'installation_id' => '51842', 'suspended_at' => now()]);
    SourceProvider::factory()->for($app->team)->create(['type' => 'github_app', 'github_app_id' => $app->id, 'installation_id' => '51843', 'suspended_at' => null]);
    SourceProvider::factory()->for($app->team)->create(['type' => 'gitlab', 'installation_id' => null, 'suspended_at' => now()]);

    webhookCheckRun();

    Queue::assertPushed(CheckGitHubInstallation::class, 1);
});

test('an app check that throws does not keep suspended connections from being checked', function () {
    Queue::fake();
    $app = GitHubApp::factory()->create(['app_id' => '2000001']);
    SourceProvider::factory()->for($app->team)->create(['type' => 'github_app', 'github_app_id' => $app->id, 'installation_id' => '51842', 'suspended_at' => now()]);
    Http::fake(['api.github.com/app/hook/deliveries*' => Http::response([['id' => 1, 'guid' => 'x', 'delivered_at' => 'not a time', 'status' => 'OK', 'status_code' => 200, 'event' => 'push']])]);

    expect(fn() => webhookCheckRun())->toThrow(InvalidFormatException::class);

    Queue::assertPushed(CheckGitHubInstallation::class, 1);
});

test('a suspended connection github will not describe does not stop the next one', function () {
    $app = GitHubApp::factory()->withoutWebhook()->create();
    $first = SourceProvider::factory()->for($app->team)->create(['type' => 'github_app', 'github_app_id' => $app->id, 'installation_id' => '51842', 'suspended_at' => now()]);
    $second = SourceProvider::factory()->for($app->team)->create(['type' => 'github_app', 'github_app_id' => $app->id, 'installation_id' => '51843', 'suspended_at' => now()]);
    Http::fake([
        'api.github.com/app/installations/51842' => Http::response(['message' => 'Server Error'], 500),
        'api.github.com/app/installations/51843' => Http::response(['id' => 51843, 'suspended_at' => null]),
    ]);

    webhookCheckRun();

    expect($first->fresh()->suspended_at)->not->toBeNull()
        ->and($second->fresh()->suspended_at)->toBeNull();
});

test('it is scheduled every fifteen minutes', function () {
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'coresmith:check-github-webhooks');
    $job = new CheckGitHubWebhooks;

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($job->queue)->toBe('default')
        ->and($job->timeout)->toBe(120)
        ->and($job->tries)->toBe(1);
});
