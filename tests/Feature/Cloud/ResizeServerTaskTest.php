<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Enums\ServerStatus;
use App\Exceptions\CloudProviderException;
use App\Jobs\CollectServerMetrics;
use App\Jobs\RunCloudTask;
use App\Jobs\SyncAuthorizedKeys;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\User;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\CloudTaskHandlers;
use App\Services\Deployment\LogSanitizer;
use App\Services\SSH\SSHConnectionManager;
use Database\Seeders\Support\CloudCatalogs;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeCloudApi;
use Tests\Support\FakeCloudApiFactory;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([RunCloudTask::class, SyncAuthorizedKeys::class]);
    Sleep::fake();

    $this->transport = (new FakeSSHTransport)
        ->respondTo('cat /etc/os-release', "ID=ubuntu\nVERSION_ID=\"24.04\"\nPRETTY_NAME=\"Ubuntu 24.04 LTS\"\n")
        ->respondTo('systemctl --version', 'systemd 255 (255.4-1ubuntu8)')
        ->respondTo('nproc', "4\n")
        ->respondTo('free -m', "              total        used        free\nMem:           7820         900        6920\n")
        ->respondTo('df -BG /', "Filesystem     1G-blocks  Used Available Use% Mounted on\n/dev/sda1           40G    9G       31G  23% /\n");

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $this->transport);
});

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

function cloudResizingApi(FakeCloudApi $api): FakeCloudApi
{
    app()->instance(CloudApiFactory::class, new FakeCloudApiFactory($api));

    return $api;
}

function cloudResizing(string $step = 'power_off', bool $powerCycle = true, bool $catalog = true): Server
{
    $account = CloudProvider::factory()->create();

    if ($catalog) {
        CloudCatalog::put($account, CloudCatalogs::hetzner(), 600);
    }

    $server = Server::factory()->fromProvider($account)->create([
        'name' => 'outpost-02',
        'status' => ServerStatus::Active,
        'meta' => ['cpu_cores' => 2, 'memory_mb' => 4096, 'disk_gb' => 40],
    ]);

    CloudTask::start($server, CloudTask::RESIZE, $step, ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => $powerCycle], User::factory()->create());

    return $server->fresh();
}

function cloudResizingRun(Server $server, int $jobs = 40): void
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

function cloudResizingCalls(FakeCloudApi $api): array
{
    return array_values(array_filter(array_map(fn(array $call): string => $call[0], $api->calls()), fn(string $method): bool => in_array($method, ['powerOff', 'find', 'resize', 'powerOn'], true)));
}

function cloudResizingStopped(string $size): CloudInstance
{
    return new CloudInstance('4711', CloudInstanceState::Stopped, '203.0.113.9', $size);
}

function cloudResizingRunning(string $size): CloudInstance
{
    return new CloudInstance('4711', CloudInstanceState::Running, '203.0.113.9', $size);
}

test('a hetzner-style resize shuts down, changes, starts and signs back in', function () {
    $api = cloudResizingApi((new FakeCloudApi)->finds(cloudResizingRunning('cx22'), cloudResizingStopped('cx22'), cloudResizingStopped('cx22'), cloudResizingStopped('cx32'), cloudResizingStopped('cx32'), cloudResizingRunning('cx32')));
    $server = cloudResizing();
    $by = CloudTask::of($server)->by;

    cloudResizingRun($server);

    $server->refresh();
    $activity = Activity::query()->where('description', 'server_resized')->sole();

    expect(cloudResizingCalls($api))->toBe(['find', 'powerOff', 'find', 'find', 'resize', 'find', 'find', 'powerOn', 'find'])
        ->and($api->calls('resize'))->toBe([['resize', ['4711', 'cx32']]])
        ->and($server->provider_size)->toBe('cx32')
        ->and((string) $server->provider_monthly_cost)->toBe('6.80')
        ->and($server->provider_currency)->toBe('EUR')
        ->and($server->meta['memory_mb'])->toBe(7820)
        ->and($server->meta['cpu_cores'])->toBe(4)
        ->and($server->last_connected_at)->not->toBeNull()
        ->and(CloudTask::of($server)->state)->toBe(CloudTask::DONE)
        ->and($activity->properties->toArray())->toBeInAnyKeyOrder(['from' => 'cx22', 'to' => 'cx32', 'provider' => 'Hetzner Cloud'])
        ->and($activity->causer_id)->toBe($by)
        ->and((string) $server->provisioning_log)->toContain('resized from cx22 to cx32');
});

