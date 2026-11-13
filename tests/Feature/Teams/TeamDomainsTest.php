<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\User;
use App\Services\Dns\DnsResolver;

function teamDomainsOwner(TeamRole $role = TeamRole::Owner): array
{
    $team = Team::factory()->create(['name' => 'Acme Labs', 'slug' => 'acme']);
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return [$user->fresh(), $team];
}

function teamDomainsDns(array $records): void
{
    app()->instance(DnsResolver::class, new class($records) implements DnsResolver
    {
        public function __construct(private array $records) {}

        public function txt(string $name): array
        {
            return $this->records[$name] ?? [];
        }
    });
}

test('an owner adds a custom domain and is shown the records to create', function () {
    [$user, $team] = teamDomainsOwner();

    $this->actingAs($user)
        ->post(route('settings.team-domain.store'), ['hostname' => ' Panel.Acme.Test. '])
        ->assertRedirect()
        ->assertSessionHas('success', 'Saved. Add the two DNS records below, then check them.');

    $domain = $team->fresh()->domain;

    expect($domain->hostname)->toBe('panel.acme.test')
        ->and($domain->verified_at)->toBeNull()
        ->and($domain->verification_token)->toHaveLength(32);

    $this->actingAs($user)
        ->get(route('teams.index'))
        ->assertInertia(fn($page) => $page
            ->component('settings/Team')
            ->where('address.domain.hostname', 'panel.acme.test')
            ->where('address.domain.verified', false)
            ->where('address.domain.records.0', ['type' => 'CNAME', 'name' => 'panel.acme.test', 'value' => 'localhost'])
            ->where('address.domain.records.1', ['type' => 'TXT', 'name' => '_coresmith-challenge.panel.acme.test', 'value' => 'coresmith-verification=' . $domain->verification_token]));
});

test('a domain that is not a domain, the panel itself or already taken is refused', function (string $hostname, string $message) {
    [$user] = teamDomainsOwner();
    TeamDomain::factory()->for(Team::factory())->create(['hostname' => 'taken.example.com']);

    $this->actingAs($user)
        ->post(route('settings.team-domain.store'), ['hostname' => $hostname])
        ->assertSessionHasErrors(['hostname' => $message]);
})->with([
    'not a domain' => ['not a domain', "That doesn't look like a domain. Try deploy.example.com."],
    'a wildcard' => ['*.example.com', "That doesn't look like a domain. Try deploy.example.com."],
    'a bare word' => ['localhost', 'That is the address of Coresmith itself.'],
    'another team' => ['taken.example.com', 'Another team already uses that domain.'],
]);

test('a domain under the tenant domain is refused because the team already has one there', function () {
    config(['coresmith.tenant_domain' => 'coresmith.test']);
    [$user] = teamDomainsOwner();

    $this->actingAs($user)
        ->post(route('settings.team-domain.store'), ['hostname' => 'acme.coresmith.test'])
        ->assertSessionHasErrors(['hostname' => 'Addresses under coresmith.test already come with your team address.']);
});

test('changing the domain issues a new token and forgets the old verification', function () {
    [$user, $team] = teamDomainsOwner();
    $old = TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'old.acme.test']);

    $this->actingAs($user)->post(route('settings.team-domain.store'), ['hostname' => 'new.acme.test'])->assertRedirect();

    $domain = $team->fresh()->domain;

    expect($domain->hostname)->toBe('new.acme.test')
        ->and($domain->verified_at)->toBeNull()
        ->and($domain->verification_token)->not->toBe($old->verification_token);
});

test('checking dns verifies the domain once the txt record matches', function () {
    [$user, $team] = teamDomainsOwner();
    $domain = TeamDomain::factory()->for($team)->create(['hostname' => 'panel.acme.test', 'verification_token' => 'abc123']);
    teamDomainsDns(['_coresmith-challenge.panel.acme.test' => ['unrelated', ' coresmith-verification=abc123 ']]);

    $this->actingAs($user)
        ->post(route('settings.team-domain.verify'))
        ->assertSessionHas('success', 'Verified. panel.acme.test now opens Acme Labs.');

    expect($domain->fresh()->verified_at)->not->toBeNull()
        ->and($domain->fresh()->failure)->toBeNull();
});

