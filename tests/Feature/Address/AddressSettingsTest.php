<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\GitHubApp;
use App\Models\Team;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Services\Address\AddressChange;
use App\Services\Address\AddressCheck;
use App\Services\Address\AddressRequest;
use App\Services\Network\AddressResolver;
use App\Services\Network\DirectReach;
use App\Services\Network\HostReach;
use App\Services\Network\LocalAddresses;
use App\Services\Network\ReachProbe;
use App\Services\Network\StableAddresses;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
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

function addressSettingsReach(?DirectReach $direct): object
{
    app()->instance(StableAddresses::class, new class implements StableAddresses
    {
        public function ipv6(): array
        {
            return [];
        }
    });

    $probe = new class($direct) implements ReachProbe
    {
        public int $asked = 0;

        public function __construct(private readonly ?DirectReach $reach) {}

        public function direct(): ?DirectReach
        {
            $this->asked++;

            return $this->reach;
        }

        public function host(string $host, string $from): ?HostReach
        {
            $this->asked++;

            return null;
        }
    };

    app()->instance(ReachProbe::class, $probe);

    return $probe;
}

beforeEach(function () {
    $this->addressState = sys_get_temp_dir() . '/coresmith-address-state-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->addressState);
    config([
        'app.url' => 'https://192.168.0.248',
        'coresmith.address.site' => '/etc/caddy/coresmith/panel.caddy',
        'coresmith.address.state_path' => $this->addressState,
    ]);
});

afterEach(fn() => File::deleteDirectory($this->addressState));

test('an owner moves coresmith to a new address, which the old one answers on until it is ready', function () {
    addressSettingsResolving(['93.184.215.14']);

    $this->actingAs(addressSettingsUser())
        ->from(route('settings.general'))
        ->put(route('settings.address.update'), ['address' => 'https://Coresmith.Example.com/'])
        ->assertRedirect(route('settings.general'))
        ->assertSessionHas('success', 'Switching to coresmith.example.com. Coresmith keeps answering on 192.168.0.248 until the certificate is ready.');

    expect(AddressChange::current())->toMatchArray(['state' => 'running', 'from' => '192.168.0.248', 'to' => 'coresmith.example.com'])
        ->and(json_decode((string) file_get_contents(AddressRequest::file()), true)['host'])->toBe('coresmith.example.com');
});

test('an address that would not work is refused next to the field, and nothing starts', function () {
    addressSettingsResolving([]);

    $this->actingAs(addressSettingsUser())
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertSessionHasErrors(['address' => "coresmith.example.com doesn't point anywhere yet. Add a DNS record that points it at this server, then check again."]);

    expect(AddressChange::current())->toBeNull();

    expect(file_exists(AddressRequest::file()))->toBeFalse();
});

test('a domain the internet cannot reach is refused with what to fix, and nothing starts', function () {
    addressSettingsResolving(['151.226.169.196']);
    addressSettingsReach(new DirectReach('151.226.169.196', false, false));

    $this->actingAs(addressSettingsUser())
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertSessionHasErrors(['address' => 'coresmith.example.com points at 151.226.169.196, but nothing reaches this server on ports 80 and 443 from the internet. Forward TCP ports 80 and 443 on your router to 192.168.0.248, then check again. If your internet provider shares that address with other customers, this server needs an IPv6 address before anyone can reach it.']);

    expect(AddressChange::current())->toBeNull()
        ->and(file_exists(AddressRequest::file()))->toBeFalse();
});

test('a domain the internet reaches starts the switch', function () {
    addressSettingsResolving(['151.226.169.196']);
    addressSettingsReach(new DirectReach('151.226.169.196', true, true));

    $this->actingAs(addressSettingsUser())
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertSessionHasNoErrors();

    expect(AddressChange::current())->toMatchArray(['state' => 'running', 'to' => 'coresmith.example.com']);
});

test('an internal name never asks get.coresmith.io', function () {
    addressSettingsResolving(['192.168.0.248']);
    $probe = addressSettingsReach(null);

    $this->actingAs(addressSettingsUser())
        ->put(route('settings.address.update'), ['address' => 'coresmith.internal'])
        ->assertSessionHasNoErrors();

    expect($probe->asked)->toBe(0);
});

