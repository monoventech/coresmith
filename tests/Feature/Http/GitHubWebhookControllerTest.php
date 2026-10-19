<?php

declare(strict_types=1);

use App\Http\Controllers\GitHubWebhookController;
use App\Models\Application;
use App\Models\GitHubApp;
use App\Models\WebhookEndpoint;
use App\Services\Teams\TeamUrls;
use App\Services\Webhook\SignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

function githubHookApp(): GitHubApp
{
    return GitHubApp::factory()->create(['webhook_secret' => 'hook-secret']);
}

function githubHookSend(string $url, string $body, array $headers): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
    }

    return test()->call('POST', $url, [], [], [], $server, $body);
}

function githubHookPost(string $url, array $payload, string $event, string $secret = 'hook-secret', ?string $delivery = null, ?string $signature = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return githubHookSend($url, $body, [
        'X-GitHub-Event' => $event,
        'X-GitHub-Delivery' => $delivery ?? (string) Str::uuid(),
        'X-Hub-Signature-256' => $signature ?? 'sha256=' . hash_hmac('sha256', $body, $secret),
    ]);
}

function githubHookConfigureEnv(?string $webhookSecret): void
{
    config(['services.github' => [
        'app_id' => '1041207',
        'app_slug' => 'coresmith',
        'client_id' => 'Iv1.0123456789abcdef',
        'client_secret' => 'env-client-secret',
        'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nenv\n-----END RSA PRIVATE KEY-----",
        'webhook_secret' => $webhookSecret,
    ]]);
}

function githubHookRejections(): int
{
    return Activity::query()->where('description', 'webhook_signature_rejected')->count();
}

beforeEach(function () {
    Http::preventStrayRequests();

    config(['services.github' => [
        'app_id' => null,
        'app_slug' => 'coresmith',
        'client_id' => null,
        'client_secret' => null,
        'private_key' => null,
        'webhook_secret' => null,
    ]]);
});

test('a ping signed with the app\'s secret is answered', function () {
    $app = githubHookApp();

    githubHookPost(route('webhooks.github.app', $app), ['zen' => 'Keep it logically awesome.', 'hook_id' => 1], 'ping')
        ->assertOk()
        ->assertExactJson(['message' => 'pong']);
});

test('an unsigned or wrongly signed delivery is refused and recorded', function (string $secret, ?string $signature) {
    $app = githubHookApp();

    githubHookPost(route('webhooks.github.app', $app), ['zen' => 'Keep it logically awesome.'], 'ping', $secret, signature: $signature)
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Invalid signature.']);

    $activity = Activity::query()->where('description', 'webhook_signature_rejected')->sole();

    expect($activity->subject?->is($app))->toBeTrue()
        ->and($activity->properties->toArray())->toBe(['app' => $app->id, 'ip' => '127.0.0.1']);
})->with([
    'unsigned' => ['hook-secret', ''],
    'a made up digest' => ['hook-secret', 'sha256=deadbeef'],
    'another secret' => ['other-secret', null],
]);

test('a delivery without a signature header is refused and recorded', function () {
    $app = githubHookApp();

    githubHookSend(route('webhooks.github.app', $app), '{"zen":"Keep it logically awesome."}', ['X-GitHub-Event' => 'ping', 'X-GitHub-Delivery' => 'no-signature-1'])
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Invalid signature.']);

    expect(githubHookRejections())->toBe(1);
});

test('the per app webhook records a rejected signature the way the app webhook does', function () {
    $endpoint = WebhookEndpoint::factory()->for(Application::factory()->create())->create([
        'secret' => 'shhh-secret',
        'provider' => 'github',
        'active' => true,
    ]);

    githubHookPost(route('webhooks.deploy', $endpoint), ['ref' => 'refs/heads/main'], 'push', signature: 'sha256=nope')
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Invalid signature.']);

    $activity = Activity::query()->where('description', 'webhook_signature_rejected')->sole();

    expect($activity->subject?->is($endpoint))->toBeTrue()
        ->and($activity->properties->toArray())->toBe(['provider' => 'github', 'ip' => '127.0.0.1']);
});

test('a delivery signed for one app is refused by another', function () {
    $first = githubHookApp();
    $second = GitHubApp::factory()->create(['webhook_secret' => 'second-secret']);
    $payload = ['zen' => 'Keep it logically awesome.'];

    githubHookPost(route('webhooks.github.app', $second), $payload, 'ping', 'hook-secret')
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Invalid signature.']);

    expect(Activity::query()->where('description', 'webhook_signature_rejected')->sole()->subject?->is($second))->toBeTrue();

    githubHookPost(route('webhooks.github.app', $first), $payload, 'ping', 'hook-secret')->assertOk();
    githubHookPost(route('webhooks.github.app', $second), $payload, 'ping', 'second-secret')->assertOk();
});

