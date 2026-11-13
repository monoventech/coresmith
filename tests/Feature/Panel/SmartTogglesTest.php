<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Enums\TeamRole;
use App\Jobs\SyncApplicationCron;
use App\Jobs\SyncApplicationProcesses;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApplicationToggledNotification;
use App\Services\Panel\SmartToggles;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function togglesService(): SmartToggles
{
    return app(SmartToggles::class);
}

function togglesApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
    ], $attributes));
}

function togglesRemoved(SyncApplicationProcesses $job): array
{
    return (fn(): array => $this->removed)->call($job);
}

function togglesChecked(SyncApplicationProcesses $job): ?string
{
    return (fn(): ?string => $this->toggle)->call($job);
}

function togglesRails(array $gems, array $attributes = []): array
{
    return ['type' => ApplicationType::Ruby, 'framework' => 'rails', 'stack' => ['runtimes' => [], 'framework' => ['name' => 'rails', 'major' => 8], 'tooling' => null, 'gems' => $gems], ...$attributes];
}

function togglesActivity(Application $application, string $description, array $properties, int $secondsAgo = 0): void
{
    $activity = activity()->performedOn($application)->withProperties($properties)->log($description);
    $activity->forceFill(['created_at' => now()->subSeconds($secondsAgo)])->save();
}

function togglesDescribed(Application $application, string $key): array
{
    return collect(togglesService()->describe($application))->firstWhere('key', $key);
}

test('each framework is offered the toggles it can use', function (array $attributes, array $expected) {
    expect(togglesService()->available(new Application($attributes)))->toBe($expected);
})->with([
    'laravel' => [['type' => ApplicationType::Php, 'framework' => 'laravel'], ['queue_worker', 'scheduler', 'maintenance_mode']],
    'laravel in capitals' => [['type' => ApplicationType::Php, 'framework' => 'Laravel'], ['queue_worker', 'scheduler', 'maintenance_mode']],
    'rails probed before its gems were recorded' => [['type' => ApplicationType::Ruby, 'framework' => 'rails'], ['maintenance_mode']],
    'rails locking sidekiq' => [togglesRails(['sidekiq' => true, 'solid_queue' => false]), ['sidekiq', 'maintenance_mode']],
    'rails locking solid queue' => [togglesRails(['sidekiq' => false, 'solid_queue' => true]), ['solid_queue', 'maintenance_mode']],
    'rails locking both' => [togglesRails(['sidekiq' => true, 'solid_queue' => true]), ['sidekiq', 'solid_queue', 'maintenance_mode']],
    'rails locking neither' => [togglesRails(['sidekiq' => false, 'solid_queue' => false]), ['maintenance_mode']],
    'rails still running a sidekiq it no longer locks, so it can be turned off' => [togglesRails(['sidekiq' => false, 'solid_queue' => true], ['processes' => [['name' => 'sidekiq', 'command' => 'bundle exec sidekiq']]]), ['sidekiq', 'solid_queue', 'maintenance_mode']],
    'rails named on a javascript app' => [togglesRails(['sidekiq' => true, 'solid_queue' => true], ['type' => ApplicationType::Js]), ['maintenance_mode']],
    'sinatra locking sidekiq' => [togglesRails(['sidekiq' => true, 'solid_queue' => false], ['framework' => 'sinatra']), ['maintenance_mode']],
    'sveltekit' => [['type' => ApplicationType::Js, 'framework' => 'sveltekit'], ['maintenance_mode']],
    'plain php' => [['type' => ApplicationType::Php, 'framework' => null], ['maintenance_mode']],
    'static site' => [['type' => ApplicationType::Static, 'framework' => null], ['maintenance_mode']],
]);

test('a toggle reads as on from what the application actually runs', function () {
    $application = new Application([
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'toggles' => ['maintenance_mode' => true],
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
        'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']],
    ]);

    expect(togglesService()->isOn($application, SmartToggles::MAINTENANCE))->toBeTrue()
        ->and(togglesService()->isOn($application, SmartToggles::QUEUE))->toBeTrue()
        ->and(togglesService()->isOn($application, SmartToggles::SCHEDULER))->toBeTrue()
        ->and(togglesService()->isOn($application, SmartToggles::SIDEKIQ))->toBeFalse()
        ->and(togglesService()->isOn($application, SmartToggles::SOLID_QUEUE))->toBeFalse()
        ->and(togglesService()->isOn(new Application(['processes' => [['name' => 'jobs', 'command' => 'bin/jobs']]]), SmartToggles::SOLID_QUEUE))->toBeTrue()
        ->and(togglesService()->isOn($application, 'warp_drive'))->toBeFalse();
});

