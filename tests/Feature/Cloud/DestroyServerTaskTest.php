<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Enums\ServerStatus;
use App\Exceptions\CloudProviderException;
use App\Jobs\RunCloudTask;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\User;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\CloudTaskHandlers;
use App\Services\Cloud\Tasks\CreateServerTask;
use App\Services\Deployment\LogSanitizer;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeCloudApi;
use Tests\Support\FakeCloudApiFactory;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([RunCloudTask::class]);
    Sleep::fake();

    $this->api = new FakeCloudApi;
    app()->instance(CloudApiFactory::class, new FakeCloudApiFactory($this->api));
});

function cloudDestroying(array $attributes = [], array $data = []): Server
{
    $server = Server::factory()->fromProvider(CloudProvider::factory()->create())->create([
        'name' => 'outpost-02',
        'status' => ServerStatus::Active,
        ...$attributes,
    ]);

    CloudTask::start($server, CloudTask::DESTROY, 'delete', $data, User::factory()->create());

    return $server;
}

function cloudDestroyingRun(Server $server, int $jobs = 40): void
{
    $job = new RunCloudTask($server);

    for ($run = 0; $run < $jobs; $run++) {
        $queued = Queue::pushed(RunCloudTask::class)->count();
        (new UniqueLock(app(Repository::class)))->release($job);

        $job->handle(app(CloudApiFactory::class), app(CloudTaskHandlers::class), app(LogSanitizer::class));

        $pushed = Queue::pushed(RunCloudTask::class);

        if ($pushed->count() === $queued) {
            return;
        }

        $job = $pushed->last();
    }
}

test('destroying deletes the instance and removes the server once the provider confirms', function () {
    $server = cloudDestroying();
    $by = CloudTask::of($server)->by;

    cloudDestroyingRun($server);

    $activity = Activity::query()->where('description', 'server_destroyed')->sole();

    expect($this->api->calls('destroy'))->toBe([['destroy', ['4711']]])
        ->and(Server::query()->find($server->id))->toBeNull()
        ->and(Server::withTrashed()->find($server->id))->not->toBeNull()
        ->and($activity->causer_id)->toBe($by)
        ->and($activity->properties->toArray())->toBeInAnyKeyOrder(['provider' => 'Hetzner Cloud', 'size' => 'cx22']);
});

test('an instance whose id was never saved is found by its tag and destroyed', function () {
    $this->api->tagged(new CloudInstance('4799', CloudInstanceState::Running, '203.0.113.9', 'cx22'));
    $server = cloudDestroying(['provider_server_id' => null]);

    cloudDestroyingRun($server);

    expect($this->api->calls('findTagged'))->toBe([['findTagged', [CreateServerTask::tag($server)]]])
        ->and($this->api->calls('destroy'))->toBe([['destroy', ['4799']]])
        ->and(Server::query()->find($server->id))->toBeNull();
});

test('nothing at the provider means nothing to destroy and the server still leaves the fleet', function () {
    $this->api->tagged(null);
    $server = cloudDestroying(['provider_server_id' => null]);

    cloudDestroyingRun($server);

    expect($this->api->calls('destroy'))->toBe([])
        ->and(Server::query()->find($server->id))->toBeNull();
});

test('an instance the provider already deleted still leaves the fleet', function () {
    $this->api->failing('destroy', new CloudProviderException(CloudFailure::NotFound, CloudProviderType::Hetzner));
    $server = cloudDestroying();

    cloudDestroyingRun($server);

    expect(Server::query()->find($server->id))->toBeNull()
        ->and($this->api->calls('find'))->toBe([]);
});

test('a refusal says what to fix and keeps the server', function () {
    $this->api->failing('destroy', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'server is protected'));
    $server = cloudDestroying();

    cloudDestroyingRun($server);

    $task = CloudTask::of($server->fresh());

    expect(Server::query()->find($server->id))->not->toBeNull()
        ->and($task->state)->toBe(CloudTask::FAILED)
        ->and($task->error)->toBe("Hetzner Cloud refused the request. Hetzner Cloud said: server is protected. Try again once that's fixed, or delete it in the Hetzner Cloud console and then detach it here.")
        ->and(Activity::query()->where('description', 'server_destroy_failed')->sole()->properties['reason'])->toBe($task->error)
        ->and(Activity::query()->where('description', 'server_destroy_failed')->sole()->causer?->getKey())->toBe($task->by);
});

