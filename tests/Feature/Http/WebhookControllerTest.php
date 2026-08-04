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

function signedPost(WebhookEndpoint $endpoint, array $payload, ?string $signature = null, string $header = 'X-Hub-Signature-256')
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $signature ??= (new SignatureVerifier)->expected($body, (string) $endpoint->provider, (string) $endpoint->secret);

    return test()->call(
        'POST',
        route('webhooks.deploy', $endpoint),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_'.str_replace('-', '_', strtoupper($header)) => $signature],
        $body,
    );
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
    $forged = 'sha256='.hash_hmac('sha256', $body, 'wrong-secret');

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

test('the commit hash and message are recorded from the payload', function () {
    Queue::fake();
    $endpoint = endpointFor();

    signedPost($endpoint, [
        'ref' => 'refs/heads/main',
        'after' => 'c0ffee1234',
        'head_commit' => ['message' => 'Ship the thing'],
    ])->assertStatus(202);

    $deployment = Deployment::first();

    expect($deployment->commit_hash)->toBe('c0ffee1234')
        ->and($deployment->commit_message)->toBe('Ship the thing');
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
        ->toContain('throttle:30,1');
});
