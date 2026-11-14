<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Enums\TeamRole;
use App\Jobs\SyncApplicationProcesses;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApplicationToggledNotification;
use App\Services\Panel\SmartToggles;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

function toggleRouteMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function toggleRouteApp(Team $team, array $attributes = []): Application
{
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);

    return Application::factory()->for($team)->for($server)->create(array_merge([
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
    ], $attributes));
}

function toggleRouteUrl(Application $application, string $toggle): string
{
    return route('servers.applications.toggles.update', [$application->server_id, $application, $toggle]);
}

test('an admin can turn the queue worker on without a deployment', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team);
    $show = route('servers.applications.show', [$application->server_id, $application]);

    $this->actingAs(toggleRouteMember($team, TeamRole::Admin))
        ->from($show)
        ->put(toggleRouteUrl($application, 'queue_worker'), ['on' => true])
        ->assertRedirect($show)
        ->assertSessionHas('success', 'Saved. Queue worker is starting on web-1, no deployment needed.');

    expect(collect($application->fresh()->processes)->pluck('name')->all())->toBe(['queue']);

    Queue::assertPushed(SyncApplicationProcesses::class, 1);
});

test('turning a process toggle off reports that nothing else was touched', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team, ['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);

    $this->actingAs(toggleRouteMember($team, TeamRole::Owner))
        ->put(toggleRouteUrl($application, 'scheduler'), ['on' => false])
        ->assertSessionHas('success', 'Saved. Scheduler stopped. Nothing else was touched.');

    expect($application->fresh()->cron_jobs)->toBeNull();
});

test('the maintenance page can be turned on and off', function (bool $on, string $message) {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team, ['toggles' => ['maintenance_mode' => !$on]]);

    $this->actingAs(toggleRouteMember($team, TeamRole::Admin))
        ->put(toggleRouteUrl($application, 'maintenance_mode'), ['on' => $on])
        ->assertSessionHas('success', $message);

    expect($application->fresh()->toggles['maintenance_mode'])->toBe($on);

    Queue::assertPushed(SyncApplicationRoute::class);
})->with([
    'on' => [true, 'Saved. Maintenance page is on. Visitors see it now, your processes keep running.'],
    'off' => [false, 'Saved. Maintenance page is off. Traffic reaches the app again.'],
]);

test('a member cannot change a toggle', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team);

    $this->actingAs(toggleRouteMember($team, TeamRole::Member))
        ->put(toggleRouteUrl($application, 'queue_worker'), ['on' => true])
        ->assertForbidden();

    expect($application->fresh()->processes)->toBeNull()
        ->and(Activity::query()->where('description', 'toggle_changed')->exists())->toBeFalse();

    Queue::assertNothingPushed();
});

test('another team cannot change a toggle', function () {
    Queue::fake();
    $application = toggleRouteApp(Team::factory()->create());

    $this->actingAs(toggleRouteMember(Team::factory()->create(), TeamRole::Owner))
        ->put(toggleRouteUrl($application, 'maintenance_mode'), ['on' => true])
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('a toggle the application does not offer is a not found', function (string $toggle) {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team);

    $this->actingAs(toggleRouteMember($team, TeamRole::Owner))
        ->put(toggleRouteUrl($application, $toggle), ['on' => true])
        ->assertNotFound();

    Queue::assertNothingPushed();
})->with(['sidekiq', 'warp_drive', 'Queue-Worker']);

test('a toggle addressed through another server is a not found', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team);
    $other = Server::factory()->for($team)->create();

    $this->actingAs(toggleRouteMember($team, TeamRole::Owner))
        ->put(route('servers.applications.toggles.update', [$other, $application, 'queue_worker']), ['on' => true])
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('the requested state must be a boolean', function (array $payload) {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team);

    $this->actingAs(toggleRouteMember($team, TeamRole::Owner))
        ->put(toggleRouteUrl($application, 'queue_worker'), $payload)
        ->assertSessionHasErrors('on');

    Queue::assertNothingPushed();
})->with([
    'missing' => [[]],
    'not a boolean' => [['on' => 'maybe']],
]);

test('the maintenance page cannot be toggled while the application is stopped', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team, ['power_state' => PowerState::Stopped, 'toggles' => ['maintenance_mode' => false]]);

    $this->actingAs(toggleRouteMember($team, TeamRole::Owner))
        ->put(toggleRouteUrl($application, 'maintenance_mode'), ['on' => true])
        ->assertSessionHas('error', 'The app is stopped, so visitors already see the maintenance page. Start it first.');

    expect($application->fresh()->toggles)->toBe(['maintenance_mode' => false])
        ->and(Activity::query()->where('description', 'toggle_changed')->exists())->toBeFalse();

    Queue::assertNothingPushed();
});

test('a stopped application can still have its worker toggled for when it starts', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team, ['power_state' => PowerState::Stopped]);

    $this->actingAs(toggleRouteMember($team, TeamRole::Owner))
        ->put(toggleRouteUrl($application, 'queue_worker'), ['on' => true])
        ->assertSessionHas('success');

    Queue::assertPushed(SyncApplicationProcesses::class);
});

