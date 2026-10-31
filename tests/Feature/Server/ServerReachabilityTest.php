<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\HostKeyChangedException;
use App\Exceptions\SSHAuthenticationException;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\SSHCommandTimedOut;
use App\Jobs\CollectServerMetrics;
use App\Models\Application;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ServerReachableNotification;
use App\Notifications\ServerUnreachableNotification;
use App\Services\Panel\ActivityFeed;
use App\Services\Panel\FleetSnapshot;
use App\Services\Panel\ServerPresenter;
use App\Services\Server\MetricsCollector;
use App\Services\Server\ServerReachability;
use App\Services\SSH\SSHConnection;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Exception\ConnectionClosedException;
use phpseclib3\Exception\UnableToConnectException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;

function reachabilityServer(array $attributes = []): Server
{
    $team = Team::factory()->create();
    $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Member->value]);

    $server = Server::factory()->for($team)->create(array_merge([
        'name' => 'web-1',
        'ip_address' => '192.0.2.10',
        'status' => ServerStatus::Active,
        'host_key_fingerprint' => null,
    ], $attributes));

    Application::factory()->for($team)->for($server)->create([
        'domain' => 'app.example.com',
        'power_state' => PowerState::Sleeping,
    ]);

    return $server;
}

function reachabilityHealthy(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('top -bn1', "cpu=10\nmemory=40\ndisk=40\nload=0.1,0.1,0.1\n");
}

function reachabilityCollect(Server $server, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new CollectServerMetrics($server))->handle(app(SSHConnectionManager::class), app(MetricsCollector::class), app(ServerReachability::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 14:00:00'));
});

test('a new server has no failures and no outage on record', function () {
    $server = Server::factory()->create()->fresh();

    expect($server->unreachable_since)->toBeNull()
        ->and($server->connection_failures)->toBe(0)
        ->and($server->last_connection_error)->toBeNull();
});

test('three refused collections in a row make a server unreachable, once', function () {
    Notification::fake();
    $server = reachabilityServer();
    $member = $server->team->members()->first();
    $transport = (new FakeSSHTransport)->refuseConnections();

    reachabilityCollect($server, $transport);
    reachabilityCollect($server, $transport);

    expect($server->fresh()->unreachable_since)->toBeNull()
        ->and($server->fresh()->connection_failures)->toBe(2);

    Notification::assertNothingSent();

    reachabilityCollect($server, $transport);

    $reason = 'The connection to port 22 was refused or timed out. Check the server is running and its firewall allows SSH from this panel.';
    $unreachable = Activity::query()->where('description', 'server_unreachable')->sole();

    expect($server->fresh()->unreachable_since?->toDateTimeString())->toBe('2026-10-20 14:00:00')
        ->and($server->fresh()->connection_failures)->toBe(3)
        ->and($unreachable->subject_id)->toBe($server->id)
        ->and($unreachable->properties->toArray())->toBe(['error' => $reason]);

    Notification::assertSentToTimes($member, ServerUnreachableNotification::class, 1);
    Notification::assertSentTo($member, ServerUnreachableNotification::class, fn(ServerUnreachableNotification $notification): bool => $notification->server->is($server) && $notification->reason === $reason);

    reachabilityCollect($server, $transport);

    expect($server->fresh()->connection_failures)->toBe(4)
        ->and(Activity::query()->where('description', 'server_unreachable')->count())->toBe(1);

    Notification::assertSentToTimes($member, ServerUnreachableNotification::class, 1);
});

test('a long outage keeps counting without overflowing the column', function () {
    Notification::fake();
    $server = reachabilityServer(['unreachable_since' => now()->subDay(), 'connection_failures' => 255]);

    reachabilityCollect($server, (new FakeSSHTransport)->refuseConnections());

    expect($server->fresh()->connection_failures)->toBe(255)
        ->and($server->fresh()->unreachable_since?->toDateTimeString())->toBe('2026-10-19 14:00:00');

    Notification::assertNothingSent();
});

test('two misses and an answer are not an outage', function () {
    Notification::fake();
    $server = reachabilityServer(['last_connected_at' => now()->subHour()]);

    reachabilityCollect($server, (new FakeSSHTransport)->refuseConnections(1));
    reachabilityCollect($server, (new FakeSSHTransport)->refuseConnections(1));

    expect($server->fresh()->connection_failures)->toBe(2);

    reachabilityCollect($server, reachabilityHealthy());

    $fresh = $server->fresh();

    expect($fresh->unreachable_since)->toBeNull()
        ->and($fresh->connection_failures)->toBe(0)
        ->and($fresh->last_connection_error)->toBeNull()
        ->and($fresh->last_connected_at?->toDateTimeString())->toBe('2026-10-20 14:00:00')
        ->and(Activity::query()->whereIn('description', ['server_unreachable', 'server_reachable'])->count())->toBe(0);

    Notification::assertNothingSent();
});

