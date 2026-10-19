<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Events\DeploymentUpdated;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DeployApplication;
use App\Jobs\DetectStaleDeployments;
use App\Jobs\InstallRuntimes;
use App\Listeners\FollowUpAutoDeploy;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\User;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProbeOutput;

function autoDeployApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'repository_url' => 'https://github.com/monoven/site.git',
        'repository_branch' => 'main',
        'quick_deploy' => true,
    ], $attributes));
}

function autoDeploySha(string $character): string
{
    return str_repeat($character, 40);
}

function autoDeployQueued(DeployApplication $job): Deployment
{
    return (fn(): Deployment => $this->deployment)->call($job);
}

function autoDeployTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('df /', "40\n", 0);
}

function autoDeployRunFollowUps(): void
{
    Queue::pushed(CallQueuedListener::class, fn(CallQueuedListener $job): bool => $job->class === FollowUpAutoDeploy::class)
        ->each(fn(CallQueuedListener $job) => app(FollowUpAutoDeploy::class)->handle(...array_values($job->data)));
}

function autoDeployRun(Deployment $deployment, ?FakeSSHTransport $transport = null): void
{
    $transport ??= autoDeployTransport();

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, '20261018-140500'))->handle(
            app(SSHConnectionManager::class),
            new DeploymentCommands(new AsdfManager),
        );
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-18 14:05:00'));
});

test('nothing is deployed while auto deploy is off', function () {
    $application = autoDeployApp(['quick_deploy' => false]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('a'), 'push'))->toBeNull();

    $fresh = $application->fresh();

    expect($fresh->repository_head)->toBe(autoDeploySha('a'))
        ->and($fresh->repository_head_source)->toBe('push')
        ->and($fresh->repository_head_at?->toDateTimeString())->toBe('2026-10-18 14:05:00')
        ->and(Deployment::count())->toBe(0);

    Queue::assertNothingPushed();
});

test('a new head is deployed once', function () {
    $application = autoDeployApp();

    $deployment = app(AutoDeploy::class)->observe($application, autoDeploySha('a'), 'push');

    expect($deployment)->toBeInstanceOf(Deployment::class)
        ->and($deployment->fresh()->only(['application_id', 'branch', 'trigger', 'requested_commit', 'commit_hash', 'status']))->toBe([
            'application_id' => $application->id,
            'branch' => 'main',
            'trigger' => 'push',
            'requested_commit' => autoDeploySha('a'),
            'commit_hash' => null,
            'status' => DeploymentStatus::Pending,
        ]);

    Queue::assertPushedOn('deployments', DeployApplication::class, fn(DeployApplication $job): bool => autoDeployQueued($job)->is($deployment));

    expect(app(AutoDeploy::class)->observe($application->fresh(), autoDeploySha('a'), 'push'))->toBeNull()
        ->and(Deployment::count())->toBe(1);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a head that already shipped is left alone', function () {
    $application = autoDeployApp();
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('a')]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('a'), 'poll'))->toBeNull()
        ->and(Deployment::count())->toBe(1);

    Queue::assertNothingPushed();
});

test('a head that failed is not retried, the next one is', function () {
    $application = autoDeployApp();
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('c'), 'created_at' => now()->subHour()]);
    Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Failed,
        'commit_hash' => null,
        'requested_commit' => autoDeploySha('a'),
    ]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('a'), 'poll'))->toBeNull();

    Queue::assertNothingPushed();

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('b'), 'poll')?->requested_commit)->toBe(autoDeploySha('b'));

    Queue::assertPushed(DeployApplication::class, 1);
});

test('cancelled deployments do not count', function () {
    $application = autoDeployApp();
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('c'), 'created_at' => now()->subHour()]);
    Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Cancelled,
        'commit_hash' => null,
        'requested_commit' => autoDeploySha('a'),
    ]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('a'), 'push')?->requested_commit)->toBe(autoDeploySha('a'));

    Queue::assertPushed(DeployApplication::class, 1);
});

