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

function resizingApi(FakeCloudApi $api): FakeCloudApi
{
    app()->instance(CloudApiFactory::class, new FakeCloudApiFactory($api));

    return $api;
}

function resizing(string $step = 'power_off', bool $powerCycle = true, bool $catalog = true): Server
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

function resizingRun(Server $server): void
{
    (new RunCloudTask($server))->handle(app(CloudApiFactory::class), app(CloudTaskHandlers::class), app(LogSanitizer::class));
}

function resizingCalls(FakeCloudApi $api): array
{
    return array_values(array_filter(array_map(fn(array $call): string => $call[0], $api->calls()), fn(string $method): bool => in_array($method, ['powerOff', 'find', 'resize', 'powerOn'], true)));
}

function resizingStopped(string $size): CloudInstance
{
    return new CloudInstance('4711', CloudInstanceState::Stopped, '203.0.113.9', $size);
}

function resizingRunning(string $size): CloudInstance
{
    return new CloudInstance('4711', CloudInstanceState::Running, '203.0.113.9', $size);
}

test('a hetzner-style resize shuts down, changes, starts and signs back in', function () {
    $api = resizingApi((new FakeCloudApi)->finds(resizingStopped('cx22'), resizingStopped('cx32'), resizingRunning('cx32')));
    $server = resizing();
    $by = CloudTask::of($server)->by;

    resizingRun($server);

    $server->refresh();
    $activity = Activity::query()->where('description', 'server_resized')->sole();

    expect(resizingCalls($api))->toBe(['powerOff', 'find', 'resize', 'find', 'powerOn', 'find'])
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
    $api = resizingApi((new FakeCloudApi)->finds(resizingRunning('cx22'), resizingStopped('cx22'), resizingStopped('cx32'), resizingRunning('cx32')));
    $server = resizing();

    resizingRun($server);

    expect(resizingCalls($api))->toBe(['powerOff', 'find', 'find', 'resize', 'find', 'powerOn', 'find'])
        ->and($server->fresh()->provider_size)->toBe('cx32');
});

test('a vultr-style resize lets the provider cycle the power', function () {
    $api = resizingApi((new FakeCloudApi(CloudProviderType::Vultr, powerCycle: false))->finds(resizingRunning('cx22'), resizingRunning('cx32')));
    $server = resizing('change', powerCycle: false);

    resizingRun($server);

    expect(resizingCalls($api))->toBe(['resize', 'find', 'find'])
        ->and($server->fresh()->provider_size)->toBe('cx32')
        ->and(CloudTask::of($server->fresh())->state)->toBe(CloudTask::DONE);
});

test('a refused size starts the server again and says nothing else changed', function () {
    $api = resizingApi((new FakeCloudApi)
        ->finds(resizingStopped('cx22'))
        ->failing('resize', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'no capacity')));
    $server = resizing();
    $by = CloudTask::of($server)->by;

    resizingRun($server);

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
    $api = resizingApi((new FakeCloudApi(CloudProviderType::Vultr, powerCycle: false))
        ->failing('resize', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Vultr, 'plan unavailable')));
    $server = resizing('change', powerCycle: false);

    resizingRun($server);

    expect($api->calls('powerOn'))->toBe([])
        ->and(CloudTask::of($server->fresh())->error)->toBe('Vultr refused the request. Vultr said: plan unavailable. Nothing changed.');
});

test('a shutdown the provider refuses changes nothing', function () {
    resizingApi((new FakeCloudApi)->failing('powerOff', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'locked')));
    $server = resizing();

    resizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe('Hetzner Cloud refused the request. Hetzner Cloud said: locked. Nothing changed, outpost-02 is still on cx22.');
});

test('a server that disappears while shutting down is reported', function () {
    resizingApi((new FakeCloudApi)->finds(null));
    $server = resizing('wait_off');

    resizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe('Hetzner Cloud says outpost-02 no longer exists.');
});

