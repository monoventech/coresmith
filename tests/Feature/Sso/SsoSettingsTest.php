<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\SsoIdentity;
use App\Models\TeamDomain;
use App\Models\TeamSsoConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__ . '/SsoHelpers.php';

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    ssoFakeNetwork();
});

function ssoSettingsPayload(array $overrides = []): array
{
    return array_merge([
        'issuer' => 'https://idp.example.test',
        'client_id' => 'coresmith-client',
        'client_secret' => 'top-secret-value',
        'email_domain' => 'example.test',
    ], $overrides);
}

test('an owner saves a connection and its secret is encrypted and never sent to the page', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload(['issuer' => ' HTTPS://IDP.Example.test ', 'email_domain' => '@Example.TEST.']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. Add the TXT record below, then check DNS.');

    $connection = TeamSsoConnection::query()->where('team_id', $team->id)->sole();

    expect($connection->issuer)->toBe('https://idp.example.test')
        ->and($connection->email_domain)->toBe('example.test')
        ->and($connection->client_secret)->toBe('top-secret-value')
        ->and($connection->getRawOriginal('client_secret'))->not->toContain('top-secret-value')
        ->and($connection->enabled)->toBeFalse()
        ->and($connection->jit_provisioning)->toBeFalse()
        ->and($connection->domain_verified_at)->toBeNull();

    $response = $this->actingAs($owner)->get('/acme/settings/team');

    $response->assertOk()->assertInertia(fn($page) => $page
        ->component('settings/Team')
        ->where('sso.can_manage', true)
        ->where('sso.connection.issuer', 'https://idp.example.test')
        ->where('sso.connection.client_id', 'coresmith-client')
        ->where('sso.connection.email_domain', 'example.test')
        ->where('sso.connection.verified', false)
        ->where('sso.connection.enabled', false)
        ->where('sso.connection.record', [
            'type' => 'TXT',
            'name' => '_coresmith-sso.example.test',
            'value' => 'coresmith-sso=' . $connection->domain_token,
        ])
        ->missing('sso.connection.client_secret'));

    expect($response->getContent())->not->toContain('top-secret-value');

    $this->actingAs($owner)
        ->withHeaders(ssoInertiaHeaders())
        ->get('/acme/settings/team')
        ->assertOk()
        ->assertDontSee('top-secret-value', false);

    expect(Activity::query()->where('description', 'sso_configured')->sole()->properties->toArray())
        ->toMatchArray(['issuer' => 'https://idp.example.test', 'email_domain' => 'example.test', 'secret_changed' => true])
        ->not->toHaveKey('client_secret');
});

test('admins and deployers see single sign-on but cannot change it', function (TeamRole $role) {
    $team = ssoTeam();
    $user = ssoMember($team, $role);
    $connection = ssoConnection($team);

    $this->actingAs($user)->put('/acme/settings/team/sso', ssoSettingsPayload(['client_id' => 'changed']))->assertForbidden();
    $this->actingAs($user)->post('/acme/settings/team/sso/verify')->assertForbidden();
    $this->actingAs($user)->put('/acme/settings/team/sso/enabled', ['enabled' => false])->assertForbidden();
    $this->actingAs($user)->put('/acme/settings/team/sso/provisioning', ['jit_provisioning' => true])->assertForbidden();
    $this->actingAs($user)->delete('/acme/settings/team/sso')->assertForbidden();

    expect($connection->fresh())
        ->client_id->toBe('coresmith-client')
        ->enabled->toBeTrue()
        ->jit_provisioning->toBeFalse();

    $this->actingAs($user)
        ->get('/acme/settings/team')
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('sso.can_manage', false)
            ->where('sso.connection.enabled', true)
            ->missing('sso.connection.client_secret'));
})->with([
    'admin' => [TeamRole::Admin],
    'deployer' => [TeamRole::Member],
]);

test('a connection needs every field and a real email domain', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ['issuer' => '', 'client_id' => '', 'client_secret' => '', 'email_domain' => ''])
        ->assertSessionHasErrors([
            'issuer' => 'Enter the issuer URL from your identity provider.',
            'client_id' => 'Enter the client ID from your identity provider.',
            'client_secret' => 'Enter the client secret from your identity provider.',
            'email_domain' => 'Enter the email domain people sign in with, like example.com.',
        ]);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload(['email_domain' => 'not a domain']))
        ->assertSessionHasErrors(['email_domain' => "That doesn't look like an email domain. Try example.com."]);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload(['email_domain' => '*.example.test']))
        ->assertSessionHasErrors(['email_domain' => "That doesn't look like an email domain. Try example.com."]);

    expect(TeamSsoConnection::query()->exists())->toBeFalse();
});