test('nothing is queued while another deployment is pending or running', function (DeploymentStatus $status) {
    $application = autoDeployApp();
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('a'), 'created_at' => now()->subHour()]);
    Deployment::factory()->for($application)->create(['status' => $status, 'commit_hash' => null, 'requested_commit' => null]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('b'), 'push'))->toBeNull()
        ->and(Deployment::count())->toBe(2);

    Queue::assertNothingPushed();
})->with([
    'pending' => [DeploymentStatus::Pending],
    'running' => [DeploymentStatus::Running],
]);

test('a push that lands during a deploy is deployed when that deploy finishes', function () {
    $application = autoDeployApp();
    $running = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'commit_hash' => null,
        'requested_commit' => autoDeploySha('a'),
    ]);

    expect(app(AutoDeploy::class)->observe($application->fresh(), autoDeploySha('b'), 'push'))->toBeNull();

    $running->forceFill(['status' => DeploymentStatus::Succeeded, 'commit_hash' => autoDeploySha('a')])->save();

    $next = app(AutoDeploy::class)->afterDeployment($running);

    expect($next?->requested_commit)->toBe(autoDeploySha('b'))
        ->and($next?->trigger)->toBe('push');

    Queue::assertPushed(DeployApplication::class, 1);
});

test('auto deploy keeps the app on its branch head', function () {
    $behind = autoDeployApp(['repository_head' => autoDeploySha('b')]);
    $current = autoDeployApp(['repository_head' => autoDeploySha('a')]);

    foreach ([$behind, $current] as $application) {
        Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('a')]);
    }

    $deployment = app(AutoDeploy::class)->reconcile($behind);

    expect($deployment?->requested_commit)->toBe(autoDeploySha('b'))
        ->and($deployment?->trigger)->toBe('poll')
        ->and(app(AutoDeploy::class)->reconcile($current))->toBeNull();

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a deploy that failed before git named its commit is passed over when comparing heads', function (string $head, int $queued) {
    $application = autoDeployApp(['repository_head' => autoDeploySha($head), 'repository_head_source' => 'poll']);
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('a'), 'created_at' => now()->subHour()]);
    Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Failed,
        'triggered_by' => User::factory()->create()->id,
        'commit_hash' => null,
        'requested_commit' => null,
    ]);

    app(AutoDeploy::class)->reconcile($application);

    expect(Queue::pushed(DeployApplication::class))->toHaveCount($queued);
})->with([
    'the live head' => ['a', 0],
    'a newer head' => ['b', 1],
]);

test('nothing is deployed until a head has been seen', function () {
    $application = autoDeployApp();
    $finished = Deployment::factory()->for($application)->succeeded()->create();

    expect(app(AutoDeploy::class)->reconcile($application->fresh()))->toBeNull()
        ->and(app(AutoDeploy::class)->afterDeployment($finished))->toBeNull();

    Queue::assertNothingPushed();
});

test('two observers racing for one head queue one deployment', function () {
    $application = autoDeployApp(['repository_head' => autoDeploySha('a'), 'repository_head_source' => 'push']);
    $held = Cache::lock('auto-deploy:' . $application->id, 10);
    $held->get();

    expect(app(AutoDeploy::class)->reconcile($application))->toBeNull();

    Queue::assertNothingPushed();

    $held->release();

    expect(app(AutoDeploy::class)->reconcile($application))->toBeInstanceOf(Deployment::class);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a sha that is not a full commit is ignored', function (string $sha) {
    $application = autoDeployApp();

    expect(app(AutoDeploy::class)->observe($application, $sha, 'push'))->toBeNull()
        ->and($application->fresh()->repository_head)->toBeNull();

    Queue::assertNothingPushed();
})->with([
    'abbreviated' => ['abc123'],
    'in capitals' => [str_repeat('A', 40)],
    'one character too long' => [str_repeat('a', 41)],
    'with a trailing newline' => [str_repeat('a', 40) . "\n"],
]);

test('a removed app is never deployed', function () {
    $application = autoDeployApp();
    $finished = Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('a')]);
    $application->delete();

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('b'), 'push'))->toBeNull()
        ->and(app(AutoDeploy::class)->afterDeployment($finished))->toBeNull();

    Queue::assertNothingPushed();
});

