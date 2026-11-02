<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

function passwordSessionsUser(string $edition): User
{
    config(['coresmith.edition' => $edition]);
    $team = $edition === 'cloud' ? Team::factory()->subscribed(Plan::Pro)->create() : Team::factory()->create();
    $user = User::factory()->create(['email' => 'maya@example.com', 'password' => 'the-old-passphrase']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $user->fresh();
}

/**
 * @param  array<string, string>  $cookies
 * @param  array<string, mixed>  $data
 */
function passwordSessionsRequest(string $method, string $uri, array $cookies = [], array $data = []): TestResponse
{
    app('auth')->forgetGuards();
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
    app()->forgetInstance('redirect');

    $encrypted = [];

    foreach ($cookies as $name => $value) {
        $encrypted[$name] = encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()) . $value, false);
    }

    return test()->call($method, $uri, $data, $encrypted);
}

/**
 * @return array<string, string>
 */
function passwordSessionsSignIn(bool $remember = false): array
{
    $response = passwordSessionsRequest('POST', route('login.store'), [], ['email' => 'maya@example.com', 'password' => 'the-old-passphrase', ...($remember ? ['remember' => 'on'] : [])]);

    $response->assertRedirect();

    $names = [config('session.cookie'), ...($remember ? [Auth::guard()->getRecallerName()] : [])];

    return collect($names)->mapWithKeys(fn(string $name): array => [$name => (string) $response->getCookie($name)?->getValue()])->all();
}

function passwordSessionsReset(User $user): void
{
    passwordSessionsRequest('POST', route('password.update'), [], [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
}

function passwordSessionsChange(array $cookies): void
{
    passwordSessionsRequest('PUT', route('user-password.update'), $cookies, [
        'current_password' => 'the-old-passphrase',
        'password' => 'a-brand-new-passphrase',
        'password_confirmation' => 'a-brand-new-passphrase',
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Saved. Your new password works from the next sign-in.');
}

beforeEach(function () {
    config(['session.driver' => 'database']);
});

test('a password reset signs out a session opened before it', function (string $edition) {
    $user = passwordSessionsUser($edition);
    $browser = passwordSessionsSignIn();

    passwordSessionsRequest('GET', route('dashboard'), $browser)->assertOk();

    passwordSessionsReset($user);

    passwordSessionsRequest('GET', route('dashboard'), $browser)->assertRedirect(route('login'));
})->with(['self-hosted', 'cloud']);

test('changing the password in settings keeps this session and signs out the others', function (string $edition) {
    passwordSessionsUser($edition);
    $here = passwordSessionsSignIn();
    $elsewhere = passwordSessionsSignIn();

    passwordSessionsRequest('GET', route('dashboard'), $here)->assertOk();
    passwordSessionsRequest('GET', route('dashboard'), $elsewhere)->assertOk();

    passwordSessionsChange($here);

    passwordSessionsRequest('GET', route('dashboard'), $here)->assertOk();
    passwordSessionsRequest('GET', route('dashboard'), $elsewhere)->assertRedirect(route('login'));
})->with(['self-hosted', 'cloud']);

test('a remember me cookie made before a reset no longer signs in', function (string $edition) {
    $user = passwordSessionsUser($edition);
    $recaller = Auth::guard()->getRecallerName();
    $remembered = [$recaller => passwordSessionsSignIn(remember: true)[$recaller]];

    passwordSessionsRequest('GET', route('dashboard'), $remembered)->assertOk();

    passwordSessionsReset($user);

    passwordSessionsRequest('GET', route('dashboard'), $remembered)->assertRedirect(route('login'));
})->with(['self-hosted', 'cloud']);

test('a remember me cookie made before a password change elsewhere no longer signs in', function (string $edition) {
    passwordSessionsUser($edition);
    $recaller = Auth::guard()->getRecallerName();
    $remembered = [$recaller => passwordSessionsSignIn(remember: true)[$recaller]];
    $here = passwordSessionsSignIn();

    passwordSessionsRequest('GET', route('dashboard'), $remembered)->assertOk();

    passwordSessionsChange($here);

    passwordSessionsRequest('GET', route('dashboard'), $remembered)->assertRedirect(route('login'));
})->with(['self-hosted', 'cloud']);
