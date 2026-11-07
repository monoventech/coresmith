<?php

declare(strict_types=1);

use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\WebhookEndpoint;
use App\Services\Webhook\SignatureVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

function deliveryEndpoint(array $endpointAttributes = [], array $applicationAttributes = []): WebhookEndpoint
{
    $application = Application::factory()->create(array_merge(['repository_branch' => 'main', 'quick_deploy' => true], $applicationAttributes));

    return WebhookEndpoint::factory()->for($application)->create(array_merge([
        'secret' => 'delivery-secret',
        'provider' => 'github',
        'active' => true,
    ], $endpointAttributes));
}

function deliveryPost(WebhookEndpoint $endpoint, array $headers = [], array $payload = ['ref' => 'refs/heads/main', 'after' => 'abc1234'], ?string $signature = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $provider = (string) $endpoint->provider;
    $signatureHeader = match ($provider) {
        'github' => 'X-Hub-Signature-256',
        'gitlab' => 'X-Gitlab-Token',
        'bitbucket' => 'X-Hub-Signature',
        default => 'X-Coresmith-Signature',
    };

    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ([$signatureHeader => $signature ?? (new SignatureVerifier)->expected($body, $provider, (string) $endpoint->secret), ...$headers] as $name => $value) {
        $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
    }

    return test()->call('POST', route('webhooks.deploy', $endpoint), [], [], [], $server, $body);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
});

test('a delivery that was already processed is refused and queues nothing more', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();
    $headers = ['X-GitHub-Delivery' => '72d3162e-cc78-11e3-81ab-4c9367dc0958', 'X-GitHub-Event' => 'push'];

    deliveryPost($endpoint, $headers)->assertStatus(202);

    deliveryPost($endpoint, $headers)
        ->assertStatus(409)
        ->assertExactJson(['message' => 'This delivery was already processed.']);

    expect(Deployment::count())->toBe(1);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a replay is still refused a day later but accepted once the window has passed', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();
    $headers = ['X-GitHub-Delivery' => 'delivery-1'];

    deliveryPost($endpoint, $headers)->assertStatus(202);

    $this->travel(23)->hours();
    deliveryPost($endpoint, $headers)->assertStatus(409);

    $this->travel(61)->minutes();
    deliveryPost($endpoint, $headers)->assertStatus(202);

    expect(Deployment::count())->toBe(2);
});

test('the same delivery id sent to another webhook is not a replay', function () {
    Queue::fake();
    $headers = ['X-GitHub-Delivery' => 'shared-delivery'];

    deliveryPost(deliveryEndpoint(), $headers)->assertStatus(202);
    deliveryPost(deliveryEndpoint(), $headers)->assertStatus(202);

    Queue::assertPushed(DeployApplication::class, 2);
});

test('deliveries without an id are each processed', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();

    deliveryPost($endpoint)->assertStatus(202);
    deliveryPost($endpoint)->assertStatus(202);

    Queue::assertPushed(DeployApplication::class, 2);
});

test('a delivery id that is not a plain token is neither trusted for replays nor stored', function (string $delivery) {
    Queue::fake();
    $endpoint = deliveryEndpoint();

    deliveryPost($endpoint, ['X-GitHub-Delivery' => $delivery])->assertStatus(202);
    deliveryPost($endpoint, ['X-GitHub-Delivery' => $delivery])->assertStatus(202);

    expect($endpoint->fresh()->last_delivery_id)->toBeNull();
})->with([
    'with spaces' => 'abc 123',
    'with markup' => '<script>',
    'too long' => str_repeat('a', 65),
]);

test('every provider delivery header is understood', function (string $provider, array $headers, string $delivery) {
    Queue::fake();
    $endpoint = deliveryEndpoint(['provider' => $provider]);

    deliveryPost($endpoint, $headers)->assertStatus(202);
    deliveryPost($endpoint, $headers)->assertStatus(409);

    expect($endpoint->fresh()->last_delivery_id)->toBe($delivery);
})->with([
    'github' => ['github', ['X-GitHub-Delivery' => 'gh-1'], 'gh-1'],
    'gitlab' => ['gitlab', ['X-Gitlab-Event-UUID' => '2a8e4c2b-5d1f-4f6e-9b0d-1c3a5e7f9b2d'], '2a8e4c2b-5d1f-4f6e-9b0d-1c3a5e7f9b2d'],
    'bitbucket' => ['bitbucket', ['X-Request-UUID' => 'bb-3'], 'bb-3'],
    'custom' => ['custom', ['X-Coresmith-Delivery' => 'cs-4'], 'cs-4'],
]);

