<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

function rateLimitedOwner(): User
{
    $team = Team::factory()->create(['name' => 'Acme Labs', 'slug' => 'acme']);
    $user = User::factory()->create(['password' => 'the-real-password']);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

    return $user->fresh();
}

test('spending one throttled route does not lock the user out of another', function () {
    $user = rateLimitedOwner();

    foreach (range(1, 6) as $ignored) {
        $this->actingAs($user)->post(route('settings.team-domain.verify'))->assertNotFound();
    }

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'not-the-password',
            'password' => 'a-long-enough-passphrase',
            'password_confirmation' => 'a-long-enough-passphrase',
        ])
        ->assertSessionHasErrors('current_password');
});

test('a throttled route still stops a user who hammers that one route', function () {
    $user = rateLimitedOwner();

    foreach (range(1, 6) as $ignored) {
        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'not-the-password',
                'password' => 'a-long-enough-passphrase',
                'password_confirmation' => 'a-long-enough-passphrase',
            ])
            ->assertStatus(302);
    }

    $this->actingAs($user)
        ->put(route('user-password.update'), [
            'current_password' => 'not-the-password',
            'password' => 'a-long-enough-passphrase',
            'password_confirmation' => 'a-long-enough-passphrase',
        ])
        ->assertStatus(429);
});

test('every throttled route names its own bucket', function () {
    $unnamed = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (!is_string($middleware) || !Str::startsWith($middleware, 'throttle:')) {
                continue;
            }

            $arguments = explode(',', Str::after($middleware, 'throttle:'));

            if (count($arguments) === 1) {
                continue;
            }

            if (count($arguments) < 3 || trim($arguments[2]) === '') {
                $unnamed[] = ($route->getName() ?? $route->uri()) . ' → ' . $middleware;
            }
        }
    }

    expect($unnamed)->toBe([]);
});
