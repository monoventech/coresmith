<?php

declare(strict_types=1);

use App\Enums\CloudProviderType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\ServerHasNoAddress;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeSSHTransport;

function cloudModelMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('accounts and servers carry the provider columns', function () {
    expect(Schema::hasColumns('cloud_providers', ['account_label', 'verified_at', 'token_failed_at']))->toBeTrue()
        ->and(Schema::hasColumns('servers', ['cloud_provider_id', 'provider_server_id', 'provider_size', 'provider_image', 'provider_monthly_cost', 'provider_currency', 'provider_task']))->toBeTrue();
});

test('a removed account can have its token cleared', function () {
    $account = CloudProvider::factory()->create();
    $account->forceFill(['api_token' => null])->save();

    expect(DB::table('cloud_providers')->where('id', $account->id)->value('api_token'))->toBeNull();
});

test('a server with no address yet reads as null and is stored as an empty string', function () {
    $server = Server::factory()->creating()->create();

    expect($server->fresh()->ip_address)->toBeNull()
        ->and(DB::table('servers')->where('id', $server->id)->value('ip_address'))->toBe('')
        ->and($server->fresh()->sshAddress())->toBeNull()
        ->and($server->fresh()->status)->toBe(ServerStatus::Creating);
});

test('the ssh address joins user, address and port', function () {
    $server = Server::factory()->create(['ip_address' => '203.0.113.40', 'ssh_user' => 'deploy', 'ssh_port' => 2222]);

    expect($server->sshAddress())->toBe('deploy@203.0.113.40:2222');
});

test('a server created at a provider knows its account', function () {
    $account = CloudProvider::factory()->create();
    $server = Server::factory()->fromProvider($account)->create();

    expect($server->fromProvider())->toBeTrue()
        ->and($server->cloudProvider->is($account))->toBeTrue()
        ->and($server->provider_monthly_cost)->toBe('4.35')
        ->and(Server::factory()->create()->fromProvider())->toBeFalse();
});

test('an account knows its type, its servers and when its token was refused', function () {
    $account = CloudProvider::factory()->digitalocean()->create();
    $server = Server::factory()->fromProvider($account)->create(['provider_task' => ['type' => 'create', 'state' => 'running']]);

    expect($account->type)->toBe(CloudProviderType::DigitalOcean)
        ->and($account->verified_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($account->isRefused())->toBeFalse()
        ->and($account->servers->modelKeys())->toBe([$server->id])
        ->and($account->toArray())->not->toHaveKey('api_token')
        ->and($server->fresh()->team_id)->toBe($account->team_id)
        ->and($server->fresh()->provider_task)->toBe(['type' => 'create', 'state' => 'running'])
        ->and(CloudProvider::factory()->vultr()->create()->type)->toBe(CloudProviderType::Vultr)
        ->and(CloudProvider::factory()->linode()->create()->type)->toBe(CloudProviderType::Linode)
        ->and(CloudProvider::factory()->refused()->create()->fresh()->isRefused())->toBeTrue();
});

test('a server still knows its account after the account is removed', function () {
    $account = CloudProvider::factory()->create();
    $server = Server::factory()->fromProvider($account)->create();

    $account->delete();

    expect($server->fresh()->cloudProvider?->is($account))->toBeTrue();
});

test('each provider type names itself and where its token comes from', function (CloudProviderType $type, string $label, string $host) {
    expect($type->label())->toBe($label)
        ->and($type->apiHost())->toBe($host)
        ->and($type->tokenUrl())->toStartWith('https://')
        ->and($type->tokenHelp())->not->toContain("\u{2014}")
        ->and($type->permissionHint())->not->toBe('');
})->with([
    [CloudProviderType::Hetzner, 'Hetzner Cloud', 'api.hetzner.cloud'],
    [CloudProviderType::DigitalOcean, 'DigitalOcean', 'api.digitalocean.com'],
    [CloudProviderType::Vultr, 'Vultr', 'api.vultr.com'],
    [CloudProviderType::Linode, 'Linode', 'api.linode.com'],
]);

test('a configured api base url is used without its trailing slash', function () {
    config(['services.hetzner.api_url' => 'http://127.0.0.1:9/v1/']);

    expect(CloudProviderType::Hetzner->apiUrl())->toBe('http://127.0.0.1:9/v1')
        ->and(CloudProviderType::Hetzner->apiHost())->toBe('127.0.0.1');
});

test('only an owner or admin can connect or change an account', function (TeamRole $role, bool $manage) {
    $team = Team::factory()->create();
    $account = CloudProvider::factory()->for($team)->create();
    $user = cloudModelMember($team, $role);

    expect($user->can('create', CloudProvider::class))->toBe($manage)
        ->and($user->can('update', $account))->toBe($manage)
        ->and($user->can('delete', $account))->toBe($manage)
        ->and($user->can('update', CloudProvider::factory()->create()))->toBeFalse();
})->with([
    [TeamRole::Owner, true],
    [TeamRole::Admin, true],
    [TeamRole::Member, false],
]);

test('a server with no address yet shows no ssh line', function () {
    $server = Server::factory()->creating()->create();
    $owner = cloudModelMember($server->team, TeamRole::Owner);

    $this->actingAs($owner)
        ->get(route('servers.show', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('server.provisioning.ssh', null));

    $this->actingAs($owner)
        ->get(route('servers.hardening', $server))
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('access.ssh', null));
});

test('ssh is refused for a server with no address before anything connects', function () {
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app(SSHConnectionManager::class)->for(Server::factory()->creating()->create(['name' => 'outpost-01']))->execute('true');
    } finally {
        SSHConnectionManager::createTransportUsing(null);

        expect($transport->calls())->toBe([])
            ->and($transport->commands())->toBe([]);
    }
})->throws(ServerHasNoAddress::class, 'outpost-01 has no IP address yet. Coresmith is still creating it at the provider.');
