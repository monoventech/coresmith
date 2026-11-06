<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudProviderType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\CloudProviderException;
use App\Jobs\RebootServer;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Panel\ServerPresenter;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeCloudApi;
use Tests\Support\FakeCloudApiFactory;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->api = new FakeCloudApi;
    $this->transport = new FakeSSHTransport;

    app()->instance(CloudApiFactory::class, new FakeCloudApiFactory($this->api));
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $this->transport);
});

afterEach(function () {
    SSHConnectionManager::createTransportUsing(null);
});

function cloudRebootingServer(?Team $team = null): Server
{
    $account = CloudProvider::factory()->for($team ?? Team::factory()->create())->create();

    return Server::factory()->fromProvider($account)->create(['name' => 'outpost-02', 'status' => ServerStatus::Active]);
}

test('a provider server is rebooted through its provider, and is recorded as accepted rather than back', function () {
    $server = cloudRebootingServer();

    app()->call([new RebootServer($server), 'handle']);

    expect($this->api->calls('reboot'))->toBe([['reboot', ['4711']]])
        ->and($this->transport->commands())->toBe([])
        ->and(Activity::query()->where('description', 'server_reboot_requested')->sole()->properties->toArray())->toBe(['via' => 'Hetzner Cloud'])
        ->and(Activity::query()->where('description', 'server_rebooted')->exists())->toBeFalse();
});

test('a reboot is recorded against the person who asked for it', function (Closure $meanwhile, ?string $instance, string $description) {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $server = cloudRebootingServer($team);
    $server->forceFill(['provider_server_id' => $instance])->save();

    $this->actingAs($user->fresh())->post(route('servers.reboot', $server))->assertSessionHas('success');

    $meanwhile($this->api, $server);
    $this->app['auth']->forgetGuards();
    $job = unserialize(serialize(Queue::pushed(RebootServer::class)->sole()));
    rescue(fn() => app()->call([$job, 'handle']), report: false);

    expect(Activity::query()->where('description', $description)->sole()->causer?->is($user))->toBeTrue();
})->with([
    'accepted by the provider' => [fn() => null, '4711', 'server_reboot_requested'],
    'refused by the provider' => [fn(FakeCloudApi $api) => $api->failing('reboot', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'locked')), '4711', 'server_reboot_failed'],
    'waiting for provider work' => [fn(FakeCloudApi $api, Server $server) => CloudTask::start($server, CloudTask::RESIZE, 'power_off', ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]), '4711', 'server_reboot_failed'],
    'over SSH' => [fn() => null, null, 'server_rebooted'],
]);

test('a reboot queued before other provider work began does not reach the provider during it', function (string $type, string $step, string $reason) {
    $server = cloudRebootingServer();
    $job = new RebootServer($server);
    CloudTask::start($server, $type, $step, ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);

    app()->call([$job, 'handle']);

    expect($this->api->calls('reboot'))->toBe([])
        ->and($this->transport->commands())->toBe([])
        ->and(Activity::query()->where('description', 'server_reboot_failed')->sole()->properties->toArray())->toBe(['reason' => $reason])
        ->and(Activity::query()->whereIn('description', ['server_rebooted', 'server_reboot_requested'])->exists())->toBeFalse();
})->with([
    'a resize' => [CloudTask::RESIZE, 'power_off', "Coresmith is resizing outpost-02 at Hetzner Cloud. Reboot once it's back."],
    'a destroy' => [CloudTask::DESTROY, 'delete', 'Coresmith is destroying outpost-02 at Hetzner Cloud.'],
]);

test('a reboot over SSH also waits for provider work that began after it was queued', function () {
    $server = cloudRebootingServer();
    $server->forceFill(['provider_server_id' => null])->save();
    $job = new RebootServer($server);
    CloudTask::start($server, CloudTask::CREATE, 'boot');

    app()->call([$job, 'handle']);

    expect($this->transport->commands())->toBe([])
        ->and(Activity::query()->where('description', 'server_reboot_failed')->sole()->properties->toArray())->toBe(['reason' => 'Coresmith is still creating outpost-02 at Hetzner Cloud.']);
});

test('a reboot after provider work finished goes ahead', function () {
    $server = cloudRebootingServer();
    CloudTask::start($server, CloudTask::RESIZE, 'reconnect', ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);
    CloudTask::finish($server);

    app()->call([new RebootServer($server), 'handle']);

    expect($this->api->calls('reboot'))->toBe([['reboot', ['4711']]]);
});

test('a token the provider refuses for a reboot marks the account', function () {
    $this->api->failing('reboot', new CloudProviderException(CloudFailure::TokenRejected, CloudProviderType::Hetzner, null, 401));
    $server = cloudRebootingServer();

    expect(fn() => app()->call([new RebootServer($server), 'handle']))->toThrow(CloudProviderException::class);

    expect($server->cloudProvider->fresh()->token_failed_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'server_reboot_failed')->exists())->toBeTrue();
});

test('a reboot the provider refuses for another reason leaves the account alone', function () {
    $this->api->failing('reboot', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'locked'));
    $server = cloudRebootingServer();

    expect(fn() => app()->call([new RebootServer($server), 'handle']))->toThrow(CloudProviderException::class);

    expect($server->cloudProvider->fresh()->token_failed_at)->toBeNull();
});

test('a provider server that is not answering is still rebooted through its provider', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $server = cloudRebootingServer($team);
    $server->forceFill(['unreachable_since' => now()->subMinutes(5), 'last_connection_error' => 'The server took the connection but stopped answering. It may be overloaded or rebooting.'])->save();

    expect(app(ServerPresenter::class)->header($server->fresh(), $user->fresh())['unreachable']['can_reboot'])->toBeTrue();

    $this->actingAs($user->fresh())
        ->post(route('servers.reboot', $server))
        ->assertSessionHas('success', 'Reboot scheduled. outpost-02 restarts in a few seconds, apps come back via systemd.');

    Queue::assertPushed(RebootServer::class, 1);

    app()->call([new RebootServer($server->fresh()), 'handle']);

    expect($this->api->calls('reboot'))->toBe([['reboot', ['4711']]])
        ->and($this->transport->commands())->toBe([]);
});

test('a provider server whose instance is unknown is rebooted over SSH', function () {
    $server = cloudRebootingServer();
    $server->forceFill(['provider_server_id' => null])->save();

    app()->call([new RebootServer($server), 'handle']);

    expect($this->api->calls('reboot'))->toBe([])
        ->and($this->transport->commands())->toBe([RebootServer::COMMAND]);
});

test('a provider that refuses the reboot leaves a note in the activity', function () {
    $this->api->failing('reboot', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'locked'));
    $server = cloudRebootingServer();

    expect(fn() => app()->call([new RebootServer($server), 'handle']))->toThrow(CloudProviderException::class);

    expect(Activity::query()->where('description', 'server_reboot_failed')->sole()->properties->toArray())
        ->toBe(['reason' => 'Hetzner Cloud refused the request. Hetzner Cloud said: locked.'])
        ->and(Activity::query()->whereIn('description', ['server_rebooted', 'server_reboot_requested'])->exists())->toBeFalse();
});

test('a reboot waits while the server is being resized', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $server = cloudRebootingServer($team);
    CloudTask::start($server, CloudTask::RESIZE, 'power_off', ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);

    $this->actingAs($user->fresh())
        ->post(route('servers.reboot', $server))
        ->assertSessionHas('error', "Coresmith is resizing outpost-02 at Hetzner Cloud. Reboot once it's back.");

    Queue::assertNotPushed(RebootServer::class);
});
