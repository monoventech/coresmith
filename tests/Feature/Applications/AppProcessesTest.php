<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\RestartAppProcess;
use App\Models\Application;
use App\Models\AppProcess;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\AppProcesses;
use App\Services\Panel\SmartToggles;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00'));
    Queue::fake();
});

/**
 * @return array{Team, Server}
 */
function appProcessesFleet(): array
{
    $team = Team::factory()->owned()->create();

    return [$team, Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active])];
}

function appProcessesLaravel(Server $server, array $attributes = []): Application
{
    return Application::factory()->for($server->team)->for($server)->create([
        'name' => 'shop',
        'slug' => 'shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.4.1',
        'processes' => [['name' => 'queue', 'command' => 'php artisan queue:work']],
        ...$attributes,
    ]);
}

function appProcessesLive(Application $application, string $process, string $active = 'active', array $extra = []): AppProcess
{
    return AppProcess::query()->create([
        'application_id' => $application->id,
        'unit' => 'coresmith-app-' . $application->slug . '-' . ($process === 'php' ? 'fpm' : $process),
        'process' => $process,
        'active_state' => $active,
        'sub_state' => $active === 'active' ? 'running' : 'dead',
        'memory_bytes' => 64 * 1048576,
        'restarts' => 0,
        'restart_times' => [],
        'started_at' => now()->subHours(2),
        'read_at' => now(),
        ...$extra,
    ]);
}

test('a Laravel app lists its web process first, then what its switches run, each with its live state', function () {
    [$team, $server] = appProcessesFleet();
    $shop = appProcessesLaravel($server);
    appProcessesLive($shop, 'php');
    appProcessesLive($shop, 'queue');

    $rows = app(AppProcesses::class)->rows($shop, $team->owner);

    expect(array_column($rows, 'key'))->toBe(['web', SmartToggles::QUEUE, SmartToggles::SCHEDULER])
        ->and($rows[0])->toMatchArray([
            'label' => 'Web',
            'description' => 'Serves requests with PHP 8.4.1',
            'toggle' => null,
            'state' => ['key' => 'running', 'label' => 'Running', 'tone' => 'success'],
            'memory' => "64\u{a0}MB",
            'restart_href' => route('servers.applications.processes.restart', [$server, $shop, 'php']),
            'log_href' => route('servers.applications.logs', [$server, $shop, 'sources' => ['process:php']]),
        ])
        ->and($rows[1])->toMatchArray([
            'label' => 'Queue worker',
            'toggle' => SmartToggles::QUEUE,
            'state' => ['key' => 'running', 'label' => 'Running', 'tone' => 'success'],
            'restart_href' => route('servers.applications.processes.restart', [$server, $shop, 'queue']),
        ])
        ->and($rows[2])->toMatchArray([
            'label' => 'Scheduler',
            'toggle' => SmartToggles::SCHEDULER,
            'state' => ['key' => 'off', 'label' => 'Off', 'tone' => 'default'],
            'memory' => null,
            'restart_href' => null,
            'log_href' => null,
        ]);
});

test('a switched-off worker reads off with nothing to restart, and a failed web process says so', function () {
    [$team, $server] = appProcessesFleet();
    $shop = appProcessesLaravel($server, ['processes' => []]);
    appProcessesLive($shop, 'php', 'failed');

    $rows = collect(app(AppProcesses::class)->rows($shop, $team->owner))->keyBy('key');

    expect($rows['web']['state'])->toBe(['key' => 'failed', 'label' => 'Failed', 'tone' => 'destructive'])
        ->and($rows[SmartToggles::QUEUE]['state'])->toBe(['key' => 'off', 'label' => 'Off', 'tone' => 'default'])
        ->and($rows[SmartToggles::QUEUE]['restart_href'])->toBeNull();
});

