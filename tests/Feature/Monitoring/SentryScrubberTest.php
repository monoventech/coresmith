<?php

declare(strict_types=1);

use App\Support\Sentry\SentryScrubber;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;

function scrubberEvent(array $request = []): Event
{
    return Event::createEvent()->setRequest($request);
}

function scrubberBreadcrumb(?string $message = null, array $metadata = []): Breadcrumb
{
    return new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', $message, $metadata);
}

test('the request body, cookies and environment never leave', function () {
    $event = scrubberEvent([
        'url' => 'https://panel.example.com/servers',
        'method' => 'POST',
        'data' => ['ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nabc\n-----END OPENSSH PRIVATE KEY-----"],
        'cookies' => ['coresmith_session' => 's'],
        'env' => ['REMOTE_ADDR' => '203.0.113.9'],
    ]);

    $request = SentryScrubber::beforeSend($event)->getRequest();

    expect($request)->not->toHaveKey('data')
        ->not->toHaveKey('cookies')
        ->not->toHaveKey('env')
        ->and($request['method'])->toBe('POST');
});

test('only harmless headers are kept', function () {
    $event = scrubberEvent(['headers' => [
        'Authorization' => ['Bearer abc.def.ghi'],
        'X-Hub-Signature-256' => ['sha256=abcdef'],
        'Cookie' => ['coresmith_session=s'],
        'User-Agent' => ['Firefox/140'],
        'Content-Type' => ['application/json'],
    ]]);

    expect(SentryScrubber::beforeSend($event)->getRequest()['headers'])
        ->toBe(['User-Agent' => ['Firefox/140'], 'Content-Type' => ['application/json']]);
});

test('header names are matched whatever their case and keep their own spelling', function () {
    $event = scrubberEvent(['headers' => [
        'AUTHORIZATION' => ['Bearer abc.def.ghi'],
        'user-agent' => ['Firefox/140'],
        'ACCEPT' => ['text/html'],
        'x-github-delivery' => ['9d3b'],
    ]]);

    expect(SentryScrubber::beforeSend($event)->getRequest()['headers'])
        ->toBe(['user-agent' => ['Firefox/140'], 'ACCEPT' => ['text/html']]);
});

test('tokens and codes in the address are filtered', function (array $request, string $field, string $expected) {
    expect(SentryScrubber::beforeSend(scrubberEvent($request))->getRequest()[$field])->toBe($expected);
})->with([
    'a setup link' => [
        ['url' => 'https://panel.example.com/install?token=s3cr3t-setup&step=2'],
        'url',
        'https://panel.example.com/install?token=%5BFiltered%5D&step=2',
    ],
    'an oauth callback query string' => [
        ['query_string' => 'code=abc123&state=xyz789'],
        'query_string',
        'code=%5BFiltered%5D&state=%5BFiltered%5D',
    ],
    'a signed link' => [
        ['url' => 'https://panel.example.com/verify?id=7&signature=9f8e7d6c&expires=17'],
        'url',
        'https://panel.example.com/verify?id=7&signature=%5BFiltered%5D&expires=17',
    ],
    'any letter case' => [
        ['query_string' => 'CODE=abc123&State=xyz789'],
        'query_string',
        'CODE=%5BFiltered%5D&State=%5BFiltered%5D',
    ],
    'a fragment is left alone' => [
        ['url' => 'https://panel.example.com/callback?code=abc123#top'],
        'url',
        'https://panel.example.com/callback?code=%5BFiltered%5D#top',
    ],
    'every other pair byte for byte' => [
        ['query_string' => 'a=1&b=%20x&c[]=2&token=t&d=a+b'],
        'query_string',
        'a=1&b=%20x&c[]=2&token=%5BFiltered%5D&d=a+b',
    ],
    'a name that only ends like a filtered one' => [
        ['query_string' => 'barcode=abc123&postcode=n1'],
        'query_string',
        'barcode=abc123&postcode=n1',
    ],
    'an address with nothing to filter' => [
        ['url' => 'https://panel.example.com/servers?page=2&sort=name'],
        'url',
        'https://panel.example.com/servers?page=2&sort=name',
    ],
]);

test('credentials in the address are redacted', function () {
    $event = scrubberEvent(['url' => 'https://deploy:hunter2hunter2@git.example.com/acme/app.git?step=1']);

    expect(SentryScrubber::beforeSend($event)->getRequest()['url'])
        ->toBe('https://[REDACTED]@git.example.com/acme/app.git?step=1');
});

test('a request that is not shaped as expected loses what it cannot vouch for', function () {
    $event = scrubberEvent(['url' => ['x'], 'method' => 5, 'query_string' => null, 'headers' => 'none']);

    expect(SentryScrubber::beforeSend($event)->getRequest())->toBe([]);
});

test('exception messages go through the log sanitizer', function () {
    $event = Event::createEvent()->setExceptions([
        new ExceptionDataBag(new RuntimeException('clone https://x-access-token:ghs_A1b2C3d4E5f6G7h8I9j0K1l2@github.com/acme/app.git failed')),
        new ExceptionDataBag(new LogicException('DB_PASSWORD=hunter2hunter2 was rejected')),
    ]);

    [$outer, $previous] = SentryScrubber::beforeSend($event)->getExceptions();

    expect($outer->getValue())->toContain('https://[REDACTED]@github.com')
        ->not->toContain('ghs_')
        ->and($previous->getValue())->toBe('DB_PASSWORD=[REDACTED] was rejected');
});

test('the message and extra strings are sanitised, other extra kept', function () {
    $event = Event::createEvent()
        ->setMessage('DB_PASSWORD=hunter2hunter2 was rejected')
        ->setExtra(['output' => 'Bearer abc.def.ghi', 'attempts' => 3]);

    $scrubbed = SentryScrubber::beforeSend($event);

    expect($scrubbed->getMessage())->toContain('[REDACTED]')
        ->not->toContain('hunter2hunter2')
        ->and($scrubbed->getExtra())->toBe(['output' => 'Bearer [REDACTED]', 'attempts' => 3]);
});

test('nested extra values are sanitised and anything that is not a string is kept', function () {
    $event = Event::createEvent()->setExtra([
        'attempt' => [
            'output' => 'api_key=AKIAABCDEFGHIJKLMNOP',
            'count' => 2,
            'ratio' => 0.5,
            'done' => false,
            'next' => null,
        ],
        'lines' => ['Bearer abc.def.ghi', 7],
        'url' => 'https://panel.example.com/callback?code=abc123',
    ]);

    expect(SentryScrubber::beforeSend($event)->getExtra())->toBe([
        'attempt' => [
            'output' => 'api_key=[REDACTED]',
            'count' => 2,
            'ratio' => 0.5,
            'done' => false,
            'next' => null,
        ],
        'lines' => ['Bearer [REDACTED]', 7],
        'url' => 'https://panel.example.com/callback?code=%5BFiltered%5D',
    ]);
});

test('the formatted message and its parameters are sanitised too', function () {
    $event = Event::createEvent()->setMessage(
        'Rejected %s',
        ['api_key=AKIAABCDEFGHIJKLMNOP'],
        'Rejected api_key=AKIAABCDEFGHIJKLMNOP',
    );

    $scrubbed = SentryScrubber::beforeSend($event);

    expect($scrubbed->getMessage())->toBe('Rejected %s')
        ->and($scrubbed->getMessageParams())->toBe(['api_key=[REDACTED]'])
        ->and($scrubbed->getMessageFormatted())->toBe('Rejected api_key=[REDACTED]');
});

test('an event without a message keeps none', function () {
    $scrubbed = SentryScrubber::beforeSend(Event::createEvent());

    expect($scrubbed->getMessage())->toBeNull()
        ->and($scrubbed->getMessageFormatted())->toBeNull()
        ->and($scrubbed->getMessageParams())->toBe([]);
});

test('breadcrumbs inside an event are scrubbed', function () {
    $event = Event::createEvent()->setBreadcrumb([
        scrubberBreadcrumb('api_key=AKIAABCDEFGHIJKLMNOP'),
        scrubberBreadcrumb('Processing queue job', ['url' => 'https://panel.example.com/callback?state=xyz789']),
    ]);

    [$first, $second] = SentryScrubber::beforeSend($event)->getBreadcrumbs();

    expect($first->getMessage())->toContain('[REDACTED]')
        ->not->toContain('AKIAABCDEFGHIJKLMNOP')
        ->and($second->getMetadata()['url'])->toBe('https://panel.example.com/callback?state=%5BFiltered%5D');
});

test('a breadcrumb keeps its non-string data', function () {
    $breadcrumb = SentryScrubber::beforeBreadcrumb(new Breadcrumb(
        Breadcrumb::LEVEL_INFO,
        Breadcrumb::TYPE_HTTP,
        'http',
        null,
        ['url' => 'https://api.github.com/x?token=abc', 'status_code' => 200],
    ));

    expect($breadcrumb->getMetadata()['url'])->toContain('token=%5BFiltered%5D')
        ->and($breadcrumb->getMetadata()['status_code'])->toBe(200);
});

test('an http breadcrumb has its query string filtered', function () {
    $breadcrumb = SentryScrubber::beforeBreadcrumb(new Breadcrumb(
        Breadcrumb::LEVEL_INFO,
        Breadcrumb::TYPE_HTTP,
        'http',
        null,
        [
            'url' => 'https://git.example.com/login/callback',
            'http.query' => 'code=abc123&grant=authorization_code',
            'http.fragment' => '',
            'http.request.method' => 'GET',
            'http.response.status_code' => 200,
        ],
    ));

    expect($breadcrumb->getMetadata())->toBe([
        'url' => 'https://git.example.com/login/callback',
        'http.query' => 'code=%5BFiltered%5D&grant=authorization_code',
        'http.fragment' => '',
        'http.request.method' => 'GET',
        'http.response.status_code' => 200,
    ]);
});

test('breadcrumb urls are filtered wherever they sit', function () {
    $breadcrumb = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb(null, [
        'from' => '/servers?code=aaa111',
        'to' => '/callback?state=bbb222&page=2',
        'http.url' => 'https://api.example.com/x?signature=ccc333',
        'context' => [
            'url' => 'https://other.example.com/?token=ddd444',
            'note' => 'DB_PASSWORD=hunter2hunter2',
        ],
    ]));

    expect($breadcrumb->getMetadata())->toBe([
        'from' => '/servers?code=%5BFiltered%5D',
        'to' => '/callback?state=%5BFiltered%5D&page=2',
        'http.url' => 'https://api.example.com/x?signature=%5BFiltered%5D',
        'context' => [
            'url' => 'https://other.example.com/?token=%5BFiltered%5D',
            'note' => 'DB_PASSWORD=[REDACTED]',
        ],
    ]);
});

