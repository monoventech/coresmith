<?php

declare(strict_types=1);

use App\Http\Controllers\GitHubWebhookController;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\WebhookEndpoint;
use App\Services\Git\GitHub\GitHubAppHealth;
use App\Services\Git\GitHub\GitHubApps;
use App\Services\Git\GitHub\GitHubEventHandler;
use App\Services\Git\GitHub\RepositoryCatalog;
use App\Services\Teams\TeamUrls;
use App\Services\Webhook\SignatureVerifier;
use App\Services\Webhook\WebhookDeliveries;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

function githubHookConnection(?GitHubApp $app, array $attributes = []): SourceProvider
{
    return SourceProvider::factory()->for($app === null ? Team::factory()->create() : $app->team)->create([
        'type' => 'github_app',
        'github_app_id' => $app?->getKey(),
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
        'repository_selection' => 'all',
        ...$attributes,
    ]);
}

function githubHookLinkedTo(SourceProvider $connection, array $attributes = []): Application
{
    return Application::factory()->for($connection->team)->create([
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_provider' => 'github',
        'repository_branch' => 'main',
        'source_provider_id' => $connection->id,
        'repository_id' => '70000001',
        'quick_deploy' => true,
        ...$attributes,
    ]);
}

function githubHookLinkedApp(GitHubApp $app, array $attributes = []): Application
{
    return githubHookLinkedTo(githubHookConnection($app), $attributes);
}

function githubHookPush(array $changes = []): array
{
    return array_replace_recursive([
        'ref' => 'refs/heads/main',
        'after' => str_repeat('c', 40),
        'deleted' => false,
        'installation' => ['id' => 51842],
        'repository' => ['id' => 70000001, 'full_name' => 'monoventech/atlas'],
        'head_commit' => ['message' => 'Ship it'],
    ], $changes);
}

beforeEach(function () {
    Queue::fake();
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

test('a push to a linked branch head deploys it', function () {
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp);
    $after = str_repeat('c', 40);

    githubHookPost(route('webhooks.github.app', $githubApp), [
        'ref' => 'refs/heads/main', 'after' => $after, 'deleted' => false,
        'installation' => ['id' => 51842],
        'repository' => ['id' => 70000001, 'full_name' => 'monoventech/atlas'],
        'head_commit' => ['message' => 'Ship it'],
    ], 'push')->assertStatus(202);

    expect($application->fresh()->repository_head)->toBe($after)
        ->and(Deployment::where('application_id', $application->id)->first())
        ->trigger->toBe('push')
        ->requested_commit->toBe($after);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('only a push to the exact branch head deploys', function (array $changes) {
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp);

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush($changes), 'push', delivery: 'skipped-1')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Push received.']);

    expect($application->fresh()->repository_head)->toBeNull()
        ->and(Deployment::count())->toBe(0)
        ->and($application->sourceProvider->fresh()->last_delivery_id)->toBe('skipped-1');

    Queue::assertNothingPushed();
})->with([
    'a tag' => [['ref' => 'refs/tags/v1.0']],
    'the branch in another case' => [['ref' => 'refs/heads/Main']],
    'a branch that starts with the name' => [['ref' => 'refs/heads/main-old']],
    'a deleted branch' => [['deleted' => true]],
    'no commit after the push' => [['after' => str_repeat('0', 40)]],
    'a short commit' => [['after' => 'ccccccc']],
]);

test('a branch with slashes matches only itself', function () {
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp, ['repository_branch' => 'release/2.0']);
    $url = route('webhooks.github.app', $githubApp);

    githubHookPost($url, githubHookPush(['ref' => 'refs/heads/release']), 'push')->assertStatus(202);

    expect($application->fresh()->repository_head)->toBeNull();
    Queue::assertNothingPushed();

    githubHookPost($url, githubHookPush(['ref' => 'refs/heads/release/2.0']), 'push')->assertStatus(202);

    expect($application->fresh()->repository_head)->toBe(str_repeat('c', 40));
    Queue::assertPushed(DeployApplication::class, 1);
});

