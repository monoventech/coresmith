<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Enums\ServerStatus;
use App\Exceptions\CloudProviderException;
use App\Jobs\AuditServer;
use App\Jobs\ProvisionServer;
use App\Jobs\ResumeCloudTasks;
use App\Jobs\RunCloudTask;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\User;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\CloudTaskHandlers;
use App\Services\Cloud\Tasks\CreateServerTask;
use App\Services\Deployment\LogSanitizer;
use App\Services\Server\ProvisioningLog;
use App\Services\SSH\HostKeyFingerprint;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeCloudApi;
use Tests\Support\FakeCloudApiFactory;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([AuditServer::class, ProvisionServer::class, RunCloudTask::class]);

    $this->api = cloudCreatingApi(new FakeCloudApi);
    $this->transport = (new FakeSSHTransport)->respondTo('cloud-init status', "status: done\napt: idle");

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $this->transport);
});

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

function cloudCreatingApi(FakeCloudApi $api): FakeCloudApi
{
    app()->instance(CloudApiFactory::class, new FakeCloudApiFactory($api));

    return $api->finds(cloudCreatingInstance());
}

function cloudCreating(?CloudProvider $account = null): Server
{
    $server = Server::factory()
        ->creating()
        ->fromProvider($account ?? CloudProvider::factory()->create())
        ->create(['provider_server_id' => null, 'name' => 'outpost-02']);

    CloudTask::start($server, CloudTask::CREATE, 'create', [], User::factory()->create());

    return $server;
}

function cloudCreatingAt(string $step, ?string $ip = null, array $data = []): Server
{
    $server = cloudCreating();
    $server->forceFill(['provider_server_id' => '4711', 'ip_address' => $ip])->save();

    CloudTask::advance($server, $step, $data);

    return $server;
}

function cloudCreatingRun(Server|RunCloudTask $server): void
{
    $job = $server instanceof RunCloudTask ? $server : new RunCloudTask($server);

    $job->handle(app(CloudApiFactory::class), app(CloudTaskHandlers::class), app(LogSanitizer::class));
}

function cloudCreatingTask(Server $server): ?CloudTask
{
    return CloudTask::of($server->fresh());
}

function cloudCreatingInstance(CloudInstanceState $state = CloudInstanceState::Running, ?string $ip = '203.0.113.40', string $id = '4711'): CloudInstance
{
    return new CloudInstance($id, $state, $ip, 'cx22');
}

function cloudCreatingFailure(CloudFailure $failure, ?string $detail = null, ?int $status = null): CloudProviderException
{
    return new CloudProviderException($failure, CloudProviderType::Hetzner, $detail, $status);
}

function cloudCreatingSlept(int $seconds): Closure
{
    return fn(CarbonInterval $duration): bool => (int) $duration->totalSeconds === $seconds;
}

