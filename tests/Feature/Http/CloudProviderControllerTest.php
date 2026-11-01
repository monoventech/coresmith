<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Enums\TeamRole;
use App\Exceptions\CloudProviderException;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\CatalogData;
use App\Services\Cloud\CloudAccount;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\Contracts\CloudApiFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudFixtures;
use Tests\Support\FakeCloudApiFactory;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

function cloudAccountsOwner(TeamRole $role = TeamRole::Owner): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return [$team, $user->fresh()];
}

function cloudAccountsMember(): array
{
    return cloudAccountsOwner(TeamRole::Member);
}

function cloudAccountsFake(): FakeCloudApiFactory
{
    $fake = new FakeCloudApiFactory;
    app()->instance(CloudApiFactory::class, $fake);

    return $fake;
}

function cloudAccountsCatalogued(CloudProvider $account): void
{
    CloudCatalog::put($account, new CatalogData(collect(), collect(), collect()), 600);
}

test('an admin connects an account once the provider accepts its token', function () {
    [$team, $user] = cloudAccountsOwner(TeamRole::Admin);
    $fake = cloudAccountsFake();
    $fake->api->account(new CloudAccount('Monoven project'));

    $this->actingAs($user)
        ->from(route('teams.index'))
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner Cloud', 'api_token' => 'tok-1'])
        ->assertRedirect(route('teams.index'))
        ->assertSessionHas('success', 'Connected.');

    $account = CloudProvider::query()->sole();

    expect(DB::table('cloud_providers')->value('api_token'))->not->toContain('tok-1')
        ->and($account->api_token)->toBe('tok-1')
        ->and($account->team_id)->toBe($team->id)
        ->and($account->type)->toBe(CloudProviderType::Hetzner)
        ->and($account->account_label)->toBe('Monoven project')
        ->and($account->verified_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'cloud_provider_connected')->sole()->properties->toArray())->toBe(['provider' => 'Hetzner Cloud', 'name' => 'Hetzner Cloud']);
});

test('the token is checked against hetzner itself', function () {
    [, $user] = cloudAccountsOwner();
    Http::fake(['api.hetzner.cloud/v1/servers*' => Http::response(CloudFixtures::json('hetzner/servers-empty'))]);

    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner Cloud', 'api_token' => 'tok-1'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.hetzner.cloud/v1/servers?per_page=1'
        && $request->hasHeader('Authorization', 'Bearer tok-1'));

    expect(CloudProvider::query()->count())->toBe(1);
});

test('a pasted token is trimmed before it is checked and saved', function () {
    [, $user] = cloudAccountsOwner();
    $fake = cloudAccountsFake();

    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner Cloud', 'api_token' => "  tok-1\n"])
        ->assertSessionHasNoErrors();

    expect($fake->made())->toBe([[CloudProviderType::Hetzner, 'tok-1']])
        ->and(CloudProvider::query()->sole()->api_token)->toBe('tok-1');
});

test('a token with spaces inside is refused without asking the provider', function () {
    [, $user] = cloudAccountsOwner();
    $fake = cloudAccountsFake();

    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner Cloud', 'api_token' => 'tok 1'])
        ->assertSessionHasErrors(['api_token' => 'That token has spaces in it. Copy it again from Hetzner Cloud.']);

    expect($fake->made())->toBe([])
        ->and(CloudProvider::query()->count())->toBe(0);
});

test('a missing token or an unknown provider says what to do', function () {
    [, $user] = cloudAccountsOwner();
    cloudAccountsFake();

    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'vultr', 'name' => 'Vultr'])
        ->assertSessionHasErrors(['api_token' => 'Paste the API token from Vultr.']);

    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'aws', 'name' => 'AWS', 'api_token' => 'tok 1'])
        ->assertSessionHasErrors(['type' => 'Pick a provider.', 'api_token' => 'That token has spaces in it. Copy it again from the provider.']);

    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'api_token' => 'tok-1'])
        ->assertSessionHasErrors(['name' => 'Name the account, like Hetzner production.']);
});

