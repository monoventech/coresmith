<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\InstanceSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Address\CloudflareConnection;
use App\Services\Network\DirectReach;
use App\Services\Network\HostReach;
use App\Services\Network\ReachProbe;
use App\Services\Network\StableAddresses;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

const CLOUDFLARE_SETTINGS_API = 'https://api.cloudflare.test/client/v4';

function cloudflareSettingsUser(TeamRole $role = TeamRole::Owner): User
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function cloudflareSettingsMachine(?DirectReach $direct, array $ipv6): void
{
    app()->instance(StableAddresses::class, new class($ipv6) implements StableAddresses
    {
        public function __construct(private readonly array $addresses) {}

        public function ipv6(): array
        {
            return $this->addresses;
        }
    });

    app()->instance(ReachProbe::class, new class($direct) implements ReachProbe
    {
        public function __construct(private readonly ?DirectReach $reach) {}

        public function direct(): ?DirectReach
        {
            return $this->reach;
        }

        public function host(string $host, string $from): ?HostReach
        {
            return null;
        }
    });
}

function cloudflareSettingsZone(array $records = []): void
{
    Http::fake([
        CLOUDFLARE_SETTINGS_API . '/user/tokens/verify' => Http::response(['success' => true, 'errors' => [], 'result' => ['status' => 'active']]),
        CLOUDFLARE_SETTINGS_API . '/zones?per_page=1' => Http::response(['success' => true, 'errors' => [], 'result' => [['id' => 'z1', 'name' => 'coresmith.io']]]),
        CLOUDFLARE_SETTINGS_API . '/zones?name=beelink.coresmith.io' => Http::response(['success' => true, 'errors' => [], 'result' => []]),
        CLOUDFLARE_SETTINGS_API . '/zones?name=coresmith.io' => Http::response(['success' => true, 'errors' => [], 'result' => [['id' => 'z1', 'name' => 'coresmith.io']]]),
        CLOUDFLARE_SETTINGS_API . '/zones?name=*' => Http::response(['success' => true, 'errors' => [], 'result' => []]),
        CLOUDFLARE_SETTINGS_API . '/zones/z1/dns_records?*' => Http::response(['success' => true, 'errors' => [], 'result' => $records]),
        CLOUDFLARE_SETTINGS_API . '/zones/z1/dns_records' => Http::response(['success' => true, 'errors' => [], 'result' => ['id' => 'new']]),
    ]);
}

beforeEach(fn() => config(['coresmith.address.cloudflare_api' => CLOUDFLARE_SETTINGS_API, 'app.url' => 'https://192.168.0.248', 'coresmith.address.site' => '/etc/caddy/coresmith/panel.caddy']));

test('an owner connects a token Cloudflare accepts, kept encrypted and never shown again', function () {
    cloudflareSettingsZone();
    $user = cloudflareSettingsUser();

    $this->actingAs($user)
        ->post(route('settings.address.cloudflare.store'), ['token' => 'cf-secret-token'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Cloudflare is connected. Coresmith keeps its address pointed at this server.');

    expect(CloudflareConnection::token())->toBe('cf-secret-token')
        ->and((string) InstanceSetting::get(CloudflareConnection::SETTING))->not->toContain('cf-secret-token');

    $this->actingAs($user)
        ->get(route('settings.general'))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('address.cloudflare.connected', true))
        ->assertDontSee('cf-secret-token');
});

test('a token Cloudflare refuses is refused next to the field, and nothing is kept', function () {
    Http::fake([CLOUDFLARE_SETTINGS_API . '/user/tokens/verify' => Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]], 401)]);

    $this->actingAs(cloudflareSettingsUser())
        ->post(route('settings.address.cloudflare.store'), ['token' => 'not-a-token'])
        ->assertSessionHasErrors(['token' => "Cloudflare doesn't accept that token. Create one in Cloudflare under My Profile, API Tokens, from the Edit zone DNS template, then paste it here."]);

    expect(CloudflareConnection::token())->toBeNull();
});

