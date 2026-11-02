<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Dns\DnsResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CloudBilling;
use Tests\Support\CloudFixtures;
use Tests\Support\FakeCloudApiFactory;
use Tests\Support\FakeStripe;

function planLimitsOwner(Team $team): User
{
    return User::query()->findOrFail($team->owner_id);
}

function planLimitsServer(Team $team, string $name = 'web-new'): array
{
    return ['team_id' => $team->id, 'name' => $name, 'ip_address' => '203.0.113.' . random_int(10, 250), 'ssh_port' => 22, 'ssh_user' => 'root'];
}

function planLimitsServers(Team $team, int $count): void
{
    Server::factory()->for($team)->count($count)->create(['status' => ServerStatus::Active]);
}

function planLimitsDns(): void
{
    app()->instance(DnsResolver::class, new class implements DnsResolver
    {
        public function txt(string $name): array
        {
            return [];
        }
    });
}

beforeEach(function () {
    CloudBilling::enable();
    Queue::fake();
    FakeStripe::fake([FakeStripe::url('subscriptions/*') => fn($request) => Http::response(FakeStripe::subscription('sub_live', 'cus_live', 'price_test_pro'))]);
});

test('a trial can add one server', function () {
    $team = Team::factory()->onTrial()->owned()->create();
    $this->actingAs(planLimitsOwner($team));

    $this->post(route('servers.store'), planLimitsServer($team, 'web-1'))->assertSessionHasNoErrors()->assertSessionMissing('error');
    $this->post(route('servers.store'), planLimitsServer($team, 'web-2'))->assertSessionHas('error', 'The trial includes 1 server. Choose a plan to add more.');

    expect($team->servers()->count())->toBe(1);
});

test('starter stops at three servers, and detached servers do not count', function () {
    $team = Team::factory()->subscribed(Plan::Starter)->owned()->create();
    planLimitsServers($team, 3);
    $this->actingAs(planLimitsOwner($team));

    $this->post(route('servers.store'), planLimitsServer($team))->assertSessionHas('error', 'Starter includes 3 servers and this team has 3. Choose Pro or Business for unlimited servers.');

    expect($team->servers()->count())->toBe(3);

    $team->servers()->first()->delete();

    $this->post(route('servers.store'), planLimitsServer($team))->assertSessionMissing('error');

    expect($team->servers()->count())->toBe(3);
});

test('pro has no server limit', function () {
    $team = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    planLimitsServers($team, 10);

    $this->actingAs(planLimitsOwner($team))->post(route('servers.store'), planLimitsServer($team))->assertSessionMissing('error');

    expect($team->servers()->count())->toBe(11);
});

test('the server limit belongs to the team the form names', function () {
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create();
    $owner = planLimitsOwner($pro);
    $starter = Team::factory()->subscribed(Plan::Starter)->create(['owner_id' => $owner->id]);
    $starter->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    planLimitsServers($starter, 3);
    $lapsed = Team::factory()->trialEnded()->create(['owner_id' => $owner->id]);
    $lapsed->members()->attach($owner, ['role' => TeamRole::Owner->value]);
    $this->actingAs($owner->fresh());

    $this->post(route('servers.store'), planLimitsServer($starter))->assertSessionHas('error', 'Starter includes 3 servers and this team has 3. Choose Pro or Business for unlimited servers.');
    $this->post(route('servers.store'), planLimitsServer($lapsed))->assertSessionHas('error', "This team's trial has ended, so it is read-only. Servers and apps keep running. Choose a plan in Billing settings to make changes again.");
    $this->post(route('servers.store'), planLimitsServer($pro))->assertSessionMissing('error');

    expect($starter->servers()->count())->toBe(3)
        ->and($lapsed->servers()->count())->toBe(0)
        ->and($pro->servers()->count())->toBe(1);
});

test('a provider server counts against the limit', function () {
    $team = Team::factory()->subscribed(Plan::Starter)->owned()->create();
    planLimitsServers($team, 3);
    $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner Cloud', 'api_token' => 'tok-1']);
    $fake = new FakeCloudApiFactory;
    CloudFixtures::catalogue($fake->api);
    app()->instance(CloudApiFactory::class, $fake);

    $this->actingAs(planLimitsOwner($team))
        ->post(route('servers.cloud.store'), ['cloud_provider_id' => $account->id, 'name' => 'outpost-02', 'region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04', 'environment' => 'Production'])
        ->assertSessionHas('error', 'Starter includes 3 servers and this team has 3. Choose Pro or Business for unlimited servers.');

    expect($team->servers()->count())->toBe(3)
        ->and($fake->api->calls('create'))->toBe([]);
});