test('a push for another installation or another app deploys nothing', function () {
    $githubApp = githubHookApp();
    $other = GitHubApp::factory()->create(['webhook_secret' => 'other-secret']);
    $application = githubHookLinkedApp($githubApp);

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush(['installation' => ['id' => 51843]]), 'push')->assertStatus(202);
    githubHookPost(route('webhooks.github.app', $other), githubHookPush(), 'push', 'other-secret')->assertStatus(202);

    expect($application->sourceProvider->fresh()->last_delivered_at)->toBeNull();

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush(['repository' => ['id' => 70000002]]), 'push')->assertStatus(202);

    expect($application->fresh()->repository_head)->toBeNull()
        ->and(Deployment::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('the environment app and a registered app each reach only their own connections', function () {
    githubHookConfigureEnv('env-secret');
    $githubApp = githubHookApp();
    $throughEnv = githubHookLinkedTo(githubHookConnection(null));
    $throughApp = githubHookLinkedApp($githubApp);

    githubHookPost(route('webhooks.github'), githubHookPush(), 'push', 'env-secret')->assertStatus(202);

    expect($throughEnv->fresh()->repository_head)->toBe(str_repeat('c', 40))
        ->and($throughApp->fresh()->repository_head)->toBeNull();

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush(['after' => str_repeat('d', 40)]), 'push')->assertStatus(202);

    expect($throughEnv->fresh()->repository_head)->toBe(str_repeat('c', 40))
        ->and($throughApp->fresh()->repository_head)->toBe(str_repeat('d', 40));

    Queue::assertPushed(DeployApplication::class, 2);
});

test('every app on the pushed branch observes the head', function () {
    $githubApp = githubHookApp();
    $connection = githubHookConnection($githubApp);
    $production = githubHookLinkedTo($connection);
    $staging = githubHookLinkedTo($connection);
    $develop = githubHookLinkedTo($connection, ['repository_branch' => 'develop']);
    $credentials = app(GitHubApps::class)->fromModel($githubApp);

    expect(app(GitHubEventHandler::class)->push($credentials, githubHookPush(), 'push-delivery-1'))->toBe(2)
        ->and($production->fresh()->repository_head)->toBe(str_repeat('c', 40))
        ->and($staging->fresh()->repository_head)->toBe(str_repeat('c', 40))
        ->and($develop->fresh()->repository_head)->toBeNull();

    Queue::assertPushed(DeployApplication::class, 2);
});

test('a push with auto deploy off records the head only', function () {
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp, ['quick_deploy' => false]);

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush(), 'push')->assertStatus(202);

    expect($application->fresh())
        ->repository_head->toBe(str_repeat('c', 40))
        ->repository_head_source->toBe('push')
        ->and(Deployment::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('a renamed repository still deploys and its url follows the rename', function () {
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp);

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush(['repository' => ['full_name' => 'monoventech/atlas-renamed']]), 'push')->assertStatus(202);

    expect($application->fresh())
        ->repository_url->toBe('https://github.com/monoventech/atlas-renamed.git')
        ->repository_head->toBe(str_repeat('c', 40));

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a repository name that is not an owner and a name keeps the stored url', function (?string $fullName) {
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp);

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush(['repository' => ['full_name' => $fullName]]), 'push')->assertStatus(202);

    expect($application->fresh())
        ->repository_url->toBe('https://github.com/monoventech/atlas.git')
        ->repository_head->toBe(str_repeat('c', 40));

    Queue::assertPushed(DeployApplication::class, 1);
})->with([
    'a deeper path' => ['monoventech/atlas/extra'],
    'a trailing newline' => ["monoventech/atlas-renamed\n"],
    'no owner' => ['atlas-renamed'],
    'none' => [null],
]);

test('a push for a removed connection deploys nothing', function () {
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp);
    $application->sourceProvider->delete();

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush(), 'push')->assertStatus(202);

    expect($application->fresh()->repository_head)->toBeNull()
        ->and(Deployment::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('a tag push records the delivery and deploys nothing', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp);

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush(['ref' => 'refs/tags/v1.0']), 'push', delivery: 'tag-delivery-1')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Push received.']);

    $connection = $application->sourceProvider->fresh();

    expect($connection)
        ->last_delivery_id->toBe('tag-delivery-1')
        ->last_event->toBe('push')
        ->last_status->toBe(202)
        ->and($connection->last_delivered_at?->toDateTimeString())->toBe('2026-10-18 14:05:00')
        ->and($application->fresh()->repository_head)->toBeNull()
        ->and(Deployment::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('a push without a ref or a repository deploys nothing and still reaches its connection', function (string $missing) {
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp);
    $payload = githubHookPush();
    unset($payload[$missing]);

    githubHookPost(route('webhooks.github.app', $githubApp), $payload, 'push', delivery: 'partial-1')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Push received.']);

    expect($application->fresh()->repository_head)->toBeNull()
        ->and(Deployment::count())->toBe(0)
        ->and($application->sourceProvider->fresh()->last_delivery_id)->toBe('partial-1');

    Queue::assertNothingPushed();
})->with(['ref', 'repository']);