test('a delivery seen before is refused for a day', function () {
    $app = githubHookApp();
    $url = route('webhooks.github.app', $app);
    $payload = ['action' => 'opened'];

    githubHookPost($url, $payload, 'issues', delivery: 'delivery-1')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Event ignored.']);

    githubHookPost($url, $payload, 'issues', delivery: 'delivery-1')
        ->assertStatus(409)
        ->assertExactJson(['message' => 'This delivery was already processed.']);

    $this->travel(23)->hours();
    githubHookPost($url, $payload, 'issues', delivery: 'delivery-1')->assertStatus(409);

    $this->travel(2)->hours();
    githubHookPost($url, $payload, 'issues', delivery: 'delivery-1')->assertStatus(202);
});

test('a delivery id is only a replay for the app it was sent to', function () {
    $first = githubHookApp();
    $second = GitHubApp::factory()->create(['webhook_secret' => 'second-secret']);
    $payload = ['action' => 'opened'];

    githubHookPost(route('webhooks.github.app', $first), $payload, 'issues', delivery: 'shared-delivery')->assertStatus(202);
    githubHookPost(route('webhooks.github.app', $second), $payload, 'issues', 'second-secret', 'shared-delivery')->assertStatus(202);
    githubHookPost(route('webhooks.github.app', $second), $payload, 'issues', 'second-secret', 'shared-delivery')->assertStatus(409);
});

test('a delivery with a bad signature does not use up its delivery id', function () {
    $app = githubHookApp();
    $url = route('webhooks.github.app', $app);
    $payload = ['action' => 'opened'];

    githubHookPost($url, $payload, 'issues', delivery: 'forged-first', signature: 'sha256=forged')->assertStatus(401);
    githubHookPost($url, $payload, 'issues', delivery: 'forged-first')->assertStatus(202);
});

test('a delivery id that is not a plain token is not trusted for replays', function (string $delivery) {
    $app = githubHookApp();
    $url = route('webhooks.github.app', $app);

    githubHookPost($url, ['action' => 'opened'], 'issues', delivery: $delivery)->assertStatus(202);
    githubHookPost($url, ['action' => 'opened'], 'issues', delivery: $delivery)->assertStatus(202);
})->with([
    'with spaces' => 'abc 123',
    'with markup' => '<script>',
    'too long' => str_repeat('a', 65),
]);