test('a new server is created once, booted, reached and handed to the audit', function () {
    $this->freezeTime();
    $account = CloudProvider::factory()->create();
    CloudProvider::factory()->create(['team_id' => $account->team_id]);
    $server = cloudCreating($account);
    $user = User::findOrFail(cloudCreatingTask($server)->by);
    $tag = 'coresmith-' . strtolower((string) $server->getKey());
    $keyName = 'coresmith-outpost-02-' . strtolower(substr((string) $server->getKey(), -6));
    $this->api->finds(cloudCreatingInstance(CloudInstanceState::Starting, null), cloudCreatingInstance());
    $this->travel(200)->seconds();

    cloudCreatingRun($server);

    $fresh = $server->fresh();
    $creates = $this->api->calls('create');
    $activity = Activity::query()->where('description', 'server_created_at_provider')->sole();

    expect($creates)->toHaveCount(1)
        ->and($creates[0][1][0])->toEqual(new CloudServerSpec('outpost-02', 'fsn1', 'cx22', 'ubuntu-24.04', $tag, $server->ssh_public_key, 'key-1'))
        ->and(CreateServerTask::tag($server))->toBe($tag)
        ->and(CreateServerTask::keyName($server))->toBe($keyName)
        ->and($this->api->calls('findTagged'))->toBe([['findTagged', [$tag]]])
        ->and($this->api->calls('uploadKey'))->toBe([['uploadKey', [$keyName, $server->ssh_public_key]]])
        ->and($this->api->calls('find'))->toBe([['find', ['4711']], ['find', ['4711']]])
        ->and($this->api->calls('deleteKey'))->toBe([['deleteKey', ['key-1']]])
        ->and($fresh)
        ->ip_address->toBe('203.0.113.40')
        ->provider_server_id->toBe('4711')
        ->status->toBe(ServerStatus::Pending)
        ->last_connected_at->not->toBeNull()
        ->provisioning_log->toContain('Hetzner Cloud accepted it as server 4711, waiting for it to boot')
        ->provisioning_log->toContain('running at 203.0.113.40, waiting for SSH')
        ->provisioning_log->toContain('signed in over SSH, waiting for first-boot setup to finish')
        ->provisioning_log->toContain('first-boot setup finished, checking the server before provisioning')
        ->and($fresh->audit['state'])->toBe('running')
        ->and(cloudCreatingTask($server))
        ->state->toBe(CloudTask::DONE)
        ->data->toMatchArray(['key_id' => null, 'uncertain' => 0])
        ->and($activity->subject?->is($server))->toBeTrue()
        ->and($activity->causer?->is($user))->toBeTrue()
        ->and($activity->properties->toArray())->toBeInAnyKeyOrder(['provider' => 'Hetzner Cloud', 'size' => 'cx22', 'region' => 'fsn1', 'seconds' => 200])
        ->and(array_values(array_unique(app(CloudApiFactory::class)->accounts())))->toBe([(string) $account->getKey()])
        ->and(in_array(CreateServerTask::SETTLE_PROBE, $this->transport->commands(), true))->toBeTrue()
        ->and($this->transport->connected())->toBeFalse()
        ->and([CreateServerTask::STEPS, CreateServerTask::POLL_SECONDS, CreateServerTask::SETTLE_SECONDS, CreateServerTask::UNCERTAIN_LIMIT])->toBe([['create', 'boot', 'ssh', 'settle'], 5, 10, 2])
        ->and(app(CloudTaskHandlers::class)->for(CloudTask::CREATE))->toBeInstanceOf(CreateServerTask::class);
    Sleep::assertSlept(cloudCreatingSlept(CreateServerTask::POLL_SECONDS), 1);
    Sleep::assertSleptTimes(1);
    Queue::assertPushed(AuditServer::class, 1);
    Queue::assertPushed(AuditServer::class, fn(AuditServer $job): bool => (fn() => $this->provisionAfter)->call($job) === true
        && (fn() => $this->server)->call($job)->is($server));
    Queue::assertNotPushed(ProvisionServer::class);
    Queue::assertNotPushed(RunCloudTask::class);
});

test("an earlier attempt's server is adopted instead of creating a second one", function () {
    $server = cloudCreating();
    $this->api->tagged(cloudCreatingInstance(id: '4712'))->finds(cloudCreatingInstance(id: '4712'));

    cloudCreatingRun($server);

    expect($this->api->calls('create'))->toBe([])
        ->and($this->api->calls('uploadKey'))->toBe([])
        ->and($this->api->calls('deleteKey'))->toBe([])
        ->and($this->api->calls('find'))->toBe([['find', ['4712']]])
        ->and($server->fresh())
        ->provider_server_id->toBe('4712')
        ->status->toBe(ServerStatus::Pending)
        ->provisioning_log->toContain('found Hetzner Cloud server 4712 from an earlier attempt, using it')
        ->and(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE);
    Queue::assertPushed(AuditServer::class, 1);
});

test('a create whose answer was lost is found by its tag instead of created twice', function () {
    $server = cloudCreating();
    $this->api->failing('create', cloudCreatingFailure(CloudFailure::Unavailable, status: 503))
        ->tagged(null, cloudCreatingInstance());

    cloudCreatingRun($server);

    expect($this->api->calls('create'))->toHaveCount(1)
        ->and($this->api->calls('findTagged'))->toHaveCount(2)
        ->and($this->api->calls('uploadKey'))->toHaveCount(1)
        ->and($this->api->calls('deleteKey'))->toBe([['deleteKey', ['key-1']]])
        ->and($server->fresh())
        ->provider_server_id->toBe('4711')
        ->status->toBe(ServerStatus::Pending)
        ->provisioning_log->toContain('found Hetzner Cloud server 4711 from an earlier attempt, using it')
        ->provisioning_log->not->toContain('accepted it as server')
        ->and(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE);
    Sleep::assertSlept(cloudCreatingSlept(15), 1);
    Sleep::assertSleptTimes(1);
});

