<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Jobs\ReadSchedulerLog;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\AppShell;
use App\Services\Server\SchedulerLogReader;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeSSHTransport;

function schedulerLogApp(Team $team, array $attributes = []): Application
{
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'host_key_fingerprint' => null]);

    return Application::factory()->for($team)->for($server)->create([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'current_release' => '20261019-120000',
        'env_variables' => 'DB_PASSWORD=supersecretvalue1',
        ...$attributes,
    ]);
}

function schedulerLogMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function schedulerLogUrl(Application $application): string
{
    return route('servers.applications.cron-jobs.log', [$application->server_id, $application]);
}

function schedulerLogRead(Application $application, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new ReadSchedulerLog($application))->handle(app(SSHConnectionManager::class), app(SchedulerLogReader::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 14:05:09'));
});

test('the log is read as the app user, so a link the app planted reads nothing of root\'s', function () {
    $application = schedulerLogApp(Team::factory()->create());
    $transport = new FakeSSHTransport;

    schedulerLogRead($application, $transport);

    $command = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'tail -n 50'));

    expect($command)->toStartWith('systemd-run --quiet --wait --pipe --collect --service-type=exec --uid=coresmith-app-shop --gid=coresmith-app-shop ')
        ->and($command)->toContain('/var/coresmith/apps/shop/shared/logs/scheduler.log')
        ->and($command)->not->toContain('sudo -u')
        ->and($command)->toBe(app(AppShell::class)->run($application, "tail -n 50 -- '/var/coresmith/apps/shop/shared/logs/scheduler.log' 2>/dev/null || true", '/var/coresmith/apps/shop/shared', [], 30));
});

test('a server signed in as another user reads the log through sudo', function () {
    $application = schedulerLogApp(Team::factory()->create());
    $application->server->forceFill(['ssh_user' => 'coresmith'])->save();
    $transport = new FakeSSHTransport;

    schedulerLogRead($application->fresh(), $transport);

    expect(collect($transport->commands())->first(fn(string $command): bool => str_contains($command, 'tail -n 50')))->toStartWith("sudo bash -c 'systemd-run --quiet ");
});

test('the last lines come back without secrets or terminal codes, cut to a readable length', function () {
    $application = schedulerLogApp(Team::factory()->create());
    $transport = (new FakeSSHTransport)->respondTo('tail -n 50', "\e[32mINFO\e[0m  Running [prune]\nconnected with supersecretvalue1\n" . str_repeat('x', 500) . "\n");

    schedulerLogRead($application, $transport);

    expect(Cache::get(ReadSchedulerLog::cacheKey($application)))->toBe([
        'lines' => ['INFO  Running [prune]', 'connected with [REDACTED:DB_PASSWORD]', str_repeat('x', 400)],
        'error' => null,
        'read_at' => now()->timestamp,
    ])->and($transport->connected())->toBeFalse();
});

test('an app with nothing live has no log to read', function () {
    $application = schedulerLogApp(Team::factory()->create(), ['current_release' => null]);
    $transport = new FakeSSHTransport;

    schedulerLogRead($application, $transport);

    expect(Cache::get(ReadSchedulerLog::cacheKey($application)))->toBe(['lines' => [], 'error' => null, 'read_at' => now()->timestamp])
        ->and($transport->commands())->toBe([])
        ->and($transport->loginAttempted())->toBeFalse();
});

test('a log that cannot be read says so', function (FakeSSHTransport $transport) {
    $application = schedulerLogApp(Team::factory()->create());

    schedulerLogRead($application, $transport);

    expect(Cache::get(ReadSchedulerLog::cacheKey($application)))->toBe([
        'lines' => [],
        'error' => "Couldn't read the scheduler log on web-1.",
        'read_at' => now()->timestamp,
    ]);
})->with([
    'the server refuses the sign-in' => fn() => (new FakeSSHTransport)->failLogin(),
    'the app user cannot start the read' => fn() => (new FakeSSHTransport)->respondTo('tail -n 50', 'Failed to load environment files', 1),
]);

