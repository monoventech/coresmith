<?php

declare(strict_types=1);

use App\Exceptions\GitProviderException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\GitHubApp;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\User;
use App\Services\Git\GitHub\GitHubAppRegistrar;
use App\Services\Git\GitHub\ManifestBuilder;
use App\Services\Sso\AddressResolver;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

function registrationOwner(string $role = 'owner'): array
{
    $team = Team::factory()->create(['name' => 'Acme Labs', 'slug' => 'acme']);
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role]);

    return [$team, $user->fresh()];
}

function registrationIntentFor(Team $team, User $user): array
{
    test()->actingAs($user);

    return app(GitHubAppRegistrar::class)->intent($team, $user);
}

function registrationPem(): string
{
    static $pem = null;

    if ($pem === null) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    }

    return $pem;
}

function registrationConversion(array $overrides = []): array
{
    return array_merge([
        'id' => 1041207,
        'slug' => 'coresmith-acme-labs',
        'name' => 'Coresmith Acme Labs',
        'client_id' => 'Iv1.0123456789abcdef',
        'client_secret' => 'cs-live-secret',
        'webhook_secret' => 'whs-live-secret',
        'pem' => registrationPem(),
        'owner' => ['login' => 'acme-labs', 'type' => 'Organization'],
        'html_url' => 'https://github.com/apps/coresmith-acme-labs',
        'permissions' => ['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'],
        'events' => ['push'],
    ], $overrides);
}

function registrationFakeGitHub(string $key, array $conversion = [], int $conversionStatus = 201, array|int $hook = []): void
{
    Http::fake([
        'api.github.com/app-manifests/c0de/conversions' => Http::response($conversionStatus === 201 ? registrationConversion($conversion) : $conversion, $conversionStatus),
        'api.github.com/app/hook/config' => is_int($hook)
            ? Http::response(['message' => 'Bad credentials'], $hook)
            : Http::response($hook + ['url' => "https://panel.example.com/api/webhooks/github/{$key}", 'content_type' => 'json', 'secret' => '********', 'insecure_ssl' => '0']),
    ]);
}

function registrationCallback(string $state, string $code = 'c0de'): string
{
    return route('github-apps.registered', ['code' => $code, 'state' => $state]);
}

function registrationGitPage(string $slug = 'acme'): string
{
    return "http://localhost/{$slug}/settings/git";
}

beforeEach(function () {
    Http::preventStrayRequests();

    config(['inertia.ssr.enabled' => false]);

    config(['services.github' => [
        'app_id' => null,
        'app_slug' => 'coresmith',
        'client_id' => null,
        'client_secret' => null,
        'private_key' => null,
        'webhook_secret' => null,
    ]]);

    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['140.82.112.3'];
        }
    });
});

test('the git page offers a registration with this panel\'s manifest when no app exists', function () {
    [$team, $user] = registrationOwner();

    $response = $this->actingAs($user)->get(route('source-providers.index'))->assertOk();
    $registration = $response->inertiaProps('github.registration');

    $response->assertInertia(fn($page) => $page
        ->component('settings/Git')
        ->where('github.can_register', true)
        ->where('github.elsewhere', null)
        ->where('github.registration.name', 'Coresmith Acme Labs')
        ->where('github.registration.manifest', app(ManifestBuilder::class)->base())
        ->where('github.registration.reachability', [
            'reachable' => false,
            'host' => 'localhost',
            'sentence' => 'GitHub cannot reach localhost, it only answers on this machine.',
        ]));

    expect(array_keys($registration))->toBe(['state', 'key', 'personal_action', 'organization_action', 'manifest', 'webhook', 'name', 'reachability'])
        ->and($registration['state'])->toMatch('/^[A-Za-z0-9]{40}\z/')
        ->and($registration['key'])->toMatch('/^[0-9a-hjkmnp-tv-z]{26}\z/')
        ->and($registration['manifest']['redirect_url'])->toEndWith('/settings/git/github/registered')
        ->and($registration['manifest'])->not->toHaveKey('hook_attributes')
        ->and($registration['webhook']['hook_attributes']['url'])->toEndWith('/api/webhooks/github/' . $registration['key'])
        ->and($registration['webhook']['default_events'])->toBe(['push'])
        ->and($registration['personal_action'])->toBe("https://github.com/settings/apps/new?state={$registration['state']}")
        ->and($registration['organization_action'])->toBe("https://github.com/organizations/{organization}/settings/apps/new?state={$registration['state']}")
        ->and(session(GitHubAppRegistrar::SESSION_KEY))->toHaveCount(1)
        ->and(session(GitHubAppRegistrar::SESSION_KEY)[$registration['state']])->toMatchArray([
            'team' => $team->id,
            'user' => $user->id,
            'key' => $registration['key'],
        ]);
});

