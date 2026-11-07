<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Enums\TeamRole;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DetachApplication;
use App\Jobs\SyncApplicationProcesses;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\SmartToggles;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function processJobsApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
    ], $attributes));
}

function processJobsRun(object $job, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([$job, 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function processJobsMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

test('a removed process has its unit disabled and deleted before the others are reinstalled', function () {
    $application = processJobsApp(['processes' => [['name' => 'horizon', 'command' => 'php artisan horizon']]]);
    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($application, ['queue']), $transport);

    $commands = $transport->commands();
    $disable = array_search("systemctl disable --now 'coresmith-app-shop-queue' || true", $commands, true);
    $delete = array_search("rm -f '/etc/systemd/system/coresmith-app-shop-queue.service'", $commands, true);
    $reload = array_search('systemctl daemon-reload', $commands, true);

    expect($disable)->not->toBeFalse()
        ->and($delete)->not->toBeFalse()
        ->and($reload)->not->toBeFalse()
        ->and($disable)->toBeLessThan($delete)
        ->and($delete)->toBeLessThan($reload)
        ->and($transport->uploads())->toHaveKey('/etc/systemd/system/coresmith-app-shop-horizon.service')
        ->and($transport->uploads())->not->toHaveKey('/etc/systemd/system/coresmith-app-shop-queue.service');
});

test('an active application has its remaining units started', function () {
    $application = processJobsApp([
        'power_state' => PowerState::Active,
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
    ]);
    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($application), $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("systemctl enable --now 'coresmith-app-shop-fpm'")
        ->and($commands)->toContain("systemctl enable --now 'coresmith-app-shop-queue'")
        ->and($transport->uploads()['/etc/systemd/system/coresmith-app-shop-queue.service'])->toContain('ExecStart=/usr/bin/env php artisan queue:work');
});

test('a stopped or sleeping application gets its units installed but not started', function (PowerState $state) {
    $application = processJobsApp([
        'power_state' => $state,
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
    ]);
    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($application), $transport);

    expect($transport->uploads())->toHaveKey('/etc/systemd/system/coresmith-app-shop-queue.service')
        ->and(implode("\n", $transport->commands()))->not->toContain('enable --now')
        ->and($application->fresh()->power_state)->toBe($state);
})->with([PowerState::Stopped, PowerState::Sleeping]);

test('the cron file is rewritten from the application cron jobs', function () {
    $application = processJobsApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']], 'current_release' => '20261019-120000']);
    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($application), $transport);

    expect($transport->uploads()['/etc/cron.d/.coresmith-app-shop.new'] ?? '')->toContain("* * * * * root systemd-run --quiet --wait --pipe --collect --service-type=exec --uid=coresmith-app-shop --gid=coresmith-app-shop -p 'WorkingDirectory=/var/coresmith/apps/shop/current'")
        ->and($transport->uploads()['/etc/cron.d/.coresmith-app-shop.new'] ?? '')->toContain("-- /bin/bash -s < '/etc/coresmith/cron/shop/0.sh'")
        ->and($transport->uploads()['/etc/coresmith/cron/.shop.new/0.sh'] ?? '')->toBe("exec >> '/var/coresmith/apps/shop/shared/logs/scheduler.log' 2>&1\n{\nphp artisan schedule:run\n} < /dev/null\n")
        ->and(implode("\n", $transport->commands()))->toContain("mv -f '/etc/cron.d/.coresmith-app-shop.new' '/etc/cron.d/coresmith-app-shop'")
        ->and(implode("\n", $transport->commands()))->toContain("crontab -u 'coresmith-app-shop' -r");
});

test('an application with no cron jobs has its cron file and scripts removed so a removed schedule stops', function () {
    $application = processJobsApp(['cron_jobs' => null]);
    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($application), $transport);

    expect(implode("\n", $transport->commands()))->toContain("rm -f '/etc/cron.d/coresmith-app-shop' '/etc/cron.d/.coresmith-app-shop.new' && rm -rf '/etc/coresmith/cron/shop' '/etc/coresmith/cron/.shop.new'")
        ->and($transport->uploads())->not->toHaveKey('/etc/cron.d/.coresmith-app-shop.new');
});

test('the sync works from the application as saved now, not the copy it was queued with', function () {
    $application = processJobsApp();
    $queued = $application->replicate();
    $queued->id = $application->id;
    $queued->exists = true;

    $application->forceFill(['processes' => [['name' => 'worker', 'command' => 'php artisan queue:work']]])->save();

    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($queued), $transport);

    expect($transport->uploads())->toHaveKey('/etc/systemd/system/coresmith-app-shop-worker.service');
});