test('a push without an installation records nothing', function () {
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp);
    $payload = githubHookPush();
    unset($payload['installation']);

    githubHookPost(route('webhooks.github.app', $githubApp), $payload, 'push')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Push received.']);

    expect($application->fresh()->repository_head)->toBeNull()
        ->and($application->sourceProvider->fresh()->last_delivered_at)->toBeNull()
        ->and(Deployment::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('the connection records the delivery for the git page', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
    $githubApp = githubHookApp();
    $application = githubHookLinkedApp($githubApp);
    $elsewhere = githubHookConnection($githubApp, ['installation_id' => '51843']);

    githubHookPost(route('webhooks.github.app', $githubApp), githubHookPush(), 'push', delivery: 'push-delivery-1')->assertStatus(202);

    $connection = $application->sourceProvider->fresh();

    expect($connection)
        ->last_delivery_id->toBe('push-delivery-1')
        ->last_event->toBe('push')
        ->last_status->toBe(202)
        ->and($connection->last_delivered_at?->toDateTimeString())->toBe('2026-10-18 14:05:00')
        ->and($elsewhere->fresh()->last_delivered_at)->toBeNull();
});

test('every verified delivery counts as evidence that github reaches the panel', function () {
    $githubApp = githubHookApp();
    $credentials = app(GitHubApps::class)->fromModel($githubApp);
    $url = route('webhooks.github.app', $githubApp);

    githubHookPost($url, ['zen' => 'Keep it logically awesome.'], 'ping', 'forged-secret')->assertStatus(401);

    expect(app(GitHubAppHealth::class)->mode($credentials)->instant)->toBeFalse();

    githubHookPost($url, ['zen' => 'Keep it logically awesome.'], 'ping')->assertOk();

    expect(app(GitHubAppHealth::class)->mode($credentials)->instant)->toBeTrue();
});

test('a replayed delivery still counts as evidence that github reaches the panel', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
    $githubApp = githubHookApp();
    $url = route('webhooks.github.app', $githubApp);

    githubHookPost($url, ['action' => 'opened'], 'issues', delivery: 'redelivered-1')->assertStatus(202);

    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:35:00'));

    githubHookPost($url, ['action' => 'opened'], 'issues', delivery: 'redelivered-1')->assertStatus(409);

    expect(app(GitHubAppHealth::class)->mode(app(GitHubApps::class)->fromModel($githubApp)))
        ->instant->toBeTrue()
        ->sentence->toBe('GitHub reached this panel just now.');
});

test('an uninstalled installation is forgotten', function () {
    $githubApp = githubHookApp();
    $connection = githubHookConnection($githubApp);
    $otherInstallation = githubHookConnection($githubApp, ['installation_id' => '51843']);
    $otherApp = githubHookConnection(GitHubApp::factory()->create());

    githubHookPost(route('webhooks.github.app', $githubApp), ['action' => 'deleted', 'installation' => ['id' => 51842, 'account' => ['login' => 'monoventech']]], 'installation')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Installation updated.']);

    expect($connection->fresh()->trashed())->toBeTrue()
        ->and(Activity::query()->where('description', 'github_installation_removed')->sole()->subject_id)->toBe($connection->id)
        ->and($otherInstallation->fresh()->trashed())->toBeFalse()
        ->and($otherApp->fresh()->trashed())->toBeFalse();
});

test('a suspended installation is marked and unsuspended again', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
    $githubApp = githubHookApp();
    $connection = githubHookConnection($githubApp);
    $url = route('webhooks.github.app', $githubApp);

    githubHookPost($url, ['action' => 'suspend', 'installation' => ['id' => 51842, 'suspended_at' => '2026-10-18T13:30:52Z']], 'installation')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Installation updated.']);

    expect($connection->fresh()->suspended_at?->toDateTimeString())->toBe('2026-10-18 13:30:52');

    githubHookPost($url, ['action' => 'unsuspend', 'installation' => ['id' => 51842, 'suspended_at' => null]], 'installation')->assertStatus(202);

    expect($connection->fresh()->suspended_at)->toBeNull();

    githubHookPost($url, ['action' => 'suspend', 'installation' => ['id' => 51842]], 'installation')->assertStatus(202);

    expect($connection->fresh()->suspended_at?->toDateTimeString())->toBe('2026-10-18 14:05:00');
});