test('the size is only changed once the server has shut down', function () {
    $api = cloudResizingApi((new FakeCloudApi)->finds(cloudResizingRunning('cx22'), cloudResizingRunning('cx22'), cloudResizingStopped('cx22'), cloudResizingStopped('cx22'), cloudResizingStopped('cx32'), cloudResizingStopped('cx32'), cloudResizingRunning('cx32')));
    $server = cloudResizing();

    cloudResizingRun($server);

    expect(cloudResizingCalls($api))->toBe(['find', 'powerOff', 'find', 'find', 'find', 'resize', 'find', 'find', 'powerOn', 'find'])
        ->and($server->fresh()->provider_size)->toBe('cx32');
});

test('a vultr-style resize lets the provider cycle the power', function () {
    $api = cloudResizingApi((new FakeCloudApi(CloudProviderType::Vultr, powerCycle: false))->finds(cloudResizingRunning('cx22'), cloudResizingRunning('cx22'), cloudResizingRunning('cx32')));
    $server = cloudResizing('change', powerCycle: false);

    cloudResizingRun($server);

    expect(cloudResizingCalls($api))->toBe(['find', 'resize', 'find', 'find'])
        ->and($server->fresh()->provider_size)->toBe('cx32')
        ->and(CloudTask::of($server->fresh())->state)->toBe(CloudTask::DONE);
});

test('a refused size starts the server again and says nothing else changed', function () {
    $api = cloudResizingApi((new FakeCloudApi)
        ->finds(cloudResizingRunning('cx22'), cloudResizingStopped('cx22'))
        ->failing('resize', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'no capacity')));
    $server = cloudResizing();
    $by = CloudTask::of($server)->by;

    cloudResizingRun($server);

    $task = CloudTask::of($server->fresh());

    expect($api->calls('powerOn'))->toBe([['powerOn', ['4711']]])
        ->and(Activity::query()->where('description', 'server_resize_failed')->sole()->causer_id)->toBe($by)
        ->and($task->state)->toBe(CloudTask::FAILED)
        ->and($task->error)->toBe('Hetzner Cloud refused the request. Hetzner Cloud said: no capacity. outpost-02 was started again on cx22.')
        ->and($server->fresh()->provider_size)->toBe('cx22')
        ->and(Activity::query()->where('description', 'server_resize_failed')->sole()->properties->toArray())
        ->toBeInAnyKeyOrder(['from' => 'cx22', 'to' => 'cx32', 'reason' => 'Hetzner Cloud refused the request. Hetzner Cloud said: no capacity. outpost-02 was started again on cx22.']);
});

test('a refused size on a provider that keeps the power on fails without touching it', function () {
    $api = cloudResizingApi((new FakeCloudApi(CloudProviderType::Vultr, powerCycle: false))
        ->finds(cloudResizingRunning('cx22'))
        ->failing('resize', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Vultr, 'plan unavailable')));
    $server = cloudResizing('change', powerCycle: false);

    cloudResizingRun($server);

    expect($api->calls('powerOn'))->toBe([])
        ->and(CloudTask::of($server->fresh())->error)->toBe('Vultr refused the request. Vultr said: plan unavailable. Nothing changed.');
});

test('a shutdown the provider refuses changes nothing', function () {
    cloudResizingApi((new FakeCloudApi)->finds(cloudResizingRunning('cx22'))->failing('powerOff', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'locked')));
    $server = cloudResizing();

    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe('Hetzner Cloud refused the request. Hetzner Cloud said: locked. Nothing changed, outpost-02 is still on cx22.');
});

test('a server that disappears while shutting down is reported', function () {
    cloudResizingApi((new FakeCloudApi)->finds(null));
    $server = cloudResizing('wait_off');

    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe('Hetzner Cloud says outpost-02 no longer exists.');
});