test('a sync the server rejects is logged against the application and rethrown', function () {
    $application = processJobsApp();
    $transport = (new FakeSSHTransport)->respondTo('systemctl daemon-reload', 'Failed to reload daemon', 1);

    expect(fn() => processJobsRun(new SyncApplicationProcesses($application), $transport))->toThrow(SSHCommandFailedException::class);

    $activity = Activity::query()->where('description', 'processes_sync_failed')->sole();

    expect($activity->subject_id)->toBe($application->id)
        ->and($activity->properties['error'])->toBe('SSH command failed with exit code 1.')
        ->and($transport->connected())->toBeFalse();
});

function processJobsWorker(array $attributes = []): Application
{
    return processJobsApp([
        'power_state' => PowerState::Active,
        'processes' => [
            ['name' => 'horizon', 'command' => 'php artisan horizon'],
            ['name' => 'queue', 'command' => SmartToggles::QUEUE_COMMAND],
        ],
        ...$attributes,
    ]);
}

test('a worker turned on by its toggle is checked once it has started, and only that worker', function () {
    $application = processJobsWorker();
    $transport = (new FakeSSHTransport)->respondTo("systemctl is-active 'coresmith-app-shop-fpm'", "failed\n", 3);

    processJobsRun(new SyncApplicationProcesses($application, [], SmartToggles::QUEUE), $transport);

    $commands = $transport->commands();
    $started = array_search("systemctl enable --now 'coresmith-app-shop-queue'", $commands, true);
    $checked = array_search("systemctl is-active 'coresmith-app-shop-queue'", $commands, true);

    expect($started)->not->toBeFalse()
        ->and($checked)->not->toBeFalse()
        ->and($started)->toBeLessThan($checked)
        ->and(implode("\n", $commands))->not->toContain("systemctl is-active 'coresmith-app-shop-fpm'")
        ->and(implode("\n", $commands))->not->toContain("systemctl is-active 'coresmith-app-shop-horizon'")
        ->and(collect($application->fresh()->processes)->pluck('name')->all())->toBe(['horizon', 'queue'])
        ->and(Activity::query()->where('description', 'toggle_started')->sole()->properties->toArray())->toBeInAnyKeyOrder(['toggle' => 'queue_worker', 'label' => 'Queue worker'])
        ->and(Activity::query()->where('description', 'toggle_start_failed')->exists())->toBeFalse();
});

test('a worker that does not start is stopped, removed and its toggle turned back off, with the reason and its advice', function () {
    $application = processJobsWorker();
    $transport = (new FakeSSHTransport)
        ->respondTo("systemctl is-active 'coresmith-app-shop-queue'", "activating\n", 3)
        ->respondTo("journalctl -u 'coresmith-app-shop-queue'", "coresmith-app-shop-queue.service: Main process exited, code=exited, status=1/FAILURE\n")
        ->respondTo("tail -n 200 '/var/coresmith/logs/shop/queue.log'", "Could not open input file: artisan\nNo such file or directory\n");

    processJobsRun(new SyncApplicationProcesses($application, [], SmartToggles::QUEUE), $transport);

    $commands = $transport->commands();
    $checked = array_search("systemctl is-active 'coresmith-app-shop-queue'", $commands, true);
    $disabled = array_search("systemctl disable --now 'coresmith-app-shop-queue' || true", $commands, true);
    $deleted = array_search("rm -f '/etc/systemd/system/coresmith-app-shop-queue.service'", $commands, true);
    $reloaded = array_keys(array_filter($commands, fn(string $command): bool => $command === 'systemctl daemon-reload'));
    $activity = Activity::query()->where('description', 'toggle_start_failed')->sole();

    expect($checked)->toBeLessThan($disabled)
        ->and($disabled)->toBeLessThan($deleted)
        ->and(end($reloaded))->toBeGreaterThan($deleted)
        ->and(collect($application->fresh()->processes)->pluck('name')->all())->toBe(['horizon'])
        ->and($activity->subject_id)->toBe($application->id)
        ->and($activity->properties['toggle'])->toBe('queue_worker')
        ->and($activity->properties['label'])->toBe('Queue worker')
        ->and($activity->properties['error'])->toBe('coresmith-app-shop-queue did not start, systemd reports it as activating. The entry point was not found. Check the build produced the file the process starts, then deploy again.')
        ->and(Activity::query()->where('description', 'processes_sync_failed')->exists())->toBeFalse()
        ->and(Activity::query()->where('description', 'toggle_started')->exists())->toBeFalse()
        ->and($transport->connected())->toBeFalse();
});