test('checking dns says what is wrong when the record is missing or different', function (array $records, string $failure, string $message) {
    [$user, $team] = teamDomainsOwner();
    $domain = TeamDomain::factory()->for($team)->create(['hostname' => 'panel.acme.test', 'verification_token' => 'abc123']);
    teamDomainsDns(['_coresmith-challenge.panel.acme.test' => $records]);

    $this->actingAs($user)
        ->post(route('settings.team-domain.verify'))
        ->assertSessionHas('error', $message);

    expect($domain->fresh()->verified_at)->toBeNull()
        ->and($domain->fresh()->failure)->toBe($failure)
        ->and($domain->fresh()->checked_at)->not->toBeNull();
})->with([
    'missing' => [[], 'no_record', 'The TXT record is not visible yet. DNS can take a few minutes, so check again shortly.'],
    'different' => [['coresmith-verification=other'], 'wrong_value', 'The TXT record is there but its value does not match. Copy it again from this page.'],
]);

test('a verified domain loses its verification when a later check fails', function () {
    [$user, $team] = teamDomainsOwner();
    $domain = TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'panel.acme.test']);
    teamDomainsDns([]);

    $this->actingAs($user)->post(route('settings.team-domain.verify'))->assertSessionHas('error');

    expect($domain->fresh()->verified_at)->toBeNull();
});

test('removing the domain stops it opening the team', function () {
    [$user, $team] = teamDomainsOwner();
    TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'panel.acme.test']);

    $this->actingAs($user)
        ->delete(route('settings.team-domain.destroy'))
        ->assertSessionHas('success', 'Removed. panel.acme.test no longer opens this team.');

    expect($team->fresh()->domain)->toBeNull();

    $this->actingAs($user)->get('http://panel.acme.test/dashboard')->assertInertia(fn($page) => $page->where('panel.viaHost', false));
});

test('a deployer can see the domain but not change it', function () {
    [$user, $team] = teamDomainsOwner(TeamRole::Member);
    TeamDomain::factory()->for($team)->create(['hostname' => 'panel.acme.test']);

    $this->actingAs($user)->get(route('teams.index'))->assertInertia(fn($page) => $page->where('can.update', false)->where('address.domain.hostname', 'panel.acme.test'));
    $this->actingAs($user)->post(route('settings.team-domain.store'), ['hostname' => 'x.acme.test'])->assertForbidden();
    $this->actingAs($user)->post(route('settings.team-domain.verify'))->assertForbidden();
    $this->actingAs($user)->delete(route('settings.team-domain.destroy'))->assertForbidden();
});

test('checking or removing when there is no domain is not found', function () {
    [$user] = teamDomainsOwner();

    $this->actingAs($user)->post(route('settings.team-domain.verify'))->assertNotFound();
    $this->actingAs($user)->delete(route('settings.team-domain.destroy'))->assertNotFound();
});

test('the team page shows the team address, and the subdomain when the instance has a tenant domain', function () {
    [$user] = teamDomainsOwner();

    $this->actingAs($user)
        ->get('/acme/settings/team')
        ->assertInertia(fn($page) => $page
            ->where('team.slug', 'acme')
            ->where('address.path', 'http://localhost/acme')
            ->where('address.subdomain', null)
            ->where('address.domain', null)
            ->where('can.update', true));

    config(['coresmith.tenant_domain' => 'coresmith.test']);

    $this->actingAs($user)
        ->get('http://localhost/acme/settings/team')
        ->assertInertia(fn($page) => $page->where('address.subdomain', 'http://acme.coresmith.test'));
});

test('caddy may issue certificates for the panel, verified domains and team subdomains only', function () {
    config(['coresmith.tenant_domain' => 'coresmith.test']);
    $team = Team::factory()->create(['slug' => 'acme']);
    TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'panel.acme.test']);
    TeamDomain::factory()->for(Team::factory())->create(['hostname' => 'pending.example.com']);

    $this->get(route('caddy.ask', ['domain' => 'localhost']))->assertOk();
    $this->get(route('caddy.ask', ['domain' => 'panel.acme.test']))->assertOk();
    $this->get(route('caddy.ask', ['domain' => 'PANEL.ACME.TEST.']))->assertOk();
    $this->get(route('caddy.ask', ['domain' => 'acme.coresmith.test']))->assertOk();
    $this->get(route('caddy.ask', ['domain' => 'pending.example.com']))->assertNotFound();
    $this->get(route('caddy.ask', ['domain' => 'nobody.coresmith.test']))->assertNotFound();
    $this->get(route('caddy.ask', ['domain' => 'evil.example.com']))->assertNotFound();
    $this->get(route('caddy.ask'))->assertNotFound();
});
