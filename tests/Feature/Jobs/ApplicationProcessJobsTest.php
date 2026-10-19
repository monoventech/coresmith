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
    $application = processJobsApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);
    $transport = new FakeSSHTransport;

    processJobsRun(new SyncApplicationProcesses($application), $transport);

    expect($transport->uploads()['/etc/cron.d/.coresmith-app-shop.new'] ?? '')->toContain("* * * * * root systemd-run --quiet --wait --pipe --collect --service-type=exec --uid=coresmith-app-shop --gid=coresmith-app-shop -p 'WorkingDirectory=/var/coresmith/apps/shop/current'")
        ->and($transport->uploads()['/etc/cron.d/.coresmith-app-shop.new'] ?? '')->toContain("-- /bin/bash -s < '/etc/coresmith/cron/shop/0.sh'")
        ->and($transport->uploads()['/etc/coresmith/cron/.shop.new/0.sh'] ?? '')->toBe("{\nphp artisan schedule:run\n} >> '/var/coresmith/apps/shop/shared/logs/scheduler.log' 2>&1\n")
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

test('process syncs and detaches run on the server tasks queue', function () {
    $application = processJobsApp();

    expect((new SyncApplicationProcesses($application))->queue)->toBe('server-tasks')
        ->and((new DetachApplication($application))->queue)->toBe('server-tasks');
});

test('detaching stops every unit and removes the site from caddy', function () {
    $application = processJobsApp([
        'domain' => 'shop.example.com',
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
    ]);
    $application->delete();
    $transport = new FakeSSHTransport;

    processJobsRun(new DetachApplication($application), $transport);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain("systemctl stop 'coresmith-app-shop-fpm'")
        ->and($commands)->toContain("systemctl stop 'coresmith-app-shop-queue'")
        ->and($commands)->toContain("-X DELETE 'http://localhost:2019/id/app-{$application->id}'")
        ->and($commands)->toContain('mv -f /etc/caddy/caddy-config.staged.json /etc/caddy/caddy-config.json')
        ->and(Application::withTrashed()->find($application->id)->power_state)->toBe(PowerState::Stopped)
        ->and($transport->connected())->toBeFalse();
});

test('detaching removes the app cron file, its scripts and any old user crontab, so its scheduled jobs stop with it', function () {
    $application = processJobsApp(['cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']]]);
    $application->delete();
    $transport = new FakeSSHTransport;

    processJobsRun(new DetachApplication($application), $transport);

    $commands = $transport->commands();
    $stopped = array_search("systemctl stop 'coresmith-app-shop-fpm'", $commands, true);
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

test('detaching an application without a domain never calls caddy', function () {
    $application = processJobsApp(['domain' => null, 'additional_domains' => null]);
    $transport = new FakeSSHTransport;

    processJobsRun(new DetachApplication($application), $transport);

    expect(implode("\n", $transport->commands()))->not->toContain('curl')
        ->and(implode("\n", $transport->commands()))->toContain("systemctl stop 'coresmith-app-shop-fpm'");
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
