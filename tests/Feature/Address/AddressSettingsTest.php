<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Jobs\ChangeAddress;
use App\Models\GitHubApp;
use App\Models\Team;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Services\Address\AddressChange;
use App\Services\Network\AddressResolver;
use App\Services\Network\LocalAddresses;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\CloudBilling;

function addressSettingsUser(TeamRole $role = TeamRole::Owner): User
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function addressSettingsResolving(array $addresses): void
{
    app()->instance(AddressResolver::class, new class($addresses) implements AddressResolver
    {
        public function __construct(private readonly array $records) {}

        public function addresses(string $host): array
        {
            return $this->records;
        }
    });

    app()->instance(LocalAddresses::class, new class implements LocalAddresses
    {
        public function all(): array
        {
            return ['127.0.0.1', '192.168.0.248'];
        }
    });
}

beforeEach(function () {
    Queue::fake();
    config(['app.url' => 'https://192.168.0.248', 'coresmith.address.site' => '/etc/caddy/coresmith/panel.caddy']);
});

test('an owner moves coresmith to a new address, which the old one answers on until it is ready', function () {
    addressSettingsResolving(['93.184.215.14']);

    $this->actingAs(addressSettingsUser())
        ->from(route('settings.general'))
        ->put(route('settings.address.update'), ['address' => 'https://Coresmith.Example.com/'])
        ->assertRedirect(route('settings.general'))
        ->assertSessionHas('success', 'Switching to coresmith.example.com. Coresmith keeps answering on 192.168.0.248 until the certificate is ready.');

    expect(AddressChange::current())->toMatchArray(['state' => 'running', 'from' => '192.168.0.248', 'to' => 'coresmith.example.com']);

    Queue::assertPushed(ChangeAddress::class, fn(ChangeAddress $job): bool => $job->host === 'coresmith.example.com');
});

test('an address that would not work is refused next to the field, and nothing starts', function () {
    addressSettingsResolving([]);

    $this->actingAs(addressSettingsUser())
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertSessionHasErrors(['address' => "coresmith.example.com doesn't point anywhere yet. Add a DNS record that points it at this server, then try again."]);

    expect(AddressChange::current())->toBeNull();

    Queue::assertNothingPushed();
});

test('an install from before Coresmith could change its address says to run the installer', function () {
    config(['coresmith.address.site' => '']);
    addressSettingsResolving(['93.184.215.14']);

    $this->actingAs(addressSettingsUser())
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertSessionHasErrors(['address' => "This install can't change its address from here yet. Run the installer again with --domain coresmith.example.com."]);

    Queue::assertNothingPushed();
});

test('a change already under way is not started twice', function () {
    addressSettingsResolving(['93.184.215.14']);
    AddressChange::begin('192.168.0.248', 'coresmith.example.com');

    $this->actingAs(addressSettingsUser())
        ->put(route('settings.address.update'), ['address' => 'other.example.com'])
        ->assertSessionHasErrors(['address' => 'Coresmith is already changing its address. Wait for it to finish.']);

    Queue::assertNothingPushed();
});

test('only an owner of a team here may change the address', function () {
    addressSettingsResolving(['93.184.215.14']);

    $this->actingAs(addressSettingsUser(TeamRole::Admin))
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('coresmith cloud has no address to change, even for an owner on a paid plan', function () {
    config(['coresmith.edition' => 'cloud']);
    addressSettingsResolving(['93.184.215.14']);
    $owner = CloudBilling::owner(Team::factory()->owned()->subscribed(Plan::Pro)->create());

    $this->actingAs($owner)
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('the settings page shows the address, its certificate and whether it can change here', function () {
    $this->actingAs(addressSettingsUser())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('address.host', '192.168.0.248')
            ->where('address.url', 'https://192.168.0.248')
            ->where('address.certificate', 'internal')
            ->where('address.managed', true)
            ->where('address.change', null)
            ->where('address.follow', null));
});

test('a change under way or failed is shown with what happened', function () {
    AddressChange::begin('192.168.0.248', 'coresmith.example.com');
    AddressChange::fail('No certificate for coresmith.example.com after 3 minutes.');

    $this->actingAs(addressSettingsUser())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('address.change.state', 'failed')
            ->where('address.change.to', 'coresmith.example.com')
            ->where('address.change.error', 'No certificate for coresmith.example.com after 3 minutes.'));
});

test('after a move the page says what else needs the new address', function () {
    config(['app.url' => 'https://coresmith.example.com']);
    AddressChange::begin('192.168.0.248', 'coresmith.example.com');
    AddressChange::finish();
    GitHubApp::factory()->create();
    TeamSsoConnection::factory()->create();

    $this->actingAs(addressSettingsUser())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('address.certificate', 'public')
            ->where('address.follow.github', route('source-providers.index'))
            ->where('address.follow.sso', 'https://coresmith.example.com/sso/callback'));
});

test('cloud has no address card', function () {
    config(['coresmith.edition' => 'cloud']);

    $this->actingAs(addressSettingsUser())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('address', null));
});