test('an issuer that is not https or reaches a private address is refused without contacting it', function (string $issuer, string $message) {
    ssoFakeNetwork(['inside.example.test' => ['203.0.113.7', '169.254.169.254']]);
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload(['issuer' => $issuer]))
        ->assertSessionHasErrors(['issuer' => $message]);

    expect(TeamSsoConnection::query()->exists())->toBeFalse();

    Http::assertNothingSent();
})->with([
    'plain http' => ['http://idp.example.test', 'Use an https:// address, like https://login.example.com.'],
    'not a url' => ['idp.example.test', 'Use an https:// address, like https://login.example.com.'],
    'loopback' => ['https://127.0.0.1', 'Coresmith will not connect to 127.0.0.1, it points at a private address.'],
    'loopback with a path' => ['https://127.0.0.1:8443/realms/acme', 'Coresmith will not connect to 127.0.0.1, it points at a private address.'],
    'ipv6 loopback' => ['https://[::1]', 'Coresmith will not connect to ::1, it points at a private address.'],
    'ipv4 mapped loopback' => ['https://[::ffff:127.0.0.1]', 'Coresmith will not connect to ::ffff:127.0.0.1, it points at a private address.'],
    'cloud metadata' => ['https://169.254.169.254/latest', 'Coresmith will not connect to 169.254.169.254, it points at a private address.'],
    'unique local ipv6' => ['https://[fd00:ec2::254]', 'Coresmith will not connect to fd00:ec2::254, it points at a private address.'],
    'unspecified address' => ['https://0.0.0.0', 'Coresmith will not connect to 0.0.0.0, it points at a private address.'],
    'localhost' => ['https://localhost', "Coresmith will not connect to localhost. Use your identity provider's public address."],
    'metadata name' => ['https://metadata.google.internal', "Coresmith will not connect to metadata.google.internal. Use your identity provider's public address."],
    'resolves to metadata' => ['https://inside.example.test', 'Coresmith will not connect to inside.example.test, it points at a private address.'],
    'numeric host' => ['https://2130706433', '"2130706433" is not a valid host name.'],
    'credentials' => ['https://user:pass@idp.example.test', 'Remove the username and password from the address.'],
    'query string' => ['https://idp.example.test/?tenant=acme', 'Remove the ? query or # fragment from the issuer URL.'],
]);

test('saving again with a blank secret keeps the saved secret', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $connection = ssoConnection($team);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload(['client_secret' => '']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved.');

    expect($connection->fresh())
        ->client_secret->toBe('client-secret')
        ->enabled->toBeTrue()
        ->domain_verified_at->not->toBeNull();

    expect(Activity::query()->where('description', 'sso_updated')->sole()->properties['secret_changed'])->toBeFalse();
});

test('changing the issuer or client id needs the secret again and turns single sign-on off', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $connection = ssoConnection($team);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload(['issuer' => 'https://login.other.test', 'client_secret' => '']))
        ->assertSessionHasErrors(['client_secret' => 'Enter the client secret again when you change the issuer or client ID.']);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload(['client_id' => 'another-client', 'client_secret' => '']))
        ->assertSessionHasErrors(['client_secret' => 'Enter the client secret again when you change the issuer or client ID.']);

    expect($connection->fresh()->issuer)->toBe('https://idp.example.test');

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload(['issuer' => 'https://login.other.test', 'client_secret' => 'new-secret']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. Single sign-on is off until you turn it on again.');

    expect($connection->fresh())
        ->issuer->toBe('https://login.other.test')
        ->client_secret->toBe('new-secret')
        ->enabled->toBeFalse()
        ->domain_verified_at->not->toBeNull();
});

test('changing the email domain needs a new DNS record', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $connection = ssoConnection($team);
    $token = $connection->domain_token;

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload(['client_secret' => '', 'email_domain' => 'acme.test']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. Add the TXT record below, then check DNS.');

    expect($connection->fresh())
        ->email_domain->toBe('acme.test')
        ->domain_verified_at->toBeNull()
        ->enabled->toBeFalse()
        ->client_secret->toBe('client-secret')
        ->domain_token->not->toBe($token);
});

test('an email domain another team has verified cannot be claimed', function () {
    ssoConnection(ssoTeam('other'), ['email_domain' => 'example.test']);
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso', ssoSettingsPayload())
        ->assertSessionHasErrors(['email_domain' => 'Another team already signs in with that email domain.']);
});

