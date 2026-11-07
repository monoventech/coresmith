<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

function refusedFormPost(string $method, string $uri): TestResponse
{
    return test()->from('/where-the-form-was')->withHeaders(['X-Inertia' => 'true'])->{strtolower($method)}($uri);
}

test('a sign-in over the limit says so on the page and says when to try again', function () {
    $user = User::factory()->create();
    RateLimiter::increment(md5('login' . implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->from(route('login'))->withHeaders(['X-Inertia' => 'true'])->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

    $response->assertStatus(303)->assertRedirect(route('login'));

    expect(session('error'))->toMatch('/^Too many attempts\. Try again in (\d+ seconds?|1 minute)\.$/');
});

test('a sign-in over the limit outside the panel is still a plain 429', function () {
    $user = User::factory()->create();
    RateLimiter::increment(md5('login' . implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])->assertTooManyRequests();
});

test('the wait is named in the unit that reads best', function (int $seconds, string $wait) {
    Route::post('/refused-form-limit', fn() => throw new TooManyRequestsHttpException($seconds))->middleware('web');

    refusedFormPost('POST', '/refused-form-limit')->assertStatus(303)->assertRedirect('/where-the-form-was');

    expect(session('error'))->toBe("Too many attempts. Try again in {$wait}.");
})->with([
    'no wait given' => [0, '1 second'],
    'one second' => [1, '1 second'],
    'seconds' => [59, '59 seconds'],
    'exactly a minute' => [60, '1 minute'],
    'just over a minute' => [61, '2 minutes'],
    'an hour' => [3600, '60 minutes'],
]);

test('a form that outlived its session asks to try again', function (string $method) {
    Route::match([$method], '/refused-form-expired', fn() => throw new TokenMismatchException)->middleware('web');

    refusedFormPost($method, '/refused-form-expired')->assertStatus(303)->assertRedirect('/where-the-form-was');

    expect(session('error'))->toBe('This page expired. Try again.');
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

test('a throttled page visit is left alone, since going back could ask for it again', function () {
    Route::get('/refused-form-visit', fn() => throw new TooManyRequestsHttpException(30));

    refusedFormPost('GET', '/refused-form-visit')->assertStatus(429);

    expect(session('error'))->toBeNull();
});

test('other refusals are left alone', function () {
    Route::post('/refused-form-forbidden', fn() => abort(403))->middleware('web');

    refusedFormPost('POST', '/refused-form-forbidden')->assertForbidden();

    expect(session('error'))->toBeNull();
});
