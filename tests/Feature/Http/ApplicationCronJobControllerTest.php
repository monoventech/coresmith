<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Enums\TeamRole;
use App\Http\Requests\Application\CronJobRequest;
use App\Jobs\SyncApplicationCron;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\CronJobs;
use Illuminate\Support\Facades\Queue;

beforeEach(fn() => Queue::fake());

function cronRouteMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function cronRouteApp(Team $team, array $attributes = []): Application
{
    $server = Server::factory()->for($team)->create(['name' => 'web-1']);

    return Application::factory()->for($team)->for($server)->create([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'framework' => 'laravel',
        'current_release' => '20261019-120000',
        'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']],
        ...$attributes,
    ]);
}

function cronRouteStore(Application $application): string
{
    return route('servers.applications.cron-jobs.store', [$application->server_id, $application]);
}

function cronRouteJob(Application $application, array $entry): string
{
    return route('servers.applications.cron-jobs.update', [$application->server_id, $application, CronJobs::id($entry)]);
}

function cronRouteSync(Application $application): string
{
    return route('servers.applications.cron-jobs.sync', [$application->server_id, $application]);
}

test('an admin adds a cron job and it goes to the server with no deploy', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $show = route('servers.applications.show', [$application->server_id, $application]);

    $this->actingAs(cronRouteMember($team, TeamRole::Admin))
        ->from($show)
        ->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup'])
        ->assertRedirect($show)
        ->assertSessionHas('success', 'Saved. web-1 picks it up in a few seconds, no deployment needed.');

    expect($application->fresh()->cron_jobs[1])->toBeInAnyKeyOrder(['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => 'Nightly backup']);

    Queue::assertPushed(SyncApplicationCron::class, 1);
});

test('the schedule is checked before it is saved, saying what to fix', function (string $schedule, string $message) {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->post(cronRouteStore($application), ['schedule' => $schedule, 'command' => 'php artisan backup:run'])
        ->assertSessionHasErrors(['schedule' => $message]);

    expect($application->fresh()->cron_jobs)->toHaveCount(1);

    Queue::assertNothingPushed();
})->with([
    'a minute cron has no' => ['61 * * * *', "61 isn't a minute. Minutes run from 0 to 59."],
    'words' => ['every day', 'Write five fields separated by single spaces, using only digits and * / , - in each, such as */5 * * * *.'],
    'a step with no range' => ['5/10 * * * *', '5/10 has a step but no range. Write */10 or 5-59/10 instead.'],
    'nothing' => ['', 'Choose when it runs.'],
]);

test('a pasted command keeps its lines and loses its Windows endings and outer blanks', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->post(cronRouteStore($application), ['schedule' => "  0  3 * *\t* ", 'command' => "  php artisan report --since='1 day' \"\$(date)\"\r\necho \"100%\" # done  \r\n"])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->cron_jobs[1])->toBeInAnyKeyOrder(['schedule' => '0 3 * * *', 'command' => "php artisan report --since='1 day' \"\$(date)\"\necho \"100%\" # done"]);
});

test('a command with an invisible control or direction character is refused', function (string $command) {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => $command])
        ->assertSessionHasErrors(['command' => 'The command has an invisible control character in it. Retype that part and save again.']);
})->with([
    'a bell' => "echo \x07done",
    'an escape code' => "echo \e[31mred",
    'a right-to-left override' => "echo \u{202E}txt.exe",
    'bytes that are not UTF-8' => "echo \xff",
    'a C1 control' => "echo \u{0085}done",
    'the last C1 control' => "echo \u{009F}done",
    'a zero-width space' => "php artisan back\u{200B}up:run",
    'a right-to-left mark' => "echo \u{200F}done",
    'a word joiner' => "echo \u{2060}done",
    'a byte order mark' => "php artisan\u{FEFF} backup:run",
    'an arabic letter mark' => "echo \u{061C}done",
]);

test('a description with a zero-width or direction mark is refused', function (string $description) {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run', 'description' => $description])
        ->assertSessionHasErrors(['description' => 'The description has an invisible control character in it. Retype it and save again.']);
})->with(['a zero-width joiner' => "Night\u{200D}ly", 'a left-to-right mark' => "Night\u{200E}ly", 'a C1 control' => "Night\u{0090}ly"]);

