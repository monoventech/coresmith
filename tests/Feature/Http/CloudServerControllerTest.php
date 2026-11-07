<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudProviderType;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\CloudProviderException;
use App\Jobs\RunCloudTask;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\CatalogData;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\HttpCloudApiFactory;
use App\Services\Cloud\Tasks\CloudTask;
use Database\Seeders\Support\CloudCatalogs;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CloudFixtures;
use Tests\Support\FakeCloudApiFactory;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
});

function cloudServerFake(): FakeCloudApiFactory
{
    $fake = new FakeCloudApiFactory;
    CloudFixtures::catalogue($fake->api);
    app()->instance(CloudApiFactory::class, $fake);

    return $fake;
}

function cloudServerAdmin(TeamRole $role = TeamRole::Admin): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner Cloud', 'api_token' => 'tok-1']);

    return [$team, $user->fresh(), $account];
}

function cloudServerPayload(CloudProvider $account, array $overrides = []): array
{
    return [
        'cloud_provider_id' => $account->id,
        'name' => 'outpost-02',
        'region' => 'fsn1',
        'size' => 'cx22',
        'image' => 'ubuntu-24.04',
        'environment' => 'Production',
        ...$overrides,
    ];
}

test('an admin provisions a server through a connected account', function () {
    [$team, $user, $account] = cloudServerAdmin();
    cloudServerFake();

    $response = $this->actingAs($user)->post(route('servers.cloud.store'), cloudServerPayload($account));

    $server = Server::query()->sole();
    $task = CloudTask::of($server);

    $response->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('success', 'Creating outpost-02 at Hetzner Cloud. This page follows along, close it and it keeps going.');

    expect($server->team_id)->toBe($team->id)
        ->and($server->status)->toBe(ServerStatus::Creating)
        ->and($server->ip_address)->toBeNull()
        ->and($server->ssh_user)->toBe('root')
        ->and($server->ssh_port)->toBe(22)
        ->and($server->ssh_public_key)->toStartWith('ssh-ed25519 ')
        ->and($server->cloud_provider_id)->toBe($account->id)
        ->and($server->region)->toBe('fsn1')
        ->and($server->provider_size)->toBe('cx22')
        ->and($server->provider_image)->toBe('ubuntu-24.04')
        ->and((string) $server->provider_monthly_cost)->toBe('3.79')
        ->and($server->provider_currency)->toBe('EUR')
        ->and($server->environment)->toBe('Production')
        ->and($task->type)->toBe(CloudTask::CREATE)
        ->and($task->step)->toBe('create')
        ->and($task->by)->toBe($user->id)
        ->and($task->data)->toMatchArray(['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)'])
        ->and($server->provisioning_log)->toContain('creating outpost-02 at Hetzner Cloud, cx22 in fsn1 with Ubuntu 24.04')
        ->and(Activity::query()->where('description', 'server_creation_started')->sole()->properties->toArray())->toBeInAnyKeyOrder(['provider' => 'Hetzner Cloud', 'size' => 'cx22', 'region' => 'fsn1']);

    Queue::assertPushed(RunCloudTask::class, 1);
});

test('a server is created with the account that was picked', function () {
    [$team, $user] = cloudServerAdmin();
    cloudServerFake();
    $second = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner staging']);

    $this->actingAs($user)->post(route('servers.cloud.store'), cloudServerPayload($second))->assertSessionHasNoErrors();

    expect(Server::query()->sole()->cloud_provider_id)->toBe($second->id);
});

test("provisioning from setup returns to the setup's provisioning step", function () {
    [, $user, $account] = cloudServerAdmin();
    cloudServerFake();

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account, ['return_to' => 'setup']))
        ->assertRedirect(route('setup.show', ['step' => 2]));
});

