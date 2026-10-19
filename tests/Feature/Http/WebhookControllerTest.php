<?php

declare(strict_types=1);

use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\WebhookEndpoint;
use App\Services\Webhook\SignatureVerifier;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

function endpointFor(array $applicationAttributes = [], array $endpointAttributes = []): WebhookEndpoint
{
    $application = Application::factory()->create(array_merge([
        'repository_branch' => 'main',
    ], $applicationAttributes));

    return WebhookEndpoint::factory()->for($application)->create(array_merge([
        'secret' => 'shhh-secret',
        'provider' => 'github',
        'active' => true,
    ], $endpointAttributes));
}

function signedPost(WebhookEndpoint $endpoint, array $payload, ?string $signature = null, string $header = 'X-Hub-Signature-256', array $headers = [])
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $server = ['CONTENT_TYPE' => 'application/json'];

    $signature ??= (new SignatureVerifier)->expected($body, (string) $endpoint->provider, (string) $endpoint->secret);

    foreach ([$header => $signature, ...$headers] as $name => $value) {
        $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
    }

    return test()->call('POST', route('webhooks.deploy', $endpoint), [], [], [], $server, $body);
}

test('a correctly signed push queues a deployment', function () {
    Queue::fake();
    $endpoint = endpointFor();

    signedPost($endpoint, ['ref' => 'refs/heads/main', 'after' => 'abc1234'])->assertStatus(202);

    Queue::assertPushed(DeployApplication::class);
    expect(Deployment::count())->toBe(1);
});

test('an unsigned request is rejected and queues nothing', function () {
    Queue::fake();
    $endpoint = endpointFor();

    signedPost($endpoint, ['ref' => 'refs/heads/main'], '')->assertStatus(401);

    Queue::assertNothingPushed();
    expect(Deployment::count())->toBe(0);
});

test('a wrong signature is rejected', function () {
    Queue::fake();
    $endpoint = endpointFor();

    signedPost($endpoint, ['ref' => 'refs/heads/main'], 'sha256=deadbeef')->assertStatus(401);

    Queue::assertNothingPushed();
});

test('a signature computed with a different secret is rejected', function () {
    Queue::fake();
    $endpoint = endpointFor();
    $body = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $forged = 'sha256=' . hash_hmac('sha256', $body, 'wrong-secret');

    signedPost($endpoint, ['ref' => 'refs/heads/main'], $forged)->assertStatus(401);

    Queue::assertNothingPushed();
});

test('a tampered body invalidates a previously valid signature', function () {
    Queue::fake();
    $endpoint = endpointFor();
    $original = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $signature = (new SignatureVerifier)->expected($original, 'github', 'shhh-secret');

    signedPost($endpoint, ['ref' => 'refs/heads/main', 'injected' => true], $signature)->assertStatus(401);

    Queue::assertNothingPushed();
});

test('a rejected signature is recorded as a security event', function () {
    Queue::fake();
    $endpoint = endpointFor();

    signedPost($endpoint, ['ref' => 'refs/heads/main'], 'sha256=nope');

    expect(Activity::where('description', 'webhook_signature_rejected')->count())->toBe(1);
});

test('a push to another branch is acknowledged but not deployed', function () {
    Queue::fake();
    $endpoint = endpointFor(['repository_branch' => 'main']);

    signedPost($endpoint, ['ref' => 'refs/heads/feature/x'])->assertStatus(202);

    Queue::assertNothingPushed();
    expect(Deployment::count())->toBe(0);
});

test('a disabled webhook does not reveal that it exists', function () {
    Queue::fake();
    $endpoint = endpointFor([], ['active' => false]);

    signedPost($endpoint, ['ref' => 'refs/heads/main'])->assertStatus(404);

    Queue::assertNothingPushed();
});

test('the message is recorded from the payload and the commit hash is left for git to record', function () {
    Queue::fake();
    $endpoint = endpointFor();

    signedPost($endpoint, [
        'ref' => 'refs/heads/main',
        'after' => str_repeat('c', 40),
        'head_commit' => ['message' => 'Ship the thing'],
    ])->assertStatus(202);

    $deployment = Deployment::first();

    expect($deployment->commit_hash)->toBeNull()
        ->and($deployment->commit_message)->toBe('Ship the thing');
});