test('ordinary text beside those ranges is still accepted', function (string $command) {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => $command])
        ->assertSessionHasNoErrors();
})->with(['an accented letter' => 'echo café', 'a non-breaking space' => "echo a\u{00A0}b", 'a dash and quotes' => 'echo “done” – ok']);

test('a lone carriage return becomes a new line too', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => "php artisan backup:run\rphp artisan prune"])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->cron_jobs[1]['command'])->toBe("php artisan backup:run\nphp artisan prune");
});

test('a page left open while the job changed elsewhere changes nothing and says so', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $stale = cronRouteJob($application, ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']);
    $owner = cronRouteMember($team, TeamRole::Owner);

    $this->actingAs($owner)->put($stale, ['schedule' => '0 4 * * *', 'command' => 'php artisan backup:run'])
        ->assertSessionHas('error', 'That cron job changed since this page loaded, so nothing was saved. Check the list and try again.');
    $this->actingAs($owner)->delete($stale)
        ->assertSessionHas('error', 'That cron job changed since this page loaded, so nothing was saved. Check the list and try again.');

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]);

    Queue::assertNothingPushed();
});

test('the app page carries its cron jobs and what a deployer may do with them', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $server = $application->server;

    $this->actingAs(cronRouteMember($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$server, $application]))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('cron.jobs.0.title', 'Laravel scheduler')
            ->where('cron.jobs.0.sentence', 'Every minute')
            ->where('cron.jobs.0.scheduler', true)
            ->where('cron.jobs.0.href', cronRouteJob($application, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']))
            ->where('cron.store_href', cronRouteStore($application))
            ->where('cron.sync_href', route('servers.applications.cron-jobs.sync', [$server, $application]))
            ->where('cron.log_href', route('servers.applications.cron-jobs.log', [$server, $application]))
            ->where('cron.limit', 20)
            ->where('cron.user', 'coresmith-app-shop')
            ->where('cron.live', true)
            ->where('can.update', false)
            ->where('can.viewLogs', true)
            ->missing('application.cron_jobs'));
});

test('owners and admins change cron jobs, deployers cannot', function (string $action, TeamRole $role, bool $allowed) {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $scheduler = cronRouteJob($application, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']);

    $this->actingAs(cronRouteMember($team, $role));

    $response = match ($action) {
        'store' => $this->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']),
        'update' => $this->put($scheduler, ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']),
        'destroy' => $this->delete($scheduler),
        'sync' => $this->post(cronRouteSync($application)),
    };

    if ($allowed) {
        $response->assertRedirect();

        Queue::assertPushed(SyncApplicationCron::class, 1);

        return;
    }

    $response->assertForbidden();

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]);

    Queue::assertNothingPushed();
})->with(['store', 'update', 'destroy', 'sync'])->with([
    'an owner' => [TeamRole::Owner, true],
    'an admin' => [TeamRole::Admin, true],
    'a deployer' => [TeamRole::Member, false],
]);

test('a cron job is changed only through its own server', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $other = Server::factory()->for($team)->create();
    $id = CronJobs::id(['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']);
    $owner = cronRouteMember($team, TeamRole::Owner);

    $this->actingAs($owner)
        ->post(route('servers.applications.cron-jobs.store', [$other, $application]), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'])
        ->assertNotFound();
    $this->actingAs($owner)
        ->put(route('servers.applications.cron-jobs.update', [$other, $application, $id]), ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'])
        ->assertNotFound();
    $this->actingAs($owner)->delete(route('servers.applications.cron-jobs.destroy', [$other, $application, $id]))->assertNotFound();
    $this->actingAs($owner)->post(route('servers.applications.cron-jobs.sync', [$other, $application]))->assertNotFound();

    expect($application->fresh()->cron_jobs)->toHaveCount(1);

    Queue::assertNothingPushed();
});

test('the command is required and kept under 1,000 characters', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $owner = cronRouteMember($team, TeamRole::Owner);

    $this->actingAs($owner)->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => ''])
        ->assertSessionHasErrors(['command' => 'Enter the command to run, like php artisan backup:run.']);
    $this->actingAs($owner)->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'echo ' . str_repeat('x', 996)])
        ->assertSessionHasErrors(['command' => 'Keep the command under 1,000 characters. Put a longer one in a script in your repository and run that.']);
    $this->actingAs($owner)->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'echo ' . str_repeat('x', 995)])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->cron_jobs[1]['command'])->toHaveLength(1000);
});