test('a refused token is not saved and the form says what to do', function () {
    [, $user] = cloudAccountsOwner();
    $fake = cloudAccountsFake();
    $fake->api->failing('verify', new CloudProviderException(CloudFailure::TokenRejected, CloudProviderType::Hetzner));

    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner Cloud', 'api_token' => 'tok-1'])
        ->assertSessionHasErrors(['api_token' => "Hetzner Cloud didn't accept that token. Copy it again from Hetzner Cloud, then paste it here."]);

    expect(CloudProvider::query()->count())->toBe(0)
        ->and(Activity::query()->where('description', 'cloud_provider_connected')->count())->toBe(0);
});

test('a validation error never puts the token in the session', function () {
    [, $user] = cloudAccountsOwner();
    cloudAccountsFake();

    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => str_repeat('n', 61), 'api_token' => 'tok-1'])
        ->assertSessionHasErrors('name');

    expect(session('_old_input'))->toHaveKey('name')
        ->and(session('_old_input'))->not->toHaveKey('api_token');
});

test('two accounts at the same provider live side by side', function () {
    [$team, $user] = cloudAccountsOwner();
    cloudAccountsFake();

    $this->actingAs($user)->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner production', 'api_token' => 'tok-prod'])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner staging', 'api_token' => 'tok-stage'])->assertSessionHasNoErrors();
    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner staging', 'api_token' => 'tok-3'])
        ->assertSessionHasErrors(['name' => 'This team already has an account with that name. Give this one another name.']);

    $production = CloudProvider::query()->where('name', 'Hetzner production')->sole();
    $staging = CloudProvider::query()->where('name', 'Hetzner staging')->sole();
    cloudAccountsCatalogued($production);
    cloudAccountsCatalogued($staging);

    $this->actingAs($user)
        ->put(route('cloud-providers.update', $staging), ['api_token' => 'tok-stage-2'])
        ->assertSessionHas('success', 'Saved.');

    expect(CloudProvider::query()->where('team_id', $team->id)->count())->toBe(2)
        ->and($production->fresh()->api_token)->toBe('tok-prod')
        ->and($staging->fresh()->api_token)->toBe('tok-stage-2')
        ->and(Cache::has(CloudCatalog::cacheKey($production)))->toBeTrue()
        ->and(Cache::has(CloudCatalog::cacheKey($staging)))->toBeFalse();
});

test('a removed account frees its name for a new one', function () {
    [$team, $user] = cloudAccountsOwner();
    cloudAccountsFake();
    CloudProvider::factory()->for($team)->create(['name' => 'Hetzner production'])->delete();

    $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner production', 'api_token' => 'tok-1'])
        ->assertSessionHasNoErrors();

    expect(CloudProvider::query()->where('name', 'Hetzner production')->count())->toBe(1);
});

test('a deployer cannot connect, replace or remove an account', function () {
    [$team, $user] = cloudAccountsMember();
    $fake = cloudAccountsFake();
    $account = CloudProvider::factory()->for($team)->create(['api_token' => 'tok-1']);

    $this->actingAs($user)->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner Cloud', 'api_token' => 'tok-2'])->assertForbidden();
    $this->actingAs($user)->put(route('cloud-providers.update', $account), ['api_token' => 'tok-2'])->assertForbidden();
    $this->actingAs($user)->delete(route('cloud-providers.destroy', $account))->assertForbidden();

    expect($fake->made())->toBe([])
        ->and($account->fresh()->api_token)->toBe('tok-1')
        ->and($account->fresh()->trashed())->toBeFalse();
});