test('only tabs survive among the control characters, and only the last 50 lines are kept', function () {
    $application = schedulerLogApp(Team::factory()->create());
    $lines = array_map(fn(int $line): string => "line {$line}", range(1, 60));

    expect(app(SchedulerLogReader::class)->parse("a\tb\x07c\x7Fd\x1b]0;title\x07\r\n", $application))->toBe(["a\tbcd]0;title"])
        ->and(app(SchedulerLogReader::class)->parse("left \u{202E}right\u{2066} \u{0085}end\n\xff\xfe broken", $application))->toBe(['left right end', '?? broken'])
        ->and(app(SchedulerLogReader::class)->parse(implode("\n", $lines) . "\n", $application))->toBe(array_slice($lines, -50))
        ->and(app(SchedulerLogReader::class)->parse('', $application))->toBe([]);
});

test('before the first read the endpoint answers pending and queues a read', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = schedulerLogApp($team);

    $this->actingAs(schedulerLogMember($team, TeamRole::Member))
        ->get(schedulerLogUrl($application))
        ->assertStatus(202)
        ->assertExactJson(['lines' => [], 'pending' => true]);

    Queue::assertPushed(ReadSchedulerLog::class, 1);
});

test('fresh lines come back without another read', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = schedulerLogApp($team);

    Cache::put(ReadSchedulerLog::cacheKey($application), ['lines' => ['Running [prune]'], 'error' => null, 'read_at' => now()->subSeconds(3)->timestamp], 120);

    $this->actingAs(schedulerLogMember($team, TeamRole::Member))
        ->get(schedulerLogUrl($application))
        ->assertOk()
        ->assertExactJson(['lines' => ['Running [prune]'], 'error' => null, 'pending' => false]);

    Queue::assertNotPushed(ReadSchedulerLog::class);
});

test('lines exactly ten seconds old still count as fresh', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = schedulerLogApp($team);

    Cache::put(ReadSchedulerLog::cacheKey($application), ['lines' => [], 'error' => null, 'read_at' => now()->subSeconds(10)->timestamp], 120);

    $this->actingAs(schedulerLogMember($team, TeamRole::Member))->get(schedulerLogUrl($application))->assertOk();

    Queue::assertNotPushed(ReadSchedulerLog::class);
});

test('lines older than ten seconds come back while a fresh read is queued', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = schedulerLogApp($team);

    Cache::put(ReadSchedulerLog::cacheKey($application), ['lines' => ['Running [prune]'], 'error' => "Couldn't read the scheduler log on web-1.", 'read_at' => now()->subSeconds(11)->timestamp], 120);

    $this->actingAs(schedulerLogMember($team, TeamRole::Member))
        ->get(schedulerLogUrl($application))
        ->assertOk()
        ->assertExactJson(['lines' => ['Running [prune]'], 'error' => "Couldn't read the scheduler log on web-1.", 'pending' => false]);

    Queue::assertPushed(ReadSchedulerLog::class, 1);
});

test('deployers can read the output, another team cannot, and another server is not found', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $application = schedulerLogApp($team);

    $this->actingAs(schedulerLogMember($team, TeamRole::Member))->get(schedulerLogUrl($application))->assertStatus(202);

    Queue::fake();

    $this->actingAs(schedulerLogMember(Team::factory()->create(), TeamRole::Owner))->get(schedulerLogUrl($application))->assertForbidden();
    $this->actingAs(schedulerLogMember($team, TeamRole::Owner))
        ->get(route('servers.applications.cron-jobs.log', [Server::factory()->for($team)->create(), $application]))
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('the endpoint needs a session and names its rate limit bucket', function () {
    $application = schedulerLogApp(Team::factory()->create());

    $this->get(schedulerLogUrl($application))->assertRedirect(route('login'));

    expect(Route::getRoutes()->getByName('servers.applications.cron-jobs.log')->gatherMiddleware())->toContain('throttle:30,1,scheduler-log');
});

test('a read is kept for two minutes', function () {
    $application = schedulerLogApp(Team::factory()->create());

    schedulerLogRead($application, new FakeSSHTransport);

    $this->travel(119)->seconds();

    expect(Cache::get(ReadSchedulerLog::cacheKey($application)))->not->toBeNull();

    $this->travel(1)->seconds();

    expect(Cache::get(ReadSchedulerLog::cacheKey($application)))->toBeNull();
});

test('reads are unique per app so polling cannot pile them up', function () {
    $application = schedulerLogApp(Team::factory()->create());
    $job = new ReadSchedulerLog($application);

    expect($job->uniqueId())->toBe((string) $application->id)
        ->and($job->queue)->toBe('default')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(45)
        ->and($job->uniqueFor)->toBe(15)
        ->and(ReadSchedulerLog::cacheKey($application))->toBe('scheduler-log:' . $application->id);
});