test('a deploy releases its lock before auto deploy looks for the next head', function () {
    $standIn = new class
    {
        public array $sawLockFree = [];

        public function afterDeployment(Deployment $deployment): ?Deployment
        {
            $this->sawLockFree[] = Cache::lock('deploy:' . $deployment->application_id, 1)->get();

            return null;
        }
    };

    app()->instance(AutoDeploy::class, $standIn);
    $deployment = Deployment::factory()->for(autoDeployApp())->create();

    autoDeployRun($deployment);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($standIn->sawLockFree)->toBe([true]);
});

test('a deploy that hands off to runtime installs does not follow up yet', function () {
    $standIn = new class
    {
        public int $calls = 0;

        public function afterDeployment(Deployment $deployment): ?Deployment
        {
            $this->calls++;

            return null;
        }
    };

    app()->instance(AutoDeploy::class, $standIn);
    $deployment = Deployment::factory()->for(autoDeployApp(['php_version' => null, 'run_migrations' => false]))->create();
    $transport = autoDeployTransport()
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['composer.json' => '{"require":{"php":"^8.4"}}']))
        ->respondTo("ls -1 '/opt/asdf-data/installs/php'", "\n");

    autoDeployRun($deployment, $transport);

    Queue::assertPushed(InstallRuntimes::class, 1);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Running)
        ->and($standIn->calls)->toBe(0);
});

test('a follow up that throws is reported and never hides how the deploy ended', function () {
    Exceptions::fake();
    app()->instance(AutoDeploy::class, new class
    {
        public function afterDeployment(Deployment $deployment): ?Deployment
        {
            throw new RuntimeException('The follow up broke.');
        }
    });
    $deployment = Deployment::factory()->for(autoDeployApp(['run_migrations' => true]))->create();
    $transport = autoDeployTransport()->respondTo('artisan migrate', '', 1);

    expect(fn() => autoDeployRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);

    autoDeployRunFollowUps();

    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn(RuntimeException $e): bool => $e->getMessage() === 'The follow up broke.');
});

test('a failed deploy is followed up from the queue fifteen seconds later', function () {
    $application = autoDeployApp(['repository_head' => autoDeploySha('b'), 'repository_head_source' => 'push']);
    $failed = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Failed,
        'commit_hash' => null,
        'requested_commit' => autoDeploySha('a'),
    ]);

    DeploymentFailed::dispatch($failed);

    Queue::assertPushedOn('default', CallQueuedListener::class, fn(CallQueuedListener $job): bool => $job->class === FollowUpAutoDeploy::class);
    Queue::assertNotPushed(DeployApplication::class);

    expect(Queue::delayedJobs('default')->pluck('name')->all())->toBe([FollowUpAutoDeploy::class])
        ->and(get_class_vars(FollowUpAutoDeploy::class)['delay'])->toBe(15);

    autoDeployRunFollowUps();

    Queue::assertPushed(DeployApplication::class, fn(DeployApplication $job): bool => autoDeployQueued($job)->requested_commit === autoDeploySha('b'));
});