test('disconnecting forgets the token', function () {
    CloudflareConnection::connect('cf-secret-token');

    $this->actingAs(cloudflareSettingsUser())->delete(route('settings.address.cloudflare.destroy'))->assertRedirect();

    expect(CloudflareConnection::token())->toBeNull();
});

test("pointing a domain makes its A record say this server when the server's IPv4 answers, and leaves AAAA alone", function () {
    cloudflareSettingsZone();
    cloudflareSettingsMachine(new DirectReach('203.0.113.5', true, true), ['2a02:c7c:c0e:b500::248']);
    CloudflareConnection::connect('cf-secret-token');

    $this->actingAs(cloudflareSettingsUser())
        ->post(route('settings.address.point'), ['address' => 'Beelink.Coresmith.io'])
        ->assertSessionHas('success', 'Cloudflare now points beelink.coresmith.io at this server. Check again in a minute.');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && ($request->data()['type'] ?? null) === 'A' && $request->data()['content'] === '203.0.113.5');
    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), 'type=AAAA'));

    expect(CloudflareConnection::status())->toMatchArray(['host' => 'beelink.coresmith.io', 'error' => null]);
});

test('a server whose IPv4 nobody reaches is pointed at over IPv6, and its A records are left alone', function () {
    cloudflareSettingsZone();
    cloudflareSettingsMachine(new DirectReach('151.226.169.196', false, false), ['2a02:c7c:c0e:b500::248']);
    CloudflareConnection::connect('cf-secret-token');

    $this->actingAs(cloudflareSettingsUser())->post(route('settings.address.point'), ['address' => 'beelink.coresmith.io']);

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && ($request->data()['type'] ?? null) === 'AAAA' && $request->data()['content'] === '2a02:c7c:c0e:b500::248');
    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), 'type=A&'));
});

test("a domain outside the token's zones, or a server nothing reaches, changes nothing and says why", function (?DirectReach $direct, array $ipv6, string $address, string $error) {
    cloudflareSettingsZone();
    cloudflareSettingsMachine($direct, $ipv6);
    CloudflareConnection::connect('cf-secret-token');

    $this->actingAs(cloudflareSettingsUser())
        ->post(route('settings.address.point'), ['address' => $address])
        ->assertSessionHas('error', $error);

    Http::assertNotSent(fn(Request $request): bool => in_array($request->method(), ['POST', 'PATCH', 'DELETE'], true));
})->with([
    'another zone' => [new DirectReach('203.0.113.5', true, true), [], 'shop.example.com', 'None of the zones that Cloudflare token can see holds shop.example.com.'],
    'nothing reaches it' => [new DirectReach('151.226.169.196', false, false), [], 'beelink.coresmith.io', 'Coresmith found no address of this server that the internet reaches, so it changed no records.'],
]);

test('only an owner of a team here may connect Cloudflare or point a domain', function () {
    $admin = cloudflareSettingsUser(TeamRole::Admin);

    $this->actingAs($admin)->post(route('settings.address.cloudflare.store'), ['token' => 'cf-secret-token'])->assertForbidden();
    $this->actingAs($admin)->post(route('settings.address.point'), ['address' => 'beelink.coresmith.io'])->assertForbidden();
});

test('every ten minutes Coresmith points its own domain again, so a changed address is followed', function () {
    cloudflareSettingsZone();
    cloudflareSettingsMachine(new DirectReach('203.0.113.5', true, true), []);
    CloudflareConnection::connect('cf-secret-token');
    config(['app.url' => 'https://beelink.coresmith.io']);

    $event = collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:point-address');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *');

    $event->run(app());

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && ($request->data()['name'] ?? null) === 'beelink.coresmith.io');
});

test('the schedule leaves DNS alone without a token, or while Coresmith answers on an IP address', function () {
    cloudflareSettingsZone();
    cloudflareSettingsMachine(new DirectReach('203.0.113.5', true, true), []);

    collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:point-address')->run(app());
    CloudflareConnection::connect('cf-secret-token');
    collect(app(Schedule::class)->events())->first(fn($event): bool => $event->description === 'coresmith:point-address')->run(app());

    Http::assertNothingSent();
});
