<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\SsoIdentity;
use App\Models\TeamSsoConnection;
use App\Models\User;
use App\Services\Sso\PendingSsoLink;
use App\Services\Sso\SignIn;
use App\Services\Sso\SignInState;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__ . '/SsoHelpers.php';

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    ssoFakeNetwork();
    ssoFakeProvider();
});

test('start sends the browser to the provider with state, nonce and a PKCE challenge', function () {
    $this->freezeTime();
    $team = ssoTeam();
    ssoConnection($team);

    $flow = ssoStart();

    expect(Str::before($flow['location'], '?'))->toBe('https://idp.example.test/authorize')
        ->and($flow['response_type'])->toBe('code')
        ->and($flow['scope'])->toBe('openid email profile')
        ->and($flow['client_id'])->toBe('coresmith-client')
        ->and($flow['redirect_uri'])->toBe('http://localhost/sso/callback')
        ->and($flow['code_challenge_method'])->toBe('S256')
        ->and($flow['state'])->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($flow['nonce'])->not->toBe($flow['state']);

    $stored = session(SignInState::SESSION_KEY)[$flow['state']];

    expect($stored['team'])->toBe($team->id)
        ->and($stored['host'])->toBe('http://localhost')
        ->and($stored['nonce'])->toBe($flow['nonce'])
        ->and(strlen($stored['verifier']))->toBeGreaterThanOrEqual(43)
        ->and($flow['code_challenge'])->toBe(ssoBase64(hash('sha256', $stored['verifier'], true)))
        ->and($stored['expires'])->toBe(now()->addMinutes(10)->getTimestamp())
        ->and($flow['location'])->not->toContain('client-secret');

    ssoFakeToken(ssoToken(ssoClaims($flow['nonce'])));
    ssoLinkedMember($team);

    ssoCallback($flow['state'])->assertRedirect('http://localhost/acme/dashboard');

    Http::assertSent(fn(HttpRequest $request): bool => $request->url() === 'https://idp.example.test/token'
        && $request->method() === 'POST'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['client_id'] === 'coresmith-client'
        && $request['client_secret'] === 'client-secret'
        && $request['redirect_uri'] === 'http://localhost/sso/callback'
        && $request['code_verifier'] === $stored['verifier']);
});

test('an inertia visit to start leaves the page for the provider', function () {
    ssoConnection(ssoTeam());

    $this->withHeaders(ssoInertiaHeaders())
        ->get('/sso/acme/start')
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

test('a linked member signs in and lands on the team fleet', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoLinkedMember($team);

    ssoSignIn()->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);

    $identity = SsoIdentity::query()->sole();

    expect($identity->user_id)->toBe($member->id)
        ->and($identity->team_id)->toBe($team->id)
        ->and($identity->issuer)->toBe('https://idp.example.test')
        ->and($identity->subject)->toBe('user-123')
        ->and($identity->email)->toBe('ada@example.test')
        ->and($identity->last_login_at)->not->toBeNull()
        ->and($member->fresh()->current_team_id)->toBe($team->id)
        ->and(Activity::query()->where('description', 'sso_signed_in')->where('causer_id', $member->id)->exists())->toBeTrue();
});

test('an existing account is only connected after its owner signs in with the password once', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);

    ssoSignIn()->assertRedirect('http://localhost/acme/login')->assertSessionMissing('error');

    $this->assertGuest();

    expect(SsoIdentity::query()->exists())->toBeFalse();

    $this->get('/acme/login')->assertInertia(fn($page) => $page->where('sso.link', 'ada@example.test'));

    $this->post('/acme/login', ['email' => 'ada@example.test', 'password' => 'password'])
        ->assertRedirect('http://localhost/acme/dashboard')
        ->assertSessionHas('success', 'Connected. Sign in with single sign-on from now on.');

    $this->assertAuthenticatedAs($member);

    expect(SsoIdentity::query()->sole())
        ->user_id->toBe($member->id)
        ->subject->toBe('user-123')
        ->and(session(PendingSsoLink::SESSION_KEY))->toBeNull()
        ->and(Activity::query()->where('description', 'sso_linked')->where('causer_id', $member->id)->exists())->toBeTrue();

    auth()->logout();

    ssoSignIn()->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);
});

