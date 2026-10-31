<?php

declare(strict_types=1);

use App\Support\Sentry\SentryScrubber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

function sentryTransport(): TransportInterface
{
    return new class implements TransportInterface
    {
        public array $sent = [];

        public function send(Event $event): Result
        {
            $this->sent[] = $event;

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };
}

function sentryHub(TransportInterface $transport): Hub
{
    $builder = app(ClientBuilder::class)->setTransport($transport);
    $builder->getOptions()->setTracesSampleRate(1.0);

    return new Hub($builder->getClient());
}

function sentryConfigWith(array $environment): array
{
    $repository = Env::getRepository();

    foreach ($environment as $name => $value) {
        $repository->set($name, $value);
    }

    try {
        return require config_path('sentry.php');
    } finally {
        foreach (array_keys($environment) as $name) {
            $repository->clear($name);
        }
    }
}

test('sentry never collects personal data or request bodies', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.max_request_body_size'))->toBe('never');
});

test('every hook is the scrubber and survives config caching', function () {
    expect(config('sentry.before_send'))->toBe([SentryScrubber::class, 'beforeSend'])
        ->and(config('sentry.before_send_transaction'))->toBe([SentryScrubber::class, 'beforeSendTransaction'])
        ->and(config('sentry.before_breadcrumb'))->toBe([SentryScrubber::class, 'beforeBreadcrumb'])
        ->and(is_callable(config('sentry.before_send')))->toBeTrue()
        ->and(is_callable(config('sentry.before_send_transaction')))->toBeTrue()
        ->and(is_callable(config('sentry.before_breadcrumb')))->toBeTrue()
        ->and(fn() => serialize(config('sentry')))->not->toThrow(Exception::class);
});

test("events are tied to the panel's version", function () {
    expect(config('sentry.release'))->toBe(config('coresmith.version'));
});

test('the environment cannot switch on logs, bound values or personal data', function () {
    $config = sentryConfigWith([
        'SENTRY_ENABLE_LOGS' => 'true',
        'SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED' => 'true',
        'SENTRY_TRACE_SQL_BINDINGS_ENABLED' => 'true',
        'SENTRY_SEND_DEFAULT_PII' => 'true',
    ]);

    expect($config['enable_logs'])->toBeFalse()
        ->and($config['breadcrumbs']['sql_bindings'])->toBeFalse()
        ->and($config['tracing']['sql_bindings'])->toBeFalse()
        ->and($config['send_default_pii'])->toBeFalse();
});

test('a blank sample rate leaves tracing off until a rate is given', function () {
    $blank = sentryConfigWith(['SENTRY_TRACES_SAMPLE_RATE' => ''])['traces_sample_rate'];

    expect($blank)->toBeNull()
        ->and((new Options(['traces_sample_rate' => $blank]))->isTracingEnabled())->toBeFalse()
        ->and(sentryConfigWith([])['traces_sample_rate'])->toBeNull()
        ->and(sentryConfigWith(['SENTRY_TRACES_SAMPLE_RATE' => '0.25'])['traces_sample_rate'])->toBe(0.25)
        ->and(sentryConfigWith(['SENTRY_TRACES_SAMPLE_RATE' => '0'])['traces_sample_rate'])->toBe(0.0);
});

test('the test suite can never send a real event', function () {
    expect(config('sentry.dsn'))->toBeEmpty()
        ->and((string) file_get_contents(base_path('phpunit.xml')))->toContain('<env name="SENTRY_LARAVEL_DSN" value="" force="true"/>');
});

test('a reported exception reaches sentry', function () {
    $original = SentrySdk::getCurrentHub();
    $hub = Mockery::mock(HubInterface::class)->shouldIgnoreMissing();
    $hub->shouldReceive('captureException')->once()->with(Mockery::type(RuntimeException::class), Mockery::any());
    SentrySdk::setCurrentHub($hub);

    try {
        report(new RuntimeException('boom'));
    } finally {
        SentrySdk::setCurrentHub($original);
    }
});