test('an installation change the panel does not track leaves the connection alone', function () {
    $githubApp = githubHookApp();
    $connection = githubHookConnection($githubApp);

    githubHookPost(route('webhooks.github.app', $githubApp), ['action' => 'new_permissions_accepted', 'installation' => ['id' => 51842, 'suspended_at' => '2026-10-18T13:30:52Z']], 'installation')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Installation updated.']);

    expect($connection->fresh())
        ->trashed()->toBeFalse()
        ->suspended_at->toBeNull();
});

test('a change of repositories clears the cached list', function () {
    $githubApp = githubHookApp();
    $connection = githubHookConnection($githubApp);
    $elsewhere = githubHookConnection($githubApp, ['installation_id' => '51843']);
    $catalog = ['total' => 1, 'truncated' => false, 'repositories' => [['id' => '70000001', 'full_name' => 'monoventech/atlas', 'default_branch' => 'main']]];

    Cache::put(RepositoryCatalog::key($connection), $catalog, 300);
    Cache::put(RepositoryCatalog::key($elsewhere), $catalog, 300);

    githubHookPost(route('webhooks.github.app', $githubApp), [
        'action' => 'removed',
        'installation' => ['id' => 51842, 'repository_selection' => 'selected'],
        'repository_selection' => 'selected',
        'repositories_added' => [],
        'repositories_removed' => [['id' => 70000002, 'full_name' => 'monoventech/vault']],
    ], 'installation_repositories')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Repositories updated.']);

    expect(Cache::has(RepositoryCatalog::key($connection)))->toBeFalse()
        ->and($connection->fresh()->repository_selection)->toBe('selected')
        ->and(Cache::has(RepositoryCatalog::key($elsewhere)))->toBeTrue()
        ->and($elsewhere->fresh()->repository_selection)->toBe('all');
});

test('a delivery whose handling fails can be delivered again', function () {
    Exceptions::fake();
    $githubApp = githubHookApp();
    githubHookLinkedApp($githubApp);
    $url = route('webhooks.github.app', $githubApp);
    $failing = true;

    Deployment::creating(function () use (&$failing): void {
        if ($failing) {
            throw new RuntimeException('The database went away.');
        }
    });

    githubHookPost($url, githubHookPush(), 'push', delivery: 'redeliver-1')->assertStatus(500);

    Exceptions::assertReported(fn(RuntimeException $failure): bool => $failure->getMessage() === 'The database went away.');

    $failing = false;

    githubHookPost($url, githubHookPush(), 'push', delivery: 'redeliver-1')
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Push received.']);

    githubHookPost($url, githubHookPush(), 'push', delivery: 'redeliver-1')->assertStatus(409);

    expect(Deployment::count())->toBe(1);
    Queue::assertPushed(DeployApplication::class, 1);
});

test('releasing a delivery id frees it in its own scope only', function () {
    $deliveries = app(WebhookDeliveries::class);

    expect($deliveries->claim('github-delivery:env', 'delivery-1'))->toBeTrue()
        ->and($deliveries->claim('github-delivery:01jaj4v0z8x6v3c2c2e8n1q6m7', 'delivery-1'))->toBeTrue();

    $deliveries->release('github-delivery:env', 'delivery-1');

    expect($deliveries->claim('github-delivery:env', 'delivery-1'))->toBeTrue()
        ->and($deliveries->claim('github-delivery:env', 'delivery-1'))->toBeFalse()
        ->and($deliveries->claim('github-delivery:01jaj4v0z8x6v3c2c2e8n1q6m7', 'delivery-1'))->toBeFalse();
});

test('the parameters that carry the app and its secret are marked sensitive', function () {
    $marked = [
        [GitHubWebhookController::class, 'handle', 'app'],
        [GitHubWebhookController::class, 'process', 'app'],
        [GitHubEventHandler::class, 'push', 'app'],
        [GitHubEventHandler::class, 'installation', 'app'],
        [GitHubEventHandler::class, 'installationRepositories', 'app'],
        [GitHubEventHandler::class, 'connections', 'app'],
        [SignatureVerifier::class, 'verify', 'secret'],
        [SignatureVerifier::class, 'expected', 'secret'],
    ];

    foreach ($marked as [$class, $method, $name]) {
        $parameter = collect((new ReflectionMethod($class, $method))->getParameters())->first(fn(ReflectionParameter $candidate): bool => $candidate->getName() === $name);

        expect($parameter?->getAttributes(SensitiveParameter::class))->toHaveCount(1, "{$class}::{$method}(\${$name})");
    }
});