test('a toggle reads as off when nothing matching is configured', function () {
    $application = new Application([
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'toggles' => ['maintenance_mode' => false],
        'processes' => [['name' => 'horizon', 'command' => 'php artisan horizon']],
        'cron_jobs' => [['schedule' => '0 * * * *', 'command' => 'php artisan backup:run']],
    ]);

    expect(togglesService()->isOn($application, SmartToggles::MAINTENANCE))->toBeFalse()
        ->and(togglesService()->isOn($application, SmartToggles::QUEUE))->toBeFalse()
        ->and(togglesService()->isOn($application, SmartToggles::SCHEDULER))->toBeFalse();
});

test('the description of each toggle names what it runs, not how Coresmith runs it', function () {
    $described = collect(togglesService()->describe(togglesApp(['slug' => 'shop'])))->keyBy('key');

    expect($described->keys()->all())->toBe(['queue_worker', 'scheduler', 'maintenance_mode', 'auto_deploy'])
        ->and($described['queue_worker'])->toMatchArray(['label' => 'Queue worker', 'on' => false, 'disabled' => false])
        ->and($described['queue_worker']['description'])->toBe('php artisan queue:work')
        ->and($described['scheduler']['label'])->toBe('Scheduler')
        ->and($described['maintenance_mode'])->toMatchArray(['label' => 'Maintenance page', 'disabled' => false]);
});

test('the maintenance toggle is disabled while the application is stopped', function () {
    $described = collect(togglesService()->describe(togglesApp(['power_state' => PowerState::Stopped])))->keyBy('key');

    expect($described['maintenance_mode']['disabled'])->toBeTrue()
        ->and($described['maintenance_mode']['description'])->toBe('The application is stopped, so visitors already see one.')
        ->and($described['queue_worker']['disabled'])->toBeFalse();
});

test('turning maintenance on stores it alongside other toggles and re-syncs the route', function () {
    Queue::fake();
    $application = togglesApp(['toggles' => ['something_else' => true]]);

    togglesService()->apply($application, SmartToggles::MAINTENANCE, true);

    expect($application->fresh()->toggles)->toBe(['something_else' => true, 'maintenance_mode' => true]);

    Queue::assertPushed(SyncApplicationRoute::class, 1);
    Queue::assertNotPushed(SyncApplicationProcesses::class);
});

test('turning maintenance off stores false rather than dropping the key', function () {
    Queue::fake();
    $application = togglesApp(['toggles' => ['maintenance_mode' => true]]);

    togglesService()->apply($application, SmartToggles::MAINTENANCE, false);

    expect($application->fresh()->toggles)->toBe(['maintenance_mode' => false]);

    Queue::assertPushed(SyncApplicationRoute::class);
});

test('every applied toggle is logged with its key, label and new state', function () {
    Queue::fake();
    $application = togglesApp();

    togglesService()->apply($application, SmartToggles::SCHEDULER, true);

    $activity = Activity::query()->where('description', 'toggle_changed')->sole();

    expect($activity->subject_id)->toBe($application->id)
        ->and($activity->properties->toArray())->toBeInAnyKeyOrder(['toggle' => 'scheduler', 'label' => 'Scheduler', 'on' => true]);
});

