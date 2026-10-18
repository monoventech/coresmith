<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\InstanceSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Install\SetupToken;
use Illuminate\Support\Facades\Http;

function tokenFile(): string
{
    return sys_get_temp_dir() . '/coresmith-setup-token-' . getmypid();
}

function issueToken(): string
{
    config(['coresmith.setup_token_path' => tokenFile()]);

    return app(SetupToken::class)->generate();
}

function installPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ose',
        'email' => 'ose@example.com',
        'password' => 'a-long-enough-passphrase',
        'password_confirmation' => 'a-long-enough-passphrase',
        'instance_name' => 'Monoven',
        'timezone' => 'Europe/London',
        'notification_email' => 'alerts@example.com',
    ], $overrides);
}

beforeEach(function () {
    config(['fortify.features' => []]);
    $this->breachedRange = '';
    Http::fake(fn() => Http::response($this->breachedRange, 200));
    config(['coresmith.setup_token_path' => tokenFile()]);
    @unlink(tokenFile());
});

afterEach(fn() => @unlink(tokenFile()));

test('the setup page is refused without the token', function () {
    issueToken();

    $this->get('/install')->assertForbidden();
});

test('the setup page is refused with the wrong token', function () {
    issueToken();

    $this->get('/install?token=not-the-real-one')->assertForbidden();
});

test('the setup page opens with the right token', function () {
    $token = issueToken();

    $this->get('/install?token=' . $token)
        ->assertOk()
        ->assertInertia(fn($page) => $page->component('Install')->where('setupToken', $token));
});

test('the setup page is refused when no token file exists at all', function () {
    $this->get('/install?token=anything')->assertForbidden();
});

test('an account cannot be created without the token', function () {
    issueToken();

    $this->post('/install', installPayload())->assertForbidden();

    expect(User::count())->toBe(0);
});

test('an account cannot be created with a forged token', function () {
    issueToken();

    $this->post('/install', installPayload(['setup_token' => 'forged']))->assertForbidden();

    expect(User::count())->toBe(0);
});

test('the owner account, team and settings are created together', function () {
    $token = issueToken();

    $this->post('/install', installPayload(['setup_token' => $token]))
        ->assertRedirect(route('dashboard'));

    $user = User::firstOrFail();
    $team = Team::firstOrFail();

    expect($user->email)->toBe('ose@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($team->name)->toBe('Monoven')
        ->and($team->owner_id)->toBe($user->getKey())
        ->and($team->members()->whereKey($user->getKey())->first()->pivot->role)->toBe(TeamRole::Owner->value)
        ->and(InstanceSetting::get('instance_name'))->toBe('Monoven')
        ->and(InstanceSetting::get('timezone'))->toBe('Europe/London')
        ->and(InstanceSetting::get('notification_email'))->toBe('alerts@example.com');
});

test('the token file is deleted the moment the account exists', function () {
    $token = issueToken();

    expect(file_exists(tokenFile()))->toBeTrue();

    $this->post('/install', installPayload(['setup_token' => $token]));

    expect(file_exists(tokenFile()))->toBeFalse();
});

test('the setup link cannot be replayed once an account exists', function () {
    $token = issueToken();

    $this->post('/install', installPayload(['setup_token' => $token]));
    $this->post('/install', installPayload(['setup_token' => $token, 'email' => 'second@example.com']))
        ->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1);
});

test('the setup page redirects away once an account exists', function () {
    issueToken();
    User::factory()->create();

    $this->get('/install?token=whatever')->assertRedirect(route('dashboard'));
});

test('the new owner is signed in', function () {
    $token = issueToken();

    $this->post('/install', installPayload(['setup_token' => $token]));

    $this->assertAuthenticatedAs(User::firstOrFail());
});

test('a short password is refused', function () {
    $token = issueToken();

    $this->post('/install', installPayload([
        'setup_token' => $token,
        'password' => 'short1234',
        'password_confirmation' => 'short1234',
    ]))->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
});

test('a password known to be breached is refused', function () {
    $breached = 'password123456';
    $this->breachedRange = substr(strtoupper(sha1($breached)), 5) . ':42000';

    $token = issueToken();

    $this->post('/install', installPayload([
        'setup_token' => $token,
        'password' => $breached,
        'password_confirmation' => $breached,
    ]))->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
});

test('a mismatched confirmation is refused', function () {
    $token = issueToken();

    $this->post('/install', installPayload(['setup_token' => $token, 'password_confirmation' => 'something-else']))
        ->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
});

test('an unknown timezone is refused', function () {
    $token = issueToken();

    $this->post('/install', installPayload(['setup_token' => $token, 'timezone' => 'Mars/Olympus']))
        ->assertSessionHasErrors('timezone');
});

test('the instance name and timezone are required', function (string $field) {
    $token = issueToken();

    $this->post('/install', installPayload(['setup_token' => $token, $field => '']))
        ->assertSessionHasErrors($field);
})->with(['instance_name', 'timezone', 'name', 'email']);

test('nothing is written when validation fails', function () {
    $token = issueToken();

    $this->post('/install', installPayload(['setup_token' => $token, 'email' => 'not-an-email']));

    expect(User::count())->toBe(0)
        ->and(Team::count())->toBe(0)
        ->and(InstanceSetting::query()->count())->toBe(0)
        ->and(file_exists(tokenFile()))->toBeTrue();
});

test('a fresh instance shows the holding page rather than the app', function () {
    $this->get('/dashboard')
        ->assertStatus(503)
        ->assertInertia(fn($page) => $page->component('auth/SetupRequired'));
});

test('the holding page never leaks the token', function () {
    $token = issueToken();

    expect($this->get('/dashboard')->getContent())->not->toContain($token);
});

test('an api caller on a fresh instance gets a clear status rather than a page', function () {
    $this->getJson('/api/v1/servers')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Coresmith is installed but not yet configured.');
});

test('the holding page disappears once the instance is set up', function () {
    User::factory()->create();

    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('the instance name replaces the app name across the interface', function () {
    $token = issueToken();

    $this->post('/install', installPayload(['setup_token' => $token, 'instance_name' => 'Acme Ops']));

    $this->get(route('dashboard'))
        ->assertInertia(fn($page) => $page->where('appName', 'Acme Ops'));
});

test('a token the panel is not allowed to delete still stops working once used', function () {
    $directory = sys_get_temp_dir() . '/coresmith-locked-token-' . getmypid();
    @mkdir($directory, 0755);
    $path = $directory . '/setup-token';
    config(['coresmith.setup_token_path' => $path]);
    $token = app(SetupToken::class)->generate();
    chmod($directory, 0555);

    try {
        app(SetupToken::class)->forget();

        expect(app(SetupToken::class)->matches($token))->toBeFalse()
            ->and(!file_exists($path) || file_get_contents($path) === '')->toBeTrue();
    } finally {
        chmod($directory, 0755);
        @unlink($path);
        @rmdir($directory);
    }
});