test('a server that will not shut down is left running and reported', function () {
    $api = cloudResizingApi((new FakeCloudApi)->finds(cloudResizingRunning('cx22')));
    $server = cloudResizing('wait_off');

    $this->travel(301)->seconds();
    cloudResizingRun($server);

    expect($api->calls('powerOn'))->toBe([])
        ->and(CloudTask::of($server->fresh())->error)->toBe("Hetzner Cloud didn't shut outpost-02 down within 5 minutes. outpost-02 is running on cx22.");
});

test('a server the provider will not start again is left for the console', function () {
    cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx22'))->failing('powerOn', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'locked')));
    $server = cloudResizing('wait_off');

    $this->travel(301)->seconds();
    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe("Hetzner Cloud didn't shut outpost-02 down within 5 minutes. Coresmith couldn't start outpost-02 again, so it is off on cx22. Start it from the Hetzner Cloud console.");
});

test('a server that will not start again says to start it from the console', function () {
    cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx32')));
    $server = cloudResizing('wait_on');

    $this->travel(301)->seconds();
    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe("outpost-02 is resized to cx32 but didn't start again within 5 minutes. Start it from the Hetzner Cloud console.");
});

test('a resize that never settles is reported, with the power back on where it was cycled', function (bool $powerCycle, string $error) {
    $api = cloudResizingApi((new FakeCloudApi(powerCycle: $powerCycle))->finds(cloudResizingStopped('cx22')));
    $server = cloudResizing('wait_change', powerCycle: $powerCycle);

    $this->travel(1201)->seconds();
    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe($error)
        ->and(count($api->calls('powerOn')))->toBe($powerCycle ? 1 : 0);
})->with([
    'with a power cycle' => [true, "Hetzner Cloud didn't finish resizing outpost-02 within 20 minutes. outpost-02 was started again on cx22."],
    'without one' => [false, "Hetzner Cloud didn't finish resizing outpost-02 within 20 minutes. Check it in the Hetzner Cloud console."],
]);

test('a server that cannot be signed in to after the resize is reported', function () {
    cloudResizingApi(new FakeCloudApi);
    $server = cloudResizing('reconnect');
    $this->transport->failLogin();

    $this->travel(601)->seconds();
    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe("outpost-02 is resized to cx32 and running at Hetzner Cloud, but Coresmith couldn't sign in over SSH within 10 minutes. Check it in the Hetzner Cloud console.");
});

test('the new price is cleared when the provider catalog cannot be read', function () {
    cloudResizingApi((new FakeCloudApi)->failing('regions', new CloudProviderException(CloudFailure::Unavailable, CloudProviderType::Hetzner, status: 503), 5));
    $server = cloudResizing('reconnect', catalog: false);

    cloudResizingRun($server);

    $server->refresh();

    expect($server->provider_size)->toBe('cx32')
        ->and($server->provider_monthly_cost)->toBeNull()
        ->and($server->provider_currency)->toBeNull()
        ->and((string) $server->provisioning_log)->toContain("couldn't read the new price from Hetzner Cloud");
});

test('a key change made during a resize reaches the server once it is back', function (string $step, array $finds) {
    cloudResizingApi((new FakeCloudApi)->finds(...$finds));
    $server = cloudResizing($step);

    $this->travel($step === 'wait_off' ? 301 : 0)->seconds();
    cloudResizingRun($server);

    Queue::assertPushed(SyncAuthorizedKeys::class, 1);
})->with([
    'after a resize that worked' => ['reconnect', []],
    'after one that failed' => ['wait_off', [new CloudInstance('4711', CloudInstanceState::Running, '203.0.113.9', 'cx22')]],
]);

test('metrics wait while a resize runs', function () {
    cloudResizingApi(new FakeCloudApi);
    $server = cloudResizing();

    app()->call([new CollectServerMetrics($server), 'handle']);

    expect($this->transport->commands())->toBe([]);
});

function cloudResizingFailure(CloudFailure $failure, ?string $detail = null, ?int $status = null): CloudProviderException
{
    return new CloudProviderException($failure, CloudProviderType::Hetzner, $detail, $status);
}