test('the first answer after an outage clears it and says so', function (?int $answeredMinutesAgo, int $unreachableMinutesAgo, int $seconds, string $downFor) {
    Notification::fake();
    $server = reachabilityServer([
        'last_connected_at' => $answeredMinutesAgo === null ? null : now()->subMinutes($answeredMinutesAgo),
        'unreachable_since' => now()->subMinutes($unreachableMinutesAgo),
        'connection_failures' => $unreachableMinutesAgo,
        'last_connection_error' => 'The server took the connection but stopped answering. It may be overloaded or rebooting.',
    ]);
    $member = $server->team->members()->first();

    reachabilityCollect($server, reachabilityHealthy());

    $fresh = $server->fresh();
    $reachable = Activity::query()->where('description', 'server_reachable')->sole();

    expect($fresh->unreachable_since)->toBeNull()
        ->and($fresh->connection_failures)->toBe(0)
        ->and($fresh->last_connection_error)->toBeNull()
        ->and($fresh->last_connected_at?->toDateTimeString())->toBe('2026-10-20 14:00:00')
        ->and($reachable->subject_id)->toBe($server->id)
        ->and($reachable->properties->toArray())->toBe(['seconds' => $seconds]);

    Notification::assertSentTo($member, ServerReachableNotification::class, fn(ServerReachableNotification $notification): bool => $notification->server->is($server) && $notification->downFor === $downFor);

    reachabilityCollect($server, reachabilityHealthy());

    Notification::assertSentToTimes($member, ServerReachableNotification::class, 1);

    expect(Activity::query()->where('description', 'server_reachable')->count())->toBe(1);
})->with([
    'never answered before' => [null, 47, 2820, '47m 00s'],
    'last answered two hours five minutes ago' => [125, 122, 7500, '2 hours 5 minutes'],
]);

test('a changed host key is neither collected nor counted', function () {
    Queue::fake();
    $healthy = reachabilityServer(['name' => 'healthy']);
    $rekeyed = reachabilityServer(['name' => 'rekeyed', 'host_key_fingerprint' => 'SHA256:old', 'pending_host_key_fingerprint' => 'SHA256:new']);
    $transport = reachabilityHealthy()->setHostKey('a different host key');

    reachabilityCollect($rekeyed, $transport);

    expect($transport->calls())->toBe([])
        ->and($transport->commands())->toBe([])
        ->and($rekeyed->fresh()->connection_failures)->toBe(0)
        ->and(Activity::query()->where('description', 'ssh_host_key_changed')->exists())->toBeFalse();

    collect(app(Schedule::class)->events())->firstWhere('description', 'coresmith:collect-metrics')->run(app());

    Queue::assertPushed(CollectServerMetrics::class, 1);
    Queue::assertPushed(CollectServerMetrics::class, fn(CollectServerMetrics $job): bool => $job->uniqueId() === $healthy->id);
});

test('each kind of failure says what to check', function (FakeSSHTransport $transport, string $expected) {
    $server = reachabilityServer(['ssh_port' => 2222, 'ssh_user' => 'deploy']);

    reachabilityCollect($server, $transport);

    expect($server->fresh()->last_connection_error)->toBe($expected)
        ->and($server->fresh()->connection_failures)->toBe(1);
})->with([
    'a refused connection' => [
        fn() => (new FakeSSHTransport)->refuseConnections(),
        'The connection to port 2222 was refused or timed out. Check the server is running and its firewall allows SSH from this panel.',
    ],
    'a refused key' => [
        fn() => (new FakeSSHTransport)->failLogin(),
        "The server refused Coresmith's key for deploy. Check the key is still in ~/.ssh/authorized_keys.",
    ],
    'a dropped connection' => [
        fn() => (new FakeSSHTransport)->dropConnectionOn('top -bn1'),
        'The server took the connection but stopped answering. It may be overloaded or rebooting.',
    ],
    'a command that never finishes' => [
        fn() => (new FakeSSHTransport)->timeOutOn('top -bn1'),
        'The server took the connection but stopped answering. It may be overloaded or rebooting.',
    ],
]);

test('only failures to reach the server count against it', function (Throwable $failure, bool $counts) {
    expect(ServerReachability::isConnectionFailure($failure))->toBe($counts);
})->with([
    'a refused connection' => [new UnableToConnectException('Connection refused'), true],
    'a closed connection' => [new ConnectionClosedException('Connection closed by server'), true],
    'a refused key' => [new SSHAuthenticationException('SSH authentication failed'), true],
    'a command that timed out' => [new SSHCommandTimedOut('uptime', 20, ''), true],
    'a command that failed' => [new SSHCommandFailedException('', 1), false],
    'a changed host key' => [HostKeyChangedException::forServer('192.0.2.10', 'SHA256:old', 'SHA256:new'), false],
    'anything else' => [new RuntimeException('Something broke'), false],
]);