test('turning the queue worker on adds a worker that names no php version and keeps the others', function () {
    Queue::fake();
    $application = togglesApp(['processes' => [['name' => 'horizon', 'command' => 'php artisan horizon']]]);

    togglesService()->apply($application, SmartToggles::QUEUE, true);

    expect($application->fresh()->processes)->toBe([
        ['name' => 'horizon', 'command' => 'php artisan horizon'],
        ['name' => 'queue', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --max-time=3600'],
    ]);

    Queue::assertPushed(SyncApplicationProcesses::class, fn(SyncApplicationProcesses $job): bool => togglesRemoved($job) === []);
});

test('turning the queue worker on twice replaces the worker rather than running two', function () {
    Queue::fake();
    $application = togglesApp(['processes' => [['name' => 'queue', 'command' => 'old command']]]);

    togglesService()->apply($application, SmartToggles::QUEUE, true);

    expect($application->fresh()->processes)->toHaveCount(1)
        ->and($application->fresh()->processes[0]['command'])->toContain('queue:work');
});

test('the stored worker is the same whatever php version the app runs when it is toggled', function (?string $php) {
    Queue::fake();
    $application = togglesApp(['php_version' => $php]);

    togglesService()->apply($application, SmartToggles::QUEUE, true);

    expect($application->fresh()->processes[0]['command'])->toBe('php artisan queue:work --sleep=3 --tries=3 --max-time=3600');
})->with(['no version yet' => [null], 'a version' => ['8.4.20']]);

test('turning the queue worker off removes only that process and tells the sync job to tear its unit down', function () {
    Queue::fake();
    $application = togglesApp(['processes' => [
        ['name' => 'horizon', 'command' => 'php artisan horizon'],
        ['name' => 'queue', 'command' => 'php artisan queue:work'],
    ]]);

    togglesService()->apply($application, SmartToggles::QUEUE, false);

    expect($application->fresh()->processes)->toBe([['name' => 'horizon', 'command' => 'php artisan horizon']]);

    Queue::assertPushed(SyncApplicationProcesses::class, fn(SyncApplicationProcesses $job): bool => togglesRemoved($job) === ['queue']);
});

test('removing the last process clears the list', function () {
    Queue::fake();
    $application = togglesApp(['processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']]]);

    togglesService()->apply($application, SmartToggles::QUEUE, false);

    expect($application->fresh()->processes)->toBeNull();
});

test('turning the scheduler on adds a minutely schedule run and keeps other cron jobs', function () {
    Queue::fake();
    $application = togglesApp(['cron_jobs' => [['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']]]);

    togglesService()->apply($application, SmartToggles::SCHEDULER, true);

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'],
        ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
    ]);

    Queue::assertPushed(SyncApplicationCron::class, 1);
    Queue::assertNotPushed(SyncApplicationProcesses::class);
});

test('turning the scheduler off removes the schedule run and clears an empty list', function () {
    Queue::fake();
    $application = togglesApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);

    togglesService()->apply($application, SmartToggles::SCHEDULER, false);

    expect($application->fresh()->cron_jobs)->toBeNull();

    Queue::assertPushed(SyncApplicationCron::class, 1);
    Queue::assertNotPushed(SyncApplicationProcesses::class);
});

test('turning sidekiq on runs it through bundler with no ruby version named and off tears the unit down', function () {
    Queue::fake();
    $application = togglesApp(togglesRails(['sidekiq' => true, 'solid_queue' => false], ['php_version' => null, 'ruby_version' => '3.4.1']));

    togglesService()->apply($application, SmartToggles::SIDEKIQ, true);

    expect($application->fresh()->processes)->toBe([
        ['name' => 'sidekiq', 'command' => 'bundle exec sidekiq'],
    ]);

    togglesService()->apply($application->fresh(), SmartToggles::SIDEKIQ, false);

    expect($application->fresh()->processes)->toBeNull();

    Queue::assertPushed(SyncApplicationProcesses::class, fn(SyncApplicationProcesses $job): bool => togglesRemoved($job) === ['sidekiq']);
});

test('turning solid queue on runs bin/jobs as its own unit and off tears the unit down', function () {
    Queue::fake();
    $application = togglesApp(togglesRails(['sidekiq' => false, 'solid_queue' => true], ['php_version' => null, 'ruby_version' => '3.4.1']));

    togglesService()->apply($application, SmartToggles::SOLID_QUEUE, true);

    expect($application->fresh()->processes)->toBe([['name' => 'jobs', 'command' => 'bin/jobs']]);

    togglesService()->apply($application->fresh(), SmartToggles::SOLID_QUEUE, false);

    expect($application->fresh()->processes)->toBeNull();

    Queue::assertPushed(SyncApplicationProcesses::class, fn(SyncApplicationProcesses $job): bool => togglesRemoved($job) === ['jobs']);
});

test('the solid queue toggle is described the way the other workers are', function () {
    $described = togglesDescribed(togglesApp(togglesRails(['sidekiq' => false, 'solid_queue' => true], ['slug' => 'shop'])), SmartToggles::SOLID_QUEUE);

    expect($described)->toMatchArray([
        'label' => 'Solid Queue',
        'description' => 'bin/jobs',
        'busy_on' => 'Starting Solid Queue…',
        'busy_off' => 'Stopping the unit and removing it…',
        'on' => false,
        'disabled' => false,
    ])
        ->and(togglesService()->label(SmartToggles::SOLID_QUEUE))->toBe('Solid Queue');
});