test('a deployer cannot provision', function () {
    [, $user, $account] = cloudServerAdmin(TeamRole::Member);
    $fake = cloudServerFake();

    $this->actingAs($user)->post(route('servers.cloud.store'), cloudServerPayload($account))->assertForbidden();

    expect(Server::query()->count())->toBe(0)
        ->and($fake->api->calls())->toBe([]);

    Queue::assertNothingPushed();
});

test("another team's account cannot be used", function () {
    [, $user] = cloudServerAdmin();
    cloudServerFake();
    $theirs = CloudProvider::factory()->create();

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($theirs))
        ->assertSessionHasErrors(['cloud_provider_id' => 'Pick a connected provider account.']);

    expect(Server::query()->count())->toBe(0);
});

test('a removed account cannot be used', function () {
    [, $user, $account] = cloudServerAdmin();
    $fake = cloudServerFake();
    $account->delete();

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account))
        ->assertSessionHasErrors(['cloud_provider_id' => 'Pick a connected provider account.']);

    expect($fake->api->calls())->toBe([])
        ->and(Server::query()->count())->toBe(0);
});

test('an account whose token was refused cannot be used', function () {
    [, $user, $account] = cloudServerAdmin();
    $fake = cloudServerFake();
    $account->forceFill(['token_failed_at' => now()])->save();

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account))
        ->assertSessionHasErrors('cloud_provider_id');

    expect(session('errors')->first('cloud_provider_id'))->toContain("refused this account's token")
        ->and($fake->api->calls())->toBe([])
        ->and(Server::query()->count())->toBe(0);
});

test('a size the provider no longer offers in that region is refused', function () {
    [, $user, $account] = cloudServerAdmin();
    cloudServerFake();

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account, ['size' => 'cx52']))
        ->assertSessionHasErrors(['size' => "That size isn't offered in fsn1 any more. Pick another."]);

    expect(Server::query()->count())->toBe(0);
});

test('a size offered only in another region is refused', function () {
    [, $user, $account] = cloudServerAdmin();
    $fake = new FakeCloudApiFactory;
    $fake->api->catalog(
        [new CloudRegion('fsn1', 'Falkenstein (fsn1)', 'DE'), new CloudRegion('ash', 'Ashburn (ash)', 'US')],
        [
            new CloudSize('cx22', 2, 4096, 40, 'x86', null, ['fsn1' => new CloudPrice('3.79', 'EUR')], ['fsn1']),
            new CloudSize('cpx21', 3, 4096, 80, 'x86', null, ['ash' => new CloudPrice('8.49', 'EUR')], ['ash']),
        ],
        [new CloudImage('ubuntu-24.04', 'ubuntu', '24.04', ['x86', 'arm'], 'Ubuntu 24.04')],
    );
    app()->instance(CloudApiFactory::class, $fake);

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account, ['size' => 'cpx21']))
        ->assertSessionHasErrors(['size' => "That size isn't offered in fsn1 any more. Pick another."]);

    expect(Server::query()->count())->toBe(0);
});

test('a region the provider no longer offers is refused', function () {
    [, $user, $account] = cloudServerAdmin();
    cloudServerFake();

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account, ['region' => 'ash']))
        ->assertSessionHasErrors(['region' => "That region isn't offered any more. Pick another."]);
});

test('an image that does not run on the size is refused', function () {
    [, $user, $account] = cloudServerAdmin();
    cloudServerFake();

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account, ['size' => 'cax11', 'image' => 'debian-12']))
        ->assertSessionHasErrors(['image' => "That image doesn't run on cax11. Pick another."]);
});

test('a name the providers would refuse is refused before anything is sent', function (string $name) {
    [, $user, $account] = cloudServerAdmin();
    $fake = cloudServerFake();

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account, ['name' => $name]))
        ->assertSessionHasErrors(['name' => 'Use 3 to 63 lowercase letters, digits and single hyphens, like outpost-02.']);

    expect($fake->api->calls())->toBe([]);
})->with([
    'too short' => ['ab'],
    'upper case' => ['Outpost'],
    'a double hyphen' => ['out--post'],
    'a leading hyphen' => ['-outpost'],
    'a trailing hyphen' => ['outpost-'],
    'an underscore' => ['outpost_02'],
    'too long' => [str_repeat('a', 64)],
]);