test('the registration says the webhook can be delivered once the panel has a public https address', function () {
    config(['app.url' => 'https://panel.example.com']);
    [, $user] = registrationOwner();

    $this->actingAs($user)->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page
            ->where('github.registration.reachability', ['reachable' => true, 'host' => 'panel.example.com', 'sentence' => null])
            ->where('github.registration.manifest.redirect_url', 'https://panel.example.com/settings/git/github/registered'));
});

test('a registration started under a team address still points github at the canonical address', function () {
    [, $user] = registrationOwner();

    $registration = $this->actingAs($user)->get('/acme/settings/git')->assertOk()->inertiaProps('github.registration');

    expect($registration['manifest']['redirect_url'])->toBe('http://localhost/settings/git/github/registered')
        ->and($registration['manifest']['callback_urls'])->toBe(['http://localhost/settings/source-providers/github_app/callback'])
        ->and($registration['webhook']['hook_attributes']['url'])->toBe("http://localhost/api/webhooks/github/{$registration['key']}")
        ->and($registration['manifest']['url'])->toBe('http://localhost/');
});

test('the registration carries no credential', function () {
    [, $user] = registrationOwner();

    $response = $this->actingAs($user)->get(route('source-providers.index'))->assertOk();

    expect($response->getContent())->not->toContain('PRIVATE KEY')
        ->and($response->getContent())->not->toContain('client_secret')
        ->and($response->getContent())->not->toContain('webhook_secret');
});

test('a member who cannot manage is offered no registration and starts none', function () {
    [, $member] = registrationOwner('member');

    foreach (range(1, 6) as $ignored) {
        $this->actingAs($member)->get(route('source-providers.index'))
            ->assertOk()
            ->assertInertia(fn($page) => $page
                ->where('can.manage', false)
                ->where('github.can_register', false)
                ->where('github.registration', null)
                ->where('github.elsewhere', null));
    }

    expect(session(GitHubAppRegistrar::SESSION_KEY, []))->toBe([]);
});

test('a member never burns one of the waiting registrations', function () {
    [$team, $user] = registrationOwner();
    $waiting = registrationIntentFor($team, $user);
    $team->members()->updateExistingPivot($user->id, ['role' => 'member']);

    foreach (range(1, 6) as $ignored) {
        $this->actingAs($user->fresh())->get(route('source-providers.index'))
            ->assertInertia(fn($page) => $page->where('github.can_register', false)->where('github.registration', null));
    }

    expect(array_keys(session(GitHubAppRegistrar::SESSION_KEY)))->toBe([$waiting['state']]);
});

test('registration is offered to exactly those who can manage the page', function (string $role, bool $manages) {
    [, $user] = registrationOwner($role);

    $response = $this->actingAs($user)->get(route('source-providers.index'))->assertOk();

    expect($response->inertiaProps('can.manage'))->toBe($manages)
        ->and($response->inertiaProps('github.can_register'))->toBe($manages)
        ->and($response->inertiaProps('github.registration') !== null)->toBe($manages)
        ->and(count(session(GitHubAppRegistrar::SESSION_KEY, [])))->toBe($manages ? 1 : 0);
})->with([
    'an owner' => ['owner', true],
    'an admin' => ['admin', true],
    'a member' => ['member', false],
]);