test('a worker the lockfile does not lock cannot be turned on', function (string $key, array $gems) {
    Queue::fake();
    $application = togglesApp(togglesRails($gems));

    expect(fn() => togglesService()->apply($application, $key, true))->toThrow(InvalidArgumentException::class);

    expect($application->fresh()->processes)->toBeNull();

    Queue::assertNothingPushed();
})->with([
    'sidekiq' => [SmartToggles::SIDEKIQ, ['sidekiq' => false, 'solid_queue' => true]],
    'solid queue' => [SmartToggles::SOLID_QUEUE, ['sidekiq' => true, 'solid_queue' => false]],
]);

test('turning a worker on asks the sync to check it started, while turning it off or another toggle does not', function () {
    Queue::fake();
    $application = togglesApp();

    togglesService()->apply($application, SmartToggles::QUEUE, true);
    togglesService()->apply($application->fresh(), SmartToggles::QUEUE, false);
    togglesService()->apply($application->fresh(), SmartToggles::SCHEDULER, true);

    $checked = [];

    Queue::assertPushed(SyncApplicationProcesses::class, function (SyncApplicationProcesses $job) use (&$checked): bool {
        $checked[] = togglesChecked($job);

        return true;
    });

    expect($checked)->toBe(['queue_worker', null]);

    Queue::assertPushed(SyncApplicationCron::class, 1);
});

test('the scheduler toggle says how often the scheduler runs', function (?array $jobs, string $description) {
    expect(togglesDescribed(togglesApp(['cron_jobs' => $jobs]), SmartToggles::SCHEDULER))
        ->toMatchArray(['description' => $description, 'busy_on' => 'Adding the scheduler to the cron file…', 'busy_off' => 'Taking the scheduler out of the cron file…']);
})->with([
    'off' => [null, 'artisan schedule:run every minute · cron'],
    'every minute' => [[['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']], 'artisan schedule:run every minute · cron'],
    'every five minutes' => [[['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']], 'artisan schedule:run every 5 minutes · cron'],
    'a custom schedule' => [[['schedule' => '*/5 9-17 * * 1-5', 'command' => 'php artisan schedule:run']], 'artisan schedule:run on a custom schedule · cron'],
]);

test('a worker is starting from the moment it is turned on until the sync reports how it went', function () {
    $application = togglesApp(['power_state' => PowerState::Active, 'processes' => [['name' => 'queue', 'command' => SmartToggles::QUEUE_COMMAND]]]);

    togglesActivity($application, 'toggle_changed', ['toggle' => 'queue_worker', 'label' => 'Queue worker', 'on' => true], secondsAgo: 5);

    expect(togglesDescribed($application, SmartToggles::QUEUE))->toMatchArray(['starting' => true, 'failure' => null])
        ->and(togglesDescribed($application, SmartToggles::SCHEDULER))->toMatchArray(['starting' => false, 'failure' => null]);

    togglesActivity($application, 'toggle_started', ['toggle' => 'queue_worker', 'label' => 'Queue worker']);

    expect(togglesDescribed($application, SmartToggles::QUEUE))->toMatchArray(['starting' => false, 'failure' => null]);
});

test('a worker is not waited on once the sync could have finished, or while the app is not running', function (array $attributes, int $secondsAgo) {
    $application = togglesApp(['processes' => [['name' => 'queue', 'command' => SmartToggles::QUEUE_COMMAND]], ...$attributes]);

    togglesActivity($application, 'toggle_changed', ['toggle' => 'queue_worker', 'label' => 'Queue worker', 'on' => true], $secondsAgo);

    expect(togglesDescribed($application, SmartToggles::QUEUE)['starting'])->toBeFalse();
})->with([
    'past the start window' => [['power_state' => PowerState::Active], SmartToggles::START_WINDOW_SECONDS + 1],
    'stopped' => [['power_state' => PowerState::Stopped], 5],
    'sleeping' => [['power_state' => PowerState::Sleeping], 5],
]);