test('single sign-on does not ask a linked account for Coresmith two-factor', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoLinkedMember($team, user: User::factory()->withTwoFactor()->create(['email' => 'ada@example.test']));

    ssoSignIn()->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);
});

test('connecting an account with two-factor needs the second factor too', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoMember($team, TeamRole::Member, user: User::factory()->create(['email' => 'ada@example.test']));
    $member->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    ssoSignIn()->assertRedirect('http://localhost/acme/login');

    $this->assertGuest();

    $this->post('/acme/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect('http://localhost/acme/two-factor-challenge');

    $this->assertGuest();

    expect(SsoIdentity::query()->exists())->toBeFalse();

    $this->post('/acme/two-factor-challenge', ['recovery_code' => 'code1'])->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);

    expect(SsoIdentity::query()->sole()->user_id)->toBe($member->id);
});

test('a waiting connection is dropped when a different account signs in with its password', function () {
    $team = ssoTeam();
    ssoConnection($team);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);
    $other = ssoMember($team, TeamRole::Member, ['email' => 'grace@example.test']);

    ssoSignIn()->assertRedirect('http://localhost/acme/login');

    $this->post('/acme/login', ['email' => 'grace@example.test', 'password' => 'password'])
        ->assertRedirect('http://localhost/acme/dashboard')
        ->assertSessionMissing('success');

    $this->assertAuthenticatedAs($other);

    expect(SsoIdentity::query()->exists())->toBeFalse()
        ->and(session(PendingSsoLink::SESSION_KEY))->toBeNull();
});

test('a waiting connection expires after ten minutes', function () {
    $team = ssoTeam();
    ssoConnection($team);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);

    ssoSignIn()->assertRedirect('http://localhost/acme/login');

    $this->travel(11)->minutes();

    $this->get('/acme/login')->assertInertia(fn($page) => $page->where('sso.link', null));
    $this->post('/acme/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertSessionMissing('success');

    expect(SsoIdentity::query()->exists())->toBeFalse();
});

test('a waiting connection is not made once single sign-on is turned off', function () {
    $team = ssoTeam();
    $connection = ssoConnection($team);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);

    ssoSignIn()->assertRedirect('http://localhost/acme/login');

    $connection->forceFill(['enabled' => false])->save();

    $this->post('/acme/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertSessionMissing('success');

    expect(SsoIdentity::query()->exists())->toBeFalse();
});

test('an account already connected through the team picks up a new identity provider without its password', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoLinkedMember($team, subject: 'old-subject');
    SsoIdentity::query()->update(['issuer' => 'https://old-idp.example.test']);

    ssoSignIn()->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);

    expect(SsoIdentity::query()->where('user_id', $member->id)->count())->toBe(2);
});

test('an ES256 signed ID token is accepted', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoLinkedMember($team);

    ssoSignIn([], ['alg' => 'ES256', 'kid' => 'ec-1'])->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);
});

test('an ID token within a minute of expiry is still accepted', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoLinkedMember($team);

    ssoSignIn(['exp' => now()->subSeconds(30)->getTimestamp(), 'iat' => now()->addSeconds(30)->getTimestamp()])
        ->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);
});

test('a new person gets an account on first sign-in when the team creates accounts', function () {
    $team = ssoTeam();
    ssoConnection($team, ['jit_provisioning' => true]);

    ssoSignIn(['email' => 'Grace@Example.TEST', 'name' => 'Grace Hopper'])->assertRedirect('http://localhost/acme/dashboard');

    $user = User::query()->where('email', 'grace@example.test')->sole();

    $this->assertAuthenticatedAs($user);

    expect($user->name)->toBe('Grace Hopper')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->roleIn($team))->toBe(TeamRole::Member)
        ->and($user->current_team_id)->toBe($team->id)
        ->and(Hash::check('password', $user->password))->toBeFalse()
        ->and(SsoIdentity::query()->sole()->user_id)->toBe($user->id);
});