test('checking DNS verifies the email domain once the TXT record matches', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $connection = TeamSsoConnection::factory()->for($team)->create(['email_domain' => 'example.test']);

    ssoFakeNetwork(txt: ['_coresmith-sso.example.test' => ['coresmith-sso=something-else']]);

    $this->actingAs($owner)
        ->post('/acme/settings/team/sso/verify')
        ->assertSessionHas('error', 'The TXT record is there but its value does not match. Copy it again from this page.');

    ssoFakeNetwork();

    $this->actingAs($owner)
        ->post('/acme/settings/team/sso/verify')
        ->assertSessionHas('error', 'The TXT record is not visible yet. DNS can take a few minutes, check again shortly.');

    expect($connection->fresh()->domain_verified_at)->toBeNull();

    ssoFakeNetwork(txt: ['_coresmith-sso.example.test' => ['v=spf1 -all', '"coresmith-sso=' . $connection->domain_token . '"']]);

    $this->actingAs($owner)
        ->post('/acme/settings/team/sso/verify')
        ->assertSessionHas('success', 'Verified. You can turn on single sign-on now.');

    expect($connection->fresh()->domain_verified_at)->not->toBeNull()
        ->and(Activity::query()->where('description', 'sso_domain_verified')->exists())->toBeTrue();

    Http::assertNothingSent();
});

test('checking DNS for a domain another team verified first is refused', function () {
    ssoConnection(ssoTeam('other'), ['email_domain' => 'example.test']);
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $connection = TeamSsoConnection::factory()->for($team)->create(['email_domain' => 'example.test']);

    ssoFakeNetwork(txt: ['_coresmith-sso.example.test' => ['coresmith-sso=' . $connection->domain_token]]);

    $this->actingAs($owner)
        ->post('/acme/settings/team/sso/verify')
        ->assertSessionHas('error', 'Another team already signs in with that email domain. Use a different domain.');

    expect($connection->fresh()->domain_verified_at)->toBeNull();
});

test('when two teams verified the same domain the first one keeps it', function () {
    $first = ssoTeam('first');
    $firstOwner = ssoMember($first, TeamRole::Owner);
    $earlier = TeamSsoConnection::factory()->for($first)->create([
        'issuer' => 'https://idp.example.test',
        'email_domain' => 'example.test',
        'domain_verified_at' => now()->subHour(),
    ]);

    $second = ssoTeam('second');
    $secondOwner = ssoMember($second, TeamRole::Owner);
    $later = TeamSsoConnection::factory()->for($second)->create([
        'issuer' => 'https://idp.example.test',
        'email_domain' => 'example.test',
        'domain_verified_at' => now(),
    ]);

    ssoFakeProvider();

    $this->actingAs($secondOwner)
        ->put('/second/settings/team/sso/enabled', ['enabled' => true])
        ->assertSessionHas('error', 'Another team already signs in with that email domain. Use a different domain.');

    $this->actingAs($firstOwner)
        ->put('/first/settings/team/sso/enabled', ['enabled' => true])
        ->assertSessionHas('success');

    expect($earlier->fresh()->enabled)->toBeTrue()
        ->and($later->fresh()->enabled)->toBeFalse();
});

test('turning on is refused until the email domain is verified', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $connection = TeamSsoConnection::factory()->for($team)->create(['issuer' => 'https://idp.example.test']);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso/enabled', ['enabled' => true])
        ->assertSessionHas('error', 'Verify the email domain first. Add the TXT record, then check DNS.');

    expect($connection->fresh()->enabled)->toBeFalse();

    Http::assertNothingSent();
});

test('turning on is refused when the provider configuration does not check out', function (Closure $fake, string $message) {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $connection = TeamSsoConnection::factory()->for($team)->verified()->create(['issuer' => 'https://idp.example.test']);

    $fake();

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso/enabled', ['enabled' => true])
        ->assertSessionHas('error', 'Single sign-on is still off. ' . $message);

    expect($connection->fresh()->enabled)->toBeFalse();
})->with([
    'no configuration' => [
        fn() => Http::fake(['https://idp.example.test/.well-known/openid-configuration' => Http::response('Not found', 404)]),
        'There is no OpenID configuration at that issuer. Check the issuer URL, then try again.',
    ],
    'a redirect' => [
        fn() => Http::fake(['https://idp.example.test/.well-known/openid-configuration' => Http::response('', 302, ['Location' => 'https://169.254.169.254/'])]),
        'There is no OpenID configuration at that issuer. Check the issuer URL, then try again.',
    ],
    'another issuer' => [
        fn() => ssoFakeProvider(['issuer' => 'https://evil.example.test']),
        'The OpenID configuration belongs to a different issuer. It names https://evil.example.test, use that exact URL.',
    ],
    'an http token endpoint' => [
        fn() => ssoFakeProvider(['token_endpoint' => 'http://idp.example.test/token']),
        'The OpenID configuration lists a token_endpoint Coresmith will not use. Use an https:// address, like https://login.example.com.',
    ],
    'keys on a private address' => [
        fn() => ssoFakeProvider(['jwks_uri' => 'https://169.254.169.254/keys']),
        'The OpenID configuration lists a jwks_uri Coresmith will not use. Coresmith will not connect to 169.254.169.254, it points at a private address.',
    ],
    'no authorization endpoint' => [
        fn() => ssoFakeProvider(['authorization_endpoint' => null]),
        "The OpenID configuration has no authorization_endpoint. Check your identity provider's settings.",
    ],
]);