test('a provider that cannot be reached is reported on the form', function () {
    [, $user, $account] = cloudServerAdmin();
    $fake = cloudServerFake();
    $fake->api->failing('regions', new CloudProviderException(CloudFailure::Unreachable, CloudProviderType::Hetzner));

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account))
        ->assertSessionHasErrors('cloud_provider_id');

    expect(session('errors')->first('cloud_provider_id'))->toStartWith("Coresmith couldn't check that with Hetzner Cloud.")
        ->and(Server::query()->count())->toBe(0);
});

test('a token the provider refuses while checking marks the account refused', function () {
    [, $user, $account] = cloudServerAdmin();
    $fake = cloudServerFake();
    $fake->api->failing('regions', new CloudProviderException(CloudFailure::TokenRejected, CloudProviderType::Hetzner));

    $this->actingAs($user)
        ->post(route('servers.cloud.store'), cloudServerPayload($account))
        ->assertSessionHasErrors('cloud_provider_id');

    expect($account->fresh()->isRefused())->toBeTrue()
        ->and(Server::query()->count())->toBe(0);
});

test('a failed creation can be tried again', function () {
    [$team, $user, $account] = cloudServerAdmin();
    $server = Server::factory()->fromProvider($account)->creating()->create();
    CloudTask::start($server, CloudTask::CREATE, 'create');
    CloudTask::fail($server, 'Hetzner Cloud refused the size.');

    $this->actingAs($user)
        ->post(route('servers.cloud.retry', $server))
        ->assertSessionHas('success', 'Trying again. This page follows along.');

    $task = CloudTask::of($server->fresh());

    expect($task->state)->toBe(CloudTask::RUNNING)
        ->and($task->error)->toBeNull();

    Queue::assertPushed(RunCloudTask::class, 1);
});

test('only a failed creation can be tried again', function () {
    [$team, $user, $account] = cloudServerAdmin();
    $running = Server::factory()->fromProvider($account)->creating()->create();
    CloudTask::start($running, CloudTask::CREATE, 'boot');
    $manual = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
    $failed = Server::factory()->fromProvider($account)->creating()->create();
    CloudTask::start($failed, CloudTask::CREATE, 'create');
    CloudTask::fail($failed, 'Hetzner Cloud refused the size.');
    $resize = Server::factory()->fromProvider($account)->create(['status' => ServerStatus::Active]);
    CloudTask::start($resize, CloudTask::RESIZE, 'resize');
    CloudTask::fail($resize, 'Hetzner Cloud refused the size.');
    $deployer = User::factory()->create();
    $team->members()->attach($deployer, ['role' => TeamRole::Member->value]);

    $this->actingAs($user)->post(route('servers.cloud.retry', $running))->assertNotFound();
    $this->actingAs($user)->post(route('servers.cloud.retry', $manual))->assertNotFound();
    $this->actingAs($user)->post(route('servers.cloud.retry', $resize))->assertNotFound();
    $this->actingAs($deployer->fresh())->post(route('servers.cloud.retry', $failed))->assertForbidden();

    Queue::assertNothingPushed();
});