test('a new account without a name claim is named after the email', function () {
    $team = ssoTeam();
    ssoConnection($team, ['jit_provisioning' => true]);

    ssoSignIn(['email' => 'grace@example.test', 'name' => null])->assertRedirect('http://localhost/acme/dashboard');

    expect(User::query()->where('email', 'grace@example.test')->sole()->name)->toBe('grace');
});

test('a subject that differs from a linked one only in case, accents or a trailing space is another person', function (string $subject) {
    $team = ssoTeam();
    ssoConnection($team, ['jit_provisioning' => true]);
    $ada = ssoLinkedMember($team, subject: 'Ada');

    ssoSignIn(['sub' => $subject, 'email' => 'grace@example.test', 'name' => 'Grace Hopper'])->assertRedirect('http://localhost/acme/dashboard');

    $grace = User::query()->where('email', 'grace@example.test')->sole();

    $this->assertAuthenticatedAs($grace);

    expect(SsoIdentity::query()->where('user_id', $ada->id)->sole())
        ->subject->toBe('Ada')
        ->email->toBe('ada@example.test')
        ->and(SsoIdentity::query()->where('user_id', $grace->id)->sole()->subject)->toBe($subject);
})->with([
    'letter case' => ['ada'],
    'an accent' => ['Adá'],
    'a trailing space' => ['Ada '],
]);

test('identities from issuers that differ only in letter case are kept apart', function () {
    $team = ssoTeam();
    $ada = ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);
    $grace = ssoMember($team, TeamRole::Member, ['email' => 'grace@example.test']);

    SsoIdentity::factory()->create(['user_id' => $ada->id, 'team_id' => $team->id, 'issuer' => 'https://idp.example.test/realms/Acme', 'subject' => 'user-123', 'email' => 'ada@example.test']);
    SsoIdentity::factory()->create(['user_id' => $grace->id, 'team_id' => $team->id, 'issuer' => 'https://idp.example.test/realms/acme', 'subject' => 'user-123', 'email' => 'grace@example.test']);

    expect(SsoIdentity::query()->where('issuer', 'https://idp.example.test/realms/Acme')->where('subject', 'user-123')->sole()->user_id)->toBe($ada->id)
        ->and(SsoIdentity::query()->where('issuer', 'https://idp.example.test/realms/acme')->where('subject', 'user-123')->sole()->user_id)->toBe($grace->id);
});

test('a new person is refused when the team does not create accounts', function () {
    $team = ssoTeam();
    ssoConnection($team);

    ssoSignIn(['email' => 'grace@example.test'])
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', 'There is no Coresmith account for grace@example.test. Ask an owner of Acme Labs to add you.');

    $this->assertGuest();

    expect(User::query()->where('email', 'grace@example.test')->exists())->toBeFalse()
        ->and(SsoIdentity::query()->exists())->toBeFalse();
});

test('someone with an account outside the team joins as a deployer once they connect it with their password', function () {
    $team = ssoTeam();
    ssoConnection($team, ['jit_provisioning' => true]);
    $outsider = User::factory()->create(['email' => 'ada@example.test']);

    ssoSignIn()->assertRedirect('http://localhost/acme/login');

    $this->assertGuest();

    expect($outsider->fresh()->belongsToTeam($team))->toBeFalse();

    $this->post('/acme/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($outsider);

    expect($outsider->fresh()->roleIn($team))->toBe(TeamRole::Member)
        ->and(SsoIdentity::query()->sole()->user_id)->toBe($outsider->id)
        ->and(User::query()->count())->toBe(2);
});

test('someone outside the team is refused when the team does not create accounts', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $outsider = User::factory()->create(['email' => 'ada@example.test']);

    ssoSignIn()
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', 'You are not a member of Acme Labs yet. Ask an owner to add you, then try again.');

    $this->assertGuest();

    expect($outsider->fresh()->belongsToTeam($team))->toBeFalse()
        ->and(SsoIdentity::query()->exists())->toBeFalse();
});