test('registration is not offered when the environment app is configured, or once the team has an app', function (string $setup) {
    [$team, $user] = registrationOwner();

    if ($setup === 'environment') {
        config(['services.github' => ['app_id' => '1041207', 'app_slug' => 'coresmith', 'client_id' => 'Iv1.env', 'client_secret' => 'env-secret', 'private_key' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----", 'webhook_secret' => null]]);
    }

    if ($setup === 'its own app') {
        GitHubApp::factory()->for($team)->create();
    }

    $this->actingAs($user)->get(route('source-providers.index'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('github.can_register', false)
            ->where('github.registration', null)
            ->where('github.elsewhere', null));

    expect(session(GitHubAppRegistrar::SESSION_KEY, []))->toBe([]);
})->with(['environment', 'its own app']);

test('another team\'s app does not stop this team registering its own', function () {
    [, $user] = registrationOwner();
    GitHubApp::factory()->for(Team::factory()->create())->create();

    $this->actingAs($user)->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.can_register', true)->has('github.registration.state'));
});

test('on a team\'s own domain the page sends the owner to the canonical address instead', function () {
    [$team, $user] = registrationOwner();
    TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'panel.acme.test']);

    $this->actingAs($user)->get('http://panel.acme.test/settings/git')
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('github.can_register', true)
            ->where('github.registration', null)
            ->where('github.elsewhere', 'http://localhost/acme/settings/git'));

    expect(session(GitHubAppRegistrar::SESSION_KEY, []))->toBe([]);
});

test('a member on a team\'s own domain is not sent anywhere either', function () {
    [$team, $member] = registrationOwner('member');
    TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'panel.acme.test']);

    $this->actingAs($member)->get('http://panel.acme.test/settings/git')
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('github.can_register', false)
            ->where('github.registration', null)
            ->where('github.elsewhere', null));
});

test('on the canonical address there is nowhere else to go', function () {
    [, $user] = registrationOwner();

    $this->actingAs($user)->get('/acme/settings/git')
        ->assertInertia(fn($page) => $page->where('github.elsewhere', null));
});

test('at most five registrations wait at once and each lasts an hour', function () {
    [, $user] = registrationOwner();
    $states = [];

    foreach (range(1, 6) as $ignored) {
        $states[] = $this->actingAs($user)->get(route('source-providers.index'))->inertiaProps('github.registration.state');
    }

    expect(array_keys(session(GitHubAppRegistrar::SESSION_KEY)))->toBe(array_slice($states, 1));

    $this->travel(61)->minutes();

    $fresh = $this->actingAs($user)->get(route('source-providers.index'))->inertiaProps('github.registration.state');

    expect(array_keys(session(GitHubAppRegistrar::SESSION_KEY)))->toBe([$fresh]);
});

test('a page that is not asked for the github prop does not start a registration', function () {
    [, $user] = registrationOwner();

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()), 'X-Inertia-Partial-Component' => 'settings/Git', 'X-Inertia-Partial-Data' => 'providers'])
        ->get(route('source-providers.index'))
        ->assertOk();

    expect(session(GitHubAppRegistrar::SESSION_KEY, []))->toBe([]);
});