test('a push records the commit it asked for and that a push triggered it', function (string $provider, string $header, array $payload, ?string $requested) {
    Queue::fake();
    $endpoint = endpointFor([], ['provider' => $provider]);

    signedPost($endpoint, $payload, null, $header)->assertStatus(202);

    expect(Deployment::sole()->only(['trigger', 'requested_commit', 'commit_hash']))->toBe([
        'trigger' => 'push',
        'requested_commit' => $requested,
        'commit_hash' => null,
    ]);
})->with([
    'github' => ['github', 'X-Hub-Signature-256', ['ref' => 'refs/heads/main', 'after' => str_repeat('c', 40)], str_repeat('c', 40)],
    'gitlab' => ['gitlab', 'X-Gitlab-Token', ['ref' => 'refs/heads/main', 'checkout_sha' => str_repeat('d', 40)], str_repeat('d', 40)],
    'a head that is not a full commit' => ['github', 'X-Hub-Signature-256', ['ref' => 'refs/heads/main', 'after' => 'c0ffee1234'], null],
    'a head in capitals' => ['github', 'X-Hub-Signature-256', ['ref' => 'refs/heads/main', 'after' => str_repeat('C', 40)], null],
]);

test('a long commit message keeps its first line', function (string $message, string $stored) {
    Queue::fake();

    signedPost(endpointFor(), ['ref' => 'refs/heads/main', 'head_commit' => ['message' => $message]])->assertStatus(202);

    expect(mb_strlen($message))->toBe(600)
        ->and(Deployment::sole()->commit_message)->toBe($stored);
})->with([
    'a short subject' => ['Ship the release notes page' . "\n" . str_repeat('x', 572), 'Ship the release notes page'],
    'a subject longer than the column' => [str_repeat('a', 300) . "\n" . str_repeat('b', 299), str_repeat('a', 250)],
]);

test('a github ping is answered without deploying', function () {
    Queue::fake();
    $endpoint = endpointFor();

    signedPost($endpoint, ['zen' => 'Keep it logically awesome.'], headers: ['X-GitHub-Event' => 'ping', 'X-GitHub-Delivery' => 'ping-1'])
        ->assertOk()
        ->assertExactJson(['message' => 'pong']);

    expect(Deployment::count())->toBe(0)
        ->and($endpoint->fresh()->only(['last_delivery_id', 'last_event', 'last_status']))->toBe(['last_delivery_id' => 'ping-1', 'last_event' => 'ping', 'last_status' => 200]);

    Queue::assertNothingPushed();
});

test('a github event that is not a push is acknowledged and ignored', function () {
    Queue::fake();
    $endpoint = endpointFor();

    signedPost($endpoint, ['ref' => 'refs/heads/main', 'action' => 'opened'], headers: ['X-GitHub-Event' => 'issues'])
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Event ignored.']);

    expect(Deployment::count())->toBe(0)
        ->and($endpoint->fresh()->last_status)->toBe(202);

    Queue::assertNothingPushed();
});

test('a github push event still deploys', function () {
    Queue::fake();

    signedPost(endpointFor(), ['ref' => 'refs/heads/main', 'after' => str_repeat('c', 40)], headers: ['X-GitHub-Event' => 'push'])
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Deployment queued.']);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('only github webhooks read github event names', function () {
    Queue::fake();
    $endpoint = endpointFor([], ['provider' => 'gitlab']);

    signedPost($endpoint, ['ref' => 'refs/heads/main'], null, 'X-Gitlab-Token', ['X-GitHub-Event' => 'issues'])
        ->assertStatus(202)
        ->assertExactJson(['message' => 'Deployment queued.']);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('gitlab uses its own header and a bare digest', function () {
    Queue::fake();
    $endpoint = endpointFor([], ['provider' => 'gitlab']);
    $body = json_encode(['ref' => 'refs/heads/main'], JSON_THROW_ON_ERROR);
    $digest = hash_hmac('sha256', $body, 'shhh-secret');

    signedPost($endpoint, ['ref' => 'refs/heads/main'], $digest, 'X-Gitlab-Token')->assertStatus(202);

    Queue::assertPushed(DeployApplication::class);
});

test('a github signature presented on the gitlab header is rejected', function () {
    Queue::fake();
    $endpoint = endpointFor();

    signedPost($endpoint, ['ref' => 'refs/heads/main'], null, 'X-Gitlab-Token')->assertStatus(401);

    Queue::assertNothingPushed();
});

test('the endpoint is rate limited', function () {
    $endpoint = endpointFor();

    expect(collect(Route::getRoutes()->getByName('webhooks.deploy')->gatherMiddleware()))
        ->toContain('throttle:30,1,webhook-deploy');
});