test('an install from before Coresmith could change its address says to run the installer', function () {
    config(['coresmith.address.site' => '']);
    addressSettingsResolving(['93.184.215.14']);

    $this->actingAs(addressSettingsUser())
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertSessionHasErrors(['address' => "This install can't change its address from here yet. Run the installer again with --domain coresmith.example.com."]);

    expect(file_exists(AddressRequest::file()))->toBeFalse();
});

test('a change already under way is not started twice', function () {
    addressSettingsResolving(['93.184.215.14']);
    AddressChange::begin('192.168.0.248', 'coresmith.example.com');

    $this->actingAs(addressSettingsUser())
        ->put(route('settings.address.update'), ['address' => 'other.example.com'])
        ->assertSessionHasErrors(['address' => 'Coresmith is already changing its address. Wait for it to finish.']);

    expect(file_exists(AddressRequest::file()))->toBeFalse();
});

test('a request coresmith cannot write fails at once and says why', function () {
    addressSettingsResolving(['93.184.215.14']);
    chmod($this->addressState, 0555);

    try {
        $this->actingAs(addressSettingsUser())
            ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
            ->assertSessionHasErrors('address');
    } finally {
        chmod($this->addressState, 0755);
    }

    expect(AddressChange::current()['state'])->toBe('failed')
        ->and(AddressChange::current()['error'])->toStartWith("Coresmith couldn't ask for the change: ");
});

test('only an owner of a team here may change the address', function () {
    addressSettingsResolving(['93.184.215.14']);

    $this->actingAs(addressSettingsUser(TeamRole::Admin))
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertForbidden();

    expect(file_exists(AddressRequest::file()))->toBeFalse();
});

test('coresmith cloud has no address to change, even for an owner on a paid plan', function () {
    config(['coresmith.edition' => 'cloud']);
    addressSettingsResolving(['93.184.215.14']);
    $owner = CloudBilling::owner(Team::factory()->owned()->subscribed(Plan::Pro)->create());

    $this->actingAs($owner)
        ->put(route('settings.address.update'), ['address' => 'coresmith.example.com'])
        ->assertForbidden();

    expect(file_exists(AddressRequest::file()))->toBeFalse();
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

test('a domain with only an IPv6 address is flagged, since people without IPv6 cannot open Coresmith there', function (array $records, bool $flagged) {
    config(['app.url' => 'https://coresmith.example.com']);
    addressSettingsResolving($records);

    $this->actingAs(addressSettingsUser())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('address.host', 'coresmith.example.com')->where('address.ipv6_only', $flagged));
})->with([
    'only IPv6' => [['2001:db8::10'], true],
    'IPv4 and IPv6' => [['203.0.113.10', '2001:db8::10'], false],
    'only IPv4' => [['203.0.113.10'], false],
    'not resolving yet' => [[], false],
]);

test('an address with an internal certificate is never looked up or flagged', function (string $url) {
    config(['app.url' => $url]);
    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            throw new RuntimeException("Looked up {$host}.");
        }
    });

    $this->actingAs(addressSettingsUser())
        ->get(route('settings.general'))
        ->assertOk()
        ->assertInertia(fn(AssertableInertia $page) => $page->where('address.ipv6_only', false));
})->with(['localhost' => 'http://localhost', 'an IP' => 'https://192.168.0.248', 'a .local name' => 'https://beelink.local']);

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