test('turning on checks the provider once and turning off needs no provider', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $connection = TeamSsoConnection::factory()->for($team)->verified()->create([
        'issuer' => 'https://idp.example.test',
        'email_domain' => 'example.test',
    ]);

    ssoFakeProvider();

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso/enabled', ['enabled' => true])
        ->assertSessionHas('success', 'Single sign-on is on. People at example.test can use it to sign in.');

    expect($connection->fresh()->enabled)->toBeTrue()
        ->and(Activity::query()->where('description', 'sso_enabled')->exists())->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn($request) => $request->url() === 'https://idp.example.test/.well-known/openid-configuration');

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso/enabled', ['enabled' => false])
        ->assertSessionHas('success', 'Single sign-on is off.');

    expect($connection->fresh()->enabled)->toBeFalse()
        ->and(Activity::query()->where('description', 'sso_disabled')->exists())->toBeTrue();

    Http::assertSentCount(1);
});

test('an owner decides whether signing in creates accounts', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $connection = ssoConnection($team);

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso/provisioning', ['jit_provisioning' => true])
        ->assertSessionHas('success', 'Saved.');

    expect($connection->fresh()->jit_provisioning)->toBeTrue()
        ->and(Activity::query()->where('description', 'sso_provisioning_enabled')->exists())->toBeTrue();

    $this->actingAs($owner)
        ->get('/acme/settings/team')
        ->assertInertia(fn($page) => $page->where('sso.connection.jit_provisioning', true));

    $this->actingAs($owner)->put('/acme/settings/team/sso/provisioning', ['jit_provisioning' => false]);

    expect($connection->fresh()->jit_provisioning)->toBeFalse()
        ->and(Activity::query()->where('description', 'sso_provisioning_disabled')->exists())->toBeTrue();

    $this->actingAs($owner)
        ->put('/acme/settings/team/sso/provisioning', ['jit_provisioning' => 'sometimes'])
        ->assertSessionHasErrors('jit_provisioning');
});

test('removing single sign-on deletes the connection and the sign-ins linked through it', function () {
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    $member = ssoMember($team);
    ssoConnection($team);
    SsoIdentity::factory()->create(['user_id' => $member->id, 'team_id' => $team->id]);

    $other = ssoTeam('other');
    ssoConnection($other, ['email_domain' => 'other.test']);
    $kept = SsoIdentity::factory()->create(['team_id' => $other->id]);

    $this->actingAs($owner)
        ->delete('/acme/settings/team/sso')
        ->assertSessionHas('success', 'Removed. People sign in with their Coresmith password again.');

    expect(TeamSsoConnection::query()->where('team_id', $team->id)->exists())->toBeFalse()
        ->and(SsoIdentity::query()->where('team_id', $team->id)->exists())->toBeFalse()
        ->and($kept->fresh())->not->toBeNull()
        ->and(User::query()->whereKey($member->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'sso_removed')->exists())->toBeTrue();

    $this->actingAs($owner)->delete('/acme/settings/team/sso')->assertNotFound();
});

test('the team page lists a redirect uri for every address the team opens on', function () {
    config(['coresmith.tenant_domain' => 'coresmith.test']);
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'panel.acme.test']);

    $this->actingAs($owner)
        ->get('/acme/settings/team')
        ->assertInertia(fn($page) => $page
            ->where('sso.connection', null)
            ->where('sso.redirect_uris', [
                'http://localhost/sso/callback',
                'https://panel.acme.test/sso/callback',
                'http://acme.coresmith.test/sso/callback',
            ]));
});

test('an unverified custom domain and a missing tenant domain add no redirect uri', function () {
    config(['coresmith.tenant_domain' => null]);
    $team = ssoTeam();
    $owner = ssoMember($team, TeamRole::Owner);
    TeamDomain::factory()->for($team)->create(['hostname' => 'panel.acme.test']);

    $this->actingAs($owner)
        ->get('/acme/settings/team')
        ->assertInertia(fn($page) => $page->where('sso.redirect_uris', ['http://localhost/sso/callback']));
});