test('a sync that was not started by a worker toggle checks nothing', function (?string $toggle) {
    $application = processJobsWorker();
    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($application, [], $toggle), $transport);

    expect(implode("\n", $transport->commands()))->not->toContain('systemctl is-active')
        ->and(Activity::query()->whereIn('description', ['toggle_started', 'toggle_start_failed'])->exists())->toBeFalse();
})->with(['no toggle' => null, 'the scheduler' => SmartToggles::SCHEDULER]);

test('a worker toggled off again before its sync ran is not checked', function () {
    $application = processJobsWorker(['processes' => [['name' => 'horizon', 'command' => 'php artisan horizon']]]);
    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($application, [], SmartToggles::QUEUE), $transport);

    expect(implode("\n", $transport->commands()))->not->toContain('systemctl is-active')
        ->and(Activity::query()->whereIn('description', ['toggle_started', 'toggle_start_failed'])->exists())->toBeFalse();
});

test('a stopped app has its toggled worker installed but neither started nor checked', function () {
    $application = processJobsWorker(['power_state' => PowerState::Stopped]);
    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($application, [], SmartToggles::QUEUE), $transport);

    expect($transport->uploads())->toHaveKey('/etc/systemd/system/coresmith-app-shop-queue.service')
        ->and(implode("\n", $transport->commands()))->not->toContain('systemctl is-active')
        ->and(collect($application->fresh()->processes)->pluck('name')->all())->toBe(['horizon', 'queue']);
});

test('process syncs and detaches run on the server tasks queue', function () {
    $application = processJobsApp();

    expect((new SyncApplicationProcesses($application))->queue)->toBe('server-tasks')
        ->and((new DetachApplication($application))->queue)->toBe('server-tasks');
});

function detachUnitCommands(string $slug, array $processes): array
{
    return collect($processes)->flatMap(fn(string $process): array => [
        "systemctl disable --now 'coresmith-app-{$slug}-{$process}' || true",
        "rm -f '/etc/systemd/system/coresmith-app-{$slug}-{$process}.service'",
    ])->values()->all();
}

test('detaching disables and deletes every unit of the app and its php-fpm pool, then removes the site from caddy', function () {
    $application = processJobsApp([
        'domain' => 'shop.example.com',
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
    ]);
    $application->delete();
    $transport = new FakeSSHTransport;

    processJobsRun(new DetachApplication($application), $transport);

    $commands = implode("\n", $transport->commands());

    expect(array_slice($transport->commands(), 0, 6))->toBe([
        ...detachUnitCommands('shop', ['fpm', 'queue']),
        "rm -f '/etc/php-fpm.d/coresmith-app-shop.conf'",
        'systemctl daemon-reload',
    ])
        ->and($commands)->toContain("-X DELETE 'http://localhost:2019/id/app-{$application->id}'")
        ->and($commands)->toContain('mv -f /etc/caddy/caddy-config.staged.json /etc/caddy/caddy-config.json')
        ->and(Application::withTrashed()->find($application->id)->power_state)->toBe(PowerState::Stopped)
        ->and($transport->connected())->toBeFalse();
});

test('detaching removes the units the app has by their exact names and never touches another app on the server', function () {
    $application = processJobsApp(['domain' => null, 'processes' => [['name' => 'queue', 'command' => SmartToggles::QUEUE_COMMAND]]]);
    Application::factory()->create([
        'server_id' => $application->server_id,
        'team_id' => $application->team_id,
        'slug' => 'shop-api',
        'system_user' => 'coresmith-app-shop-api',
        'type' => ApplicationType::Js,
        'js_runtime' => 'node',
        'node_version' => '24.15.0',
        'php_version' => null,
        'processes' => [['name' => 'queue', 'command' => 'node queue.js']],
    ]);
    $application->delete();
    $transport = new FakeSSHTransport;

    processJobsRun(new DetachApplication($application), $transport);

    $commands = implode("\n", $transport->commands());

    expect($transport->commands())->toContain(...detachUnitCommands('shop', ['fpm', 'queue']))
        ->and($commands)->not->toContain('shop-api')
        ->and($commands)->not->toContain('*');
});

test('detaching a rails app removes its web and worker units, and it has no php-fpm pool to remove', function () {
    $application = processJobsApp([
        'type' => ApplicationType::Ruby,
        'framework' => 'rails',
        'php_version' => null,
        'ruby_version' => '3.3.6',
        'domain' => null,
        'processes' => [['name' => 'sidekiq', 'command' => SmartToggles::SIDEKIQ_COMMAND], ['name' => 'jobs', 'command' => SmartToggles::SOLID_QUEUE_COMMAND]],
    ]);
    $application->delete();
    $transport = new FakeSSHTransport;

    processJobsRun(new DetachApplication($application), $transport);

    expect(array_slice($transport->commands(), 0, 7))->toBe([...detachUnitCommands('shop', ['web', 'sidekiq', 'jobs']), 'systemctl daemon-reload'])
        ->and(implode("\n", $transport->commands()))->not->toContain('php-fpm');
});