test('a deploy that stalls is followed up once it is marked failed', function () {
    $application = autoDeployApp();
    $stalled = Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'commit_hash' => null,
        'requested_commit' => autoDeploySha('a'),
        'started_at' => now()->subMinutes(30),
        'stale_after' => now()->subMinutes(15),
    ]);

    expect(app(AutoDeploy::class)->observe($application->fresh(), autoDeploySha('b'), 'push'))->toBeNull();

    (new DetectStaleDeployments)->handle();

    expect($stalled->fresh()->status)->toBe(DeploymentStatus::Failed);

    Queue::assertNotPushed(DeployApplication::class);

    autoDeployRunFollowUps();

    expect(Deployment::query()->where('requested_commit', autoDeploySha('b'))->count())->toBe(1);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a manual deploy of a commit the poller has not seen is not deployed again', function (?string $head, ?string $source, ?string $seenAt) {
    $application = autoDeployApp([
        'repository_head' => $head,
        'repository_head_source' => $source,
        'repository_head_at' => $seenAt,
    ]);
    $other = autoDeployApp([
        'repository_head' => autoDeploySha('e'),
        'repository_head_source' => 'poll',
        'repository_head_at' => '2026-10-18 13:05:00',
    ]);
    $deployment = Deployment::factory()->for($application)->create(['triggered_by' => User::factory()->create()->id, 'commit_hash' => null]);

    autoDeployRun($deployment, autoDeployTransport()->respondTo('log -1 --format', autoDeploySha('b') . "\nAda Lovelace\nShip the release screen\n"));

    $fresh = $application->fresh();

    expect($deployment->fresh()->only(['status', 'commit_hash']))->toBe(['status' => DeploymentStatus::Succeeded, 'commit_hash' => autoDeploySha('b')])
        ->and($fresh->repository_head)->toBe(autoDeploySha('b'))
        ->and($fresh->repository_head_source)->toBe($source)
        ->and($fresh->repository_head_at?->toDateTimeString())->toBe('2026-10-18 14:05:00')
        ->and($other->fresh()->repository_head)->toBe(autoDeploySha('e'))
        ->and($other->fresh()->repository_head_at?->toDateTimeString())->toBe('2026-10-18 13:05:00');

    Queue::assertNotPushed(DeployApplication::class);
})->with([
    'a head polled before the deploy' => [str_repeat('a', 40), 'poll', '2026-10-18 14:00:00'],
    'no head seen yet' => [null, null, null],
]);

test('a push that lands during a deploy is still deployed after it', function () {
    $application = autoDeployApp([
        'repository_head' => autoDeploySha('a'),
        'repository_head_source' => 'push',
        'repository_head_at' => now()->subMinutes(5),
    ]);
    $deployment = Deployment::factory()->for($application)->create(['trigger' => 'push', 'requested_commit' => autoDeploySha('a'), 'commit_hash' => null]);
    $landed = false;

    Event::listen(DeploymentUpdated::class, function () use ($application, &$landed): void {
        if (!$landed) {
            $landed = true;
            app(AutoDeploy::class)->observe($application->fresh(), autoDeploySha('c'), 'push');
        }
    });

    autoDeployRun($deployment, autoDeployTransport()->respondTo('log -1 --format', autoDeploySha('b') . "\nAda Lovelace\nShip the release screen\n"));

    expect($landed)->toBeTrue()
        ->and($deployment->fresh()->commit_hash)->toBe(autoDeploySha('b'))
        ->and($application->fresh()->repository_head)->toBe(autoDeploySha('c'));

    Queue::assertPushed(DeployApplication::class, 1);
    Queue::assertPushed(DeployApplication::class, fn(DeployApplication $job): bool => autoDeployQueued($job)->requested_commit === autoDeploySha('c'));
});

test('the log says what triggered an automatic deploy', function (string $trigger, string $ending) {
    $deployment = Deployment::factory()->for(autoDeployApp())->create(['trigger' => $trigger]);

    autoDeployRun($deployment);

    expect((preg_split('/\R/', (string) $deployment->fresh()->log) ?: [])[0])->toEndWith($ending);
})->with([
    'polling' => ['poll', 'triggered by polling'],
    'a push' => ['push', 'triggered by push'],
]);

test('a push older than the last one seen changes nothing', function (string $pushedAt) {
    $application = autoDeployApp([
        'repository_head' => autoDeploySha('b'),
        'repository_head_source' => 'push',
        'repository_head_at' => '2026-10-18 13:55:00',
        'repository_pushed_at' => '2026-10-18 13:50:00',
    ]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('a'), 'push', CarbonImmutable::parse($pushedAt)))->toBeNull();

    $fresh = $application->fresh();

    expect($fresh->repository_head)->toBe(autoDeploySha('b'))
        ->and($fresh->repository_head_at?->toDateTimeString())->toBe('2026-10-18 13:55:00')
        ->and($fresh->repository_pushed_at?->toDateTimeString())->toBe('2026-10-18 13:50:00')
        ->and(Deployment::count())->toBe(0);

    Queue::assertNothingPushed();
})->with([
    'an earlier push' => '2026-10-18 13:40:00',
    'another commit pushed at the same moment' => '2026-10-18 13:50:00',
]);