test('after three unanswered creates it stops and says where to look', function () {
    $server = cloudCreating();
    $this->api->failing('create', cloudCreatingFailure(CloudFailure::Unreachable), 3);

    cloudCreatingRun($server);

    $reason = "Coresmith couldn't get an answer from Hetzner Cloud after three tries. Check the Hetzner Cloud console for a server called outpost-02: if there is one, delete it there, then remove this server and provision it again.";

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->step->toBe('create')
        ->error->toBe($reason)
        ->data->toMatchArray(['uncertain' => 3, 'key_id' => null])
        ->and(cloudCreatingTask($server)->data['uncertain'])->toBeGreaterThan(CreateServerTask::UNCERTAIN_LIMIT)
        ->and($this->api->calls('create'))->toHaveCount(3)
        ->and($this->api->calls('findTagged'))->toHaveCount(3)
        ->and($this->api->calls('uploadKey'))->toHaveCount(1)
        ->and($this->api->calls('deleteKey'))->toBe([['deleteKey', ['key-1']]])
        ->and($server->fresh())
        ->status->toBe(ServerStatus::Creating)
        ->provider_server_id->toBeNull()
        ->provisioning_log->toContain("failed: {$reason}");
    Sleep::assertSlept(cloudCreatingSlept(15), 2);
    Sleep::assertSleptTimes(2);
    Queue::assertNothingPushed();
});

test('a refused create says what to do and removes the uploaded key', function () {
    $server = cloudCreating();
    $this->api->failing('create', cloudCreatingFailure(CloudFailure::Refused, 'server type cx22 is not available in hel1', 422));

    cloudCreatingRun($server);

    $reason = 'Hetzner Cloud refused the request. Hetzner Cloud said: server type cx22 is not available in hel1. Remove this server and provision it again with another size or region.';
    $activity = Activity::query()->where('description', 'server_creation_failed')->sole();

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe($reason)
        ->data->toMatchArray(['key_id' => null])
        ->and($this->api->calls('create'))->toHaveCount(1)
        ->and($this->api->calls('deleteKey'))->toBe([['deleteKey', ['key-1']]])
        ->and($server->fresh())
        ->status->toBe(ServerStatus::Creating)
        ->provider_server_id->toBeNull()
        ->provisioning_log->toContain("failed: {$reason}")
        ->and($activity->subject?->is($server))->toBeTrue()
        ->and($activity->properties->toArray())->toBeInAnyKeyOrder(['provider' => 'Hetzner Cloud', 'step' => 'create', 'reason' => $reason])
        ->and(Activity::query()->where('description', 'server_created_at_provider')->exists())->toBeFalse();
    Sleep::assertNeverSlept();
    Queue::assertNothingPushed();
});

test('a create the provider will not take for another reason fails in its own words', function () {
    $server = cloudCreating();
    $refusal = cloudCreatingFailure(CloudFailure::Forbidden);
    $this->api->failing('create', $refusal);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe($refusal->getMessage())
        ->and($this->api->calls('create'))->toHaveCount(1);
    Sleep::assertNeverSlept();
});

