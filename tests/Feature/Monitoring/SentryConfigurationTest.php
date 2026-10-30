<?php

declare(strict_types=1);

use App\Support\Sentry\SentryScrubber;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

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

test('the sdk runs the scrubber before an event reaches its transport', function () {
    $transport = new class implements TransportInterface
    {
        public ?Event $sent = null;

        public function send(Event $event): Result
        {
            $this->sent = $event;

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    $scope = new Scope;
    $scope->addEventProcessor(fn(Event $event): Event => $event->setRequest([
        'url' => 'https://panel.example.com/callback?code=abc123&state=xyz789',
        'method' => 'POST',
        'data' => ['ssh_private_key' => 'not-a-real-key'],
        'cookies' => ['coresmith_session' => 's'],
    ]));

    app(ClientBuilder::class)->setTransport($transport)->getClient()
        ->captureException(new RuntimeException('DB_PASSWORD=hunter2hunter2 was rejected'), $scope);

    expect($transport->sent)->toBeInstanceOf(Event::class)
        ->and($transport->sent->getExceptions()[0]->getValue())->toBe('DB_PASSWORD=[REDACTED] was rejected')
        ->and($transport->sent->getRequest())->toBe([
            'url' => 'https://panel.example.com/callback?code=%5BFiltered%5D&state=%5BFiltered%5D',
            'method' => 'POST',
        ])
        ->and($transport->sent->getRelease())->toBe(config('coresmith.version'));
});

test('the example environment names the sentry keys', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^SENTRY_LARAVEL_DSN=/m')
        ->toMatch('/^SENTRY_TRACES_SAMPLE_RATE=/m')
        ->toMatch('/^SENTRY_ENVIRONMENT=/m');
});