test('a delivery with a bad signature does not use up its delivery id', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();
    $headers = ['X-GitHub-Delivery' => 'forged-first'];

    deliveryPost($endpoint, $headers, signature: 'sha256=forged')->assertStatus(401);
    deliveryPost($endpoint, $headers)->assertStatus(202);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a delivery for another branch still counts, so replaying it is refused', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();
    $headers = ['X-GitHub-Delivery' => 'feature-push'];
    $payload = ['ref' => 'refs/heads/feature/x'];

    deliveryPost($endpoint, $headers, $payload)->assertStatus(202)->assertJson(['message' => 'Branch ignored.']);
    deliveryPost($endpoint, $headers, $payload)->assertStatus(409);

    Queue::assertNothingPushed();
});

test('an accepted delivery is recorded as the last one', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();

    deliveryPost($endpoint, ['X-GitHub-Delivery' => 'accepted-1', 'X-GitHub-Event' => 'push'])
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Deployment queued.']);

    $fresh = $endpoint->fresh();

    expect($fresh->last_delivery_id)->toBe('accepted-1')
        ->and($fresh->last_event)->toBe('push')
        ->and($fresh->last_status)->toBe(202)
        ->and($fresh->last_delivered_at?->toDateTimeString())->toBe('2026-09-13 14:05:09');
});

test('a rejected delivery is recorded with its status so a bad secret is visible', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();

    deliveryPost($endpoint, ['X-GitHub-Delivery' => 'rejected-1', 'X-GitHub-Event' => 'push'], signature: 'sha256=wrong')->assertStatus(401);

    $fresh = $endpoint->fresh();

    expect($fresh->last_delivery_id)->toBe('rejected-1')
        ->and($fresh->last_event)->toBe('push')
        ->and($fresh->last_status)->toBe(401)
        ->and($fresh->last_delivered_at)->not->toBeNull();
});

test('ignored and replayed deliveries are recorded too', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();

    deliveryPost($endpoint, ['X-GitHub-Delivery' => 'ping-1', 'X-GitHub-Event' => 'ping'], ['zen' => 'Keep it logically awesome.'])->assertOk();

    expect($endpoint->fresh()->only(['last_delivery_id', 'last_event', 'last_status']))
        ->toBe(['last_delivery_id' => 'ping-1', 'last_event' => 'ping', 'last_status' => 200]);

    deliveryPost($endpoint, ['X-GitHub-Delivery' => 'branch-1', 'X-GitHub-Event' => 'push'], ['ref' => 'refs/heads/other'])->assertStatus(202);

    expect($endpoint->fresh()->only(['last_delivery_id', 'last_status']))->toBe(['last_delivery_id' => 'branch-1', 'last_status' => 202]);

    $this->travel(5)->minutes();
    deliveryPost($endpoint, ['X-GitHub-Delivery' => 'branch-1', 'X-GitHub-Event' => 'push'], ['ref' => 'refs/heads/other'])->assertStatus(409);

    expect($endpoint->fresh()->last_status)->toBe(409)
        ->and($endpoint->fresh()->last_delivered_at?->toDateTimeString())->toBe('2026-09-13 14:10:09');
});

test('the event name is recorded for every provider', function (string $provider, array $headers, string $event) {
    Queue::fake();
    $endpoint = deliveryEndpoint(['provider' => $provider]);

    deliveryPost($endpoint, $headers)->assertStatus(202);

    expect($endpoint->fresh()->last_event)->toBe($event);
})->with([
    'github' => ['github', ['X-GitHub-Event' => 'push'], 'push'],
    'gitlab' => ['gitlab', ['X-Gitlab-Event' => 'Push Hook'], 'Push Hook'],
    'bitbucket' => ['bitbucket', ['X-Event-Key' => 'repo:push'], 'repo:push'],
    'custom' => ['custom', ['X-Coresmith-Event' => 'deploy'], 'deploy'],
]);

test('an event name carrying markup is not stored', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();

    deliveryPost($endpoint, ['X-GitHub-Event' => '<img src=x onerror=alert(1)>'])->assertStatus(202);

    expect($endpoint->fresh()->last_event)->toBeNull();
});

test('a webhook whose application was removed stops deploying and records why', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint();
    $endpoint->application->delete();

    deliveryPost($endpoint, ['X-GitHub-Delivery' => 'orphan-1'])
        ->assertStatus(404)
        ->assertExactJson(['message' => 'This webhook has no application.']);

    expect($endpoint->fresh()->last_status)->toBe(404);

    Queue::assertNothingPushed();
});

test('a disabled webhook records nothing about the delivery', function () {
    Queue::fake();
    $endpoint = deliveryEndpoint(['active' => false]);

    deliveryPost($endpoint, ['X-GitHub-Delivery' => 'disabled-1', 'X-GitHub-Event' => 'push'])->assertStatus(404);

    expect($endpoint->fresh()->only(['last_delivery_id', 'last_event', 'last_status', 'last_delivered_at']))
        ->toBe(['last_delivery_id' => null, 'last_event' => null, 'last_status' => null, 'last_delivered_at' => null]);
});