test('a provider that takes keys inline gets the public key in the request', function () {
    $api = cloudCreatingApi(new FakeCloudApi(CloudProviderType::Linode, uploads: false));
    $server = cloudCreating(CloudProvider::factory()->linode()->create());

    cloudCreatingRun($server);

    $creates = $api->calls('create');

    expect($api->calls('uploadKey'))->toBe([])
        ->and($api->calls('deleteKey'))->toBe([])
        ->and($creates)->toHaveCount(1)
        ->and($creates[0][1][0]->keyId)->toBeNull()
        ->and($creates[0][1][0]->publicKey)->toBe($server->ssh_public_key)
        ->and($server->fresh()->provisioning_log)->toContain('Linode accepted it as server 4711, waiting for it to boot')
        ->and(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and(Activity::query()->where('description', 'server_created_at_provider')->sole()->properties['provider'])->toBe('Linode');
});

test('a server that vanished while booting fails clearly', function () {
    $server = cloudCreating();
    $this->api->finds(null);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->step->toBe('boot')
        ->error->toBe('Hetzner Cloud says server 4711 no longer exists. Remove this server and provision it again.')
        ->and($this->api->calls('find'))->toHaveCount(2)
        ->and($server->fresh()->status)->toBe(ServerStatus::Creating);
    Sleep::assertSlept(cloudCreatingSlept(CreateServerTask::POLL_SECONDS), 1);
});

test('a server the provider does not list yet right after it was created is waited for', function () {
    $server = cloudCreating();
    $this->api->finds(null, cloudCreatingInstance());

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and($server->fresh()->ip_address)->toBe('203.0.113.40');
    Sleep::assertSlept(cloudCreatingSlept(CreateServerTask::POLL_SECONDS), 1);
    Sleep::assertSleptTimes(1);
});

test('booting past its deadline fails with the boot copy', function () {
    $server = cloudCreatingAt('boot');
    $this->travel(11)->minutes();

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe("Hetzner Cloud didn't finish starting outpost-02 within 10 minutes. It may still be starting: try again to keep waiting, or destroy it.")
        ->and($this->api->calls('find'))->toBe([])
        ->and($server->fresh()->status)->toBe(ServerStatus::Creating);
});

test('ssh is tried again until the key works', function () {
    $server = cloudCreating();
    $this->transport->refuseConnections(2)->failLogin(1);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and($server->fresh())
        ->status->toBe(ServerStatus::Pending)
        ->last_connected_at->not->toBeNull()
        ->host_key_fingerprint->toBe(HostKeyFingerprint::fromPublicKey(FakeSSHTransport::HOST_KEY))
        ->provisioning_log->toContain('signed in over SSH, waiting for first-boot setup to finish')
        ->and($this->transport->connected())->toBeFalse();
    Sleep::assertSlept(cloudCreatingSlept(CreateServerTask::POLL_SECONDS), 3);
    Sleep::assertSleptTimes(3);
});

test('a connection kept from before the server had an address is not reused', function () {
    $server = cloudCreating();
    app(SSHConnectionManager::class)->for($server);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and($server->fresh()->ip_address)->toBe('203.0.113.40');
    Sleep::assertNeverSlept();
});

test('a failed ssh attempt keeps a short, cleaned copy of what went wrong', function () {
    $server = cloudCreatingAt('ssh', '203.0.113.40');
    $this->transport->refuseConnections(message: 'Cannot connect to 203.0.113.40:22 as Bearer sk-live-abcdef0123456789 here. ' . str_repeat('Connection refused. ', 20));

    cloudCreatingRun($server);

    $error = cloudCreatingTask($server)->data['last_error'];

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::RUNNING)
        ->step->toBe('ssh')
        ->and($error)->toStartWith('Cannot connect to 203.0.113.40:22 as Bearer [REDACTED] here. Connection refused.')
        ->and($error)->not->toContain('sk-live-abcdef0123456789')
        ->and(mb_strlen($error))->toBeLessThanOrEqual(160)
        ->and($error)->toEndWith('...');
    Sleep::assertSlept(cloudCreatingSlept(CreateServerTask::POLL_SECONDS), 18);
    Queue::assertPushed(RunCloudTask::class, fn(RunCloudTask $job): bool => $job->uniqueId() === (string) $server->getKey());
});

test('an ssh error is kept whole up to 160 characters and cut with a mark beyond that', function (string $message, string $kept) {
    $server = cloudCreatingAt('ssh', '203.0.113.40');
    $this->transport->refuseConnections(message: $message);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->data['last_error'])->toBe($kept);
})->with([
    'exactly 160 characters' => [str_repeat('x', 160), str_repeat('x', 160)],
    'one character more' => [str_repeat('x', 161), str_repeat('x', 157) . '...'],
    'a cut that lands on a space' => [str_repeat('x', 156) . ' ' . str_repeat('y', 10), str_repeat('x', 156) . '...'],
]);