test('a change the provider keeps failing is given up within its limit and the server started again', function () {
    $unavailable = cloudResizingFailure(CloudFailure::Unavailable, status: 503);
    $api = cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx22'))->failing('resize', $unavailable, 99));
    $server = cloudResizing('change');

    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->state)->toBe(CloudTask::RUNNING);

    $this->travel(301)->seconds();
    cloudResizingRun($server);

    $task = CloudTask::of($server->fresh());

    expect($api->calls('powerOn'))->toBe([['powerOn', ['4711']]])
        ->and($task->state)->toBe(CloudTask::FAILED)
        ->and($task->error)->toBe('Hetzner Cloud kept failing for 5 minutes when Coresmith asked it to resize outpost-02. The last error was: ' . rtrim($unavailable->getMessage(), '.') . '. outpost-02 was started again on cx22.')
        ->and($server->fresh()->provider_size)->toBe('cx22');
});

test('a token refused between the shutdown and the change still tries to start the server', function () {
    $refused = cloudResizingFailure(CloudFailure::TokenRejected);
    $api = cloudResizingApi((new FakeCloudApi)->failing('find', $refused, 9)->failing('powerOn', $refused));
    $server = cloudResizing('change');

    cloudResizingRun($server);

    $task = CloudTask::of($server->fresh());

    expect($api->calls('resize'))->toBe([])
        ->and($api->calls('powerOn'))->toBe([['powerOn', ['4711']]])
        ->and($task->state)->toBe(CloudTask::FAILED)
        ->and($task->error)->toBe($refused->getMessage() . " Coresmith couldn't start outpost-02 again, so start it from the Hetzner Cloud console.")
        ->and($server->fresh()->cloudProvider->token_failed_at)->not->toBeNull();
});

test('a server the provider no longer has at the change is reported gone', function () {
    $missing = cloudResizingFailure(CloudFailure::NotFound, status: 404);
    $api = cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx22'), null)->failing('resize', $missing));
    $server = cloudResizing('change');

    cloudResizingRun($server);

    expect($api->calls('powerOn'))->toBe([])
        ->and(CloudTask::of($server->fresh())->error)->toBe($missing->getMessage() . ' Hetzner Cloud says outpost-02 no longer exists.');
});

test('a server the provider will not start after the change is reported off on its new size', function (CloudFailure $failure, bool $tokenRefused) {
    $refused = cloudResizingFailure($failure, 'locked');
    cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx32'))->failing('powerOn', $refused));
    $server = cloudResizing('power_on');

    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh()))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe($refused->getMessage() . ' outpost-02 is resized to cx32 but still off. Start it from the Hetzner Cloud console.')
        ->and($server->fresh()->cloudProvider->token_failed_at !== null)->toBe($tokenRefused);
})->with([
    'a refusal' => [CloudFailure::Refused, false],
    'a refused token' => [CloudFailure::TokenRejected, true],
]);

test('a start the provider keeps failing is reported off on its new size once its limit passes', function () {
    $unavailable = cloudResizingFailure(CloudFailure::Unavailable, status: 503);
    cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx32'))->failing('powerOn', $unavailable, 99));
    $server = cloudResizing('power_on');

    cloudResizingRun($server);
    $this->travel(301)->seconds();
    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe('Hetzner Cloud kept failing for 5 minutes when Coresmith asked it to start outpost-02 again. The last error was: ' . rtrim($unavailable->getMessage(), '.') . '. outpost-02 is resized to cx32 but still off. Start it from the Hetzner Cloud console.');
});

test('a resize whose answer was lost is not sent again once the provider shows the new size', function () {
    $api = cloudResizingApi((new FakeCloudApi)
        ->finds(cloudResizingStopped('cx22'), cloudResizingStopped('cx32'), cloudResizingStopped('cx32'), cloudResizingStopped('cx32'), cloudResizingRunning('cx32'))
        ->failing('resize', cloudResizingFailure(CloudFailure::Unavailable, status: 503)));
    $server = cloudResizing('change');

    cloudResizingRun($server);

    expect($api->calls('resize'))->toHaveCount(1)
        ->and(CloudTask::of($server->fresh())->state)->toBe(CloudTask::DONE)
        ->and($server->fresh()->provider_size)->toBe('cx32');
});