test('the provision page offers the team accounts and loads the catalog after it renders', function () {
    [$team, $user, $first] = cloudServerAdmin();
    cloudServerFake();
    $second = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner staging', 'api_token' => 'tok-secret-2', 'created_at' => now()->addMinute()]);

    $this->actingAs($user)->get(route('servers.create'))
        ->assertOk()
        ->assertDontSee('tok-1')
        ->assertDontSee('tok-secret-2')
        ->assertInertia(fn($page) => $page
            ->where('cloud.selected', 'account:' . $first->id)
            ->missing('catalog')
            ->loadDeferredProps(fn($reload) => $reload
                ->where('catalog.error', null)
                ->has('catalog.sizes', 2)
                ->where('catalog.sizes.0.prices.fsn1', '€3.79')));

    $this->actingAs($user)->get(route('servers.create', ['account' => $second->id]))
        ->assertInertia(fn($page) => $page->where('cloud.selected', 'account:' . $second->id));

    $this->actingAs($user)->get(route('servers.create', ['account' => 'nope']))
        ->assertInertia(fn($page) => $page->where('cloud.selected', 'account:' . $first->id));

    $ocean = CloudProvider::factory()->digitalocean()->for($team)->create(['created_at' => now()->addMinutes(2)]);

    $this->actingAs($user)->get(route('servers.create', ['account' => $ocean->id]))
        ->assertInertia(fn($page) => $page
            ->where('cloud.selected', 'account:' . $ocean->id)
            ->loadDeferredProps(fn($reload) => $reload->where('catalog.provider', 'DigitalOcean')));
});

test('the provision page with no account offers a manual server and loads no catalog', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    $this->actingAs($user->fresh())->get(route('servers.create'))
        ->assertInertia(fn($page) => $page
            ->where('cloud.selected', 'manual')
            ->loadDeferredProps(fn($reload) => $reload->where('catalog', null)));
});

function cloudDestroyable(CloudProvider $account, array $attributes = []): Server
{
    return Server::factory()->fromProvider($account)->create(['name' => 'outpost-02', 'status' => ServerStatus::Active, ...$attributes]);
}

test("destroying needs the server's name typed exactly", function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account);

    $this->actingAs($user)
        ->from(route('servers.show', $server))
        ->delete(route('servers.cloud.destroy', $server), ['confirm' => 'Outpost-02'])
        ->assertSessionHasErrors(['confirm' => 'Type outpost-02 exactly to confirm.']);

    expect(CloudTask::of($server->fresh()))->toBeNull();

    Queue::assertNothingPushed();
});

test('an admin starts destroying a provider server', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account);

    $this->actingAs($user)
        ->from(route('servers.show', $server))
        ->delete(route('servers.cloud.destroy', $server), ['confirm' => 'outpost-02'])
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('success', 'Destroying outpost-02 at Hetzner Cloud. It leaves the fleet once Hetzner Cloud confirms.');

    $task = CloudTask::of($server->fresh());

    expect($task->type)->toBe(CloudTask::DESTROY)
        ->and($task->step)->toBe('delete')
        ->and($task->by)->toBe($user->id)
        ->and($server->fresh()->provisioning_log)->toContain('destroying outpost-02 at Hetzner Cloud');

    Queue::assertPushed(RunCloudTask::class, 1);
});

test('a key left over from creating a server is carried into destroying it', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account);
    CloudTask::start($server, CloudTask::CREATE, 'settle', ['key_id' => 'key-9']);
    CloudTask::finish($server);

    $this->actingAs($user)->delete(route('servers.cloud.destroy', $server), ['confirm' => 'outpost-02']);

    expect(CloudTask::of($server->fresh())->data)->toBe(['key_id' => 'key-9']);
});

test('a deployer cannot destroy', function () {
    [, $user, $account] = cloudServerAdmin(TeamRole::Member);
    $server = cloudDestroyable($account);

    $this->actingAs($user)->delete(route('servers.cloud.destroy', $server), ['confirm' => 'outpost-02'])->assertForbidden();

    Queue::assertNothingPushed();
});

test('a server not from a provider cannot be destroyed here', function () {
    [$team, $user, $account] = cloudServerAdmin();
    $manual = Server::factory()->for($team)->create(['name' => 'outpost-02', 'status' => ServerStatus::Active]);
    $never = cloudDestroyable($account, ['name' => 'never-made', 'provider_server_id' => null]);

    $this->actingAs($user)->delete(route('servers.cloud.destroy', $manual), ['confirm' => 'outpost-02'])->assertNotFound();
    $this->actingAs($user)->delete(route('servers.cloud.destroy', $never), ['confirm' => 'never-made'])->assertNotFound();

    Queue::assertNothingPushed();
});