test('a response that fails a check signs nobody in', function (Closure $token, string $reason, string $message) {
    $team = ssoTeam();
    ssoConnection($team, ['jit_provisioning' => true]);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);
    Log::spy();

    $flow = ssoStart();
    ssoFakeToken($token($flow['nonce']));

    ssoCallback($flow['state'])
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', $message);

    $this->assertGuest();

    expect(SsoIdentity::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('description', 'sso_signed_in')->exists())->toBeFalse();

    Log::shouldHaveReceived('notice')
        ->withArgs(fn(string $text, array $context = []): bool => ($context['reason'] ?? null) === $reason)
        ->once();
})->with([
    'a bad signature' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce), [], ssoForeignRsaKey()), 'id_token.signature', SignIn::UNVERIFIED],
    'a tampered payload' => [fn(string $nonce): string => (function () use ($nonce): string {
        [$header,, $signature] = explode('.', ssoToken(ssoClaims($nonce)));

        return $header . '.' . ssoBase64((string) json_encode(ssoClaims($nonce, ['sub' => 'someone-else']))) . '.' . $signature;
    })(), 'id_token.signature', SignIn::UNVERIFIED],
    'alg none' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce), ['alg' => 'none']), 'id_token.alg', SignIn::UNVERIFIED],
    'HS256 signed with the client secret' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce), ['alg' => 'HS256']), 'id_token.alg', SignIn::UNVERIFIED],
    'an RSA key used for ES256' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce), ['alg' => 'ES256', 'kid' => 'rsa-1'], ssoEcKey()), 'id_token.key', SignIn::UNVERIFIED],
    'an unknown key id' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce), ['kid' => 'rotated-away']), 'id_token.key', SignIn::UNVERIFIED],
    'a wrong issuer' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['iss' => 'https://evil.example.test'])), 'id_token.iss', SignIn::UNVERIFIED],
    'a wrong audience' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['aud' => 'another-client'])), 'id_token.aud', SignIn::UNVERIFIED],
    'several audiences without azp' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['aud' => ['coresmith-client', 'another-client']])), 'id_token.azp', SignIn::UNVERIFIED],
    'azp naming another client' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['azp' => 'another-client'])), 'id_token.azp', SignIn::UNVERIFIED],
    'an expired token' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['exp' => now()->subMinutes(2)->getTimestamp()])), 'id_token.exp', SignIn::UNVERIFIED],
    'a token issued in the future' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['iat' => now()->addMinutes(5)->getTimestamp()])), 'id_token.iat', SignIn::UNVERIFIED],
    'a nonce mismatch' => [fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['nonce' => 'not-the-nonce'])), 'id_token.nonce', SignIn::UNVERIFIED],
    'a missing nonce' => [fn(string $nonce): string => ssoToken(array_diff_key(ssoClaims($nonce), ['nonce' => true])), 'id_token.nonce', SignIn::UNVERIFIED],
    'not a JWT' => [fn(string $nonce): string => 'definitely.not-a.jwt!', 'id_token.format', SignIn::UNVERIFIED],
    'an email outside the verified domain' => [
        fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['email' => 'ada@elsewhere.test'])),
        'email.domain',
        "That account's email address is not at example.test, so it cannot sign in to Acme Labs. Ask your administrator to check the email your identity provider sends.",
    ],
    'an email at a subdomain of the verified domain' => [
        fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['email' => 'ada@mail.example.test'])),
        'email.domain',
        "That account's email address is not at example.test, so it cannot sign in to Acme Labs. Ask your administrator to check the email your identity provider sends.",
    ],
    'an unverified email' => [
        fn(string $nonce): string => ssoToken(ssoClaims($nonce, ['email_verified' => false])),
        'email.unverified',
        'Your identity provider has not verified that email address. Verify it there, then try again.',
    ],
]);

test('a callback with a state this browser never started is refused', function () {
    ssoConnection(ssoTeam());

    ssoCallback(str_repeat('a', 40))
        ->assertRedirect('http://localhost/login')
        ->assertSessionHas('error', 'That sign-in attempt has expired or was already used. Start again from the sign-in page.');

    ssoCallback('../../etc')->assertRedirect('http://localhost/login');

    $this->assertGuest();

    Http::assertNothingSent();
});