test('a worker that did not start is described with the reason until its toggle is changed again', function () {
    $application = togglesApp(['power_state' => PowerState::Active]);
    $error = "The queue process didn't start (activating). The app's environment file is missing from the server. Deploy again to write it.";

    togglesActivity($application, 'toggle_changed', ['toggle' => 'queue_worker', 'label' => 'Queue worker', 'on' => true], secondsAgo: 20);
    togglesActivity($application, 'toggle_start_failed', ['toggle' => 'queue_worker', 'label' => 'Queue worker', 'error' => $error], secondsAgo: 10);

    expect(togglesDescribed($application, SmartToggles::QUEUE))->toMatchArray(['on' => false, 'starting' => false, 'failure' => $error]);

    $application->forceFill(['processes' => [['name' => 'queue', 'command' => SmartToggles::QUEUE_COMMAND]]])->save();
    togglesActivity($application, 'toggle_changed', ['toggle' => 'queue_worker', 'label' => 'Queue worker', 'on' => true]);

    expect(togglesDescribed($application, SmartToggles::QUEUE))->toMatchArray(['on' => true, 'starting' => true, 'failure' => null]);
});

test('a worker that did not start more than a day ago is no longer described as failing', function () {
    $application = togglesApp();

    togglesActivity($application, 'toggle_start_failed', ['toggle' => 'queue_worker', 'label' => 'Queue worker', 'error' => 'The queue process didn\'t start.'], secondsAgo: 86400 + 60);

    expect(togglesDescribed($application, SmartToggles::QUEUE)['failure'])->toBeNull();
});

test('a worker turned on reports how it started after the change, even when the sync runs at once', function () {
    $application = togglesApp(['power_state' => PowerState::Active]);
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => new FakeSSHTransport);

    try {
        togglesService()->apply($application, SmartToggles::QUEUE, true);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect(Activity::query()->whereIn('description', ['toggle_changed', 'toggle_started'])->orderBy('id')->pluck('description')->all())->toBe(['toggle_changed', 'toggle_started'])
        ->and(togglesDescribed($application->fresh(), SmartToggles::QUEUE))->toMatchArray(['on' => true, 'starting' => false, 'failure' => null]);
});

test('a unit that did not start is named by its worker label, and the unit that serves the app by none', function (string $unit, ?string $worker) {
    $application = new Application(['slug' => 'shop', 'type' => ApplicationType::Ruby, 'framework' => 'rails', 'ruby_version' => '3.4.10']);

    expect(togglesService()->failedWorker($application, $unit))->toBe($worker);
})->with([
    'the web unit' => ['coresmith-app-shop-web', null],
    'sidekiq' => ['coresmith-app-shop-sidekiq', 'Sidekiq'],
    'solid queue' => ['coresmith-app-shop-jobs', 'Solid Queue'],
    'the queue worker' => ['coresmith-app-shop-queue', 'Queue worker'],
    'a worker no toggle made' => ['coresmith-app-shop-horizon', 'Horizon'],
]);

test('a toggle the application does not offer is refused and changes nothing', function (string $key) {
    Queue::fake();
    $application = togglesApp();

    expect(fn() => togglesService()->apply($application, $key, true))->toThrow(InvalidArgumentException::class);

    expect($application->fresh()->processes)->toBeNull()
        ->and(Activity::query()->where('description', 'toggle_changed')->exists())->toBeFalse();

    Queue::assertNothingPushed();
})->with(['sidekiq', 'solid_queue', 'warp_drive']);

test('teammates who want toggle changes are told, and the person who made it is not', function () {
    Queue::fake();
    Notification::fake();
    $team = Team::factory()->create();
    $actor = User::factory()->create(['name' => 'Ada Lovelace']);
    $teammate = User::factory()->create();
    $optedOut = User::factory()->create(['notification_preferences' => ['toggle_changes' => false]]);

    foreach ([$actor, $teammate, $optedOut] as $member) {
        $team->members()->attach($member, ['role' => TeamRole::Admin->value]);
    }

    $application = togglesApp(['team_id' => $team->id, 'name' => 'Shop']);

    $this->actingAs($actor);

    togglesService()->apply($application, SmartToggles::QUEUE, true);

    Notification::assertSentTo($teammate, ApplicationToggledNotification::class, fn(ApplicationToggledNotification $notification): bool => $notification->change === 'Queue worker turned on'
        && $notification->actor === 'Ada Lovelace'
        && $notification->application->is($application));
    Notification::assertNotSentTo($actor, ApplicationToggledNotification::class);
    Notification::assertNotSentTo($optedOut, ApplicationToggledNotification::class);
});