test('destroying waits for other work on the server', function () {
    [, $user, $account] = cloudServerAdmin();
    $busy = cloudDestroyable($account);
    CloudTask::start($busy, CloudTask::RESIZE, 'change');
    $deploying = cloudDestroyable($account, ['name' => 'outpost-03']);
    Deployment::factory()->for(Application::factory()->for($deploying->team)->for($deploying)->create())->create(['status' => DeploymentStatus::Running]);

    $this->actingAs($user)
        ->delete(route('servers.cloud.destroy', $busy), ['confirm' => 'outpost-02'])
        ->assertSessionHas('error', 'Coresmith is already working on outpost-02. Wait for that to finish first.');
    $this->actingAs($user)
        ->delete(route('servers.cloud.destroy', $deploying), ['confirm' => 'outpost-03'])
        ->assertSessionHas('error', 'A deploy is running on outpost-03. Destroy it once the deploy finishes.');

    expect(CloudTask::of($busy->fresh())->type)->toBe(CloudTask::RESIZE)
        ->and(CloudTask::of($deploying->fresh()))->toBeNull();

    Queue::assertNothingPushed();
});

test('a failed destroy can be tried again', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account);
    CloudTask::start($server, CloudTask::DESTROY, 'delete');
    CloudTask::fail($server, 'Hetzner Cloud refused the request.');

    $this->actingAs($user)
        ->post(route('servers.cloud.retry', $server))
        ->assertSessionHas('success', 'Trying again. This page follows along.');

    expect(CloudTask::of($server->fresh())->state)->toBe(CloudTask::RUNNING);

    Queue::assertPushed(RunCloudTask::class, 1);
});

function cloudResizable(CloudProvider $account, array $attributes = []): Server
{
    CloudCatalog::put($account, CloudCatalogs::hetzner(), 600);

    return cloudDestroyable($account, $attributes);
}

test('the resize choices keep the disk and the architecture', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudResizable($account);

    $this->actingAs($user)
        ->getJson(route('servers.cloud.sizes', $server))
        ->assertOk()
        ->assertExactJson([
            'current' => ['id' => 'cx22', 'label' => '4 GB · 2 vCPU', 'disk_gb' => 40, 'monthly' => '€3.79'],
            'sizes' => [
                ['id' => 'cx32', 'label' => '8 GB · 4 vCPU', 'monthly' => '€6.80'],
                ['id' => 'cpx21', 'label' => '4 GB · 3 vCPU', 'monthly' => '€7.55'],
                ['id' => 'cx42', 'label' => '16 GB · 8 vCPU', 'monthly' => '€16.40'],
            ],
            'note' => 'The disk stays 40 GB, so you can size back down later.',
            'error' => null,
        ]);
});

test('vultr only offers bigger plans', function () {
    [$team, $user] = cloudServerAdmin();
    $account = CloudProvider::factory()->for($team)->create(['type' => CloudProviderType::Vultr, 'name' => 'Vultr']);
    $usd = fn(string $monthly): CloudPrice => new CloudPrice($monthly, 'USD');

    CloudCatalog::put($account, new CatalogData(collect([new CloudRegion('fra', 'Frankfurt', 'DE')]), collect([
        new CloudSize('vc2-1c-2gb', 1, 2048, 55, 'x86', $usd('10.00')),
        new CloudSize('vhp-1c-2gb', 1, 2048, 64, 'x86', $usd('10.00')),
        new CloudSize('vc2-2c-4gb', 2, 4096, 80, 'x86', $usd('20.00')),
    ]), collect()), 600);

    $server = cloudDestroyable($account, ['region' => 'fra', 'provider_size' => 'vc2-1c-2gb']);

    $this->actingAs($user)
        ->getJson(route('servers.cloud.sizes', $server))
        ->assertJsonPath('sizes.*.id', ['vc2-2c-4gb'])
        ->assertJsonPath('note', 'Vultr only resizes up, and the disk grows with the new size.');
});