test('a state only works once', function () {
    $team = ssoTeam();
    ssoConnection($team);
    ssoLinkedMember($team);

    $flow = ssoStart();
    ssoFakeToken(ssoToken(ssoClaims($flow['nonce'])));

    ssoCallback($flow['state'])->assertRedirect('http://localhost/acme/dashboard');

    auth()->logout();

    ssoCallback($flow['state'])
        ->assertRedirect('http://localhost/login')
        ->assertSessionHas('error', 'That sign-in attempt has expired or was already used. Start again from the sign-in page.');

    $this->assertGuest();

    expect(Http::recorded(fn(HttpRequest $request): bool => $request->url() === 'https://idp.example.test/token'))->toHaveCount(1);
});

test('a state older than ten minutes is refused', function () {
    $team = ssoTeam();
    ssoConnection($team);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);

    $flow = ssoStart();
    ssoFakeToken(ssoToken(ssoClaims($flow['nonce'])));

    $this->travel(11)->minutes();

    ssoCallback($flow['state'])
        ->assertRedirect('http://localhost/login')
        ->assertSessionHas('error', 'That sign-in attempt has expired or was already used. Start again from the sign-in page.');

    $this->assertGuest();
});

test('a provider error comes back as a plain message and never echoes the provider text', function () {
    ssoConnection(ssoTeam());

    $flow = ssoStart();

    ssoCallback($flow['state'], query: ['error' => 'access_denied', 'error_description' => 'Visit evil.example.test to fix this', 'code' => null])
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', 'Your identity provider did not sign you in. Try again, or ask your administrator for access.');

    $this->assertGuest();

    Http::assertNotSent(fn(HttpRequest $request): bool => $request->url() === 'https://idp.example.test/token');
});

test('a provider that refuses the code exchange is reported without its response', function () {
    ssoConnection(ssoTeam());

    $flow = ssoStart();
    Http::fake(['https://idp.example.test/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'secret-looking detail'], 400)]);

    ssoCallback($flow['state'])
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', SignIn::REFUSED);

    $this->assertGuest();
});

test('a client secret the vault cannot read ends on the sign-in page, not an error page', function () {
    $team = ssoTeam();
    $connection = ssoConnection($team);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);
    DB::table('team_sso_connections')->where('id', $connection->id)->update(['client_secret' => base64_encode(random_bytes(48))]);

    ssoSignIn()
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', SignIn::REFUSED);

    $this->assertGuest();

    Http::assertNotSent(fn(HttpRequest $request): bool => $request->url() === 'https://idp.example.test/token');
});

test('turning single sign-on off mid flow stops the callback', function () {
    $team = ssoTeam();
    $connection = ssoConnection($team);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);

    $flow = ssoStart();
    ssoFakeToken(ssoToken(ssoClaims($flow['nonce'])));

    $connection->forceFill(['enabled' => false])->save();

    ssoCallback($flow['state'])
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', 'Single sign-on is not turned on for that team any more. Sign in with your password.');

    $this->assertGuest();
});

test('the email comes from userinfo when the ID token leaves it out', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoLinkedMember($team);
    ssoFakeUserinfo(['sub' => 'user-123', 'email' => 'ada@example.test', 'email_verified' => true]);

    ssoSignIn(['email' => null, 'email_verified' => null])->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);

    Http::assertSent(fn(HttpRequest $request): bool => $request->url() === 'https://idp.example.test/userinfo'
        && $request->hasHeader('Authorization', 'Bearer access-token'));
});

test('userinfo still has to verify the email and match the subject', function (array $userinfo, string $message) {
    $team = ssoTeam();
    ssoConnection($team);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);
    ssoFakeUserinfo($userinfo);

    ssoSignIn(['email' => null, 'email_verified' => null])
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', $message);

    $this->assertGuest();
})->with([
    'another subject' => [['sub' => 'someone-else', 'email' => 'ada@example.test'], SignIn::UNVERIFIED],
    'an unverified email' => [['sub' => 'user-123', 'email' => 'ada@example.test', 'email_verified' => 'false'], 'Your identity provider has not verified that email address. Verify it there, then try again.'],
    'no email at all' => [['sub' => 'user-123'], SignIn::NO_EMAIL],
]);