test('a shutdown whose answer was lost is not sent again once the server is off', function () {
    $api = cloudResizingApi((new FakeCloudApi)
        ->finds(cloudResizingRunning('cx22'), cloudResizingStopped('cx22'), cloudResizingStopped('cx22'), cloudResizingStopped('cx32'), cloudResizingStopped('cx32'), cloudResizingRunning('cx32'))
        ->failing('powerOff', cloudResizingFailure(CloudFailure::Unavailable, status: 503)));
    $server = cloudResizing();

    cloudResizingRun($server);

    expect($api->calls('powerOff'))->toHaveCount(1)
        ->and($api->calls('resize'))->toHaveCount(1)
        ->and(CloudTask::of($server->fresh())->state)->toBe(CloudTask::DONE);
});

test('a start whose answer was lost is not sent again once the server is on', function () {
    $api = cloudResizingApi((new FakeCloudApi)
        ->finds(cloudResizingStopped('cx32'), cloudResizingRunning('cx32'))
        ->failing('powerOn', cloudResizingFailure(CloudFailure::Unavailable, status: 503)));
    $server = cloudResizing('power_on');

    cloudResizingRun($server);

    expect($api->calls('powerOn'))->toHaveCount(1)
        ->and(CloudTask::of($server->fresh())->state)->toBe(CloudTask::DONE);
});

test('a resize the provider applied keeps its new size and price when a later step fails', function () {
    cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx32'))->failing('powerOn', cloudResizingFailure(CloudFailure::Refused, 'locked')));
    $server = cloudResizing('wait_change');

    cloudResizingRun($server);

    $server->refresh();

    expect(CloudTask::of($server)->state)->toBe(CloudTask::FAILED)
        ->and($server->provider_size)->toBe('cx32')
        ->and((string) $server->provider_monthly_cost)->toBe('6.80')
        ->and($server->provider_currency)->toBe('EUR');
});

test('a server gone before a step writes to it is reported and nothing is sent', function (string $step, string $write) {
    $api = cloudResizingApi((new FakeCloudApi)->finds(null));
    $server = cloudResizing($step);

    cloudResizingRun($server);

    expect($api->calls($write))->toBe([])
        ->and(CloudTask::of($server->fresh())->error)->toBe('Hetzner Cloud says outpost-02 no longer exists.');
})->with([
    'the shutdown' => ['power_off', 'powerOff'],
    'the change' => ['change', 'resize'],
    'the start' => ['power_on', 'powerOn'],
]);

test('a change whose answer was lost moves on when the provider shows the new size at its deadline', function () {
    $api = cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx22'))->failing('resize', cloudResizingFailure(CloudFailure::Unavailable, status: 503), 99));
    $server = cloudResizing('change');

    cloudResizingRun($server);

    $api->finds(cloudResizingStopped('cx32'), cloudResizingStopped('cx32'), cloudResizingRunning('cx32'));
    $this->travel(301)->seconds();
    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->state)->toBe(CloudTask::DONE)
        ->and($api->calls('powerOn'))->toBe([])
        ->and($server->fresh()->provider_size)->toBe('cx32');
});

test('a start whose answer was lost moves on when the provider shows it running at its deadline', function () {
    $api = cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx32'))->failing('powerOn', cloudResizingFailure(CloudFailure::Unavailable, status: 503), 99));
    $server = cloudResizing('power_on');

    cloudResizingRun($server);

    $api->finds(cloudResizingRunning('cx32'));
    $this->travel(301)->seconds();
    cloudResizingRun($server);

    expect(CloudTask::of($server->fresh())->state)->toBe(CloudTask::DONE);
});

test('a resize the provider only shows once its wait ran out keeps the new size and says where it was started', function () {
    cloudResizingApi((new FakeCloudApi)->finds(cloudResizingStopped('cx32')));
    $server = cloudResizing('wait_change');

    $this->travel(1201)->seconds();
    CloudCatalog::put($server->cloudProvider, CloudCatalogs::hetzner(), 600);
    cloudResizingRun($server);

    $server->refresh();

    expect(CloudTask::of($server)->error)->toBe("Hetzner Cloud didn't finish resizing outpost-02 within 20 minutes. outpost-02 was started again on cx32.")
        ->and($server->provider_size)->toBe('cx32')
        ->and((string) $server->provider_monthly_cost)->toBe('6.80');
});