test('a description is optional, short and on one line', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $owner = cronRouteMember($team, TeamRole::Owner);
    $job = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'];

    $this->actingAs($owner)->post(cronRouteStore($application), [...$job, 'description' => "a\nb"])
        ->assertSessionHasErrors(['description' => 'Keep the description on one line.']);
    $this->actingAs($owner)->post(cronRouteStore($application), [...$job, 'description' => str_repeat('d', 81)])
        ->assertSessionHasErrors(['description' => 'Keep the description under 80 characters.']);
    $this->actingAs($owner)->post(cronRouteStore($application), [...$job, 'description' => "\u{202E}x"])
        ->assertSessionHasErrors(['description' => 'The description has an invisible control character in it. Retype it and save again.']);
    $this->actingAs($owner)->post(cronRouteStore($application), [...$job, 'description' => ' ' . str_repeat('d', 80) . ' '])
        ->assertSessionHasNoErrors();
    $this->actingAs($owner)->delete(cronRouteJob($application, [...$job, 'description' => str_repeat('d', 80)]))
        ->assertSessionHas('success');
    $this->actingAs($owner)->post(cronRouteStore($application), [...$job, 'description' => '   '])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->cron_jobs[1])->toBeInAnyKeyOrder($job);
});

test('the same job twice is refused, and so is a second scheduler', function () {
    $team = Team::factory()->create();
    $backup = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'];
    $application = cronRouteApp($team, ['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'], $backup]]);
    $owner = cronRouteMember($team, TeamRole::Owner);

    $this->actingAs($owner)->post(cronRouteStore($application), [...$backup, 'description' => 'Another name'])
        ->assertSessionHasErrors(['command' => 'That exact job is already on the list.']);
    $this->actingAs($owner)->post(cronRouteStore($application), ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'])
        ->assertSessionHasErrors(['command' => 'The Laravel scheduler is already on the list. Edit that job instead.']);
    $this->actingAs($owner)->put(cronRouteJob($application, $backup), ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'])
        ->assertSessionHasErrors(['command' => 'That exact job is already on the list.']);

    Queue::assertNothingPushed();

    $this->actingAs($owner)->put(cronRouteJob($application, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']), ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'], $backup]);
});

test('a job may share its schedule or its command with another, and may be renamed', function () {
    $team = Team::factory()->create();
    $backup = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'];
    $application = cronRouteApp($team, ['cron_jobs' => [$backup]]);
    $owner = cronRouteMember($team, TeamRole::Owner);

    $this->actingAs($owner)->post(cronRouteStore($application), ['schedule' => '0 4 * * *', 'command' => 'php artisan backup:run'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'php artisan prune'])->assertSessionHasNoErrors();
    $this->actingAs($owner)->put(cronRouteJob($application, $backup), [...$backup, 'description' => 'Nightly backup'])->assertSessionHasNoErrors();

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([
        [...$backup, 'description' => 'Nightly backup'],
        ['schedule' => '0 4 * * *', 'command' => 'php artisan backup:run'],
        ['schedule' => '0 3 * * *', 'command' => 'php artisan prune'],
    ]);
});

test('an app has at most 20 cron jobs', function () {
    $team = Team::factory()->create();
    $jobs = array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan report:{$minute}"], range(0, 19));
    $application = cronRouteApp($team, ['cron_jobs' => $jobs]);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'])
        ->assertSessionHasErrors(['command' => 'This app already has 20 cron jobs. Remove one, or run several commands from one job.']);

    expect($application->fresh()->cron_jobs)->toHaveCount(20);

    Queue::assertNothingPushed();

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->put(cronRouteJob($application, $jobs[0]), ['schedule' => '0 3 * * *', 'command' => 'php artisan report:0'])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->cron_jobs[0])->toBeInAnyKeyOrder(['schedule' => '0 3 * * *', 'command' => 'php artisan report:0']);
});