test('an account from another team is not found here', function () {
    [$theirs, $owner] = cloudAccountsOwner();
    $mine = Team::factory()->create(['slug' => 'acme']);
    $mine->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $theirs->forceFill(['slug' => 'monoven'])->save();
    $account = CloudProvider::factory()->for($theirs)->create(['api_token' => 'tok-1']);
    [, $stranger] = cloudAccountsOwner();
    $fake = cloudAccountsFake();

    $this->actingAs($stranger)->put(route('cloud-providers.update', $account), ['name' => 'Mine now'])->assertNotFound();
    $this->actingAs($stranger)->delete(route('cloud-providers.destroy', $account))->assertNotFound();
    $this->actingAs($owner)->put('/acme' . route('cloud-providers.update', $account, false), ['name' => 'Mine now'])->assertNotFound();
    $this->actingAs($owner)->delete('/acme' . route('cloud-providers.destroy', $account, false))->assertNotFound();

    expect($fake->made())->toBe([])
        ->and($account->fresh()->name)->not->toBe('Mine now')
        ->and($account->fresh()->trashed())->toBeFalse();
});

test('connecting from the provision form goes back to it with the account picked', function (string $from, Closure $target) {
    [, $user] = cloudAccountsOwner();
    cloudAccountsFake();

    $response = $this->actingAs($user)
        ->post(route('cloud-providers.store'), ['type' => 'hetzner', 'name' => 'Hetzner Cloud', 'api_token' => 'tok-1', 'return_to' => $from]);

    $response->assertRedirect($target(CloudProvider::query()->sole()))
        ->assertSessionHas('success', 'Connected. Pick a region and a size.');
})->with([
    'the provision form' => ['provision', fn(CloudProvider $account): string => route('servers.create', ['account' => $account->id])],
    'setup' => ['setup', fn(CloudProvider $account): string => route('setup.show', ['step' => 1, 'account' => $account->id])],
]);

test('a replacement token must see the servers made with the account', function () {
    [$team, $user] = cloudAccountsOwner();
    $fake = cloudAccountsFake();
    $fake->api->finds(null);
    $account = CloudProvider::factory()->for($team)->create(['api_token' => 'tok-1']);
    Server::factory()->fromProvider($account)->create(['name' => 'outpost-01']);

    $this->actingAs($user)
        ->put(route('cloud-providers.update', $account), ['api_token' => 'tok-2'])
        ->assertSessionHasErrors(['api_token' => "That token can't see outpost-01, which was created with this account. Paste a token from the same Hetzner Cloud account."]);

    expect($account->fresh()->api_token)->toBe('tok-1')
        ->and($fake->made())->toBe([[CloudProviderType::Hetzner, 'tok-2']]);
});

test('a replacement token clears the refusal and the cached catalog', function () {
    [$team, $user] = cloudAccountsOwner();
    $fake = cloudAccountsFake();
    $account = CloudProvider::factory()->for($team)->refused()->create(['api_token' => 'tok-1', 'verified_at' => now()->subWeek()]);
    $server = Server::factory()->fromProvider($account)->create();
    $fake->api->finds(new CloudInstance($server->provider_server_id, CloudInstanceState::Running, '203.0.113.9'));
    cloudAccountsCatalogued($account);

    $this->actingAs($user)
        ->put(route('cloud-providers.update', $account), ['api_token' => 'tok-2'])
        ->assertSessionHas('success', 'Saved.');

    $fresh = $account->fresh();

    expect($fresh->api_token)->toBe('tok-2')
        ->and($fresh->token_failed_at)->toBeNull()
        ->and($fresh->verified_at->isToday())->toBeTrue()
        ->and(Cache::has(CloudCatalog::cacheKey($account)))->toBeFalse()
        ->and(Activity::query()->where('description', 'cloud_provider_token_replaced')->sole()->properties->toArray())->toBe(['provider' => 'Hetzner Cloud', 'name' => $account->name]);
});

test('renaming an account checks nothing at the provider', function () {
    [$team, $user] = cloudAccountsOwner();
    $fake = cloudAccountsFake();
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner', 'api_token' => 'tok-1']);

    $this->actingAs($user)
        ->put(route('cloud-providers.update', $account), ['name' => 'Hetzner production'])
        ->assertSessionHas('success', 'Saved.');

    expect($account->fresh()->name)->toBe('Hetzner production')
        ->and($fake->made())->toBe([])
        ->and(Activity::query()->where('description', 'cloud_provider_token_replaced')->count())->toBe(0);
});