test('github\'s code becomes an app owned by the team, then the install begins', function () {
    config(['app.url' => 'https://panel.example.com']);
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    registrationFakeGitHub($intent['key']);

    $this->actingAs($user)
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(route('source-providers.connect', ['type' => 'github_app', 'app' => $intent['key']]));

    $app = GitHubApp::find($intent['key']);

    expect($app)->not->toBeNull()
        ->and($app->team_id)->toBe($team->id)
        ->and($app->created_by)->toBe($user->id)
        ->and($app->app_id)->toBe('1041207')
        ->and($app->slug)->toBe('coresmith-acme-labs')
        ->and($app->name)->toBe('Coresmith Acme Labs')
        ->and($app->client_id)->toBe('Iv1.0123456789abcdef')
        ->and($app->owner_login)->toBe('acme-labs')
        ->and($app->owner_type)->toBe('Organization')
        ->and($app->html_url)->toBe('https://github.com/apps/coresmith-acme-labs')
        ->and($app->permissions)->toBe(['contents' => 'read', 'metadata' => 'read', 'statuses' => 'write'])
        ->and($app->events)->toBe(['push'])
        ->and($app->hook_url)->toBe("https://panel.example.com/api/webhooks/github/{$intent['key']}")
        ->and($app->client_secret)->toBe('cs-live-secret')
        ->and($app->webhook_secret)->toBe('whs-live-secret')
        ->and($app->private_key)->toBe(registrationPem())
        ->and(session(GitHubAppRegistrar::SESSION_KEY, []))->toBe([]);

    $raw = json_encode(DB::table('github_apps')->where('id', $intent['key'])->first(), JSON_THROW_ON_ERROR);

    expect($raw)->not->toContain('whs-live-secret')
        ->and($raw)->not->toContain('cs-live-secret')
        ->and($raw)->not->toContain('PRIVATE KEY');

    Http::assertSentCount(2);
    Http::assertSent(fn($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.github.com/app-manifests/c0de/conversions'
        && !$request->hasHeader('Authorization'));
    Http::assertSent(fn($request) => $request->method() === 'GET'
        && $request->url() === 'https://api.github.com/app/hook/config'
        && str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer '));
});

test('registering an app is recorded without any secret', function () {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    registrationFakeGitHub($intent['key']);

    $this->actingAs($user)->get(registrationCallback($intent['state']))->assertRedirect();

    $activity = Activity::query()->where('description', 'github_app_registered')->sole();
    $properties = json_encode($activity->properties, JSON_THROW_ON_ERROR);

    expect($activity->causer?->is($user))->toBeTrue()
        ->and($activity->subject?->is(GitHubApp::find($intent['key'])))->toBeTrue()
        ->and($properties)->not->toContain('whs-live-secret')
        ->and($properties)->not->toContain('cs-live-secret')
        ->and($properties)->not->toContain('PRIVATE KEY')
        ->and($properties)->not->toContain('c0de');
});

test('an app whose webhook address cannot be read is still kept, so its one-time credentials are not lost', function (array|int $hook) {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    registrationFakeGitHub($intent['key'], hook: $hook);

    $this->actingAs($user)
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(route('source-providers.connect', ['type' => 'github_app', 'app' => $intent['key']]));

    $app = GitHubApp::find($intent['key']);

    expect($app)->not->toBeNull()
        ->and($app->hook_url)->toBeNull()
        ->and($app->private_key)->toBe(registrationPem());
})->with([
    'a refused read' => [401],
    'an app with no webhook' => [['url' => null]],
]);

test('a state that is not waiting is refused and nothing is saved', function () {
    [, $user] = registrationOwner();

    $this->actingAs($user)
        ->get(registrationCallback('not-a-waiting-state'))
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', 'That GitHub request could not be verified. Start again from Settings, Git.');

    Http::assertNothingSent();
    expect(GitHubApp::count())->toBe(0);
});

test('a state is used once', function () {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    registrationFakeGitHub($intent['key']);

    $this->actingAs($user)->get(registrationCallback($intent['state']))->assertRedirect(route('source-providers.connect', ['type' => 'github_app', 'app' => $intent['key']]));

    $this->actingAs($user)
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', 'That GitHub request could not be verified. Start again from Settings, Git.');

    Http::assertSentCount(2);
    expect(GitHubApp::count())->toBe(1);
});

test('a state that waited longer than an hour is refused', function () {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);

    $this->travel(61)->minutes();

    $this->actingAs($user)
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', 'That GitHub request could not be verified. Start again from Settings, Git.');

    Http::assertNothingSent();
    expect(GitHubApp::count())->toBe(0);
});

test('a state still counts a little short of the hour', function () {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    registrationFakeGitHub($intent['key']);

    $this->travel(59)->minutes();

    $this->actingAs($user)
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(route('source-providers.connect', ['type' => 'github_app', 'app' => $intent['key']]));
});

test('a state another person started in this browser is refused and left alone', function () {
    [$team, $owner] = registrationOwner();
    $other = User::factory()->create();
    $team->members()->attach($other, ['role' => 'owner']);
    $intent = registrationIntentFor($team, $owner);

    $this->actingAs($other->fresh())
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', 'That GitHub request could not be verified. Start again from Settings, Git.');

    Http::assertNothingSent();
    expect(GitHubApp::count())->toBe(0)
        ->and(session(GitHubAppRegistrar::SESSION_KEY))->toHaveKey($intent['state']);
});

test('a callback without a usable code or state is refused before github is asked', function (array $query) {
    [$team, $user] = registrationOwner();
    registrationIntentFor($team, $user);

    $this->actingAs($user)
        ->get(route('github-apps.registered', $query))
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', 'That GitHub request could not be verified. Start again from Settings, Git.');

    Http::assertNothingSent();
    expect(GitHubApp::count())->toBe(0);
})->with([
    'nothing' => [[]],
    'no code' => [['state' => 'abc']],
    'no state' => [['code' => 'c0de']],
    'a code that is too long' => [['code' => str_repeat('c', 101), 'state' => 'abc']],
    'a state that is too long' => [['code' => 'c0de', 'state' => str_repeat('s', 65)]],
    'a list instead of a code' => [['code' => ['c0de'], 'state' => 'abc']],
    'a list instead of a state' => [['code' => 'c0de', 'state' => ['abc']]],
]);

test('a trailing newline never makes a state or a code acceptable', function (string $field, string $message) {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    $given = ['state' => $intent['state'], 'code' => 'c0de'];
    $given[$field] .= "\n";

    expect(fn() => app(GitHubAppRegistrar::class)->complete($given['state'], $given['code'], $user))
        ->toThrow(GitProviderException::class, $message);

    Http::assertNothingSent();
    expect(GitHubApp::count())->toBe(0);
})->with([
    'a state' => ['state', 'That GitHub request could not be verified. Start again from Settings, Git.'],
    'a code' => ['code', 'GitHub did not finish creating the app, so nothing was saved. Try again from Settings, Git.'],
]);

test('a state is matched exactly, so one with a newline on it is not found', function () {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    $registrar = app(GitHubAppRegistrar::class);

    expect($registrar->teamFor($intent['state'] . "\n", $user))->toBeNull()
        ->and($registrar->teamFor("\n" . $intent['state'], $user))->toBeNull()
        ->and($registrar->teamFor($intent['state'], $user)?->is($team))->toBeTrue()
        ->and(session(GitHubAppRegistrar::SESSION_KEY))->toHaveKey($intent['state']);
});

test('the callback itself refuses a trailing newline when nothing upstream trims it', function (string $field, string $message) {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    $query = ['code' => 'c0de', 'state' => $intent['state']];
    $query[$field] .= "\n";

    $this->withoutMiddleware(TrimStrings::class)
        ->actingAs($user)
        ->get(route('github-apps.registered', $query))
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', $message);

    Http::assertNothingSent();
    expect(GitHubApp::count())->toBe(0);
})->with([
    'a state' => ['state', 'That GitHub request could not be verified. Start again from Settings, Git.'],
    'a code' => ['code', 'GitHub did not finish creating the app, so nothing was saved. Try again from Settings, Git.'],
]);

test('a callback that arrives under a team address goes back to that team\'s git page without doubling the address', function () {
    [, $user] = registrationOwner();

    $this->actingAs($user)
        ->get('/acme/settings/git/github/registered?code=c0de&state=not-a-waiting-state')
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', 'That GitHub request could not be verified. Start again from Settings, Git.');

    Http::assertNothingSent();
});

test('a callback that arrives signed out is sent to sign in first', function () {
    $this->get(route('github-apps.registered', ['code' => 'c0de', 'state' => 'abc']))->assertRedirect(route('login'));

    Http::assertNothingSent();
});

test('the callback is throttled in a bucket of its own', function () {
    $middleware = Route::getRoutes()->getByName('github-apps.registered')->gatherMiddleware();

    expect($middleware)->toContain('throttle:10,1,github-app-registered')
        ->and($middleware)->toContain('auth');
});

test('an owner who left the team cannot finish its registration', function () {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    $team->members()->updateExistingPivot($user->id, ['role' => 'member']);

    $this->actingAs($user->fresh())
        ->get(registrationCallback($intent['state']))
        ->assertForbidden();

    Http::assertNothingSent();
    expect(GitHubApp::count())->toBe(0);
});

test('a team that no longer exists cannot be registered for', function () {
    [$team, $user] = registrationOwner();
    $other = Team::factory()->create(['slug' => 'beta']);
    $other->members()->attach($user, ['role' => 'owner']);
    $intent = registrationIntentFor($team, $user);
    $team->delete();

    $this->actingAs($user->fresh())
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(registrationGitPage('beta'))
        ->assertSessionHas('error', 'That GitHub request could not be verified. Start again from Settings, Git.');

    Http::assertNothingSent();
    expect(GitHubApp::count())->toBe(0);
});

test('github refusing the code saves nothing', function () {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    registrationFakeGitHub($intent['key'], ['message' => 'Validation Failed'], 422);

    $this->actingAs($user)
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', 'GitHub did not finish creating the app, so nothing was saved. Try again from Settings, Git.');

    Http::assertSentCount(1);
    expect(GitHubApp::count())->toBe(0)
        ->and(Activity::query()->where('description', 'github_app_registered')->count())->toBe(0);
});

test('every way the conversion can go wrong saves nothing and says the same thing', function (string $failure) {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);

    Http::fake(['api.github.com/app-manifests/c0de/conversions' => match ($failure) {
        'a used code' => Http::response(['message' => 'Not Found'], 404),
        'no answer' => fn() => throw new ConnectionException('timed out'),
        'credentials missing from the answer' => Http::response(['id' => 1041207, 'slug' => 'x'], 201),
    }]);

    $this->actingAs($user)
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', 'GitHub did not finish creating the app, so nothing was saved. Try again from Settings, Git.');

    Http::assertNotSent(fn($request) => str_contains($request->url(), '/app/hook/config'));
    expect(GitHubApp::count())->toBe(0);
})->with(['a used code', 'no answer', 'credentials missing from the answer']);