test('a size the provider no longer lists cannot be resized from', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudResizable($account, ['provider_size' => 'cx11']);

    $this->actingAs($user)
        ->getJson(route('servers.cloud.sizes', $server))
        ->assertOk()
        ->assertExactJson(['error' => "Coresmith can't find cx11 in Hetzner Cloud's current sizes, so it can't offer a resize."]);
});

test('an account whose token was refused says so instead of offering sizes', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudResizable($account);
    $account->forceFill(['token_failed_at' => now()->setDate(2026, 10, 30)])->save();

    $this->actingAs($user)
        ->getJson(route('servers.cloud.sizes', $server))
        ->assertOk()
        ->assertExactJson(['error' => "Hetzner Cloud refused this account's token on Oct 30. Replace it in Settings → Team, then try again."]);
});

test('a catalog that cannot be read says why', function () {
    $fake = cloudServerFake();
    $fake->api->failing('regions', new CloudProviderException(CloudFailure::Unavailable, CloudProviderType::Hetzner, status: 503));
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account);

    $this->actingAs($user)
        ->getJson(route('servers.cloud.sizes', $server))
        ->assertOk()
        ->assertExactJson(['error' => "Coresmith couldn't load Hetzner Cloud's regions and sizes. Hetzner Cloud had a problem answering (HTTP 503). Try again in a minute."]);
});