test('the sdk runs every scrubber hook before anything reaches its transport', function () {
    $transport = sentryTransport();
    $hub = sentryHub($transport);

    $hub->configureScope(function (Scope $scope): void {
        $scope->addEventProcessor(fn(Event $event): Event => $event->setRequest([
            'url' => 'https://panel.example.com/callback?code=abc123&state=xyz789',
            'method' => 'POST',
            'data' => ['ssh_private_key' => 'not-a-real-key'],
            'cookies' => ['coresmith_session' => 's'],
        ]));
    });

    $hub->addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'api_key=AKIAABCDEFGHIJKLMNOP', [
        'url' => 'https://api.example.com/cb?code=abc123',
        'password' => 'hunter2hunter2',
    ]));

    $kept = [];
    $hub->configureScope(function (Scope $scope) use (&$kept): void {
        $kept = $scope->getBreadcrumbs();
    });

    $hub->captureException(new RuntimeException('DB_PASSWORD=hunter2hunter2 was rejected'));

    $transaction = $hub->startTransaction(TransactionContext::make()->setName('POST /callback'));
    $transaction->startChild(
        SpanContext::make()
            ->setOp('http.client')
            ->setDescription('GET https://api.example.com/cb?code=abc123 failed')
            ->setData(['http.query' => 'state=xyz789']),
    )->finish();
    $transaction->finish();

    $request = [
        'url' => 'https://panel.example.com/callback?code=%5BFiltered%5D&state=%5BFiltered%5D',
        'method' => 'POST',
    ];

    expect($kept)->toHaveCount(1)
        ->and($kept[0]->getMessage())->toBe('api_key=[REDACTED]')
        ->and($kept[0]->getMetadata())->toBe(['url' => 'https://api.example.com/cb?code=%5BFiltered%5D', 'password' => '[Filtered]'])
        ->and($transport->sent)->toHaveCount(2);

    [$error, $trace] = $transport->sent;

    expect((string) $error->getType())->toBe('event')
        ->and($error->getExceptions()[0]->getValue())->toBe('DB_PASSWORD=[REDACTED] was rejected')
        ->and($error->getRequest())->not->toHaveKey('data')
        ->and($error->getRequest())->toBe($request)
        ->and($error->getRelease())->toBe(config('coresmith.version'))
        ->and((string) $trace->getType())->toBe('transaction')
        ->and($trace->getRequest())->not->toHaveKey('data')
        ->and($trace->getRequest())->toBe($request)
        ->and($trace->getSpans())->toHaveCount(1)
        ->and($trace->getSpans()[0]->getDescription())->toBe('GET https://api.example.com/cb?code=%5BFiltered%5D failed')
        ->and($trace->getSpans()[0]->getData()['http.query'])->toBe('state=%5BFiltered%5D')
        ->and($trace->getRelease())->toBe(config('coresmith.version'));
});

test('a failed query reaches the transport with its placeholders and without its values', function () {
    $transport = sentryTransport();
    $failure = new QueryException(
        'sqlite',
        'insert into "users" ("email", "password") values (?, ?)',
        ['maya@monoven.io', '$2y$04$abcdefghijklmnopqrstuuMq0b3f1xkD1e8Gz5Yw9bW6n7y4c2eHa'],
        new PDOException('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.email'),
    );

    sentryHub($transport)->captureException($failure);

    expect($failure->getMessage())->toContain('maya@monoven.io')
        ->and($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]->getExceptions()[0]->getValue())
        ->toBe('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: users.email (Connection: sqlite, SQL: insert into "users" ("email", "password") values (?, ?))');
});

test('the example environment names the sentry keys', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^SENTRY_LARAVEL_DSN=/m')
        ->toMatch('/^SENTRY_TRACES_SAMPLE_RATE=/m')
        ->toMatch('/^SENTRY_ENVIRONMENT=/m');
});
