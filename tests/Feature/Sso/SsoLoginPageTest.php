<?php

declare(strict_types=1);

use App\Models\TeamSsoConnection;
use Illuminate\Support\Facades\Http;

require_once __DIR__ . '/SsoHelpers.php';

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    ssoFakeNetwork();
});

test('a team address with single sign-on offers it on the sign-in page', function () {
    ssoConnection(ssoTeam());

    $this->get('/acme/login')
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('auth/Login')
            ->where('sso.team', 'Acme Labs')
            ->where('sso.start', 'http://localhost/sso/acme/start')
            ->where('sso.lookup', false));

    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('sso.team', null)
            ->where('sso.start', null)
            ->where('sso.lookup', true));
});

test('a team host with single sign-on starts on that host', function () {
    config(['coresmith.tenant_domain' => 'coresmith.test']);
    ssoConnection(ssoTeam());

    $this->get('http://acme.coresmith.test/login')
        ->assertOk()
        ->assertInertia(fn($page) => $page->where('sso.start', 'http://acme.coresmith.test/sso/acme/start'));
});

test('the sign-in page offers no single sign-on when no team has it turned on', function () {
    TeamSsoConnection::factory()->for(ssoTeam())->verified()->create();
    TeamSsoConnection::factory()->for(ssoTeam('unverified'))->create(['enabled' => true]);

    $this->get('/acme/login')
        ->assertInertia(fn($page) => $page->where('sso.start', null)->where('sso.lookup', false));

    $this->get('/login')
        ->assertInertia(fn($page) => $page->where('sso.start', null)->where('sso.lookup', false));
});

test('a team without single sign-on still links to the lookup when another team has it', function () {
    ssoTeam();
    ssoConnection(ssoTeam('other'), ['email_domain' => 'other.test']);

    $this->get('/acme/login')
        ->assertInertia(fn($page) => $page->where('sso.start', null)->where('sso.lookup', true));
});

test('the single sign-on page asks for a work email', function () {
    $this->get('/sso')->assertOk()->assertInertia(fn($page) => $page->component('auth/SingleSignOn'));
});

test('a work email at a domain with single sign-on goes to that team', function () {
    ssoConnection(ssoTeam());

    $this->post('/sso', ['email' => ' Ada@Example.TEST '])->assertRedirect('http://localhost/sso/acme/start');

    $this->withHeaders(ssoInertiaHeaders())
        ->post('/sso', ['email' => 'ada@example.test'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'http://localhost/sso/acme/start');

    Http::assertNothingSent();
});

test('an email domain without single sign-on points back to password sign-in', function (string $email) {
    TeamSsoConnection::factory()->for(ssoTeam())->verified()->create(['email_domain' => 'off.test']);
    TeamSsoConnection::factory()->for(ssoTeam('unverified'))->create(['email_domain' => 'unverified.test', 'enabled' => true]);
    $deleted = ssoTeam('deleted');
    ssoConnection($deleted, ['email_domain' => 'deleted.test']);
    $deleted->delete();

    $this->from('/sso')
        ->post('/sso', ['email' => $email])
        ->assertRedirect('/sso')
        ->assertSessionHasErrors(['email' => 'No team signs in with single sign-on for that email domain. Sign in with your password instead.']);
})->with([
    'turned off' => ['ada@off.test'],
    'unverified domain' => ['ada@unverified.test'],
    'deleted team' => ['ada@deleted.test'],
    'unknown domain' => ['ada@nowhere.test'],
    'a subdomain' => ['ada@mail.off.test'],
]);

test('the lookup needs a real email address', function () {
    $this->from('/sso')
        ->post('/sso', ['email' => 'not-an-email'])
        ->assertSessionHasErrors(['email' => 'Enter a full email address, like you@example.com.']);

    $this->from('/sso')
        ->post('/sso', ['email' => ''])
        ->assertSessionHasErrors(['email' => 'Enter your work email address.']);
});

test('looking up from a team host sends other teams to the main address', function () {
    config(['coresmith.tenant_domain' => 'coresmith.test']);
    ssoConnection(ssoTeam());
    ssoConnection(ssoTeam('other'), ['email_domain' => 'other.test']);

    $this->post('http://acme.coresmith.test/sso', ['email' => 'bob@other.test'])->assertRedirect('http://localhost/sso/other/start');
    $this->post('http://acme.coresmith.test/sso', ['email' => 'ada@example.test'])->assertRedirect('http://acme.coresmith.test/sso/acme/start');
});

test('the lookup is throttled', function () {
    foreach (range(1, 10) as $attempt) {
        $this->from('/sso')->post('/sso', ['email' => 'ada@nowhere.test'])->assertRedirect('/sso');
    }

    $this->from('/sso')->post('/sso', ['email' => 'ada@nowhere.test'])->assertStatus(429);
});
