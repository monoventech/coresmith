<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Exceptions\CronListRefused;
use App\Jobs\SyncApplicationCron;
use App\Models\Application;
use App\Services\Panel\CronJobs;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Queue::fake();
});

function cronJobsService(): CronJobs
{
    return app(CronJobs::class);
}

function cronJobsApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'framework' => 'laravel',
        'current_release' => '20261019-120000',
        'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']],
        ...$attributes,
    ]);
}

function cronJobsLogged(): int
{
    return Activity::query()->where('description', 'like', 'cron_job_%')->count();
}

test('a job is known by a hash of what it is, so a stale copy no longer matches', function () {
    $entry = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup'];

    expect(CronJobs::id($entry))->toMatch('/^[0-9a-f]{16}\z/')
        ->and(CronJobs::id([...$entry, 'schedule' => '0 4 * * *']))->not->toBe(CronJobs::id($entry))
        ->and(CronJobs::id([...$entry, 'command' => 'php artisan backup:run --only-db']))->not->toBe(CronJobs::id($entry))
        ->and(CronJobs::id([...$entry, 'description' => 'Backup']))->not->toBe(CronJobs::id($entry))
        ->and(CronJobs::id(['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']))->toBe(CronJobs::id(['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => null]));
});

test('adding a job keeps the others, records who added what, and sends the list to the server', function () {
    $application = cronJobsApp();

    $entry = cronJobsService()->add($application, ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup']);

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([
        ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup'],
    ])
        ->and($entry['id'])->toBe(CronJobs::id($entry))
        ->and($application->fresh()->cron_pending_since)->not->toBeNull()
        ->and(Activity::query()->where('description', CronJobs::ADDED)->sole()->properties->toArray())->toBeInAnyKeyOrder(['schedule' => '0 3 * * *', 'description' => 'Nightly backup', 'command' => 'php artisan backup:run']);

    Queue::assertPushed(SyncApplicationCron::class, 1);
});

test('a job with no description is stored without one', function () {
    $application = cronJobsApp(['cron_jobs' => null]);

    cronJobsService()->add($application, ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => null]);

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']]);
});

test('changing a job keeps its place in the list', function () {
    $jobs = [
        ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'],
        ['schedule' => '0 4 * * *', 'command' => 'php artisan prune'],
    ];
    $application = cronJobsApp(['cron_jobs' => $jobs]);

    $updated = cronJobsService()->update($application, CronJobs::id($jobs[1]), ['schedule' => '30 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Backup']);

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([
        $jobs[0],
        ['schedule' => '30 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Backup'],
        $jobs[2],
    ])
        ->and($updated['id'])->toBe(CronJobs::id($updated))
        ->and(Activity::query()->where('description', CronJobs::UPDATED)->sole()->properties->toArray())->toBeInAnyKeyOrder(['schedule' => '30 3 * * *', 'from' => '0 3 * * *', 'description' => 'Backup', 'command' => 'php artisan backup:run']);

    Queue::assertPushed(SyncApplicationCron::class, 1);
});

test('a change that changes nothing saves and sends nothing', function () {
    $job = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'];
    $application = cronJobsApp(['cron_jobs' => [$job]]);

    $same = cronJobsService()->update($application, CronJobs::id($job), [...$job, 'description' => null]);

    expect($same)->toMatchArray($job)
        ->and($application->fresh()->cron_pending_since)->toBeNull()
        ->and(cronJobsLogged())->toBe(0);

    Queue::assertNothingPushed();
});

test('an id that matches nothing changes nothing', function () {
    $application = cronJobsApp();

    expect(cronJobsService()->update($application, '0000000000000000', ['schedule' => '0 3 * * *', 'command' => 'true', 'description' => null]))->toBeNull()
        ->and(cronJobsService()->remove($application, '0000000000000000'))->toBeNull()
        ->and($application->fresh()->cron_jobs)->toHaveCount(1)
        ->and(cronJobsLogged())->toBe(0);

    Queue::assertNothingPushed();
});

test('the list skips what cron cannot run and tidies descriptions', function () {
    $application = cronJobsApp(['cron_jobs' => [
        'php artisan inspire',
        ['command' => 'php artisan inspire'],
        ['schedule' => '0 3 * * *', 'command' => '   '],
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => '  Nightly backup  '],
        ['schedule' => '0 4 * * *', 'command' => 'php artisan prune', 'description' => '   '],
    ]]);

    expect(cronJobsService()->entries($application))->toBe([
        ['id' => CronJobs::id(['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup']), 'schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup'],
        ['id' => CronJobs::id(['schedule' => '0 4 * * *', 'command' => 'php artisan prune']), 'schedule' => '0 4 * * *', 'command' => 'php artisan prune', 'description' => null],
    ]);
});

test('a job is found by its id, and the scheduler by its command', function () {
    $backup = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup'];
    $application = cronJobsApp(['cron_jobs' => [$backup]]);

    expect(cronJobsService()->find($application, CronJobs::id($backup)))->toBe(['id' => CronJobs::id($backup), ...$backup])
        ->and(cronJobsService()->find($application, '0000000000000000'))->toBeNull()
        ->and(cronJobsService()->isScheduler(['schedule' => '*/5 * * * *', 'command' => "  php artisan schedule:run\n"]))->toBeTrue()
        ->and(cronJobsService()->isScheduler(['schedule' => '* * * * *', 'command' => 'php artisan schedule:run --verbose']))->toBeFalse()
        ->and(cronJobsService()->hasScheduler($application))->toBeFalse()
        ->and(cronJobsService()->hasScheduler(cronJobsApp(['slug' => 'blog'])))->toBeTrue();
});

test('a change clears the last failure, since the new list is on its way', function () {
    $application = cronJobsApp(['cron_error' => 'web-1 refused the change.']);

    cronJobsService()->add($application, ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => null]);

    expect($application->fresh()->cron_error)->toBeNull();
});

test('removing a job keeps the rest in order', function () {
    $jobs = [
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'],
        ['schedule' => '0 4 * * *', 'command' => 'php artisan prune'],
        ['schedule' => '0 5 * * *', 'command' => 'php artisan report'],
    ];
    $application = cronJobsApp(['cron_jobs' => $jobs]);

    cronJobsService()->remove($application, CronJobs::id($jobs[0]));

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([$jobs[1], $jobs[2]]);
});

test('the feed keeps the first 300 characters of a command, with secrets taken out', function () {
    $application = cronJobsApp(['cron_jobs' => null]);

    cronJobsService()->add($application, ['schedule' => '0 3 * * *', 'command' => 'API_TOKEN=hunter2hunter2 php artisan report ' . str_repeat('y', 400), 'description' => null]);

    expect(Activity::query()->where('description', CronJobs::ADDED)->sole()->properties['command'])
        ->toBe(substr('API_TOKEN=[REDACTED] php artisan report ' . str_repeat('y', 400), 0, 300));
});

test('a one-line command of exactly 80 characters is its own title', function () {
    $command = 'php artisan report:build ' . str_repeat('x', 55);
    $application = cronJobsApp(['cron_jobs' => [['schedule' => '0 3 * * *', 'command' => $command]]]);

    expect(cronJobsService()->describe($application)['jobs'][0]['title'])->toBe($command);
});

test('removing the last job leaves no list at all', function () {
    $application = cronJobsApp();

    $removed = cronJobsService()->remove($application, CronJobs::id(['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']));

    expect($removed['command'])->toBe('php artisan schedule:run')
        ->and($application->fresh()->cron_jobs)->toBeNull()
        ->and(Activity::query()->where('description', CronJobs::REMOVED)->sole()->properties->toArray())->toBeInAnyKeyOrder(['schedule' => '* * * * *', 'description' => null, 'command' => 'php artisan schedule:run']);

    Queue::assertPushed(SyncApplicationCron::class, 1);
});

test('an app holds at most 20 jobs', function () {
    $jobs = array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan report:{$minute}"], range(0, 19));
    $application = cronJobsApp(['cron_jobs' => $jobs]);

    expect(fn() => cronJobsService()->add($application, ['schedule' => '0 3 * * *', 'command' => 'true', 'description' => null]))->toThrow(CronListRefused::class, 'This app already has 20 cron jobs. Remove one, or run several commands from one job.')
        ->and($application->fresh()->cron_jobs)->toHaveCount(20);

    Queue::assertNothingPushed();
});

test('the scheduler is turned on and off through the same list, and the second change rides on the sync already waiting', function () {
    $backup = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'];
    $application = cronJobsApp(['cron_jobs' => [['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'], $backup]]);

    cronJobsService()->enableScheduler($application);

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([$backup, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]);

    cronJobsService()->disableScheduler($application->fresh());

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([$backup])
        ->and(cronJobsLogged())->toBe(0);

    Queue::assertPushed(SyncApplicationCron::class, 1);
});

test('trying again marks the jobs as on their way and clears the failure', function () {
    $application = cronJobsApp(['cron_error' => 'web-1 refused the change.']);

    cronJobsService()->retry($application);

    expect($application->fresh()->cron_error)->toBeNull()
        ->and($application->fresh()->cron_pending_since)->not->toBeNull();

    Queue::assertPushed(SyncApplicationCron::class, 1);
});

test('a change is on its way for three minutes, then the page says it is still waiting', function () {
    $this->freezeSecond();
    $application = cronJobsApp(['cron_pending_since' => now()->subSeconds(10)]);

    expect(cronJobsService()->state($application))->toBe('applying');

    $application->forceFill(['cron_pending_since' => now()->subSeconds(180)]);

    expect(cronJobsService()->state($application))->toBe('applying');

    $application->forceFill(['cron_pending_since' => now()->subSeconds(181)]);

    expect(cronJobsService()->state($application))->toBe('waiting');

    $application->forceFill(['cron_pending_since' => null]);

    expect(cronJobsService()->state($application))->toBeNull();
});

test('the card names each job and says when it runs', function () {
    $long = 'php artisan report:build ' . str_repeat('x', 75);
    $application = cronJobsApp(['cron_jobs' => [
        ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup'],
        ['schedule' => '15 9-17 * * 1-5', 'command' => "php artisan prices:sync\nphp artisan cache:clear"],
        ['schedule' => '* * * * *', 'command' => $long],
    ]]);

    $card = cronJobsService()->describe($application);
    $jobs = collect($card['jobs']);

    expect($jobs->pluck('title')->all())->toBe(['Laravel scheduler', 'Nightly backup', 'php artisan prices:sync…', substr($long, 0, 80) . '…'])
        ->and($jobs->pluck('title_is_command')->all())->toBe([false, false, true, true])
        ->and($jobs->pluck('sentence')->all())->toBe(['Every minute', 'Daily at 3:00 AM UTC', null, 'Every minute'])
        ->and($jobs->pluck('scheduler')->all())->toBe([true, false, false, false])
        ->and($jobs->pluck('preset.kind')->all())->toBe(['minute', 'daily', 'custom', 'minute'])
        ->and($jobs->first()['command_summary'])->toBe('php artisan schedule:run')
        ->and(collect($card)->except('jobs')->all())->toBe(['limit' => 20, 'state' => null, 'note' => null, 'error' => null, 'live' => true, 'example' => 'php artisan backup:run']);
});

test('the card says when jobs are paused or wait for the first deploy', function () {
    $application = cronJobsApp(['power_state' => PowerState::Stopped]);

    expect(cronJobsService()->describe($application)['note'])->toBe('paused');

    $application->forceFill(['current_release' => null]);

    expect(cronJobsService()->describe($application)['note'])->toBe('undeployed')
        ->and(cronJobsService()->describe($application)['live'])->toBeFalse();
});

test('a change made from a stale copy of the app keeps a change saved since', function (?array $start, Closure $change, array $commands) {
    $application = cronJobsApp($start === null ? [] : ['cron_jobs' => $start]);
    $stale = Application::query()->findOrFail($application->id);

    cronJobsService()->add($application->fresh(), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']);

    $change($stale);

    expect(array_column((array) $application->fresh()->cron_jobs, 'command'))->toBe($commands);
})->with([
    'another add' => [null, fn(Application $stale) => cronJobsService()->add($stale, ['schedule' => '0 4 * * *', 'command' => 'php artisan report:send']), ['php artisan schedule:run', 'php artisan backup:run', 'php artisan report:send']],
    'an edit' => [null, fn(Application $stale) => cronJobsService()->update($stale, CronJobs::id(['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']), ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']), ['php artisan schedule:run', 'php artisan backup:run']],
    'a remove' => [null, fn(Application $stale) => cronJobsService()->remove($stale, CronJobs::id(['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'])), ['php artisan backup:run']],
    'the scheduler turned off' => [null, fn(Application $stale) => cronJobsService()->disableScheduler($stale), ['php artisan backup:run']],
    'the scheduler turned on' => [[['schedule' => '0 5 * * *', 'command' => 'php artisan prune']], fn(Application $stale) => cronJobsService()->enableScheduler($stale), ['php artisan prune', 'php artisan backup:run', 'php artisan schedule:run']],
]);

test('the limit and duplicates are checked against the list as saved now, not a stale copy', function (array $start, array $added, array $entry, string $message) {
    $application = cronJobsApp(['cron_jobs' => $start]);
    $stale = Application::query()->findOrFail($application->id);

    cronJobsService()->add($application->fresh(), $added);

    expect(fn() => cronJobsService()->add($stale, $entry))->toThrow(CronListRefused::class, $message);
    expect($application->fresh()->cron_jobs)->toHaveCount(count($start) + 1);
})->with([
    'the limit' => [
        array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan job:{$minute}"], range(0, 18)),
        ['schedule' => '30 * * * *', 'command' => 'php artisan job:30'],
        ['schedule' => '31 * * * *', 'command' => 'php artisan job:31'],
        'This app already has 20 cron jobs. Remove one, or run several commands from one job.',
    ],
    'the same job twice' => [
        [],
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'],
        ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'],
        'That exact job is already on the list.',
    ],
    'a second scheduler' => [
        [],
        ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
        ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'],
        'The Laravel scheduler is already on the list. Edit that job instead.',
    ],
]);

test('the scheduler is not turned on past the limit', function () {
    $application = cronJobsApp(['cron_jobs' => array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan job:{$minute}"], range(0, 19))]);

    expect(fn() => cronJobsService()->enableScheduler($application))->toThrow(CronListRefused::class, 'This app already has 20 cron jobs. Remove one, then turn the scheduler on.');
    expect($application->fresh()->cron_jobs)->toHaveCount(20);
});

test('an edit from a stale copy cannot make a job added since appear twice', function () {
    $application = cronJobsApp(['cron_jobs' => [['schedule' => '0 5 * * *', 'command' => 'php artisan prune']]]);
    $stale = Application::query()->findOrFail($application->id);

    cronJobsService()->add($application->fresh(), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']);

    expect(fn() => cronJobsService()->update($stale, CronJobs::id(['schedule' => '0 5 * * *', 'command' => 'php artisan prune']), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']))
        ->toThrow(CronListRefused::class, 'That exact job is already on the list.');
    expect(array_column((array) $application->fresh()->cron_jobs, 'command'))->toBe(['php artisan prune', 'php artisan backup:run']);
});

test('a change that cannot get the list to itself says to try again and changes nothing', function () {
    $application = cronJobsApp();
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->with('cron-jobs:' . $application->id, Mockery::any())->andReturn($lock);

    expect(fn() => cronJobsService()->add($application, ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']))
        ->toThrow(CronListRefused::class, 'Another change to these cron jobs is still being saved. Try again in a moment.');
    expect(cronJobsLogged())->toBe(0);
    Queue::assertNothingPushed();
});
