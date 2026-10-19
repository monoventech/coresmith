<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApplicationToggledNotification;
use App\Services\Panel\SmartToggles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

function autoDeployToggleMember(Team $team, TeamRole $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function autoDeployToggleApp(Team $team, array $attributes = []): Application
{
    $server = Server::factory()->for($team)->create(['name' => 'forge-01']);

    return Application::factory()->for($team)->for($server)->create(array_merge([
        'repository_url' => 'https://github.com/monoven/site.git',
        'repository_branch' => 'main',
    ], $attributes));
}

function autoDeployToggleUrl(Application $application): string
{
    return route('servers.applications.toggles.update', [$application->server_id, $application, 'auto_deploy']);
}

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
});

test('auto deploy is offered on apps with a repository', function () {
    $team = Team::factory()->create();
    $toggles = app(SmartToggles::class);

    expect(collect($toggles->describe(autoDeployToggleApp($team)->fresh()))->last())->toBe([
        'key' => 'auto_deploy',
        'label' => 'Auto deploy',
        'description' => 'Deploy main whenever it changes',
        'busy_on' => 'Turning on auto deploy…',
        'busy_off' => 'Turning off auto deploy…',
        'on' => false,
        'disabled' => false,
        'starting' => false,
        'failure' => null,
    ])
        ->and(collect($toggles->describe(autoDeployToggleApp($team, ['repository_url' => null])->fresh()))->pluck('key')->all())->not->toContain('auto_deploy');
});

test('auto deploy describes how the app follows its branch once it is on', function () {
    $described = collect(app(SmartToggles::class)->describe(autoDeployToggleApp(Team::factory()->create(), ['quick_deploy' => true])->fresh()))->keyBy('key');

    expect($described['auto_deploy']['on'])->toBeTrue()
        ->and($described['auto_deploy']['description'])->toBe('Checks main for new commits every 5 minutes from forge-01. This app is not linked to a GitHub connection, so pushes are not delivered here.');
});

test('turning auto deploy on and off saves it and clears the last check', function () {
    $team = Team::factory()->create();
    $application = autoDeployToggleApp($team, [
        'repository_head' => str_repeat('b', 40),
        'polled_at' => now()->subMinute(),
        'poll_error' => 'GitHub did not answer.',
    ]);
    $admin = autoDeployToggleMember($team, TeamRole::Admin);

    $this->actingAs($admin)
        ->put(autoDeployToggleUrl($application), ['on' => true])
        ->assertRedirect()
        ->assertSessionHas('success', 'Saved. Auto deploy is on, main deploys whenever it changes.');

    expect($application->fresh()->only(['quick_deploy', 'polled_at', 'poll_error']))->toBe(['quick_deploy' => true, 'polled_at' => null, 'poll_error' => null])
        ->and(Activity::query()->where('description', 'toggle_changed')->sole()->properties->toArray())->toBe(['toggle' => 'auto_deploy', 'label' => 'Auto deploy', 'on' => true]);

    Queue::assertNotPushed(DeployApplication::class);

    $application->fresh()->forceFill(['polled_at' => now(), 'poll_error' => 'GitHub did not answer.'])->save();

    $this->actingAs($admin)
        ->put(autoDeployToggleUrl($application), ['on' => false])
        ->assertSessionHas('success', 'Saved. Auto deploy is off, deploy from this page when you are ready.');

    expect($application->fresh()->only(['quick_deploy', 'polled_at', 'poll_error']))->toBe(['quick_deploy' => false, 'polled_at' => null, 'poll_error' => null]);
});

test('the email for auto deploy does not say no deployment was needed, other toggles still do', function (string $toggle, string $line) {
    Notification::fake();
    $team = Team::factory()->create();
    $application = autoDeployToggleApp($team);
    $teammate = autoDeployToggleMember($team, TeamRole::Member);

    $this->actingAs(autoDeployToggleMember($team, TeamRole::Admin, ['name' => 'Ada Lovelace']))
        ->put(route('servers.applications.toggles.update', [$application->server_id, $application, $toggle]), ['on' => true])
        ->assertSessionHas('success');

    Notification::assertSentTo($teammate, ApplicationToggledNotification::class, fn(ApplicationToggledNotification $notification): bool => $notification->toMail($teammate)->introLines === [$line]);
})->with([
    'auto deploy' => ['auto_deploy', 'Ada Lovelace made the change from the panel.'],
    'maintenance page' => ['maintenance_mode', 'Ada Lovelace made the change from the panel. No deployment was needed.'],
]);

test('a member cannot change auto deploy', function () {
    $team = Team::factory()->create();
    $application = autoDeployToggleApp($team);

    $this->actingAs(autoDeployToggleMember($team, TeamRole::Member))
        ->put(autoDeployToggleUrl($application), ['on' => true])
        ->assertForbidden();

    expect($application->fresh()->quick_deploy)->toBeFalse()
        ->and(Activity::query()->where('description', 'toggle_changed')->exists())->toBeFalse();
});