test('a server that will not shut down is started again and reported', function () {
    $api = resizingApi((new FakeCloudApi)->finds(resizingRunning('cx22')));
    $server = resizing('wait_off');

    $this->travel(301)->seconds();
    resizingRun($server);

    expect($api->calls('powerOn'))->toBe([['powerOn', ['4711']]])
        ->and(CloudTask::of($server->fresh())->error)->toBe("Hetzner Cloud didn't shut outpost-02 down within 5 minutes. outpost-02 was started again on cx22.");
});

test('a server the provider will not start again is left for the console', function () {
    resizingApi((new FakeCloudApi)->failing('powerOn', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'locked')));
    $server = resizing('wait_off');

    $this->travel(301)->seconds();
    resizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe("Hetzner Cloud didn't shut outpost-02 down within 5 minutes. Coresmith couldn't start outpost-02 again, so start it from the Hetzner Cloud console.");
});

test('a server that will not start again says to start it from the console', function () {
    resizingApi((new FakeCloudApi)->finds(resizingStopped('cx32')));
    $server = resizing('wait_on');

    $this->travel(301)->seconds();
    resizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe("outpost-02 is resized to cx32 but didn't start again within 5 minutes. Start it from the Hetzner Cloud console.");
});

test('a resize that never settles is reported, with the power back on where it was cycled', function (bool $powerCycle, string $error) {
    $api = resizingApi((new FakeCloudApi(powerCycle: $powerCycle))->finds(resizingStopped('cx22')));
    $server = resizing('wait_change', powerCycle: $powerCycle);

    $this->travel(1201)->seconds();
    resizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe($error)
        ->and(count($api->calls('powerOn')))->toBe($powerCycle ? 1 : 0);
})->with([
    'with a power cycle' => [true, "Hetzner Cloud didn't finish resizing outpost-02 within 20 minutes. outpost-02 was started again on cx22."],
    'without one' => [false, "Hetzner Cloud didn't finish resizing outpost-02 within 20 minutes. Check it in the Hetzner Cloud console."],
]);

test('a server that cannot be signed in to after the resize is reported', function () {
    resizingApi(new FakeCloudApi);
    $server = resizing('reconnect');
    $this->transport->failLogin();

    $this->travel(601)->seconds();
    resizingRun($server);

    expect(CloudTask::of($server->fresh())->error)->toBe("outpost-02 is resized to cx32 and running at Hetzner Cloud, but Coresmith couldn't sign in over SSH within 10 minutes. Check it in the Hetzner Cloud console.");
});

test('the new price is cleared when the provider catalog cannot be read', function () {
    resizingApi((new FakeCloudApi)->failing('regions', new CloudProviderException(CloudFailure::Unavailable, CloudProviderType::Hetzner, status: 503), 5));
    $server = resizing('reconnect', catalog: false);

    resizingRun($server);

    $server->refresh();

    expect($server->provider_size)->toBe('cx32')
        ->and($server->provider_monthly_cost)->toBeNull()
        ->and($server->provider_currency)->toBeNull()
        ->and((string) $server->provisioning_log)->toContain("couldn't read the new price from Hetzner Cloud");
});

test('a key change made during a resize reaches the server once it is back', function (string $step, array $finds) {
    resizingApi((new FakeCloudApi)->finds(...$finds));
    $server = resizing($step);

    $this->travel($step === 'wait_off' ? 301 : 0)->seconds();
    resizingRun($server);

    Queue::assertPushed(SyncAuthorizedKeys::class, 1);
})->with([
    'after a resize that worked' => ['reconnect', []],
    'after one that failed' => ['wait_off', [new CloudInstance('4711', CloudInstanceState::Running, '203.0.113.9', 'cx22')]],
]);

test('metrics wait while a resize runs', function () {
    resizingApi(new FakeCloudApi);
    $server = resizing();

    app()->call([new CollectServerMetrics($server), 'handle']);

    expect($this->transport->commands())->toBe([]);
});
