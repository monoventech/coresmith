<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Preferences;

test('a new user has failures, disk warnings, server problems, provisioning and toggle changes on and the digest off', function () {
    expect(Preferences::for(new User))->toBe([
        'deploy_failed' => true,
        'disk_pressure' => true,
        'server_alerts' => true,
        'provisioning' => true,
        'toggle_changes' => true,
        'weekly_digest' => false,
    ]);
});

test('stored preferences override the defaults except for deploy failures', function () {
    $user = new User(['name' => 'Ada']);
    $user->notification_preferences = [
        'deploy_failed' => false,
        'disk_pressure' => false,
        'weekly_digest' => 1,
        'something_else' => true,
    ];

    expect(Preferences::for($user))->toBe([
        'deploy_failed' => true,
        'disk_pressure' => false,
        'server_alerts' => true,
        'provisioning' => true,
        'toggle_changes' => true,
        'weekly_digest' => true,
    ])->and(Preferences::wants($user, Preferences::DEPLOY_FAILED))->toBeTrue()
        ->and(Preferences::wants($user, Preferences::DISK_PRESSURE))->toBeFalse()
        ->and(Preferences::wants($user, 'something_else'))->toBeFalse();
});

test('the notifications page lists every preference with its state and whether it can change', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'notification_preferences' => ['disk_pressure' => false, 'deploy_failed' => false]]);

    $this->actingAs($user)
        ->get(route('settings.notifications'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('settings/Notifications')
            ->where('email', 'ada@example.com')
            ->where('toggles', [
                ['key' => 'deploy_failed', 'label' => 'Deploy failed', 'description' => 'Email immediately when any phase fails', 'on' => true, 'locked' => true],
                ['key' => 'disk_pressure', 'label' => 'Disk above 80%', 'description' => 'Server disk warnings, at most once a day', 'on' => false, 'locked' => false],
                ['key' => 'server_alerts', 'label' => 'Server problems', 'description' => 'A server stops answering, runs out of memory, or a certificate is about to expire', 'on' => true, 'locked' => false],
                ['key' => 'provisioning', 'label' => 'Provisioning', 'description' => 'When a server is ready, or provisioning stops partway', 'on' => true, 'locked' => false],
                ['key' => 'toggle_changes', 'label' => 'Toggle changes', 'description' => 'When someone stops, starts or puts an app into maintenance', 'on' => true, 'locked' => false],
                ['key' => 'weekly_digest', 'label' => 'Weekly digest', 'description' => 'Deploys, rollbacks and warnings, every Monday', 'on' => false, 'locked' => false],
            ]));
});

test('a preference can be turned on and off and is stored with the rest', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('settings.notifications'))
        ->put(route('settings.notifications.update'), ['key' => 'weekly_digest', 'on' => true])
        ->assertRedirect(route('settings.notifications'))
        ->assertSessionHas('success', 'Saved.');

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), ['key' => 'disk_pressure', 'on' => false])
        ->assertSessionHas('success', 'Saved.');

    expect($user->fresh()->notification_preferences)->toBeInAnyKeyOrder([
        'deploy_failed' => true,
        'disk_pressure' => false,
        'server_alerts' => true,
        'provisioning' => true,
        'toggle_changes' => true,
        'weekly_digest' => true,
    ]);
});

test('deploy failure emails cannot be turned off', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), ['key' => 'deploy_failed', 'on' => false])
        ->assertSessionHas('error', "Failures always notify. That one can't be turned off.")
        ->assertSessionMissing('success');

    expect($user->fresh()->notification_preferences)->toBeNull()
        ->and(Preferences::wants($user->fresh(), Preferences::DEPLOY_FAILED))->toBeTrue();
});

test('a preference change is validated', function (array $payload, string $field) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('settings.notifications.update'), $payload)
        ->assertSessionHasErrors($field);

    expect($user->fresh()->notification_preferences)->toBeNull();
})->with([
    'an unknown preference' => [['key' => 'marketing', 'on' => true], 'key'],
    'a missing preference' => [['on' => true], 'key'],
    'a missing state' => [['key' => 'weekly_digest'], 'on'],
    'a state that is not a boolean' => [['key' => 'weekly_digest', 'on' => 'sometimes'], 'on'],
]);

test('notification settings require authentication', function () {
    $this->get(route('settings.notifications'))->assertRedirect(route('login'));
    $this->put(route('settings.notifications.update'), ['key' => 'weekly_digest', 'on' => true])->assertRedirect(route('login'));
});
