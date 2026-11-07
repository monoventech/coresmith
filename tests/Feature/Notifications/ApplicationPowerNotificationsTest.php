<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Enums\TeamRole;
use App\Jobs\StartApplication;
use App\Jobs\StopApplication;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApplicationToggledNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

function powerNotifyTeam(): array
{
    $team = Team::factory()->create();
    $actor = User::factory()->create(['name' => 'Ada Lovelace']);
    $teammate = User::factory()->create();
    $optedOut = User::factory()->create(['notification_preferences' => ['toggle_changes' => false]]);

    $team->members()->attach($actor, ['role' => TeamRole::Admin->value]);
    $team->members()->attach($teammate, ['role' => TeamRole::Member->value]);
    $team->members()->attach($optedOut, ['role' => TeamRole::Member->value]);

    return [$team, $actor->fresh(), $teammate, $optedOut];
}

function powerNotifyApp(Team $team, array $attributes = []): Application
{
    return Application::factory()->for($team)->for(Server::factory()->for($team))->create(array_merge(['name' => 'Shop'], $attributes));
}

test('a power change emails the rest of the team but not the person who made it', function (string $route, array $attributes, array $payload, string $method, string $change) {
    Queue::fake();
    Notification::fake();
    [$team, $actor, $teammate, $optedOut] = powerNotifyTeam();
    $application = powerNotifyApp($team, $attributes);

    $this->actingAs($actor)
        ->{$method}(route($route, [$application->server_id, $application]), $payload)
        ->assertSessionHas('success');

    Notification::assertSentTo($teammate, ApplicationToggledNotification::class, fn(ApplicationToggledNotification $notification): bool => $notification->change === $change
        && $notification->actor === 'Ada Lovelace'
        && $notification->application->is($application));
    Notification::assertNotSentTo($actor, ApplicationToggledNotification::class);
    Notification::assertNotSentTo($optedOut, ApplicationToggledNotification::class);
})->with([
    'stop' => ['servers.applications.stop', ['power_state' => PowerState::Active], [], 'post', 'The app was stopped'],
    'start' => ['servers.applications.start', ['power_state' => PowerState::Stopped], [], 'post', 'The app was started'],
    'maintenance on' => ['servers.applications.maintenance', [], ['enabled' => true], 'put', 'Maintenance page turned on'],
    'maintenance off' => ['servers.applications.maintenance', ['toggles' => ['maintenance_mode' => true]], ['enabled' => false], 'put', 'Maintenance page turned off'],
]);

test('stopping an application queues the stop and the route change and logs it', function () {
    Queue::fake();
    Notification::fake();
    [$team, $actor] = powerNotifyTeam();
    $application = powerNotifyApp($team, ['power_state' => PowerState::Active]);

    $this->actingAs($actor)->post(route('servers.applications.stop', [$application->server_id, $application]));

    expect($application->fresh()->power_state)->toBe(PowerState::Stopped)
        ->and(Activity::query()->where('description', 'application_stopped')->where('subject_id', $application->id)->exists())->toBeTrue();

    Queue::assertPushed(StopApplication::class);
    Queue::assertPushed(SyncApplicationRoute::class);
    Queue::assertNotPushed(StartApplication::class);
});

test('asking for the power state an application is already in notifies nobody', function (string $route, PowerState $state) {
    Queue::fake();
    Notification::fake();
    [$team, $actor] = powerNotifyTeam();
    $application = powerNotifyApp($team, ['power_state' => $state]);

    $this->actingAs($actor)
        ->post(route($route, [$application->server_id, $application]))
        ->assertSessionHas('success');

    Notification::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    'already stopped' => ['servers.applications.stop', PowerState::Stopped],
    'already running' => ['servers.applications.start', PowerState::Active],
]);

test('a member cannot change power and nobody is told anything', function () {
    Queue::fake();
    Notification::fake();
    [$team,, $teammate] = powerNotifyTeam();
    $application = powerNotifyApp($team);

    $this->actingAs($teammate)
        ->post(route('servers.applications.stop', [$application->server_id, $application]))
        ->assertForbidden();

    Notification::assertNothingSent();
});