test('a changed host key while starting stops creation', function () {
    $server = cloudCreating();
    $server->forceFill(['host_key_fingerprint' => 'SHA256:pinned-before-it-started'])->save();

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->step->toBe('ssh')
        ->error->toBe('outpost-02 presented a different SSH host key while it was starting. Review it on this page before trying again.')
        ->and($server->fresh())
        ->status->toBe(ServerStatus::Creating)
        ->host_key_fingerprint->toBe('SHA256:pinned-before-it-started')
        ->pending_host_key_fingerprint->toBe(HostKeyFingerprint::fromPublicKey(FakeSSHTransport::HOST_KEY))
        ->and($this->transport->connected())->toBeFalse();
    Sleep::assertNeverSlept();
});

test('ssh past its deadline says where the server is and what went wrong last', function (array $data, string $error) {
    $server = cloudCreatingAt('ssh', '203.0.113.40', $data);
    $this->travel(11)->minutes();

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe($error)
        ->and($this->transport->commands())->toBe([]);
})->with([
    'with the last error' => [
        ['last_error' => 'SSH authentication failed for root@203.0.113.40.'],
        "outpost-02 is running at 203.0.113.40, but Coresmith couldn't sign in over SSH within 10 minutes. The last error was: SSH authentication failed for root@203.0.113.40. Try again to keep waiting, or destroy it and provision another.",
    ],
    'with nothing recorded' => [
        [],
        "outpost-02 is running at 203.0.113.40, but Coresmith couldn't sign in over SSH within 10 minutes. Try again to keep waiting, or destroy it and provision another.",
    ],
    'with a last error that was cut' => [
        ['last_error' => 'Cannot connect to 203.0.113.40:22. Error 110. Connection timed out while the server was still star...'],
        "outpost-02 is running at 203.0.113.40, but Coresmith couldn't sign in over SSH within 10 minutes. The last error was: Cannot connect to 203.0.113.40:22. Error 110. Connection timed out while the server was still star... Try again to keep waiting, or destroy it and provision another.",
    ],
]);

test('first-boot setup is waited for', function () {
    $server = cloudCreating();
    $this->transport->respondToSequence('cloud-init status', [["status: running\napt: idle"], ["status: done\napt: busy"], ["status: done\napt: idle"]]);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and($server->fresh()->provisioning_log)->toContain('first-boot setup finished, checking the server before provisioning')
        ->and(array_count_values($this->transport->commands())[CreateServerTask::SETTLE_PROBE])->toBe(3)
        ->and($this->transport->budgetFor('cloud-init status'))->toBe(15)
        ->and($this->transport->budgetFor('true'))->toBe(15);
    Sleep::assertSlept(cloudCreatingSlept(CreateServerTask::SETTLE_SECONDS), 2);
    Sleep::assertSleptTimes(2);
});

test('first-boot setup counts as finished once cloud-init and apt are both quiet', function (string $output, bool $waits) {
    $server = cloudCreatingAt('settle', '203.0.113.40');
    $this->transport->respondToSequence('cloud-init status', [[$output], ["status: done\napt: idle"]]);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE);
    Sleep::assertSleptTimes($waits ? 1 : 0);
})->with([
    'cloud-init running' => ["status: running\napt: idle", true],
    'cloud-init not started' => ["status: not started\napt: idle", true],
    'cloud-init running, padded' => ["status:   running  \napt: idle", true],
    'apt still busy' => ["status: done\napt: busy", true],
    'both quiet' => ["status: done\napt: idle", false],
    'no cloud-init on the image' => ["status: absent\napt: idle", false],
    'cloud-init finished with errors' => ["status: error\napt: idle", false],
]);

test('first-boot setup that cannot be checked is checked again', function () {
    $server = cloudCreatingAt('settle', '203.0.113.40');
    $this->transport->refuseConnections(1);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and($this->transport->connected())->toBeFalse();
    Sleep::assertSlept(cloudCreatingSlept(CreateServerTask::SETTLE_SECONDS), 1);
    Sleep::assertSleptTimes(1);
});

