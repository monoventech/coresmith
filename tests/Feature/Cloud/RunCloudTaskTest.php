<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudProviderType;
use App\Exceptions\CloudProviderException;
use App\Jobs\ResumeCloudTasks;
use App\Jobs\RunCloudTask;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\User;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\HttpCloudApiFactory;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Cloud\Tasks\CloudTaskHandler;
use App\Services\Cloud\Tasks\CloudTaskHandlers;
use App\Services\Cloud\Tasks\StepResult;
use App\Services\Deployment\LogSanitizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeCloudApiFactory;

beforeEach(function () {
    Http::preventStrayRequests();

    app()->instance(CloudApiFactory::class, new FakeCloudApiFactory);
    app()->instance(CloudTaskHandlers::class, new CloudTaskHandlers(['probe' => cloudTaskRecorder()]));
});

function cloudTaskRecorder(): CloudTaskHandler
{
    return new class implements CloudTaskHandler
    {
        public array $results = [];

        public array $calls = [];

        public function step(Server $server, CloudTask $task, CloudApi $api): StepResult
        {
            return $this->answer('step', $server, $task, $api);
        }

        public function expired(Server $server, CloudTask $task, CloudApi $api): StepResult
        {
            return $this->answer('expired', $server, $task, $api);
        }

        public function finished(Server $server, CloudTask $task, CloudApi $api): void
        {
            $this->record('finished', $server, $task, $api);
        }

        public function failed(Server $server, CloudTask $task, ?CloudApi $api, string $reason): void
        {
            $this->record('failed', $server, $task, $api, $reason);
        }

        private function answer(string $method, Server $server, CloudTask $task, CloudApi $api): StepResult
        {
            $this->record($method, $server, $task, $api);

            $result = array_shift($this->results) ?? throw new LogicException("Nothing is queued for {$method}.");
            $result = $result instanceof Closure ? $result($server) : $result;

            if ($result instanceof Throwable) {
                throw $result;
            }

            return $result;
        }

        private function record(string $method, Server $server, CloudTask $task, ?CloudApi $api, ?string $reason = null): void
        {
            $this->calls[] = ['method' => $method, 'server' => $server, 'task' => $task, 'api' => $api, 'reason' => $reason];
        }
    };
}

function cloudTaskHandler(): CloudTaskHandler
{
    return app(CloudTaskHandlers::class)->for('probe');
}

function cloudTaskResults(StepResult|Throwable|Closure ...$results): void
{
    cloudTaskHandler()->results = $results;
}

function cloudTaskCalls(): array
{
    return array_map(fn(array $call): string => "{$call['method']} {$call['task']->step}", cloudTaskHandler()->calls);
}

function cloudTaskApis(): FakeCloudApiFactory
{
    return app(CloudApiFactory::class);
}

function cloudTaskServer(array $task = []): Server
{
    $account = CloudProvider::factory()->create(['api_token' => 'the-account-token']);
    $server = Server::factory()->fromProvider($account)->create();

    CloudTask::start($server, 'probe', 'one', [], User::factory()->create());

    if ($task !== []) {
        $server->forceFill(['provider_task' => array_replace($server->provider_task, $task)])->save();
    }

    return $server;
}

function cloudTaskRun(Server $server): void
{
    (new RunCloudTask($server))->handle(app(CloudApiFactory::class), app(CloudTaskHandlers::class), app(LogSanitizer::class));
}

function cloudTaskOf(Server $server): ?CloudTask
{
    return CloudTask::of($server->fresh());
}

function cloudTaskFailure(CloudFailure $failure, ?string $detail = null, ?int $status = null): CloudProviderException
{
    return new CloudProviderException($failure, CloudProviderType::Hetzner, $detail, $status);
}

function cloudTaskSlept(int $seconds): Closure
{
    return fn(CarbonInterval $duration): bool => (int) $duration->totalSeconds === $seconds;
}