test('an admin starts a resize', function () {
    cloudServerFake();
    [, $user, $account] = cloudServerAdmin();
    $server = cloudResizable($account);

    $this->actingAs($user)
        ->post(route('servers.cloud.resize', $server), ['size' => 'cx32'])
        ->assertSessionHas('success', 'Resizing outpost-02 to cx32. Apps are offline until it starts again.');

    $task = CloudTask::of($server->fresh());

    expect($task->type)->toBe(CloudTask::RESIZE)
        ->and($task->step)->toBe('power_off')
        ->and($task->data)->toBeInAnyKeyOrder(['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true])
        ->and($task->by)->toBe((string) $user->id)
        ->and((string) $server->fresh()->provisioning_log)->toContain('resizing outpost-02 from cx22 to cx32 at Hetzner Cloud');

    Queue::assertPushed(RunCloudTask::class, 1);
});

test('a size that is not offered is refused', function (string $size) {
    cloudServerFake();
    [, $user, $account] = cloudServerAdmin();
    $server = cloudResizable($account);

    $this->actingAs($user)
        ->post(route('servers.cloud.resize', $server), ['size' => $size])
        ->assertSessionHasErrors(['size' => "That size can't be used for outpost-02. Pick one from the list."]);

    expect(CloudTask::of($server->fresh()))->toBeNull();

    Queue::assertNothingPushed();
})->with(['an arm size' => 'cax11', 'the current size' => 'cx22', 'a size nobody sells' => 'cx99', 'nothing' => '']);

test('a size the provider could not be asked about says what happened, not that the size cannot be used', function (CloudFailure $failure, ?int $status, string $error, bool $marked) {
    $fake = cloudServerFake();
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account);
    $fake->api->failing('regions', new CloudProviderException($failure, CloudProviderType::Hetzner, null, $status));

    $this->actingAs($user)
        ->from(route('servers.show', $server))
        ->post(route('servers.cloud.resize', $server), ['size' => 'cx32'])
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHasErrors(['size' => $error]);

    expect(CloudTask::of($server->fresh()))->toBeNull()
        ->and($account->fresh()->token_failed_at !== null)->toBe($marked);

    Queue::assertNothingPushed();
})->with([
    'no answer' => [CloudFailure::Unavailable, 503, "Coresmith couldn't check that size with Hetzner Cloud. Hetzner Cloud had a problem answering (HTTP 503). Try again in a minute.", false],
    'a refused token' => [CloudFailure::TokenRejected, 401, "Coresmith couldn't check that size with Hetzner Cloud. Hetzner Cloud refused this account's token. Replace it in Settings → Team, then try again.", true],
]);

test('a resize whose token cannot be used says so instead of failing', function () {
    app()->instance(CloudApiFactory::class, new HttpCloudApiFactory);
    [, $user, $account] = cloudServerAdmin();
    $server = cloudResizable($account);
    $account->forceFill(['api_token' => null])->save();

    $this->actingAs($user)
        ->from(route('servers.show', $server))
        ->post(route('servers.cloud.resize', $server), ['size' => 'cx32'])
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('error', 'No token is stored for this Hetzner Cloud account. Replace the token in Settings → Team, then try again.');

    expect(CloudTask::of($server->fresh()))->toBeNull();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('a deployer cannot resize', function () {
    cloudServerFake();
    [, $user, $account] = cloudServerAdmin(TeamRole::Member);
    $server = cloudResizable($account);

    $this->actingAs($user)->getJson(route('servers.cloud.sizes', $server))->assertForbidden();
    $this->actingAs($user)->post(route('servers.cloud.resize', $server), ['size' => 'cx32'])->assertForbidden();

    Queue::assertNothingPushed();
});

test('a server that is not running at its provider cannot be resized', function (array $attributes) {
    cloudServerFake();
    [, $user, $account] = cloudServerAdmin();
    $server = cloudResizable($account, $attributes);

    $this->actingAs($user)->getJson(route('servers.cloud.sizes', $server))->assertNotFound();
    $this->actingAs($user)->post(route('servers.cloud.resize', $server), ['size' => 'cx32'])->assertNotFound();
})->with([
    'still provisioning' => [['status' => ServerStatus::Provisioning]],
    'with no instance' => [['provider_server_id' => null]],
]);

test('a resize waits for other work', function () {
    cloudServerFake();
    [, $user, $account] = cloudServerAdmin();
    $busy = cloudResizable($account);
    CloudTask::start($busy, CloudTask::DESTROY, 'delete');
    $deploying = cloudResizable($account, ['name' => 'outpost-03']);
    Deployment::factory()->for(Application::factory()->for($deploying->team)->for($deploying)->create())->create(['status' => DeploymentStatus::Running]);

    $this->actingAs($user)
        ->post(route('servers.cloud.resize', $busy), ['size' => 'cx32'])
        ->assertSessionHas('error', 'Coresmith is destroying outpost-02 at Hetzner Cloud.');
    $this->actingAs($user)
        ->post(route('servers.cloud.resize', $deploying), ['size' => 'cx32'])
        ->assertSessionHas('error', 'A deploy is running on outpost-03. Resize it once the deploy finishes.');

    Queue::assertNothingPushed();
});

test('a failed resize can be dismissed', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account);
    CloudTask::start($server, CloudTask::RESIZE, 'change', ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);
    CloudTask::fail($server, 'Hetzner Cloud refused the request.');

    $this->actingAs($user)
        ->delete(route('servers.cloud.dismiss', $server))
        ->assertSessionHas('success', 'Dismissed.');

    expect(CloudTask::of($server->fresh()))->toBeNull();
});

test('only a failed resize can be dismissed', function (string $type, string $state, TeamRole $role, int $status) {
    [, $user, $account] = cloudServerAdmin($role);
    $server = cloudDestroyable($account);
    CloudTask::start($server, $type, $type === CloudTask::RESIZE ? 'change' : 'delete');

    if ($state === CloudTask::FAILED) {
        CloudTask::fail($server, 'Hetzner Cloud refused the request.');
    }

    $this->actingAs($user)->delete(route('servers.cloud.dismiss', $server))->assertStatus($status);

    expect(CloudTask::of($server->fresh()))->not->toBeNull();
})->with([
    'a running resize' => [CloudTask::RESIZE, CloudTask::RUNNING, TeamRole::Owner, 404],
    'a failed destroy' => [CloudTask::DESTROY, CloudTask::FAILED, TeamRole::Owner, 404],
    'a failed resize, for a deployer' => [CloudTask::RESIZE, CloudTask::FAILED, TeamRole::Member, 403],
]);

test('a creation Coresmith could not confirm can be destroyed, which looks for it by its tag', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account, ['name' => 'lost-02', 'provider_server_id' => null, 'status' => ServerStatus::Creating]);
    CloudTask::start($server, CloudTask::CREATE, 'create', ['uncertain' => 3, 'key_id' => 'key-9']);
    CloudTask::fail($server, "Coresmith couldn't get an answer from Hetzner Cloud after three tries.");

    $this->actingAs($user)
        ->from(route('servers.show', $server))
        ->delete(route('servers.cloud.destroy', $server), ['confirm' => 'lost-02'])
        ->assertSessionHas('success', 'Destroying lost-02 at Hetzner Cloud. It leaves the fleet once Hetzner Cloud confirms.');

    expect(CloudTask::of($server->fresh()))
        ->type->toBe(CloudTask::DESTROY)
        ->step->toBe('delete')
        ->data->toBe(['key_id' => 'key-9']);

    Queue::assertPushed(RunCloudTask::class, 1);
});