test('first-boot setup that never finishes is not a failure', function () {
    $server = cloudCreatingAt('settle', '203.0.113.40');
    $this->transport->respondTo('cloud-init status', "status: running\napt: busy");
    $this->travel(16)->minutes();

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and($server->fresh())
        ->status->toBe(ServerStatus::Pending)
        ->provisioning_log->toContain('first-boot setup was still running after 15 minutes, carrying on')
        ->and($this->transport->commands())->toBe([]);
    Queue::assertPushed(AuditServer::class, 1);
});

test('retrying a failed boot keeps waiting rather than creating again', function () {
    $server = cloudCreatingAt('boot');
    CloudTask::fail($server, "Hetzner Cloud didn't finish starting outpost-02 within 10 minutes. It may still be starting: try again to keep waiting, or destroy it.");
    CloudTask::resume($server);

    cloudCreatingRun($server);

    expect($this->api->calls('find'))->toBe([['find', ['4711']]])
        ->and($this->api->calls('findTagged'))->toBe([])
        ->and($this->api->calls('uploadKey'))->toBe([])
        ->and($this->api->calls('create'))->toBe([])
        ->and(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and($server->fresh()->status)->toBe(ServerStatus::Pending);
});

test('a creation whose worker died is resumed at the step it reached', function () {
    $server = cloudCreatingAt('boot', data: ['key_id' => 'key-1', 'uncertain' => 0]);
    $this->travel(3)->minutes();

    (new ResumeCloudTasks)->handle();

    Queue::assertPushed(RunCloudTask::class, 1);

    cloudCreatingRun(Queue::pushed(RunCloudTask::class)->sole());

    expect($this->api->calls('uploadKey'))->toBe([])
        ->and($this->api->calls('create'))->toBe([])
        ->and($this->api->calls('findTagged'))->toBe([])
        ->and($this->api->calls('deleteKey'))->toBe([['deleteKey', ['key-1']]])
        ->and(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and($server->fresh()->status)->toBe(ServerStatus::Pending);
});

test('a create that was already accepted moves on without asking again', function () {
    $server = cloudCreating();
    $server->forceFill(['provider_server_id' => '4711'])->save();

    cloudCreatingRun($server);

    expect($this->api->calls('findTagged'))->toBe([])
        ->and($this->api->calls('create'))->toBe([])
        ->and(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE);
});

test('a create past its deadline that was already accepted moves on', function () {
    $server = cloudCreating();
    $server->forceFill(['provider_server_id' => '4711'])->save();
    $this->travel(16)->minutes();

    cloudCreatingRun($server);

    expect($this->api->calls('findTagged'))->toBe([])
        ->and($this->api->calls('create'))->toBe([])
        ->and(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE);
});

test('a create past its deadline adopts a server it finds by its tag', function () {
    $server = cloudCreating();
    $this->api->tagged(cloudCreatingInstance());
    $this->travel(16)->minutes();

    cloudCreatingRun($server);

    expect($this->api->calls('create'))->toBe([])
        ->and($this->api->calls('findTagged'))->toHaveCount(1)
        ->and($server->fresh())
        ->provider_server_id->toBe('4711')
        ->provisioning_log->toContain('found Hetzner Cloud server 4711 from an earlier attempt, using it')
        ->and(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE);
});

test('a create past its deadline says why it stopped', function (array $data, ?int $lookupFails, string $error) {
    $server = cloudCreating();
    CloudTask::touch($server, $data);

    if ($lookupFails !== null) {
        $this->api->failing('findTagged', cloudCreatingFailure(CloudFailure::Unavailable, status: $lookupFails));
    }

    $this->travel(16)->minutes();

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->step->toBe('create')
        ->error->toBe($error)
        ->and($this->api->calls('findTagged'))->toHaveCount(1)
        ->and($this->api->calls('uploadKey'))->toBe([])
        ->and($this->api->calls('create'))->toBe([]);
    Sleep::assertNeverSlept();
})->with([
    'before anything was sent' => [
        [],
        null,
        'Hetzner Cloud kept failing for 15 minutes before Coresmith could ask it to create outpost-02. Try again.',
    ],
    'with the last error' => [
        ['last_error' => 'Hetzner Cloud is rate limiting this account. Coresmith tries again shortly.'],
        null,
        'Hetzner Cloud kept failing for 15 minutes before Coresmith could ask it to create outpost-02. The last error was: Hetzner Cloud is rate limiting this account. Coresmith tries again shortly. Try again.',
    ],
    'when the last look fails too' => [
        ['last_error' => 'Hetzner Cloud is rate limiting this account. Coresmith tries again shortly.'],
        503,
        'Hetzner Cloud kept failing for 15 minutes before Coresmith could ask it to create outpost-02. The last error was: Hetzner Cloud had a problem answering (HTTP 503). Try again in a minute. Try again.',
    ],
    'after a create that went unanswered' => [
        ['uncertain' => 1],
        503,
        "Coresmith couldn't get an answer from Hetzner Cloud after three tries. Check the Hetzner Cloud console for a server called outpost-02: if there is one, delete it there, then remove this server and provision it again.",
    ],
]);

test('a failed last look before the deadline copy is remembered as the last error', function () {
    $server = cloudCreating();
    $this->api->failing('findTagged', cloudCreatingFailure(CloudFailure::Unavailable, status: 502));
    $this->travel(16)->minutes();

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->data)->toMatchArray(['last_error' => 'Hetzner Cloud had a problem answering (HTTP 502). Try again in a minute.']);
});

test('a key the provider would not remove after creating is named for the user to delete', function () {
    $server = cloudCreating();
    $this->api->failing('deleteKey', cloudCreatingFailure(CloudFailure::Unavailable, status: 503));

    cloudCreatingRun($server);

    expect($server->fresh())
        ->status->toBe(ServerStatus::Pending)
        ->provisioning_log->toContain("couldn't remove the uploaded key from Hetzner Cloud, delete " . CreateServerTask::keyName($server) . ' there')
        ->and(cloudCreatingTask($server))
        ->state->toBe(CloudTask::DONE)
        ->data->toMatchArray(['key_id' => 'key-1']);
    Queue::assertPushed(AuditServer::class, 1);
});

test('a key that could not be removed after a failure is kept for the cleanup', function (Closure $spoil) {
    $server = cloudCreating();
    $spoil($this->api, $server);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->data->toMatchArray(['key_id' => 'key-1'])
        ->and(Activity::query()->where('description', 'server_creation_failed')->sole()->properties['provider'])->toBe('Hetzner Cloud');
})->with([
    'the provider would not remove it' => [fn(FakeCloudApi $api) => $api
        ->failing('create', cloudCreatingFailure(CloudFailure::Refused, 'no capacity', 422))
        ->failing('deleteKey', cloudCreatingFailure(CloudFailure::Unavailable, status: 503))],
    'the account was removed after the upload' => [function (FakeCloudApi $api, Server $server): void {
        CloudTask::touch($server, ['key_id' => 'key-1']);
        $server->cloudProvider->delete();
    }],
]);

test('a creation tried again while its key was being removed keeps running', function (array $retried, ?string $keyId) {
    $server = cloudCreating();
    $this->api->failing('create', cloudCreatingFailure(CloudFailure::Refused, 'no capacity', 422));
    Activity::created(function (Activity $activity) use ($server, $retried): void {
        if ($activity->description === 'server_creation_failed') {
            $elsewhere = Server::query()->findOrFail($server->getKey());
            CloudTask::resume($elsewhere);
            CloudTask::touch($elsewhere, $retried);
        }
    });

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::RUNNING)
        ->error->toBeNull()
        ->data->toMatchArray(['key_id' => $keyId])
        ->and($this->api->calls('deleteKey'))->toBe([['deleteKey', ['key-1']]]);
})->with([
    'with the removed key still recorded' => [[], null],
    'after it uploaded a new key' => [['key_id' => 'key-2'], 'key-2'],
]);