test('a unit systemd no longer knows does not stop the rest of the app from being removed', function () {
    $application = processJobsApp(['domain' => null, 'processes' => [['name' => 'queue', 'command' => SmartToggles::QUEUE_COMMAND]]]);
    $application->delete();
    $transport = (new FakeSSHTransport)->respondTo("systemctl disable --now 'coresmith-app-shop-fpm'", 'Failed to disable unit: Unit file coresmith-app-shop-fpm.service does not exist.', 1);

    processJobsRun(new DetachApplication($application), $transport);

    expect(array_slice($transport->commands(), 0, 6))->toBe([
        ...detachUnitCommands('shop', ['fpm', 'queue']),
        "rm -f '/etc/php-fpm.d/coresmith-app-shop.conf'",
        'systemctl daemon-reload',
    ]);
});

test('detaching removes the app cron file, its scripts and any old user crontab, so its scheduled jobs stop with it', function () {
    $application = processJobsApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);
    $application->delete();
    $transport = new FakeSSHTransport;

    processJobsRun(new DetachApplication($application), $transport);

    $commands = $transport->commands();
    $stopped = array_search("systemctl disable --now 'coresmith-app-shop-fpm' || true", $commands, true);
    $removed = array_search(
        "rm -f '/etc/cron.d/coresmith-app-shop' '/etc/cron.d/.coresmith-app-shop.new' && rm -rf '/etc/coresmith/cron/shop' '/etc/coresmith/cron/.shop.new'"
        . " && if crontab -u 'coresmith-app-shop' -l >/dev/null 2>&1; then crontab -u 'coresmith-app-shop' -r; fi",
        $commands,
        true,
    );

    expect($stopped)->not->toBeFalse()
        ->and($removed)->not->toBeFalse()
        ->and($stopped)->toBeLessThan($removed);
});

test('detaching an app whose first deploy never reached caddy still finishes', function () {
    $application = processJobsApp(['domain' => 'shop.example.com']);
    $application->delete();
    $transport = (new FakeSSHTransport)->respondTo("-X DELETE 'http://localhost:2019/id/", 'curl: (22) The requested URL returned error: 404', 22);

    processJobsRun(new DetachApplication($application), $transport);

    expect($transport->commands())->toContain("systemctl disable --now 'coresmith-app-shop-fpm' || true");
});

test('detaching an application without a domain never calls caddy', function () {
    $application = processJobsApp(['domain' => null, 'additional_domains' => null]);
    $transport = new FakeSSHTransport;

    processJobsRun(new DetachApplication($application), $transport);

    expect(implode("\n", $transport->commands()))->not->toContain('curl')
        ->and($transport->commands())->toContain("systemctl disable --now 'coresmith-app-shop-fpm' || true");
});

test('removing an application soft deletes it and queues the detach', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Shop']);

    $this->actingAs(processJobsMember($team, TeamRole::Admin))
        ->delete(route('servers.applications.destroy', [$server, $application]))
        ->assertRedirect(route('servers.show', $server))
        ->assertSessionHas('success', 'Removed. Shop was stopped and detached.');

    expect(Application::find($application->id))->toBeNull()
        ->and(Application::withTrashed()->find($application->id))->not->toBeNull()
        ->and(Activity::query()->where('description', 'application_removed')->where('subject_id', $application->id)->exists())->toBeTrue();

    Queue::assertPushed(DetachApplication::class, fn(DetachApplication $job): bool => (fn(): Application => $this->application)->call($job)->is($application));
});

test('a member cannot remove an application and nothing is detached', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(processJobsMember($team, TeamRole::Member))
        ->delete(route('servers.applications.destroy', [$server, $application]))
        ->assertForbidden();

    expect(Application::find($application->id))->not->toBeNull();

    Queue::assertNotPushed(DetachApplication::class);
});

test('an application removed through another server is a not found', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();
    $other = Server::factory()->for($team)->create();

    $this->actingAs(processJobsMember($team, TeamRole::Owner))
        ->delete(route('servers.applications.destroy', [$other, $application]))
        ->assertNotFound();

    expect(Application::find($application->id))->not->toBeNull();

    Queue::assertNotPushed(DetachApplication::class);
});