test('restarting one process queues a restart of that unit alone, and restart all queues them all', function () {
    [$team, $server] = appProcessesFleet();
    $shop = appProcessesLaravel($server);

    $this->actingAs($team->owner)
        ->post(route('servers.applications.processes.restart', [$server, $shop, 'queue']))
        ->assertRedirect()
        ->assertSessionHas('success', 'Restarting the queue worker.');

    $this->actingAs($team->owner)
        ->post(route('servers.applications.processes.restart-all', [$server, $shop]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Restarting every process for shop.');

    Queue::assertPushed(RestartAppProcess::class, fn(RestartAppProcess $job): bool => $job->application->is($shop) && $job->process === 'queue');
    Queue::assertPushed(RestartAppProcess::class, fn(RestartAppProcess $job): bool => $job->application->is($shop) && $job->process === null);
});

test('a restart is refused for a process the app does not run, and for people who cannot change the app', function () {
    [$team, $server] = appProcessesFleet();
    $shop = appProcessesLaravel($server);
    $deployer = User::factory()->create();
    $team->members()->attach($deployer, ['role' => TeamRole::Member->value]);

    $this->actingAs($team->owner)
        ->post(route('servers.applications.processes.restart', [$server, $shop, 'reverb']))
        ->assertNotFound();

    $this->actingAs($deployer)
        ->post(route('servers.applications.processes.restart', [$server, $shop, 'queue']))
        ->assertForbidden();

    Queue::assertNotPushed(RestartAppProcess::class);
});

test('a stopped or sleeping app offers no restart and refuses one, since a restart would start its units', function (PowerState $power, string $refusal) {
    [$team, $server] = appProcessesFleet();
    $shop = appProcessesLaravel($server, ['power_state' => $power]);
    appProcessesLive($shop, 'php', 'inactive');
    appProcessesLive($shop, 'queue', 'inactive');

    expect(array_column(app(AppProcesses::class)->rows($shop, $team->owner), 'restart_href'))->toBe([null, null, null]);

    $this->actingAs($team->owner)
        ->post(route('servers.applications.processes.restart', [$server, $shop, 'queue']))
        ->assertRedirect()
        ->assertSessionHas('error', $refusal);

    $this->actingAs($team->owner)
        ->post(route('servers.applications.processes.restart-all', [$server, $shop]))
        ->assertSessionHas('error', $refusal);

    Queue::assertNotPushed(RestartAppProcess::class);
})->with([
    'stopped' => [PowerState::Stopped, 'shop is stopped. Start it to run its processes again.'],
    'asleep' => [PowerState::Sleeping, 'shop is asleep. Its processes start on its next visit.'],
]);

test('the restart job restarts the unit over SSH, or every unit, and records it', function (?string $process, array $units, string $said) {
    [$team, $server] = appProcessesFleet();
    $shop = appProcessesLaravel($server);
    $transport = new FakeSSHTransport;
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        app()->call([new RestartAppProcess($shop, $process, $team->owner), 'handle']);
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    $commands = implode("\n", $transport->commands());

    foreach ($units as $unit) {
        expect($commands)->toContain("systemctl restart '{$unit}'");
    }

    expect(Activity::query()->where('description', 'process_restarted')->sole()->properties->all())->toBe(['process' => $said]);
})->with([
    'the queue worker' => ['queue', ['coresmith-app-shop-queue'], 'the queue worker'],
    'the web process' => ['php', ['coresmith-app-shop-fpm'], 'the web process'],
    'everything' => [null, ['coresmith-app-shop-fpm', 'coresmith-app-shop-queue'], 'every process'],
]);

test('the activity feed says which process was restarted', function () {
    [$team, $server] = appProcessesFleet();
    $shop = appProcessesLaravel($server);

    activity()->performedOn($shop)->causedBy($team->owner)->withProperties(['process' => 'the queue worker'])->log('process_restarted');

    $sentences = collect((new ActivityFeed)->forTeam($team))->map(fn(array $row): string => implode('', array_column($row['parts'], 'text')))->all();

    expect($sentences)->toContain('Restarted the queue worker for shop');
});

test('the app page sends its processes, its runtimes and its last three deploys', function () {
    [$team, $server] = appProcessesFleet();
    $shop = appProcessesLaravel($server, ['node_version' => '22.11.0']);
    appProcessesLive($shop, 'php');
    Deployment::factory()->for($shop)->count(4)->sequence(fn($sequence): array => ['created_at' => now()->subHours($sequence->index + 1)])->create();

    $this->actingAs($team->owner)
        ->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn($page) => $page
            ->where('processes.0.key', 'web')
            ->where('processes.0.state.label', 'Running')
            ->where('runtimes.0.name', 'PHP')
            ->where('runtimes.1.name', 'Node.js')
            ->has('recent_deploys', 3)
            ->where('recent_deploys.0.href', fn(string $href): bool => str_contains($href, '/deployments/')));
});