test('asking for the state a toggle is already in changes nothing and says nothing', function (string $toggle, array $attributes, bool $on) {
    Queue::fake();
    Notification::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team, $attributes);
    toggleRouteMember($team, TeamRole::Admin);

    $this->actingAs(toggleRouteMember($team, TeamRole::Owner))
        ->put(toggleRouteUrl($application, $toggle), ['on' => $on])
        ->assertRedirect()
        ->assertSessionMissing('success')
        ->assertSessionMissing('error');

    expect(Activity::query()->where('description', 'toggle_changed')->exists())->toBeFalse();

    Queue::assertNothingPushed();
    Notification::assertNothingSent();
})->with([
    'queue already on' => ['queue_worker', ['processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]], true],
    'queue already off' => ['queue_worker', [], false],
    'maintenance already on' => ['maintenance_mode', ['toggles' => ['maintenance_mode' => true]], true],
    'scheduler already off' => ['scheduler', [], false],
]);

test('a toggle change notifies the rest of the team but not the person who made it', function () {
    Queue::fake();
    Notification::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team, ['name' => 'Shop']);
    $actor = toggleRouteMember($team, TeamRole::Owner);
    $teammate = toggleRouteMember($team, TeamRole::Member);

    $this->actingAs($actor)->put(toggleRouteUrl($application, 'maintenance_mode'), ['on' => true]);

    Notification::assertSentTo($teammate, ApplicationToggledNotification::class, fn(ApplicationToggledNotification $notification): bool => $notification->change === 'Maintenance page turned on' && $notification->actor === $actor->name);
    Notification::assertNotSentTo($actor, ApplicationToggledNotification::class);
});

test('toggles require authentication', function () {
    $application = toggleRouteApp(Team::factory()->create());

    $this->put(toggleRouteUrl($application, 'queue_worker'), ['on' => true])->assertRedirect(route('login'));
});

test('the scheduler cannot be turned on when the app already has 20 cron jobs', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $jobs = array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan report:{$minute}"], range(0, 19));
    $application = toggleRouteApp($team, ['cron_jobs' => $jobs]);

    $this->actingAs(toggleRouteMember($team, TeamRole::Admin))
        ->put(toggleRouteUrl($application, 'scheduler'), ['on' => true])
        ->assertSessionHas('error', 'This app already has 20 cron jobs. Remove one, then turn the scheduler on.');

    expect($application->fresh()->cron_jobs)->toHaveCount(20);

    Queue::assertNothingPushed();
});

test('the scheduler is refused when the list as saved now is full, even if the page loaded before', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team, ['cron_jobs' => null]);
    $this->app->afterResolving(SmartToggles::class, function () use ($application): void {
        Application::query()->whereKey($application->id)->update(['cron_jobs' => json_encode(array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan report:{$minute}"], range(0, 19)))]);
    });

    $this->actingAs(toggleRouteMember($team, TeamRole::Admin))
        ->put(toggleRouteUrl($application, 'scheduler'), ['on' => true])
        ->assertSessionHas('error', 'This app already has 20 cron jobs. Remove one, then turn the scheduler on.');

    expect($application->fresh()->cron_jobs)->toHaveCount(20);

    Queue::assertNothingPushed();
});

test('the scheduler can still be turned off when the app has 20 cron jobs', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $jobs = array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan report:{$minute}"], range(0, 18));
    $application = toggleRouteApp($team, ['cron_jobs' => [...$jobs, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);

    $this->actingAs(toggleRouteMember($team, TeamRole::Admin))
        ->put(toggleRouteUrl($application, 'scheduler'), ['on' => false])
        ->assertSessionHas('success');

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder($jobs);
});

test('the cron job limit only stops the scheduler', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $jobs = array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan report:{$minute}"], range(0, 19));
    $application = toggleRouteApp($team, ['cron_jobs' => $jobs]);

    $this->actingAs(toggleRouteMember($team, TeamRole::Admin))
        ->put(toggleRouteUrl($application, 'maintenance_mode'), ['on' => true])
        ->assertSessionHas('success');
});

test('turning the scheduler on says when it runs, as the cron list does', function (array $attributes, string $message) {
    Queue::fake();
    $team = Team::factory()->create();
    $application = toggleRouteApp($team, ['cron_jobs' => null, 'current_release' => '20261019-120000', ...$attributes]);

    $this->actingAs(toggleRouteMember($team, TeamRole::Admin))
        ->put(toggleRouteUrl($application, 'scheduler'), ['on' => true])
        ->assertSessionHas('success', $message);
})->with([
    'an app that is running' => [['power_state' => PowerState::Active], 'Saved. Scheduler is starting on web-1, no deployment needed.'],
    'an app that is stopped' => [['power_state' => PowerState::Stopped], 'Saved. It runs once you start the app.'],
    'an app with nothing live yet' => [['current_release' => null], 'Saved. It runs after the first deploy.'],
]);