test('a task runs its steps in one go until it finishes', function () {
    $server = cloudTaskServer();
    cloudTaskResults(StepResult::next('two', ['uncertain' => 0]), StepResult::done());

    RunCloudTask::dispatch($server);

    expect(cloudTaskCalls())->toBe(['step one', 'step two', 'finished two'])
        ->and(cloudTaskOf($server))
        ->state->toBe(CloudTask::DONE)
        ->data->toBe(['uncertain' => 0])
        ->and(cloudTaskHandler()->calls[1]['task']->data)->toBe(['uncertain' => 0])
        ->and(cloudTaskHandler()->calls[0]['api'])->toBe(cloudTaskApis()->api)
        ->and(array_unique(cloudTaskApis()->accounts()))->toBe([(string) $server->cloud_provider_id]);
    Sleep::assertNeverSlept();
});

test('a short wait sleeps and carries on', function () {
    $server = cloudTaskServer();
    cloudTaskResults(StepResult::wait(5, ['polls' => 1]), StepResult::done());

    cloudTaskRun($server);

    Sleep::assertSlept(fn($d) => (int) $d->totalSeconds === 5, 1);
    Sleep::assertSleptTimes(1);
    expect(cloudTaskCalls())->toBe(['step one', 'step one', 'finished one'])
        ->and(cloudTaskOf($server))
        ->state->toBe(CloudTask::DONE)
        ->data->toBe(['polls' => 1]);
});

test('a wait past the budget re-queues the task with that delay', function () {
    Queue::fake();
    $this->freezeTime();
    $server = cloudTaskServer();
    cloudTaskResults(StepResult::wait(95, ['polls' => 1]));

    cloudTaskRun($server);

    Queue::assertPushed(RunCloudTask::class, 1);
    Queue::assertPushed(RunCloudTask::class, fn(RunCloudTask $job): bool => $job->uniqueId() === (string) $server->getKey() && $job->delay->equalTo(now()->addSeconds(95)));
    Sleep::assertNeverSlept();
    expect(cloudTaskOf($server))
        ->state->toBe(CloudTask::RUNNING)
        ->step->toBe('one')
        ->data->toBe(['polls' => 1]);
});

test('waits in one job share a ninety second budget', function (array $waits, array $slept, int $delay) {
    Queue::fake();
    $this->freezeTime();
    $server = cloudTaskServer();
    cloudTaskResults(...array_map(StepResult::wait(...), $waits));

    cloudTaskRun($server);

    Sleep::assertSequence(array_map(fn(int $seconds): Sleep => Sleep::for($seconds)->seconds(), $slept));
    Queue::assertPushed(RunCloudTask::class, 1);
    Queue::assertPushed(RunCloudTask::class, fn(RunCloudTask $job): bool => $job->delay->equalTo(now()->addSeconds($delay)));
    expect(cloudTaskOf($server)->state)->toBe(CloudTask::RUNNING);
})->with([
    'two waits that add up past it' => [[50, 50], [50], 50],
    'a wait that fills it exactly' => [[90, 1], [90], 1],
]);

test('a task that keeps stepping hands over to a fresh job', function () {
    Queue::fake();
    $this->freezeTime();
    $server = cloudTaskServer();
    cloudTaskResults(...array_map(fn(int $step): StepResult => StepResult::next("step-{$step}"), range(1, 30)));

    cloudTaskRun($server);

    expect(cloudTaskHandler()->calls)->toHaveCount(25)
        ->and(cloudTaskOf($server))
        ->state->toBe(CloudTask::RUNNING)
        ->step->toBe('step-25');
    Queue::assertPushed(RunCloudTask::class, 1);
    Queue::assertPushed(RunCloudTask::class, fn(RunCloudTask $job): bool => $job->uniqueId() === (string) $server->getKey() && $job->delay->equalTo(now()->addSeconds(5)));
    Sleep::assertNeverSlept();
});

test('a provider hiccup waits and is remembered', function () {
    $server = cloudTaskServer();
    cloudTaskResults(cloudTaskFailure(CloudFailure::Unavailable, status: 503), StepResult::done());

    cloudTaskRun($server);

    Sleep::assertSlept(cloudTaskSlept(30), 1);
    Sleep::assertSleptTimes(1);
    expect(cloudTaskOf($server))
        ->state->toBe(CloudTask::DONE)
        ->error->toBeNull()
        ->data->toBe(['last_error' => 'Hetzner Cloud had a problem answering (HTTP 503). Try again in a minute.'])
        ->and(cloudTaskCalls())->toBe(['step one', 'step one', 'finished one']);
});