test('a provider that stays unreachable is reported with what is still billed', function () {
    $this->api->failing('destroy', new CloudProviderException(CloudFailure::Unreachable, CloudProviderType::Hetzner), 99);
    $server = cloudDestroying();

    cloudDestroyingRun($server);

    expect(CloudTask::of($server->fresh())->state)->toBe(CloudTask::RUNNING);

    $this->travel(11)->minutes();
    cloudDestroyingRun($server);

    $task = CloudTask::of($server->fresh());

    expect($task->state)->toBe(CloudTask::FAILED)
        ->and($task->error)->toStartWith("Coresmith couldn't destroy outpost-02 at Hetzner Cloud within 10 minutes. The last error was: Coresmith couldn't reach Hetzner Cloud")
        ->and($task->error)->toEndWith('It may still be running and billed: try again, or delete it in the Hetzner Cloud console and then detach it here.')
        ->and(Server::query()->find($server->id))->not->toBeNull();
});

test('a key left over from creating it is removed with it', function () {
    $server = cloudDestroying(data: ['key_id' => 'key-9']);

    cloudDestroyingRun($server);

    expect($this->api->calls('deleteKey'))->toBe([['deleteKey', ['key-9']]])
        ->and(Server::query()->find($server->id))->toBeNull();
});

function cloudDestroyingInstance(CloudInstanceState $state = CloudInstanceState::Deleting, string $id = '4711'): CloudInstance
{
    return new CloudInstance($id, $state, '203.0.113.9', 'cx22');
}

test('a destroy the provider accepted is only finished once the server is gone', function () {
    $this->api->finds(cloudDestroyingInstance(), cloudDestroyingInstance(CloudInstanceState::Running), null);
    $server = cloudDestroying();

    cloudDestroyingRun($server);

    expect($this->api->calls('destroy'))->toBe([['destroy', ['4711']]])
        ->and($this->api->calls('find'))->toBe([['find', ['4711']], ['find', ['4711']], ['find', ['4711']]])
        ->and(Server::query()->find($server->id))->toBeNull()
        ->and(Activity::query()->where('description', 'server_destroyed')->count())->toBe(1);
});

test('a destroy is not finished while the provider still shows the server', function () {
    $this->api->finds(cloudDestroyingInstance());
    $server = cloudDestroying();

    cloudDestroyingRun($server, jobs: 1);

    $task = CloudTask::of($server->fresh());

    expect(Server::query()->find($server->id))->not->toBeNull()
        ->and($task->state)->toBe(CloudTask::RUNNING)
        ->and($task->data['requested'] ?? null)->toBeTrue()
        ->and(Activity::query()->where('description', 'server_destroyed')->exists())->toBeFalse();

    Queue::assertPushed(RunCloudTask::class, 1);
});

test('a server the provider keeps showing until the deadline is kept and reported', function () {
    $this->api->finds(cloudDestroyingInstance());
    $server = cloudDestroying();

    cloudDestroyingRun($server);
    $this->travel(11)->minutes();
    cloudDestroyingRun($server);

    $task = CloudTask::of($server->fresh());

    expect($this->api->calls('destroy'))->toHaveCount(1)
        ->and($task->state)->toBe(CloudTask::FAILED)
        ->and($task->error)->toBe("Coresmith couldn't destroy outpost-02 at Hetzner Cloud within 10 minutes. It may still be running and billed: try again, or delete it in the Hetzner Cloud console and then detach it here.")
        ->and(Server::query()->find($server->id))->not->toBeNull();
});

test('a server found by its tag is watched by its own id until it is gone', function () {
    $this->api->tagged(cloudDestroyingInstance(CloudInstanceState::Running, '4799'))->finds(cloudDestroyingInstance(id: '4799'), null);
    $server = cloudDestroying(['provider_server_id' => null]);

    cloudDestroyingRun($server);

    expect($this->api->calls('destroy'))->toBe([['destroy', ['4799']]])
        ->and($this->api->calls('find'))->toBe([['find', ['4799']], ['find', ['4799']]])
        ->and($this->api->calls('findTagged'))->toHaveCount(1)
        ->and(Server::query()->find($server->id))->toBeNull();
});

test('trying a destroy again after it timed out asks the provider again', function () {
    $this->api->finds(cloudDestroyingInstance());
    $server = cloudDestroying();

    cloudDestroyingRun($server);
    $this->travel(11)->minutes();
    cloudDestroyingRun($server);

    $this->api->finds(null);
    CloudTask::resume($server->fresh());
    cloudDestroyingRun($server);

    expect($this->api->calls('destroy'))->toHaveCount(2)
        ->and(Server::query()->find($server->id))->toBeNull();
});
