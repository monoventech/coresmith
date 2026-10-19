<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Events\DeploymentUpdated;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DeployApplication;
use App\Jobs\DetectStaleDeployments;
use App\Listeners\FollowUpAutoDeploy;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\User;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

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
    Deployment::factory()->for($application)->create(['status' => $status, 'commit_hash' => null, 'requested_commit' => autoDeploySha('a')]);

    expect(app(AutoDeploy::class)->observe($application, autoDeploySha('b'), 'push'))->toBeNull()
        ->and(Deployment::count())->toBe(1);

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

test('a manual deploy of a commit the poller has not seen is not deployed again', function () {
    $application = autoDeployApp([
        'repository_head' => autoDeploySha('a'),
        'repository_head_source' => 'poll',
        'repository_head_at' => now()->subMinutes(5),
    ]);
    $deployment = Deployment::factory()->for($application)->create(['triggered_by' => User::factory()->create()->id, 'commit_hash' => null]);

    autoDeployRun($deployment, autoDeployTransport()->respondTo('log -1 --format', autoDeploySha('b') . "\nAda Lovelace\nShip the release screen\n"));

    $fresh = $application->fresh();

    expect($deployment->fresh()->only(['status', 'commit_hash']))->toBe(['status' => DeploymentStatus::Succeeded, 'commit_hash' => autoDeploySha('b')])
        ->and($fresh->repository_head)->toBe(autoDeploySha('b'))
        ->and($fresh->repository_head_source)->toBe('poll')
        ->and($fresh->repository_head_at?->toDateTimeString())->toBe('2026-10-18 14:05:00');

    Queue::assertNotPushed(DeployApplication::class);
});

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