test('the same push seen again is still deployed when its first attempt never queued', function () {
    $application = autoDeployApp([
        'repository_head' => autoDeploySha('a'),
        'repository_head_source' => 'push',
        'repository_pushed_at' => '2026-10-18 13:50:00',
    ]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('a'), 'push', CarbonImmutable::parse('2026-10-18 13:50:00')))
        ->toBeInstanceOf(Deployment::class);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a newer push moves the head and records when it was pushed', function () {
    $application = autoDeployApp([
        'repository_head' => autoDeploySha('a'),
        'repository_head_source' => 'push',
        'repository_pushed_at' => '2026-10-18 13:50:00',
    ]);
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('a')]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('b'), 'push', CarbonImmutable::parse('2026-10-18 14:00:00')))
        ->toBeInstanceOf(Deployment::class);

    $fresh = $application->fresh();

    expect($fresh->repository_head)->toBe(autoDeploySha('b'))
        ->and($fresh->repository_pushed_at?->toDateTimeString())->toBe('2026-10-18 14:00:00');
});

test('a poll still observes and keeps the time of the last push', function () {
    $application = autoDeployApp([
        'repository_head' => autoDeploySha('a'),
        'repository_head_source' => 'push',
        'repository_pushed_at' => '2026-10-18 13:50:00',
    ]);
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('a')]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('b'), 'poll'))->toBeInstanceOf(Deployment::class);

    $fresh = $application->fresh();

    expect($fresh->repository_head)->toBe(autoDeploySha('b'))
        ->and($fresh->repository_head_source)->toBe('poll')
        ->and($fresh->repository_pushed_at?->toDateTimeString())->toBe('2026-10-18 13:50:00');
});

test('the push time column is nullable and reverses cleanly', function () {
    $migration = require base_path('database/migrations/2026_10_18_200500_add_pushed_at_to_applications.php');

    expect(collect(Schema::getColumns('applications'))->firstWhere('name', 'repository_pushed_at')['nullable'] ?? null)->toBeTrue();

    $migration->down();

    expect(Schema::hasColumn('applications', 'repository_pushed_at'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('applications', 'repository_pushed_at'))->toBeTrue();
});

test('a check is concluded against the head stored now, not the one it started from', function (?string $pushedMeanwhile, string $found, string $head, string $source) {
    $application = autoDeployApp(['repository_head' => autoDeploySha('a'), 'repository_head_source' => 'poll']);
    Deployment::factory()->for($application)->succeeded()->create(['commit_hash' => autoDeploySha('a'), 'created_at' => now()->subHour()]);
    $started = $application->fresh();

    if ($pushedMeanwhile !== null) {
        Application::query()->whereKey($application->id)->update(['repository_head' => autoDeploySha($pushedMeanwhile), 'repository_head_source' => 'push']);
    }

    app(AutoDeploy::class)->checked($started, autoDeploySha($found), null);

    expect($application->fresh()->only(['repository_head', 'repository_head_source']))->toBe(['repository_head' => autoDeploySha($head), 'repository_head_source' => $source]);
})->with([
    'a new head, nothing changed meanwhile' => [null, 'b', 'b', 'poll'],
    'the head a push stored meanwhile' => ['b', 'b', 'b', 'push'],
    'an older head than a push stored meanwhile' => ['c', 'b', 'c', 'push'],
]);

test('a check writes its error cut to fit and still reconciles', function () {
    $application = autoDeployApp(['repository_head' => autoDeploySha('a'), 'repository_head_source' => 'push']);

    $deployment = app(AutoDeploy::class)->checked($application, null, 'GitHub refused the check. ' . str_repeat('a', 300));

    expect(mb_strlen((string) $application->fresh()->poll_error))->toBe(255)
        ->and($deployment?->requested_commit)->toBe(autoDeploySha('a'));

    app(AutoDeploy::class)->checked($application, autoDeploySha('a'), null);

    expect($application->fresh()->poll_error)->toBeNull();
});

test('a check whose app is gone concludes nothing', function () {
    $application = autoDeployApp();
    $application->forceDelete();

    expect(app(AutoDeploy::class)->checked($application, autoDeploySha('a'), null))->toBeNull()
        ->and(Deployment::count())->toBe(0);
});