test('a creation the provider refused has nothing to destroy', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account, ['name' => 'refused-02', 'provider_server_id' => null, 'status' => ServerStatus::Creating]);
    CloudTask::start($server, CloudTask::CREATE, 'create', ['uncertain' => 0]);
    CloudTask::fail($server, 'Hetzner Cloud refused the request.');

    $this->actingAs($user)->delete(route('servers.cloud.destroy', $server), ['confirm' => 'refused-02'])->assertNotFound();

    Queue::assertNothingPushed();
});

test('a server being provisioned is not destroyed until provisioning ends', function () {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account, ['status' => ServerStatus::Provisioning]);

    $this->actingAs($user)
        ->from(route('servers.show', $server))
        ->delete(route('servers.cloud.destroy', $server), ['confirm' => 'outpost-02'])
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('error', 'Coresmith is provisioning outpost-02. Destroy it once provisioning finishes or fails.');

    expect(CloudTask::of($server->fresh()))->toBeNull();

    Queue::assertNothingPushed();
});

test('once a destroy has finished, the server pages send the person to the fleet with a confirmation', function (string $route) {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account);
    CloudTask::start($server, CloudTask::DESTROY, 'delete');
    CloudTask::finish($server);
    $server->delete();

    $this->actingAs($user)
        ->get(route($route, $server))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Destroyed. outpost-02 was deleted at Hetzner Cloud, and billing for it stopped.');
})->with(['servers.show', 'servers.hardening', 'servers.activity', 'servers.databases.index']);

test('a server that left the panel any other way is still not found', function (Closure $leave) {
    [, $user, $account] = cloudServerAdmin();
    $server = cloudDestroyable($account);
    $leave($server);
    $server->delete();

    $this->actingAs($user)->get(route('servers.show', $server))->assertNotFound();
})->with([
    'detached' => [fn(Server $server) => null],
    'a destroy that did not finish' => [fn(Server $server) => CloudTask::start($server, CloudTask::DESTROY, 'delete')],
    'a finished resize' => [fn(Server $server) => CloudTask::finish(tap($server, fn(Server $s) => CloudTask::start($s, CloudTask::RESIZE, 'reconnect')))],
]);

test('a destroyed server is not announced to someone outside its team', function () {
    [, , $account] = cloudServerAdmin();
    [, $stranger] = cloudServerAdmin();
    $server = cloudDestroyable($account);
    CloudTask::start($server, CloudTask::DESTROY, 'delete');
    CloudTask::finish($server);
    $server->delete();

    $response = $this->actingAs($stranger)->get(route('servers.show', $server));

    expect($response->status())->toBeIn([403, 404])
        ->and(session('success'))->toBeNull();
});