test('every failure that can pass is waited out', function (CloudFailure $failure) {
    $server = cloudTaskServer();
    $hiccup = cloudTaskFailure($failure);
    cloudTaskResults($hiccup, StepResult::done());

    cloudTaskRun($server);

    Sleep::assertSlept(cloudTaskSlept(RunCloudTask::RETRY_SECONDS), 1);
    expect(cloudTaskOf($server))
        ->state->toBe(CloudTask::DONE)
        ->data->toBe(['last_error' => $hiccup->getMessage()])
        ->and($server->cloudProvider->fresh()->token_failed_at)->toBeNull();
})->with([CloudFailure::Busy, CloudFailure::RateLimited, CloudFailure::Unreachable]);

test('a provider refusal fails the task in its own words', function () {
    Exceptions::fake();
    $server = cloudTaskServer();
    cloudTaskResults(cloudTaskFailure(CloudFailure::Refused, 'nope', 422));

    cloudTaskRun($server);

    $reason = 'Hetzner Cloud refused the request. Hetzner Cloud said: nope.';

    expect(cloudTaskOf($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe($reason)
        ->and(cloudTaskCalls())->toBe(['step one', 'failed one'])
        ->and(cloudTaskHandler()->calls[1]['reason'])->toBe($reason)
        ->and(cloudTaskHandler()->calls[1]['api'])->toBe(cloudTaskApis()->api)
        ->and($server->cloudProvider->fresh()->token_failed_at)->toBeNull();
    Sleep::assertNeverSlept();
    Exceptions::assertNothingReported();
});

test('every failure that will not pass fails the task at once', function (CloudFailure $failure) {
    $server = cloudTaskServer();
    $refusal = cloudTaskFailure($failure);
    cloudTaskResults($refusal);

    cloudTaskRun($server);

    expect(cloudTaskOf($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe($refusal->getMessage())
        ->and(cloudTaskCalls())->toBe(['step one', 'failed one'])
        ->and($server->cloudProvider->fresh()->token_failed_at)->toBeNull();
    Sleep::assertNeverSlept();
})->with([CloudFailure::Forbidden, CloudFailure::NotFound]);

test('a refused token marks the account', function () {
    $server = cloudTaskServer();
    cloudTaskResults(cloudTaskFailure(CloudFailure::TokenRejected, status: 401));

    cloudTaskRun($server);

    expect($server->cloudProvider->fresh()->token_failed_at)->not->toBeNull()
        ->and(cloudTaskOf($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe("Hetzner Cloud refused this account's token. Replace it in Settings → Team, then try again.")
        ->and(cloudTaskCalls())->toBe(['step one', 'failed one']);
});

test('an account with no token stored fails before anything is sent', function () {
    app()->instance(CloudApiFactory::class, new HttpCloudApiFactory);
    $server = cloudTaskServer();
    $server->cloudProvider->forceFill(['api_token' => null])->save();

    cloudTaskRun($server);

    expect(cloudTaskOf($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe("Hetzner Cloud refused this account's token. Hetzner Cloud said: no token is stored. Replace it in Settings → Team, then try again.")
        ->and($server->cloudProvider->fresh()->token_failed_at)->not->toBeNull()
        ->and(cloudTaskCalls())->toBe(['failed one'])
        ->and(cloudTaskHandler()->calls[0]['api'])->toBeNull();
    Http::assertNothingSent();
});

test('an unexpected error fails the task and is reported', function () {
    Exceptions::fake();
    $server = cloudTaskServer();
    cloudTaskResults(new RuntimeException('boom'));

    cloudTaskRun($server);

    expect(cloudTaskOf($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toStartWith('Coresmith stopped with an unexpected error: boom.')
        ->error->toBe("Coresmith stopped with an unexpected error: boom. Try again, and if it keeps happening check the panel's logs.")
        ->and(cloudTaskCalls())->toBe(['step one', 'failed one']);
    Exceptions::assertReported(RuntimeException::class);
});

test('an unexpected error is cleaned and cut before anyone reads it', function (string $message, string $shown) {
    Exceptions::fake();
    $server = cloudTaskServer();
    cloudTaskResults(new RuntimeException($message));

    cloudTaskRun($server);

    expect(cloudTaskOf($server)->error)->toBe("Coresmith stopped with an unexpected error: {$shown}. Try again, and if it keeps happening check the panel's logs.")
        ->and(json_encode($server->fresh()->provider_task))->not->toContain('the-account-token');
})->with([
    'a token in the message' => ['The request with Bearer the-account-token failed.', 'The request with Bearer [REDACTED] failed'],
    'a long message' => [str_repeat('a', 250), str_repeat('a', 200)],
]);

test('a step that outlives its deadline is handed to expired', function () {
    $server = cloudTaskServer();
    cloudTaskResults(StepResult::done());
    $this->travel(11)->minutes();

    cloudTaskRun($server);

    expect(cloudTaskCalls())->toBe(['expired one', 'finished one']);
});

test('the step after a late one starts with its own deadline', function () {
    $server = cloudTaskServer();
    cloudTaskResults(StepResult::next('two'), StepResult::done());
    $this->travel(11)->minutes();

    cloudTaskRun($server);

    expect(cloudTaskCalls())->toBe(['expired one', 'step two', 'finished two']);
});

test('each step keeps its own deadline', function (int $seconds, string $called) {
    config(['coresmith.cloud.step_seconds.one' => 60]);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00'));
    $server = cloudTaskServer();
    cloudTaskResults(StepResult::done());
    $this->travel($seconds)->seconds();

    cloudTaskRun($server);

    expect(cloudTaskCalls())->toBe([$called, 'finished one']);
})->with([
    'at it' => [60, 'step one'],
    'past it' => [61, 'expired one'],
]);

test('what a step wrote before the task ended is handed on', function (Closure $end, string $method, array $data) {
    $server = cloudTaskServer();
    cloudTaskResults(function (Server $server) use ($end): StepResult {
        CloudTask::touch($server, ['key_id' => 'key-1']);

        return $end();
    });

    cloudTaskRun($server);

    $call = cloudTaskHandler()->calls[1];

    expect($call['method'])->toBe($method)
        ->and($call['task']->data)->toBe($data)
        ->and(CloudTask::of($call['server'])?->data)->toBe($data);
})->with([
    'finished' => [fn(): StepResult => StepResult::done(['seconds' => 42]), 'finished', ['key_id' => 'key-1', 'seconds' => 42]],
    'failed' => [fn(): StepResult => throw cloudTaskFailure(CloudFailure::Refused, 'no capacity'), 'failed', ['key_id' => 'key-1']],
]);

test('a task that is not running is left alone', function (Closure $settle) {
    $server = cloudTaskServer();
    $settle($server);
    $before = $server->fresh()->provider_task;

    cloudTaskRun($server);

    expect(cloudTaskHandler()->calls)->toBe([])
        ->and(cloudTaskApis()->accounts())->toBe([])
        ->and(cloudTaskApis()->api->calls())->toBe([])
        ->and($server->fresh()->provider_task)->toBe($before);
    Sleep::assertNeverSlept();
})->with([
    'failed' => [fn(Server $server) => CloudTask::fail($server, 'It broke.')],
    'finished' => [fn(Server $server) => CloudTask::finish($server)],
    'cleared' => [fn(Server $server) => CloudTask::clear($server)],
    'on a removed server' => [fn(Server $server) => $server->delete()],
]);

test('a server whose account was removed fails with how to reconnect', function (Closure $remove) {
    $server = cloudTaskServer();
    $remove($server->cloudProvider);

    cloudTaskRun($server);

    expect(cloudTaskOf($server))
        ->state->toBe(CloudTask::FAILED)
        ->error->toBe(RunCloudTask::NO_ACCOUNT)
        ->and(RunCloudTask::NO_ACCOUNT)->toBe('The provider account this server was created with is no longer connected. Connect it again in Settings → Team, then try again.')
        ->and(cloudTaskCalls())->toBe(['failed one'])
        ->and(cloudTaskHandler()->calls[0]['reason'])->toBe(RunCloudTask::NO_ACCOUNT)
        ->and(cloudTaskHandler()->calls[0]['api'])->toBeNull()
        ->and(cloudTaskApis()->accounts())->toBe([]);
})->with([
    'removed' => [fn(CloudProvider $account) => $account->delete()],
    'gone' => [fn(CloudProvider $account) => $account->forceDelete()],
]);

test('one task runs per server', function () {
    Queue::fake();
    $server = cloudTaskServer();
    $job = new RunCloudTask($server);
    $overlap = $job->middleware()[0];

    RunCloudTask::dispatch($server);
    RunCloudTask::dispatch($server);
    RunCloudTask::dispatch(cloudTaskServer());

    Queue::assertPushed(RunCloudTask::class, 2);
    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe((string) $server->getKey())
        ->and($job->uniqueFor)->toBe(600)
        ->and($job->middleware())->toHaveCount(1)
        ->and($overlap)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($overlap->key)->toBe('cloud-task:' . $server->getKey())
        ->and($overlap->expiresAfter)->toBe(180)
        ->and($overlap->releaseAfter)->toBeNull()
        ->and($job->queue)->toBe('server-tasks')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(150)
        ->and([RunCloudTask::BUDGET_SECONDS, RunCloudTask::MAX_STEPS, RunCloudTask::RETRY_SECONDS])->toBe([90, 25, 30]);
});

test('the queued job carries the server, never the token', function () {
    $server = cloudTaskServer();
    $account = $server->cloudProvider;

    $payload = serialize(new RunCloudTask($server));

    expect($account->api_token)->toBe('the-account-token')
        ->and($payload)->toContain((string) $server->getKey())
        ->not->toContain('the-account-token')
        ->not->toContain((string) $account->getRawOriginal('api_token'));
});

test('stale running tasks are resumed, fresh and finished ones are not', function () {
    $minutesAgo = now()->subMinutes(3)->toIso8601String();
    $stale = cloudTaskServer(['heartbeat_at' => $minutesAgo]);
    $silent = cloudTaskServer(['heartbeat_at' => null]);
    cloudTaskServer(['heartbeat_at' => now()->subSeconds(30)->toIso8601String()]);
    CloudTask::fail(cloudTaskServer(['heartbeat_at' => $minutesAgo]), 'It broke.');
    CloudTask::finish(cloudTaskServer(['heartbeat_at' => $minutesAgo]));
    cloudTaskServer(['heartbeat_at' => $minutesAgo])->delete();
    Server::factory()->create();
    Queue::fake();

    (new ResumeCloudTasks)->handle();

    expect(Queue::pushed(RunCloudTask::class)->map(fn(RunCloudTask $job): string => $job->uniqueId())->sort()->values()->all())
        ->toBe(collect([$stale, $silent])->map(fn(Server $server): string => (string) $server->getKey())->sort()->values()->all());
});

test('the schedule resumes cloud tasks every minute', function () {
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'coresmith:resume-cloud-tasks');
    $job = new ResumeCloudTasks;

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($job->queue)->toBe('default')
        ->and($job->timeout)->toBe(30)
        ->and($job->tries)->toBe(1);
});

test('a task is kept on the server in the shape the panel reads', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00'));
    $user = User::factory()->create();
    $server = Server::factory()->create();

    $task = CloudTask::start($server, CloudTask::CREATE, 'create', ['region_label' => 'Falkenstein'], $user);

    expect($server->fresh()->provider_task)->toBe([
        'type' => 'create',
        'state' => 'running',
        'step' => 'create',
        'started_at' => '2026-10-30T12:00:00+00:00',
        'step_started_at' => '2026-10-30T12:00:00+00:00',
        'heartbeat_at' => '2026-10-30T12:00:00+00:00',
        'finished_at' => null,
        'error' => null,
        'by' => $user->getKey(),
        'data' => ['region_label' => 'Falkenstein'],
    ])->and($task)->toEqual(CloudTask::of($server->fresh()));

    $this->travel(20)->seconds();
    CloudTask::touch($server, ['key_id' => 'key-1']);
    $this->travel(20)->seconds();
    CloudTask::advance($server, 'boot', ['uncertain' => 0]);
    $this->travel(20)->seconds();
    CloudTask::finish($server);

    expect($server->fresh()->provider_task)->toBe([
        'type' => 'create',
        'state' => 'done',
        'step' => 'boot',
        'started_at' => '2026-10-30T12:00:00+00:00',
        'step_started_at' => '2026-10-30T12:00:40+00:00',
        'heartbeat_at' => '2026-10-30T12:00:40+00:00',
        'finished_at' => '2026-10-30T12:01:00+00:00',
        'error' => null,
        'by' => $user->getKey(),
        'data' => ['region_label' => 'Falkenstein', 'key_id' => 'key-1', 'uncertain' => 0],
    ])->and(cloudTaskOf($server))
        ->type->toBe(CloudTask::CREATE)
        ->by->toBe($user->getKey())
        ->startedAt->toEqual(CarbonImmutable::parse('2026-10-30 12:00:00'))
        ->stepStartedAt->toEqual(CarbonImmutable::parse('2026-10-30 12:00:40'))
        ->heartbeatAt->toEqual(CarbonImmutable::parse('2026-10-30 12:00:40'));
});

test('a failed task keeps its error until it is resumed at the step it reached', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00'));
    $server = cloudTaskServer();
    CloudTask::advance($server, 'two', ['key_id' => 'key-1']);
    $this->travel(1)->minutes();
    CloudTask::fail($server, 'It broke.');

    expect($server->fresh()->provider_task)->toMatchArray([
        'state' => 'failed',
        'step' => 'two',
        'finished_at' => '2026-10-30T12:01:00+00:00',
        'error' => 'It broke.',
    ]);

    $this->travel(5)->minutes();
    CloudTask::resume($server);

    expect($server->fresh()->provider_task)->toMatchArray([
        'state' => 'running',
        'step' => 'two',
        'step_started_at' => '2026-10-30T12:06:00+00:00',
        'heartbeat_at' => '2026-10-30T12:06:00+00:00',
        'finished_at' => null,
        'error' => null,
        'data' => ['key_id' => 'key-1'],
    ])->and(cloudTaskOf($server)->stepSeconds())->toBe(0);
});

test('only a failed task is resumed', function (Closure $settle) {
    $server = cloudTaskServer();
    $settle($server);
    $before = $server->fresh()->provider_task;
    $this->travel(5)->minutes();

    CloudTask::resume($server);

    expect($server->fresh()->provider_task)->toBe($before);
})->with([
    'running' => [fn(Server $server) => null],
    'finished' => [fn(Server $server) => CloudTask::finish($server)],
    'cleared' => [fn(Server $server) => CloudTask::clear($server)],
]);

test('a task counts its step time and goes stale two minutes after it was last heard from', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00'));
    $server = cloudTaskServer();

    $this->travel(120)->seconds();
    $quiet = cloudTaskOf($server);

    expect(CloudTask::STALE_SECONDS)->toBe(120)
        ->and($quiet->stepSeconds())->toBe(120)
        ->and($quiet->isStale())->toBeFalse();

    $this->travel(1)->seconds();

    expect(cloudTaskOf($server)->isStale())->toBeTrue();

    CloudTask::touch($server);

    expect(cloudTaskOf($server))
        ->isStale()->toBeFalse()
        ->stepSeconds()->toBe(121);

    CloudTask::fail($server, 'It broke.');
    $this->travel(10)->minutes();

    expect(cloudTaskOf($server)->isStale())->toBeFalse();
});

test('a server is busy only while its task runs', function () {
    $server = cloudTaskServer();

    expect(CloudTask::running($server))->toBeTrue()
        ->and(CloudTask::running($server, 'probe'))->toBeTrue()
        ->and(CloudTask::running($server, CloudTask::RESIZE))->toBeFalse();

    CloudTask::fail($server, 'It broke.');

    expect(CloudTask::running($server))->toBeFalse()
        ->and(CloudTask::of($server)?->state)->toBe(CloudTask::FAILED);

    CloudTask::clear($server);

    expect($server->fresh()->provider_task)->toBeNull()
        ->and(CloudTask::of($server))->toBeNull()
        ->and(CloudTask::running($server))->toBeFalse()
        ->and(CloudTask::running(Server::factory()->create()))->toBeFalse();
});

test('handlers are found by the type of task', function () {
    $handler = cloudTaskRecorder();
    $handlers = new CloudTaskHandlers(['probe' => $handler, 'built' => $handler::class]);

    expect($handlers->for('probe'))->toBe($handler)
        ->and($handlers->for('built'))->toBeInstanceOf($handler::class)
        ->and($handlers->for('built'))->not->toBe($handler)
        ->and(CloudTaskHandlers::DEFAULTS)->toBe([])
        ->and(fn() => $handlers->for('unknown'))->toThrow(InvalidArgumentException::class);
});