test('removing one of two scheduler entries left from before leaves the toggle on', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team, ['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'], ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']]]);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->delete(cronRouteJob($application, ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']))
        ->assertSessionHas('success', 'Removed. It stops running on web-1 in a few seconds.');
});

test('a job is changed in place by its id', function () {
    $team = Team::factory()->create();
    $backup = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'];
    $application = cronRouteApp($team, ['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'], $backup]]);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->put(cronRouteJob($application, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']), ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'])
        ->assertSessionHas('success', 'Saved. web-1 picks it up in a few seconds, no deployment needed.');

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run'], $backup]);

    Queue::assertPushed(SyncApplicationCron::class, 1);
});

test('an edit that changes nothing says so and sends nothing', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->put(cronRouteJob($application, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']), ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run', 'description' => ''])
        ->assertSessionHas('success', 'Saved.');

    expect($application->fresh()->cron_pending_since)->toBeNull();

    Queue::assertNothingPushed();
});

test('removing a job takes it off the list and says when it stops', function () {
    $team = Team::factory()->create();
    $backup = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'];
    $application = cronRouteApp($team, ['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'], $backup]]);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->delete(cronRouteJob($application, $backup))
        ->assertSessionHas('success', 'Removed. It stops running on web-1 in a few seconds.');

    expect($application->fresh()->cron_jobs)->toBeInAnyKeyOrder([['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]);
});

test('an app without the scheduler hears nothing about the toggle when other jobs change', function () {
    $team = Team::factory()->create();
    $backup = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'];
    $application = cronRouteApp($team, ['cron_jobs' => null]);
    $owner = cronRouteMember($team, TeamRole::Owner);

    $this->actingAs($owner)->post(cronRouteStore($application), $backup)
        ->assertSessionHas('success', 'Saved. web-1 picks it up in a few seconds, no deployment needed.');
    $this->actingAs($owner)->delete(cronRouteJob($application, $backup))
        ->assertSessionHas('success', 'Removed. It stops running on web-1 in a few seconds.');
});

test('the scheduler toggle follows the list and the toast says so', function (?string $framework, string $on, string $off, string $removed) {
    $team = Team::factory()->create();
    $application = cronRouteApp($team, ['framework' => $framework, 'cron_jobs' => null]);
    $owner = cronRouteMember($team, TeamRole::Owner);

    $this->actingAs($owner)->post(cronRouteStore($application), ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'])
        ->assertSessionHas('success', $on);
    $this->actingAs($owner)->put(cronRouteJob($application, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']), ['schedule' => '* * * * *', 'command' => 'php artisan schedule:work'])
        ->assertSessionHas('success', $off);
    $this->actingAs($owner)->post(cronRouteStore($application), ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']);
    $this->actingAs($owner)->delete(cronRouteJob($application, ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']))
        ->assertSessionHas('success', $removed);
})->with([
    'a Laravel app' => ['laravel', 'Saved. The Scheduler toggle is on now.', 'Saved. That is no longer the Laravel scheduler, so the Scheduler toggle is off now.', 'Removed. The Scheduler toggle is off now.'],
    'an app with no framework' => [null, 'Saved. web-1 picks it up in a few seconds, no deployment needed.', 'Saved. web-1 picks it up in a few seconds, no deployment needed.', 'Removed. It stops running on web-1 in a few seconds.'],
]);

test('a stopped app or one with nothing live is told when its jobs will run', function (array $attributes, string $saved) {
    $team = Team::factory()->create();
    $backup = ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'];
    $application = cronRouteApp($team, $attributes);
    $owner = cronRouteMember($team, TeamRole::Owner);

    $this->actingAs($owner)->post(cronRouteStore($application), $backup)->assertSessionHas('success', $saved);
    $this->actingAs($owner)->delete(cronRouteJob($application, $backup))->assertSessionHas('success', 'Removed.');
})->with([
    'stopped' => [['power_state' => PowerState::Stopped], 'Saved. It runs once you start the app.'],
    'nothing live' => [['current_release' => null], 'Saved. It runs after the first deploy.'],
    'nothing live and stopped' => [['current_release' => null, 'power_state' => PowerState::Stopped], 'Saved. It runs after the first deploy.'],
]);

test('try again sends the jobs to the server again', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team, ['cron_error' => 'web-1 refused the change.']);

    $this->actingAs(cronRouteMember($team, TeamRole::Owner))
        ->post(cronRouteSync($application))
        ->assertSessionHas('success', 'Sending the cron jobs to web-1 again.');

    expect($application->fresh()->cron_error)->toBeNull()
        ->and($application->fresh()->cron_pending_since)->not->toBeNull();

    Queue::assertPushed(SyncApplicationCron::class, 1);
});

test('two saves from the same stale page keep both', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $this->app->afterResolving(CronJobRequest::class, function () use ($application): void {
        Application::query()->whereKey($application->id)->update(['cron_jobs' => json_encode([
            ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run'],
            ['schedule' => '0 4 * * *', 'command' => 'php artisan report:send'],
        ])]);
    });

    $this->actingAs(cronRouteMember($team, TeamRole::Admin))
        ->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(array_column((array) $application->fresh()->cron_jobs, 'command'))->toBe(['php artisan schedule:run', 'php artisan report:send', 'php artisan backup:run']);
});

test('a save the list as saved now refuses says why and changes nothing', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $full = array_map(fn(int $minute): array => ['schedule' => "{$minute} * * * *", 'command' => "php artisan job:{$minute}"], range(0, 19));
    $this->app->afterResolving(CronJobRequest::class, function () use ($application, $full): void {
        Application::query()->whereKey($application->id)->update(['cron_jobs' => json_encode($full)]);
    });

    $this->actingAs(cronRouteMember($team, TeamRole::Admin))
        ->post(cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'])
        ->assertSessionHasErrors(['command' => 'This app already has 20 cron jobs. Remove one, or run several commands from one job.']);

    expect($application->fresh()->cron_jobs)->toHaveCount(20);
    Queue::assertNothingPushed();
});

test('an edit the list as saved now refuses says why and changes nothing', function () {
    $team = Team::factory()->create();
    $application = cronRouteApp($team, ['cron_jobs' => [['schedule' => '0 5 * * *', 'command' => 'php artisan prune']]]);
    $saved = [['schedule' => '0 5 * * *', 'command' => 'php artisan prune'], ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']];
    $this->app->afterResolving(CronJobRequest::class, function () use ($application, $saved): void {
        Application::query()->whereKey($application->id)->update(['cron_jobs' => json_encode($saved)]);
    });

    $this->actingAs(cronRouteMember($team, TeamRole::Admin))
        ->put(cronRouteJob($application, ['schedule' => '0 5 * * *', 'command' => 'php artisan prune']), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run'])
        ->assertSessionHasErrors(['command' => 'That exact job is already on the list.']);

    expect($application->fresh()->cron_jobs)->toBe($saved);
});

test('another team cannot change this app\'s cron jobs', function (string $verb, Closure $url, array $payload) {
    $team = Team::factory()->create();
    $application = cronRouteApp($team);
    $stranger = cronRouteMember(Team::factory()->create(), TeamRole::Owner);

    $response = $this->actingAs($stranger)->{$verb}($url($application), $payload);

    expect($response->status())->toBeIn([403, 404])
        ->and(array_column((array) $application->fresh()->cron_jobs, 'command'))->toBe(['php artisan schedule:run'])
        ->and($application->fresh()->cron_pending_since)->toBeNull();

    Queue::assertNothingPushed();
})->with([
    'adding a job' => ['post', fn(Application $application) => cronRouteStore($application), ['schedule' => '0 3 * * *', 'command' => 'php artisan backup:run']],
    'editing a job' => ['put', fn(Application $application) => cronRouteJob($application, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']), ['schedule' => '*/5 * * * *', 'command' => 'php artisan schedule:run']],
    'removing a job' => ['delete', fn(Application $application) => cronRouteJob($application, ['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']), []],
    'sending the jobs again' => ['post', fn(Application $application) => cronRouteSync($application), []],
]);