test('checking a domain keeps what the check found, one problem at a time, and starts nothing', function () {
    addressSettingsResolving(['151.226.169.196']);
    addressSettingsReach(new DirectReach('151.226.169.196', false, false));

    $this->actingAs(addressSettingsUser())
        ->post(route('settings.address.check'), ['address' => 'Coresmith.Example.com'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(AddressCheck::current())->toMatchArray([
        'host' => 'coresmith.example.com',
        'ok' => false,
        'problems' => ['coresmith.example.com points at 151.226.169.196, but nothing reaches this server on ports 80 and 443 from the internet. Forward TCP ports 80 and 443 on your router to 192.168.0.248, then check again. If your internet provider shares that address with other customers, this server needs an IPv6 address before anyone can reach it.'],
        'notes' => [],
        'records' => [],
    ])
        ->and(AddressCheck::current()['checked_at'] ?? null)->not->toBeNull()
        ->and(AddressChange::current())->toBeNull()
        ->and(file_exists(AddressRequest::file()))->toBeFalse();
});

test('a check that asks for a DNS record keeps the record, for the dialog to show ready to copy', function () {
    addressSettingsResolving([]);
    addressSettingsReach(new DirectReach('151.226.169.196', true, true));

    $this->actingAs(addressSettingsUser())->post(route('settings.address.check'), ['address' => 'coresmith.example.com']);

    expect(AddressCheck::current())->toMatchArray([
        'host' => 'coresmith.example.com',
        'ok' => false,
        'problems' => ["coresmith.example.com doesn't point anywhere yet. Add an A record for coresmith.example.com pointing at 151.226.169.196, then check again."],
        'records' => [['type' => 'A', 'name' => 'coresmith.example.com', 'value' => '151.226.169.196', 'note' => null]],
    ]);
});

test('Coresmith reads its own address every morning, and the settings page carries what it found for that address', function () {
    config(['app.url' => 'https://coresmith.example.com']);
    addressSettingsResolving(['2001:db8::10']);
    app()->instance(StableAddresses::class, new class implements StableAddresses
    {
        public function ipv6(): array
        {
            return ['2001:db8::10'];
        }
    });
    app()->instance(ReachProbe::class, new class implements ReachProbe
    {
        public function direct(): ?DirectReach
        {
            return new DirectReach('151.226.169.196', false, false);
        }

        public function host(string $host, string $from): ?HostReach
        {
            return new HostReach($from, true, true, true);
        }
    });
    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:read-own-address');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('50 5 * * *');

    $event->run(app());

    $this->actingAs(addressSettingsUser())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('address.ipv6_only', true)
            ->where('address.reach.notes', ["coresmith.example.com has only an IPv6 address, so visitors on networks without IPv6 can't open it. The surest fix is to turn on Cloudflare's proxy for coresmith.example.com. Cloudflare answers those visitors over IPv4 and reaches this server over IPv6, which already works, so your router needs no changes. That needs coresmith.example.com's DNS on Cloudflare. Nothing on the internet reaches this server's IPv4 address, 151.226.169.196, on ports 80 and 443, so add an A record for it only if that address is yours and you can forward ports 80 and 443 to 192.168.0.248."])
            ->where('address.reach.records', [
                ['type' => 'AAAA', 'name' => 'coresmith.example.com', 'value' => '2001:db8::10', 'note' => "Best fix, with Cloudflare's proxy on"],
                ['type' => 'A', 'name' => 'coresmith.example.com', 'value' => '151.226.169.196', 'note' => 'Only if the address is yours and you can forward ports 80 and 443'],
            ]));

    config(['app.url' => 'https://coresmith.example.org']);

    $this->actingAs(addressSettingsUser())
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('address.reach', null));
});

test('a domain that passes the check is kept as ready, with its notes', function () {
    addressSettingsResolving(['151.226.169.196']);
    addressSettingsReach(new DirectReach('151.226.169.196', true, true));

    $this->actingAs(addressSettingsUser())->post(route('settings.address.check'), ['address' => 'coresmith.example.com']);

    expect(AddressCheck::current())->toMatchArray([
        'host' => 'coresmith.example.com',
        'ok' => true,
        'problems' => [],
        'notes' => ["Inside your own network, your router may not loop back to 151.226.169.196. If coresmith.example.com doesn't load there, add 192.168.0.248 coresmith.example.com to your local DNS or /etc/hosts."],
    ]);
});

test('an address the form would refuse is kept as its one problem', function () {
    $this->actingAs(addressSettingsUser())->post(route('settings.address.check'), ['address' => 'not a domain']);

    expect(AddressCheck::current())->toMatchArray(['host' => 'not a domain', 'ok' => false, 'problems' => ["That doesn't look like a domain. Try coresmith.example.com."]]);
});

test('the settings page carries the last check, for the dialog to show', function () {
    addressSettingsResolving(['151.226.169.196']);
    addressSettingsReach(new DirectReach('151.226.169.196', true, true));
    $user = addressSettingsUser();
    $this->actingAs($user)->post(route('settings.address.check'), ['address' => 'coresmith.example.com']);

    $this->actingAs($user)
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('address.check.host', 'coresmith.example.com')
            ->where('address.check.ok', true));
});

test('only an owner of a team here may check an address', function () {
    $this->actingAs(addressSettingsUser(TeamRole::Admin))
        ->post(route('settings.address.check'), ['address' => 'coresmith.example.com'])
        ->assertForbidden();

    expect(AddressCheck::current())->toBeNull();
});