test('a breadcrumb keeps everything it was not asked to change', function () {
    $original = new Breadcrumb(Breadcrumb::LEVEL_WARNING, Breadcrumb::TYPE_NAVIGATION, 'route', null, ['attempt' => 2], 1760000000.5);

    $breadcrumb = SentryScrubber::beforeBreadcrumb($original);

    expect($breadcrumb->getLevel())->toBe(Breadcrumb::LEVEL_WARNING)
        ->and($breadcrumb->getType())->toBe(Breadcrumb::TYPE_NAVIGATION)
        ->and($breadcrumb->getCategory())->toBe('route')
        ->and($breadcrumb->getMessage())->toBeNull()
        ->and($breadcrumb->getMetadata())->toBe(['attempt' => 2])
        ->and($breadcrumb->getTimestamp())->toBe(1760000000.5);
});

test('transactions are scrubbed like errors', function () {
    $transaction = Event::createTransaction()->setRequest([
        'url' => 'https://panel.example.com/callback?code=abc&state=def',
        'method' => 'GET',
        'cookies' => ['coresmith_session' => 's'],
    ]);

    expect(SentryScrubber::beforeSendTransaction($transaction)->getRequest())->toBe([
        'url' => 'https://panel.example.com/callback?code=%5BFiltered%5D&state=%5BFiltered%5D',
        'method' => 'GET',
    ]);
});

test('the scrubber never drops an event', function () {
    $event = scrubberEvent(['url' => 'https://panel.example.com/servers']);
    $transaction = Event::createTransaction();
    $bare = Event::createEvent();

    expect(SentryScrubber::beforeSend($event))->toBe($event)
        ->and(SentryScrubber::beforeSendTransaction($transaction))->toBe($transaction)
        ->and(SentryScrubber::beforeSend($bare))->toBe($bare);
});