test('a linked account keeps signing in after its email changes within the domain', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);
    ssoMember($team, TeamRole::Member, ['email' => 'ada.lovelace@example.test']);
    SsoIdentity::factory()->create([
        'user_id' => $member->id,
        'team_id' => $team->id,
        'issuer' => 'https://idp.example.test',
        'subject' => 'user-123',
        'email' => 'ada@example.test',
    ]);

    ssoSignIn(['email' => 'ada.lovelace@example.test'])->assertRedirect('http://localhost/acme/dashboard');

    $this->assertAuthenticatedAs($member);

    expect(SsoIdentity::query()->sole())
        ->user_id->toBe($member->id)
        ->email->toBe('ada.lovelace@example.test');
});

test('a linked account still needs an email at the verified domain', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);
    SsoIdentity::factory()->create(['user_id' => $member->id, 'team_id' => $team->id, 'issuer' => 'https://idp.example.test', 'subject' => 'user-123']);

    ssoSignIn(['email' => 'ada@elsewhere.test'])->assertRedirect('http://localhost/acme/login')->assertSessionHas('error');

    $this->assertGuest();
});

test('signing in returns to the page that asked for it', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoLinkedMember($team);

    $this->get('/acme/deployments')->assertRedirect('http://localhost/acme/login');

    ssoSignIn()->assertRedirect('http://localhost/acme/deployments');

    $this->assertAuthenticatedAs($member);
});

test('signing in on a team host stays on that host', function () {
    config(['coresmith.tenant_domain' => 'coresmith.test']);
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoLinkedMember($team);

    $flow = ssoStart('http://acme.coresmith.test/sso/acme/start');

    expect($flow['redirect_uri'])->toBe('http://acme.coresmith.test/sso/callback');

    ssoFakeToken(ssoToken(ssoClaims($flow['nonce'])));

    ssoCallback($flow['state'], 'http://acme.coresmith.test')->assertRedirect('http://acme.coresmith.test/dashboard');

    $this->assertAuthenticatedAs($member);

    Http::assertSent(fn(HttpRequest $request): bool => $request->url() === 'https://idp.example.test/token'
        && $request['redirect_uri'] === 'http://acme.coresmith.test/sso/callback');
});

test('a callback on another host than the one that started is refused', function () {
    config(['coresmith.tenant_domain' => 'coresmith.test']);
    $team = ssoTeam();
    ssoConnection($team);
    ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);

    $flow = ssoStart();
    ssoFakeToken(ssoToken(ssoClaims($flow['nonce'])));

    ssoCallback($flow['state'], 'http://acme.coresmith.test')
        ->assertRedirect('http://acme.coresmith.test/login')
        ->assertSessionHas('error', 'That sign-in attempt started on a different address. Start again from the sign-in page.');

    $this->assertGuest();
});

test('a team host only starts sign-in for its own team', function () {
    config(['coresmith.tenant_domain' => 'coresmith.test']);
    ssoConnection(ssoTeam());
    ssoConnection(ssoTeam('other'), ['email_domain' => 'other.test']);

    $this->get('http://acme.coresmith.test/sso/other/start')->assertNotFound();
    $this->get('http://localhost/sso/missing-team/start')->assertNotFound();

    expect(session(SignInState::SESSION_KEY))->toBeNull();
});

test('start for a team without single sign-on returns to its sign-in page', function () {
    $team = ssoTeam();
    TeamSsoConnection::factory()->for($team)->verified()->create();

    $this->get('/sso/acme/start')
        ->assertRedirect('http://localhost/acme/login')
        ->assertSessionHas('error', 'Single sign-on is not turned on for that team. Sign in with your password.');

    Http::assertNothingSent();
});

test('someone already signed in is not sent through single sign-on', function () {
    $team = ssoTeam();
    ssoConnection($team);
    $member = ssoMember($team, TeamRole::Member, ['email' => 'ada@example.test']);

    $this->actingAs($member)->get('/sso/acme/start')->assertRedirect();

    expect(session(SignInState::SESSION_KEY))->toBeNull();

    Http::assertNothingSent();
});