test('a line written while a leftover key was being removed is kept', function () {
    $server = cloudCreating();
    $this->api->failing('deleteKey', cloudCreatingFailure(CloudFailure::Unavailable, status: 503));
    Activity::created(function (Activity $activity) use ($server): void {
        if ($activity->description === 'server_created_at_provider') {
            ProvisioningLog::append(Server::query()->findOrFail($server->getKey()), 'phase 1 of 8, Operating system: started');
        }
    });

    cloudCreatingRun($server);

    expect($server->fresh()->provisioning_log)
        ->toContain('first-boot setup finished, checking the server before provisioning')
        ->toContain('phase 1 of 8, Operating system: started')
        ->toContain("couldn't remove the uploaded key from Hetzner Cloud, delete " . CreateServerTask::keyName($server) . ' there');
});

test('a creation that may exist at the provider is uncertain, and one the provider never made is not', function (Closure $arrange, bool $uncertain) {
    $server = cloudCreating();
    $arrange($this->api, $this);

    cloudCreatingRun($server);

    $this->travel(16)->minutes();
    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::FAILED)
        ->and($server->fresh()->provider_server_id)->toBeNull()
        ->and(CreateServerTask::uncertain($server->fresh()))->toBe($uncertain);
})->with([
    'three unanswered creates' => [fn(FakeCloudApi $api) => $api->failing('create', cloudCreatingFailure(CloudFailure::Unreachable), 3), true],
    'an unanswered create, then a refusal' => [fn(FakeCloudApi $api) => $api->failing('create', cloudCreatingFailure(CloudFailure::Unreachable), 1)->failing('create', cloudCreatingFailure(CloudFailure::Refused, 'server name is already used', 409)), true],
    'a refusal at the first try' => [fn(FakeCloudApi $api) => $api->failing('create', cloudCreatingFailure(CloudFailure::Refused, 'server type cx22 is not available in hel1', 422)), false],
    'a provider that kept failing before any create' => [fn(FakeCloudApi $api) => $api->failing('findTagged', cloudCreatingFailure(CloudFailure::Unavailable, status: 503), 99), false],
]);