test('a delivery without an id is processed each time', function () {
    $app = githubHookApp();
    $body = '{"action":"opened"}';
    $headers = ['X-GitHub-Event' => 'issues', 'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $body, 'hook-secret')];

    githubHookSend(route('webhooks.github.app', $app), $body, $headers)->assertStatus(202);
    githubHookSend(route('webhooks.github.app', $app), $body, $headers)->assertStatus(202);
});

test('events the panel does not use are acknowledged', function () {
    $app = githubHookApp();

    githubHookPost(route('webhooks.github.app', $app), ['action' => 'opened'], 'issues')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Event ignored.']);
});

test('an event name that is not a plain token is ignored', function () {
    $app = githubHookApp();

    githubHookPost(route('webhooks.github.app', $app), ['action' => 'opened'], '<img src=x onerror=alert(1)>')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Event ignored.']);
});

test('an app registered without a webhook does not admit deliveries', function () {
    $app = GitHubApp::factory()->withoutWebhook()->create();

    githubHookPost(route('webhooks.github.app', $app), ['zen' => 'Keep it logically awesome.'], 'ping', 'anything')
        ->assertNotFound()
        ->assertExactJson(['message' => 'This app has no webhook.']);

    expect(githubHookRejections())->toBe(0);
});

test('an app whose stored webhook secret is blank does not admit deliveries', function (string $secret) {
    $app = GitHubApp::factory()->create(['webhook_secret' => $secret]);

    githubHookPost(route('webhooks.github.app', $app), ['zen' => 'Keep it logically awesome.'], 'ping', $secret)
        ->assertNotFound()
        ->assertExactJson(['message' => 'This app has no webhook.']);
})->with([
    'empty' => [''],
    'spaces' => ['   '],
]);

test('an app that does not exist is not found', function (string $key) {
    githubHookPost(route('webhooks.github.app', $key), ['zen' => 'Keep it logically awesome.'], 'ping')->assertNotFound();
})->with([
    'a well formed id nobody holds' => '01jzzzzzzzzzzzzzzzzzzzzzzz',
    'something that is not an id' => 'not-an-app',
]);

test('the environment app answers at its own address only when configured', function () {
    githubHookPost(route('webhooks.github'), ['zen' => 'Keep it logically awesome.'], 'ping', 'env-secret')
        ->assertNotFound()
        ->assertExactJson(['message' => 'This panel has no GitHub App.']);

    githubHookConfigureEnv('env-secret');

    githubHookPost(route('webhooks.github'), ['zen' => 'Keep it logically awesome.'], 'ping', 'env-secret')
        ->assertOk()
        ->assertExactJson(['message' => 'pong']);
});

test('the environment app without a webhook secret does not admit deliveries', function (?string $secret) {
    githubHookConfigureEnv($secret);

    githubHookPost(route('webhooks.github'), ['zen' => 'Keep it logically awesome.'], 'ping', 'anything')
        ->assertNotFound()
        ->assertExactJson(['message' => 'This app has no webhook.']);
})->with([
    'unset' => [null],
    'blank' => ['   '],
]);

test('a forged delivery to the environment app is refused and recorded against no record', function () {
    githubHookConfigureEnv('env-secret');

    githubHookPost(route('webhooks.github'), ['zen' => 'Keep it logically awesome.'], 'ping', 'hook-secret')
        ->assertStatus(401)
        ->assertExactJson(['message' => 'Invalid signature.']);

    $activity = Activity::query()->where('description', 'webhook_signature_rejected')->sole();

    expect($activity->subject_type)->toBeNull()
        ->and($activity->subject_id)->toBeNull()
        ->and($activity->properties->toArray())->toBe(['app' => 'env', 'ip' => '127.0.0.1']);
});

test('a delivery id is only a replay for the environment app when it was sent there', function () {
    githubHookConfigureEnv('env-secret');
    $app = githubHookApp();
    $payload = ['action' => 'opened'];

    githubHookPost(route('webhooks.github'), $payload, 'issues', 'env-secret', 'shared-delivery')->assertStatus(202);
    githubHookPost(route('webhooks.github.app', $app), $payload, 'issues', delivery: 'shared-delivery')->assertStatus(202);
    githubHookPost(route('webhooks.github'), $payload, 'issues', 'env-secret', 'shared-delivery')->assertStatus(409);
});

test('the addresses an environment app is configured with match the routes', function () {
    expect(route('webhooks.github', [], false))->toBe('/api/webhooks/github')
        ->and(route('source-providers.callback', 'github_app', false))->toBe('/settings/source-providers/github_app/callback');
});

test('a per app webhook path is never mistaken for an app address', function () {
    $router = app('router');
    $app = githubHookApp();
    $compiled = $router->getRoutes()->toCompiledRouteCollection($router, app());
    $appFirst = new RouteCollection;

    foreach (['webhooks.github.app', 'webhooks.github', 'webhooks.deploy'] as $name) {
        $appFirst->add($router->getRoutes()->getByName($name));
    }

    foreach ([$router->getRoutes(), $compiled, $appFirst] as $routes) {
        expect($routes->match(Request::create('/api/webhooks/github/deploy', 'POST'))->getName())->toBe('webhooks.deploy')
            ->and($routes->match(Request::create(route('webhooks.github.app', $app, false), 'POST'))->getName())->toBe('webhooks.github.app')
            ->and($routes->match(Request::create('/api/webhooks/github', 'POST'))->getName())->toBe('webhooks.github');
    }
});

test('the endpoints are rate limited per bucket', function () {
    foreach (['webhooks.github', 'webhooks.github.app'] as $name) {
        expect(collect(Route::getRoutes()->getByName($name)->gatherMiddleware()))
            ->toContain('throttle:1200,1,github-webhook');
    }
});

test('a factory app points its webhook at this panel the way a registered one does', function () {
    $app = GitHubApp::factory()->create();
    $given = GitHubApp::factory()->create(['id' => '01jaj4v0z8x6v3c2c2e8n1q6m7']);
    $custom = GitHubApp::factory()->create(['hook_url' => 'https://panel.example.com/hook']);

    expect($app->id)->toMatch('/^[0-9a-z]{26}$/')
        ->and($app->hook_url)->toBe(app(TeamUrls::class)->canonical(route('webhooks.github.app', $app, false)))
        ->and($given->hook_url)->toBe(app(TeamUrls::class)->canonical('/api/webhooks/github/01jaj4v0z8x6v3c2c2e8n1q6m7'))
        ->and($custom->hook_url)->toBe('https://panel.example.com/hook')
        ->and(GitHubApp::factory()->withoutWebhook()->create()->hook_url)->toBeNull();

    githubHookPost($app->hook_url, ['zen' => 'Keep it logically awesome.'], 'ping', (string) $app->webhook_secret)
        ->assertOk()
        ->assertExactJson(['message' => 'pong']);
});

test('the parameters that carry the app and its secret are marked sensitive', function () {
    $marked = [
        [GitHubWebhookController::class, 'handle', 'app'],
        [SignatureVerifier::class, 'verify', 'secret'],
        [SignatureVerifier::class, 'expected', 'secret'],
    ];

    foreach ($marked as [$class, $method, $name]) {
        $parameter = collect((new ReflectionMethod($class, $method))->getParameters())->first(fn(ReflectionParameter $candidate): bool => $candidate->getName() === $name);

        expect($parameter?->getAttributes(SensitiveParameter::class))->toHaveCount(1, "{$class}::{$method}(\${$name})");
    }
});