test('a failing probe command is not an outage', function () {
    $server = reachabilityServer();

    expect(fn() => reachabilityCollect($server, (new FakeSSHTransport)->respondTo('top -bn1', '', 1)))->toThrow(SSHCommandFailedException::class);

    expect($server->fresh()->connection_failures)->toBe(0)
        ->and($server->fresh()->unreachable_since)->toBeNull()
        ->and(Activity::query()->where('description', 'server_unreachable')->exists())->toBeFalse();
});

test("a hung server times out inside the job's own limit", function () {
    $server = reachabilityServer();
    $transport = reachabilityHealthy();

    reachabilityCollect($server, $transport);

    $job = new CollectServerMetrics($server);

    expect(MetricsCollector::PROBE_SECONDS)->toBe(20)
        ->and($transport->budgetFor('top -bn1'))->toBe(20)
        ->and($transport->budgetFor('access.log'))->toBe(20)
        ->and($transport->budgetFor('journalctl'))->toBe(20)
        ->and($job->timeout)->toBe(120)
        ->and($job->timeout)->toBeGreaterThan(SSHConnection::TIMEOUT_SECONDS + 3 * MetricsCollector::PROBE_SECONDS)
        ->and($job->tries)->toBe(1)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe($server->id)
        ->and($job->uniqueFor)->toBe(180);
});

test('a server is never queued for collection twice at once', function () {
    Queue::fake();
    $first = reachabilityServer();
    $second = reachabilityServer(['name' => 'web-2']);

    CollectServerMetrics::dispatch($first);
    CollectServerMetrics::dispatch($first);
    CollectServerMetrics::dispatch($second);

    Queue::assertPushed(CollectServerMetrics::class, 2);
});

test('people who turned server problems off are not emailed', function () {
    Notification::fake();
    $server = reachabilityServer();
    $listening = $server->team->members()->first();
    $quiet = User::factory()->create(['notification_preferences' => ['server_alerts' => false]]);
    $server->team->members()->attach($quiet, ['role' => TeamRole::Member->value]);
    $transport = (new FakeSSHTransport)->refuseConnections(3);

    reachabilityCollect($server, $transport);
    reachabilityCollect($server, $transport);
    reachabilityCollect($server, $transport);
    reachabilityCollect($server, reachabilityHealthy());

    Notification::assertSentTo($listening, ServerUnreachableNotification::class);
    Notification::assertSentTo($listening, ServerReachableNotification::class);
    Notification::assertNotSentTo($quiet, ServerUnreachableNotification::class);
    Notification::assertNotSentTo($quiet, ServerReachableNotification::class);
});

test('the fleet puts an unreachable server first and a changed key still wins', function () {
    $team = Team::factory()->create();
    $full = Server::factory()->for($team)->create(['name' => 'full-disk', 'status' => ServerStatus::Active, 'created_at' => now()->subWeek()]);
    ServerMetric::factory()->for($full)->create(['disk_percent' => 91.4, 'recorded_at' => now()->subMinute()]);
    $down = Server::factory()->for($team)->create(['name' => 'down-1', 'status' => ServerStatus::Active, 'unreachable_since' => now()->subMinutes(5), 'created_at' => now()->subDay()]);
    $moved = Server::factory()->for($team)->create(['name' => 'moved', 'status' => ServerStatus::Active, 'unreachable_since' => now()->subMinutes(5), 'pending_host_key_fingerprint' => 'SHA256:new', 'created_at' => now()]);

    $fleet = new FleetSnapshot($team);

    expect($fleet->state($down->fresh()))->toBe(['key' => 'unreachable', 'label' => 'Unreachable', 'tone' => 'destructive'])
        ->and($fleet->state($moved->fresh()))->toBe(['key' => 'host_key', 'label' => 'Host key changed', 'tone' => 'destructive'])
        ->and($fleet->attention())->toBe([
            ['severity' => 'critical', 'subject' => 'down-1', 'summary' => 'unreachable', 'href' => route('servers.show', $down)],
            ['severity' => 'critical', 'subject' => 'moved', 'summary' => 'host key changed', 'href' => route('servers.show', $moved)],
            ['severity' => 'warning', 'subject' => 'full-disk', 'summary' => 'disk 91%', 'href' => route('servers.show', $full)],
        ]);
});