test('starter is for one person', function () {
    $starter = Team::factory()->subscribed(Plan::Starter)->owned()->create();
    $newcomer = User::factory()->create();

    $this->actingAs(planLimitsOwner($starter))
        ->post(route('teams.members.store', $starter), ['email' => $newcomer->email, 'role' => TeamRole::Member->value])
        ->assertSessionHas('error', 'Starter is for one person. Choose Pro or Business to add teammates.');

    expect($starter->members()->count())->toBe(1);

    foreach ([Team::factory()->subscribed(Plan::Pro)->owned()->create(), Team::factory()->onTrial()->owned()->create()] as $team) {
        $this->actingAs(planLimitsOwner($team))
            ->post(route('teams.members.store', $team), ['email' => $newcomer->email, 'role' => TeamRole::Member->value])
            ->assertSessionMissing('error')
            ->assertSessionHas('success', "Invitation sent to {$newcomer->email}.");

        expect($team->invitations()->pluck('email')->all())->toBe([$newcomer->email]);
    }
});

test('custom domains need pro or business', function () {
    planLimitsDns();
    $starter = Team::factory()->subscribed(Plan::Starter)->owned()->create(['slug' => 'starter']);
    $this->actingAs(planLimitsOwner($starter));

    $this->post('/starter' . route('settings.team-domain.store', [], false), ['hostname' => 'panel.starter.test'])
        ->assertSessionHas('error', 'Custom domains are part of Pro and Business. Change the plan in Billing settings.');
    $this->post('/starter' . route('settings.team-domain.verify', [], false))
        ->assertSessionHas('error', 'Custom domains are part of Pro and Business. Change the plan in Billing settings.');

    expect($starter->domain()->exists())->toBeFalse();

    foreach (['pro' => Team::factory()->subscribed(Plan::Pro), 'trying' => Team::factory()->onTrial()] as $slug => $factory) {
        $team = $factory->owned()->create(['slug' => $slug]);

        $this->actingAs(planLimitsOwner($team))
            ->post("/{$slug}" . route('settings.team-domain.store', [], false), ['hostname' => "panel.{$slug}.test"])
            ->assertSessionMissing('error');

        expect($team->domain()->exists())->toBeTrue();
    }
});

test('single sign-on needs business', function () {
    Http::fake();
    $pro = Team::factory()->subscribed(Plan::Pro)->owned()->create(['slug' => 'pro']);
    TeamSsoConnection::factory()->for($pro)->create(['enabled' => true]);
    $this->actingAs(planLimitsOwner($pro));
    $refused = 'Single sign-on is part of Business. Change the plan in Billing settings.';

    $this->put('/pro' . route('settings.sso.update', [], false), ['issuer' => 'https://idp.example.test', 'client_id' => 'c', 'client_secret' => 's', 'email_domain' => 'pro.test'])->assertSessionHas('error', $refused);
    $this->post('/pro' . route('settings.sso.verify', [], false))->assertSessionHas('error', $refused);
    $this->put('/pro' . route('settings.sso.provisioning', [], false), ['jit_provisioning' => true])->assertSessionHas('error', $refused);
    $this->put('/pro' . route('settings.sso.enabled', [], false), ['enabled' => true])->assertSessionHas('error', $refused);

    $this->put('/pro' . route('settings.sso.enabled', [], false), ['enabled' => false])->assertSessionHas('success', 'Single sign-on is off.');

    $business = Team::factory()->subscribed(Plan::Business)->owned()->create(['slug' => 'business']);

    $this->actingAs(planLimitsOwner($business))
        ->put('/business' . route('settings.sso.update', [], false), ['issuer' => 'https://idp.example.test', 'client_id' => 'c', 'client_secret' => 's', 'email_domain' => 'business.test'])
        ->assertSessionMissing('error');
});

test('self-hosted has no limits', function () {
    config(['coresmith.edition' => 'self-hosted']);
    planLimitsDns();
    $team = Team::factory()->owned()->create(['slug' => 'plain']);
    planLimitsServers($team, 5);
    $owner = planLimitsOwner($team);
    $this->actingAs($owner);

    $this->post(route('servers.store'), planLimitsServer($team))->assertSessionMissing('error');
    $this->post(route('teams.members.store', $team), ['email' => User::factory()->create()->email, 'role' => TeamRole::Member->value])->assertSessionMissing('error');
    $this->post('/plain' . route('settings.team-domain.store', [], false), ['hostname' => 'panel.plain.test'])->assertSessionMissing('error');

    expect($team->servers()->count())->toBe(6)
        ->and($team->members()->count())->toBe(2)
        ->and($team->domain()->exists())->toBeTrue();
});

test('the create page explains a full team before anyone fills the form', function () {
    $starter = Team::factory()->subscribed(Plan::Starter)->owned()->create(['name' => 'Solo Shop']);
    planLimitsServers($starter, 3);
    $owner = planLimitsOwner($starter);
    $pro = Team::factory()->subscribed(Plan::Pro)->create(['name' => 'Big Co', 'owner_id' => $owner->id]);
    $pro->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner->fresh())->get(route('servers.create'))->assertInertia(fn($page) => $page
        ->where('current', $starter->id)
        ->where('teams', fn($teams) => collect($teams)->firstWhere('id', $starter->id)['limit'] === 'Starter includes 3 servers and this team has 3. Choose Pro or Business for unlimited servers.'
            && collect($teams)->firstWhere('id', $pro->id)['limit'] === null));
});