test('a creation still waiting on an unanswered create at its deadline is uncertain', function () {
    $server = cloudCreating();
    CloudTask::touch($server, ['uncertain' => 1]);
    $this->travel(16)->minutes();

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe("Coresmith couldn't get an answer from Hetzner Cloud after three tries. Check the Hetzner Cloud console for a server called outpost-02: if there is one, delete it there, then remove this server and provision it again.")
        ->and(CreateServerTask::uncertain($server->fresh()))->toBeTrue();
});

test('trying again after three unanswered creates gets three more tries', function () {
    $server = cloudCreating();
    $this->api->failing('create', cloudCreatingFailure(CloudFailure::Unreachable), 5);

    cloudCreatingRun($server);
    CloudTask::resume($server->fresh());
    cloudCreatingRun($server);

    expect($this->api->calls('create'))->toHaveCount(6)
        ->and(cloudCreatingTask($server)->state)->toBe(CloudTask::DONE)
        ->and($server->fresh()->provider_server_id)->toBe('4711');
});

test('a retry the provider refuses after earlier unanswered creates is still uncertain', function () {
    $server = cloudCreating();
    $this->api->failing('create', cloudCreatingFailure(CloudFailure::Unreachable), 3);

    cloudCreatingRun($server);
    CloudTask::resume($server->fresh());

    expect(cloudCreatingTask($server)->data['uncertain'] ?? null)->toBe(0);

    $this->api->failing('create', cloudCreatingFailure(CloudFailure::Refused, 'server name is already used', 409));
    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::FAILED)
        ->and(CreateServerTask::uncertain($server->fresh()))->toBeTrue();
});

test('a creation adopted after an unanswered create is not uncertain when it fails later', function () {
    $server = cloudCreating();
    $this->api->failing('create', cloudCreatingFailure(CloudFailure::Unreachable))
        ->tagged(null, cloudCreatingInstance(CloudInstanceState::Starting))
        ->finds(null);

    cloudCreatingRun($server);

    expect(cloudCreatingTask($server)->state)->toBe(CloudTask::FAILED)
        ->and(cloudCreatingTask($server)->data['uncertain'] ?? null)->toBe(1)
        ->and($server->fresh()->provider_server_id)->toBe('4711')
        ->and(CreateServerTask::uncertain($server->fresh()))->toBeFalse();
});