test('an unreachable server ranks below a changed key, failed provisioning and a disconnected status, and above the rest', function (array $attributes, string $key) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(array_merge(['status' => ServerStatus::Active, 'unreachable_since' => now()->subMinutes(5)], $attributes));

    expect((new FleetSnapshot($team))->state($server->fresh())['key'])->toBe($key);
})->with([
    'a changed host key' => [['pending_host_key_fingerprint' => 'SHA256:new'], 'host_key'],
    'failed provisioning' => [['status' => ServerStatus::Failed], 'failed'],
    'a disconnected status' => [['status' => ServerStatus::Disconnected], 'disconnected'],
    'provisioning' => [['status' => ServerStatus::Provisioning], 'unreachable'],
    'not connected yet' => [['status' => ServerStatus::Pending], 'unreachable'],
    'nothing else wrong' => [[], 'unreachable'],
]);

test('the server header carries the outage', function (?int $answeredMinutesAgo, ?string $lastAnswer) {
    $server = reachabilityServer([
        'last_connected_at' => $answeredMinutesAgo === null ? null : now()->subMinutes($answeredMinutesAgo),
        'unreachable_since' => now()->subMinutes(47),
        'last_connection_error' => 'The server took the connection but stopped answering. It may be overloaded or rebooting.',
    ]);
    $user = $server->team->members()->first();

    expect(app(ServerPresenter::class)->header($server->fresh(), $user)['unreachable'])->toBe([
        'last_answer' => $lastAnswer,
        'reason' => 'The server took the connection but stopped answering. It may be overloaded or rebooting.',
    ]);
})->with([
    'a known last answer' => [50, 'today 13:10'],
    'never answered' => [null, null],
]);

test('the server header carries no outage for a reachable server', function () {
    $server = reachabilityServer();

    expect(app(ServerPresenter::class)->header($server->fresh(), $server->team->members()->first())['unreachable'])->toBeNull();
});

test('the feed says it stopped and came back', function (?int $seconds, string $came) {
    $server = reachabilityServer();

    activity()->performedOn($server)->withProperties(['error' => 'The server took the connection but stopped answering. It may be overloaded or rebooting.'])->log('server_unreachable');
    $this->travel(1)->minutes();
    activity()->performedOn($server)->withProperties($seconds === null ? [] : ['seconds' => $seconds])->log('server_reachable');

    $rows = (new ActivityFeed)->forServer($server);

    expect(array_map(fn(array $row): string => implode('', array_column($row['parts'], 'text')), $rows))->toBe([
        $came,
        'web-1 stopped answering over SSH',
    ])->and(array_column($rows, 'href'))->toBe([route('servers.show', $server), route('servers.show', $server)]);
})->with([
    'under a minute' => [38, 'web-1 answering again after 38s'],
    'under an hour' => [2820, 'web-1 answering again after 47m 00s'],
    'over an hour' => [7500, 'web-1 answering again after 2 hours 5 minutes'],
    'over a day' => [93600, 'web-1 answering again after 1 day 2 hours'],
    'no duration recorded' => [null, 'web-1 answering again'],
]);

test('the mails say what happened and what to check', function () {
    $server = reachabilityServer(['last_connected_at' => now()->subMinutes(30), 'unreachable_since' => now()]);
    $reason = 'The server took the connection but stopped answering. It may be overloaded or rebooting.';

    $down = (new ServerUnreachableNotification($server, $reason))->toMail(new User);
    $up = (new ServerReachableNotification($server, '47m 00s'))->toMail(new User);

    expect($down->subject)->toBe('web-1 is not answering')
        ->and($down->introLines)->toBe([
            'Coresmith has not been able to reach web-1 (192.0.2.10) over SSH since Oct 20, 2026 at 13:30 UTC.',
            $reason,
            'Its apps may still be serving. Coresmith checks every minute and emails again when it answers.',
        ])
        ->and($down->actionText)->toBe('Open web-1')
        ->and($down->actionUrl)->toBe(route('servers.show', $server))
        ->and($up->subject)->toBe('web-1 is answering again')
        ->and($up->introLines)->toBe(['Coresmith reached web-1 again after 47m 00s without an answer.'])
        ->and($up->actionText)->toBe('Open web-1')
        ->and($up->actionUrl)->toBe(route('servers.show', $server));
});

test('the unreachable mail counts from the outage itself when the server never answered', function () {
    $server = reachabilityServer(['last_connected_at' => null, 'unreachable_since' => now()->subMinutes(3)]);

    $mail = (new ServerUnreachableNotification($server, 'The server took the connection but stopped answering. It may be overloaded or rebooting.'))->toMail(new User);

    expect($mail->introLines[0])->toBe('Coresmith has not been able to reach web-1 (192.0.2.10) over SSH since Oct 20, 2026 at 13:57 UTC.');
});

test('both mails are queued emails and nothing else', function (object $notification) {
    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via(new User))->toBe(['mail']);
})->with([
    'unreachable' => fn() => new ServerUnreachableNotification(new Server, 'The server is not answering.'),
    'reachable' => fn() => new ServerReachableNotification(new Server, '47m 00s'),
]);
