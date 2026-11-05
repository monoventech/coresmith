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

function rebootingServer(?Team $team = null): Server
{
    $account = CloudProvider::factory()->for($team ?? Team::factory()->create())->create();

    return Server::factory()->fromProvider($account)->create(['name' => 'outpost-02', 'status' => ServerStatus::Active]);
}

test('a provider server is rebooted through its provider', function () {
    $server = rebootingServer();

    app()->call([new RebootServer($server), 'handle']);

    expect($this->api->calls('reboot'))->toBe([['reboot', ['4711']]])
        ->and($this->transport->commands())->toBe([])
        ->and(Activity::query()->where('description', 'server_rebooted')->sole()->properties->toArray())->toBe(['via' => 'Hetzner Cloud']);
});

test('a provider server that is not answering is still rebooted through its provider', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $server = rebootingServer($team);
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
    $server = rebootingServer();
    $server->forceFill(['provider_server_id' => null])->save();

    app()->call([new RebootServer($server), 'handle']);

    expect($this->api->calls('reboot'))->toBe([])
        ->and($this->transport->commands())->toBe([RebootServer::COMMAND]);
});

test('a provider that refuses the reboot leaves a note in the activity', function () {
    $this->api->failing('reboot', new CloudProviderException(CloudFailure::Refused, CloudProviderType::Hetzner, 'locked'));
    $server = rebootingServer();

    expect(fn() => app()->call([new RebootServer($server), 'handle']))->toThrow(CloudProviderException::class);

    expect(Activity::query()->where('description', 'server_reboot_failed')->sole()->properties->toArray())
        ->toBe(['reason' => 'Hetzner Cloud refused the request. Hetzner Cloud said: locked.'])
        ->and(Activity::query()->where('description', 'server_rebooted')->exists())->toBeFalse();
});

test('a reboot waits while the server is being resized', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $server = rebootingServer($team);
    CloudTask::start($server, CloudTask::RESIZE, 'power_off', ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);

    $this->actingAs($user->fresh())
        ->post(route('servers.reboot', $server))
        ->assertSessionHas('error', "Coresmith is resizing outpost-02 at Hetzner Cloud. Reboot once it's back.");

    Queue::assertNotPushed(RebootServer::class);
});