test('an update with nothing in it is refused', function () {
    [$team, $user] = cloudAccountsOwner();
    cloudAccountsFake();
    $account = CloudProvider::factory()->for($team)->create();

    $this->actingAs($user)->put(route('cloud-providers.update', $account), [])->assertSessionHasErrors();
});

test('an account still used by a server cannot be removed', function (int $servers, string $message) {
    [$team, $user] = cloudAccountsOwner();
    cloudAccountsFake();
    $account = CloudProvider::factory()->for($team)->create(['api_token' => 'tok-1']);

    foreach (range(1, $servers) as $index) {
        Server::factory()->fromProvider($account)->create(['name' => sprintf('outpost-%02d', $index), 'created_at' => now()->subMinutes(10 - $index)]);
    }

    $this->actingAs($user)
        ->delete(route('cloud-providers.destroy', $account))
        ->assertSessionHas('error', $message);

    expect($account->fresh()->trashed())->toBeFalse()
        ->and($account->fresh()->api_token)->toBe('tok-1');
})->with([
    'one server' => [1, 'outpost-01 was created with this account. Destroy or detach it first, or replace the token instead.'],
    'several' => [3, 'outpost-01 and 2 more were created with this account. Destroy or detach them first, or replace the token instead.'],
]);

test('removing an account forgets its token first', function () {
    [$team, $user] = cloudAccountsOwner();
    cloudAccountsFake();
    $account = CloudProvider::factory()->for($team)->create(['api_token' => 'tok-1']);
    Server::factory()->fromProvider($account)->create()->delete();
    cloudAccountsCatalogued($account);

    $this->actingAs($user)
        ->delete(route('cloud-providers.destroy', $account))
        ->assertSessionHas('success', 'Removed. Revoke the token at Hetzner Cloud too.');

    expect(DB::table('cloud_providers')->where('id', $account->id)->value('api_token'))->toBeNull()
        ->and($account->fresh()->trashed())->toBeTrue()
        ->and(Cache::has(CloudCatalog::cacheKey($account)))->toBeFalse()
        ->and(Activity::query()->where('description', 'cloud_provider_removed')->sole()->properties->toArray())->toBe(['provider' => 'Hetzner Cloud', 'name' => $account->name]);
});

test('the team page lists accounts without ever sending a token', function () {
    [$team, $owner] = cloudAccountsOwner();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);
    $account = CloudProvider::factory()->for($team)->refused()->create(['name' => 'Hetzner production', 'api_token' => 'tok-secret-1', 'account_label' => 'Monoven']);
    Server::factory()->fromProvider($account)->count(2)->create();
    Server::factory()->fromProvider($account)->create()->delete();
    CloudProvider::factory()->for($team)->create(['name' => 'Gone'])->delete();

    $response = $this->actingAs($owner)->get(route('teams.index'));

    $response->assertOk()
        ->assertDontSee('tok-secret-1')
        ->assertInertia(fn($page) => $page
            ->has('cloud.accounts', 1)
            ->where('cloud.accounts.0.id', $account->id)
            ->where('cloud.accounts.0.type', 'hetzner')
            ->where('cloud.accounts.0.provider', 'Hetzner Cloud')
            ->where('cloud.accounts.0.name', 'Hetzner production')
            ->where('cloud.accounts.0.label', 'Monoven')
            ->where('cloud.accounts.0.refused', true)
            ->where('cloud.accounts.0.refused_on', $account->token_failed_at->format('M j'))
            ->where('cloud.accounts.0.servers', 2)
            ->has('cloud.types', 4)
            ->where('cloud.types.0.value', 'hetzner')
            ->where('cloud.types.0.label', 'Hetzner Cloud')
            ->where('cloud.types.0.token_url', CloudProviderType::Hetzner->tokenUrl())
            ->where('cloud.types.0.help', CloudProviderType::Hetzner->tokenHelp())
            ->where('cloud.types.0.permission', CloudProviderType::Hetzner->permissionHint())
            ->where('cloud.can_manage', true));

    $this->actingAs($user)->get(route('teams.index'))->assertInertia(fn($page) => $page->where('cloud.can_manage', false));
});