test('a code that cannot be a manifest code never reaches github', function () {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);

    $this->actingAs($user)
        ->get(registrationCallback($intent['state'], 'not/a/code'))
        ->assertRedirect(registrationGitPage())
        ->assertSessionHas('error', 'GitHub did not finish creating the app, so nothing was saved. Try again from Settings, Git.');

    Http::assertNothingSent();
    expect(GitHubApp::count())->toBe(0);
});

test('a failure goes back to the git page of the team the registration was for', function () {
    [$acme, $user] = registrationOwner();
    $beta = Team::factory()->create(['name' => 'Beta Works', 'slug' => 'beta']);
    $beta->members()->attach($user, ['role' => 'owner']);
    $user->forceFill(['current_team_id' => $acme->id])->save();
    $intent = registrationIntentFor($beta, $user->fresh());
    registrationFakeGitHub($intent['key'], ['message' => 'Validation Failed'], 422);

    $this->actingAs($user->fresh())
        ->get(registrationCallback($intent['state']))
        ->assertRedirect(registrationGitPage('beta'));
});

test('an app is registered for the team the registration was started for, not the one last worked in', function () {
    [$acme, $user] = registrationOwner();
    $beta = Team::factory()->create(['name' => 'Beta Works', 'slug' => 'beta']);
    $beta->members()->attach($user, ['role' => 'owner']);
    $user->forceFill(['current_team_id' => $acme->id])->save();
    $intent = registrationIntentFor($beta, $user->fresh());
    registrationFakeGitHub($intent['key']);

    $this->actingAs($user->fresh())->get(registrationCallback($intent['state']))->assertRedirect();

    expect(GitHubApp::find($intent['key'])->team_id)->toBe($beta->id);
});

test('a registered app gives its team no second registration', function () {
    [$team, $user] = registrationOwner();
    $intent = registrationIntentFor($team, $user);
    registrationFakeGitHub($intent['key']);

    $this->actingAs($user)->get(registrationCallback($intent['state']))->assertRedirect();

    $this->actingAs($user)->get(route('source-providers.index'))
        ->assertInertia(fn($page) => $page->where('github.can_register', false)->where('github.registration', null));
});

test('someone with no team gets a not found rather than an error', function () {
    $this->actingAs(User::factory()->create())
        ->get(registrationCallback('abc'))
        ->assertNotFound();

    Http::assertNothingSent();
});
