<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Support\Sentry\SentryScrubber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\Tracing\Span;

function scrubberEvent(array $request = []): Event
{
    return Event::createEvent()->setRequest($request);
}

function scrubberBreadcrumb(?string $message = null, array $metadata = []): Breadcrumb
{
    return new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', $message, $metadata);
}

function scrubberSecretObject(): object
{
    return new class
    {
        public string $db_password = 'hunter2hunter2';

        public string $note = 'DB_PASSWORD=hunter2hunter2';

        public int $attempts = 2;

        protected string $hidden = 'never leaves';
    };
}

function scrubberQueryFailure(string $driver, array $bindings, string $connection = 'pgsql'): QueryException
{
    return new QueryException(
        $connection,
        'insert into "users" ("email", "password") values (?, ?)',
        $bindings,
        new PDOException($driver),
    );
}

function scrubberFailedUpdate(string $driver): QueryException
{
    return new QueryException(
        'pgsql',
        'update "users" set "name" = ?, "updated_at" = ? where "id" = ?',
        [null, '2026-10-31 00:00:00', '01K8ABCDEFGHJKMNPQRSTVWXYZ'],
        new PDOException($driver),
    );
}

function scrubberFailedInsert(): QueryException
{
    $insert = fn(string $id): bool => DB::table('users')->insert([
        'id' => $id,
        'name' => 'Maya Okafor',
        'email' => 'maya@monoven.io',
        'password' => '$2y$04$abcdefghijklmnopqrstuuMq0b3f1xkD1e8Gz5Yw9bW6n7y4c2eHa',
    ]);

    $insert((string) Str::ulid());

    try {
        $insert((string) Str::ulid());
    } catch (QueryException $e) {
        return $e;
    }

    throw new LogicException('The second insert should have been refused.');
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

test('kept header values go through the text rules', function () {
    $event = scrubberEvent(['headers' => [
        'User-Agent' => [
            'Mozilla/5.0 (compatible; ReportBot/1.0; +mailto:maya@monoven.io)',
            'curl/8.7.1 api_key=AKIAABCDEFGHIJKLMNOP',
        ],
        'Accept' => ['text/html'],
        'Referer' => ['https://panel.example.com/forgot?email=maya%40monoven.io'],
    ]]);

    expect(SentryScrubber::beforeSend($event)->getRequest()['headers'])->toBe([
        'User-Agent' => [
            'Mozilla/5.0 (compatible; ReportBot/1.0; +mailto:[Filtered])',
            'curl/8.7.1 api_key=[REDACTED]',
        ],
        'Accept' => ['text/html'],
    ]);
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
    'a password reset link' => [
        ['url' => 'https://panel.example.com/reset-password/9f8e7d6c5b4a39281706f5e4d3c2b1a0?email=maya%40monoven.io'],
        'url',
        'https://panel.example.com/reset-password/%5BFiltered%5D?email=%5BFiltered%5D',
    ],
    'a password reset link under a team prefix' => [
        ['url' => 'https://panel.example.com/acme/reset-password/9f8e7d6c5b4a39281706f5e4d3c2b1a0?email=maya%40monoven.io'],
        'url',
        'https://panel.example.com/acme/reset-password/%5BFiltered%5D?email=%5BFiltered%5D',
    ],
    'a password reset query string' => [
        ['query_string' => 'email=maya%40monoven.io&lang=en'],
        'query_string',
        'email=%5BFiltered%5D&lang=en',
    ],
    'a verification link' => [
        ['url' => 'https://panel.example.com/email/verify/7/1f2e3d4c5b6a79881706f5e4d3c2b1a0aabbccdd?expires=1760000000&signature=ab12cd34'],
        'url',
        'https://panel.example.com/email/verify/7/%5BFiltered%5D?expires=1760000000&signature=%5BFiltered%5D',
    ],
    'a verification link under a team prefix' => [
        ['url' => 'https://panel.example.com/acme/verify-email/7/1f2e3d4c5b6a79881706f5e4d3c2b1a0aabbccdd?expires=1760000000&signature=ab12cd34'],
        'url',
        'https://panel.example.com/acme/verify-email/7/%5BFiltered%5D?expires=1760000000&signature=%5BFiltered%5D',
    ],
    'a verification query string' => [
        ['query_string' => 'expires=1760000000&signature=ab12cd34'],
        'query_string',
        'expires=1760000000&signature=%5BFiltered%5D',
    ],
    'a reset page with no token yet' => [
        ['url' => 'https://panel.example.com/reset-password'],
        'url',
        'https://panel.example.com/reset-password',
    ],
    'a path that only starts like the reset page' => [
        ['url' => 'https://panel.example.com/reset-password-help/abc'],
        'url',
        'https://panel.example.com/reset-password-help/abc',
    ],
    'the verification notice' => [
        ['url' => 'https://panel.example.com/email/verify'],
        'url',
        'https://panel.example.com/email/verify',
    ],
    'names with an array suffix' => [
        ['query_string' => 'code[]=abc&token[0]=def&state%5B%5D=ghi&Email[x]=jkl&step=2'],
        'query_string',
        'code[]=%5BFiltered%5D&token[0]=%5BFiltered%5D&state%5B%5D=%5BFiltered%5D&Email[x]=%5BFiltered%5D&step=2',
    ],
    'a name that ends in signature' => [
        ['query_string' => 'X-Amz-Signature=abc123&X-Amz-Date=20261030&oauth_signature=def456'],
        'query_string',
        'X-Amz-Signature=%5BFiltered%5D&X-Amz-Date=20261030&oauth_signature=%5BFiltered%5D',
    ],
    'html-escaped separators' => [
        ['query_string' => 'code=abc123&amp;state=xyz789&amp;page=2'],
        'query_string',
        'code=%5BFiltered%5D&amp;state=%5BFiltered%5D&amp;page=2',
    ],
    'an email address under any query name' => [
        ['query_string' => 'user=maya%40monoven.io&q=maya@monoven.io&page=2'],
        'query_string',
        'user=%5BFiltered%5D&q=%5BFiltered%5D&page=2',
    ],
    'an email address in a path' => [
        ['url' => 'https://panel.example.com/invitations/maya@monoven.io/accept'],
        'url',
        'https://panel.example.com/invitations/%5BFiltered%5D/accept',
    ],
]);

test('query values and reset links inside text are filtered', function (string $text, string $expected) {
    $event = Event::createEvent()
        ->setExceptions([new ExceptionDataBag(new RuntimeException($text))])
        ->setMessage($text, [$text], $text)
        ->setExtra(['output' => $text])
        ->setBreadcrumb([scrubberBreadcrumb($text)]);

    $scrubbed = SentryScrubber::beforeSend($event);

    expect($scrubbed->getExceptions()[0]->getValue())->toBe($expected)
        ->and($scrubbed->getMessage())->toBe($expected)
        ->and($scrubbed->getMessageParams())->toBe([$expected])
        ->and($scrubbed->getMessageFormatted())->toBe($expected)
        ->and($scrubbed->getExtra())->toBe(['output' => $expected])
        ->and($scrubbed->getBreadcrumbs()[0]->getMessage())->toBe($expected);
})->with([
    'a url in a sentence' => [
        'GET https://git.example.com/login/callback?code=abc123&state=xyz789&x=1 returned 502',
        'GET https://git.example.com/login/callback?code=%5BFiltered%5D&state=%5BFiltered%5D&x=1 returned 502',
    ],
    'a url in json' => [
        '{"url":"https://git.example.com/cb?signature=abc123","status":502}',
        '{"url":"https://git.example.com/cb?signature=%5BFiltered%5D","status":502}',
    ],
    'a url in brackets' => [
        '(see https://git.example.com/cb?code=abc123)',
        '(see https://git.example.com/cb?code=%5BFiltered%5D)',
    ],
    'a reset link' => [
        'Could not open https://panel.example.com/acme/reset-password/9f8e7d6c5b4a39281706f5e4d3c2b1a0?email=maya%40monoven.io again',
        'Could not open https://panel.example.com/acme/reset-password/%5BFiltered%5D?email=%5BFiltered%5D again',
    ],
    'a verification link' => [
        'Rejected https://panel.example.com/email/verify/7/1f2e3d4c5b6a79881706f5e4d3c2b1a0aabbccdd?expires=1760000000&signature=ab12cd34 now',
        'Rejected https://panel.example.com/email/verify/7/%5BFiltered%5D?expires=1760000000&signature=%5BFiltered%5D now',
    ],
    'array style and signature names' => [
        'Fetch https://s3.example.com/a?code[]=abc&X-Amz-Signature=def456 failed',
        'Fetch https://s3.example.com/a?code[]=%5BFiltered%5D&X-Amz-Signature=%5BFiltered%5D failed',
    ],
    'plain words with the same names' => [
        'The state=running and code=200 and email=none lines stay',
        'The state=running and code=200 and email=none lines stay',
    ],
    'a url with nothing to filter' => [
        'GET https://git.example.com/repos?page=2&per_page=50 returned 200',
        'GET https://git.example.com/repos?page=2&per_page=50 returned 200',
    ],
    'html-escaped separators' => [
        'GET /callback?code=abc123&amp;state=xyz789&amp;page=2 failed',
        'GET /callback?code=%5BFiltered%5D&amp;state=%5BFiltered%5D&amp;page=2 failed',
    ],
    'an email address' => [
        'sent the invitation to maya@monoven.io now',
        'sent the invitation to [Filtered] now',
    ],
    'an email address with a tag' => [
        'maya+billing@monoven.io bounced',
        '[Filtered] bounced',
    ],
    'a mail transport error' => [
        'Expected response code "250" but got code "550", with message "550 5.1.1 <maya@monoven.io>: Recipient address rejected"',
        'Expected response code "250" but got code "550", with message "550 5.1.1 <[Filtered]>: Recipient address rejected"',
    ],
    'a percent-encoded email address' => [
        'GET /callback?user=maya%40monoven.io failed',
        'GET /callback?user=[Filtered] failed',
    ],
    'a git remote address' => [
        'Permission denied (publickey) for git@github.com:acme/app.git',
        'Permission denied (publickey) for git@github.com:acme/app.git',
    ],
    'a git host next to an email address' => [
        'git@gitlab.example.com refused maya@monoven.io',
        'git@gitlab.example.com refused [Filtered]',
    ],
    'an ssh login to an ip address' => [
        'deploy@203.0.113.9: connection refused',
        'deploy@203.0.113.9: connection refused',
    ],
    'a package version and a scope' => [
        'installed node@24.21.0 and @types/node',
        'installed node@24.21.0 and @types/node',
    ],
    'an email address at an accented domain' => [
        'maya@münchen.de bounced',
        '[Filtered] bounced',
    ],
    'an email address in another script' => [
        'maya@пример.рф bounced',
        '[Filtered] bounced',
    ],
    'an accented mailbox' => [
        'josé@example.com bounced',
        '[Filtered] bounced',
    ],
    'a mailbox with an accent inside' => [
        'müller@example.com bounced',
        '[Filtered] bounced',
    ],
    'a mailbox with an apostrophe' => [
        "o'brien@company.ie bounced",
        '[Filtered] bounced',
    ],
    'a quoted email address keeps its quotes' => [
        "Duplicate entry 'maya@monoven.io' for key 'users.users_email_unique'",
        "Duplicate entry '[Filtered]' for key 'users.users_email_unique'",
    ],
    'a git host next to an accented email address' => [
        "'git@github.com:acme/app.git' refused josé@example.com",
        "'git@github.com:acme/app.git' refused [Filtered]",
    ],
    'an email address after a byte that is not utf-8' => [
        "upload failed at \xFF for maya@münchen.de",
        "upload failed at \xFF for [Filtered]",
    ],
]);

test('an email address with text straight after it leaves whole characters', function () {
    $text = 'maya@例え.jp、送信に失敗しました';

    $scrubbed = SentryScrubber::beforeSend(Event::createEvent()->setMessage($text))->getMessage();

    expect($scrubbed)->toStartWith('[Filtered]')
        ->not->toContain('maya')
        ->and(mb_check_encoding($scrubbed, 'UTF-8'))->toBeTrue();
});

test('long runs of apostrophes and accented letters are scrubbed without stalling', function (string $text) {
    $started = hrtime(true);

    $scrubbed = SentryScrubber::beforeSend(Event::createEvent()->setMessage($text))->getMessage();

    expect((hrtime(true) - $started) / 1_000_000_000)->toBeLessThan(0.5)
        ->and(strlen($text))->toBeGreaterThan(500_000)
        ->and($scrubbed)->not->toBe('');
})->with([
    'apostrophes between letters' => [str_repeat("a'", 270_000) . '@monoven.io'],
    'accented letters' => [str_repeat('ü', 270_000) . '@monoven.io'],
    'accented addresses' => [str_repeat('ü@ü.', 135_000)],
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

test('every stack frame loses the arguments it was called with', function () {
    $frame = fn(string $function): Frame => new Frame($function, 'app/Services/SSH/SSHConnection.php', 63, null, null, ['key' => '-----BEGIN OPENSSH PRIVATE KEY----- not-a-real-key', 'port' => 22]);
    $event = scrubberEvent();
    $event->setStacktrace(new Stacktrace([$frame('connect')]));
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('outer'), new Stacktrace([$frame('outer'), $frame('load')])),
        new ExceptionDataBag(new RuntimeException('inner'), new Stacktrace([$frame('inner')])),
        new ExceptionDataBag(new RuntimeException('bare')),
    ]);

    SentryScrubber::beforeSend($event);

    $frames = [
        ...$event->getStacktrace()->getFrames(),
        ...$event->getExceptions()[0]->getStacktrace()->getFrames(),
        ...$event->getExceptions()[1]->getStacktrace()->getFrames(),
    ];

    expect($frames)->toHaveCount(4)
        ->and(array_map(fn(Frame $frame): array => $frame->getVars(), $frames))->toBe([[], [], [], []])
        ->and(array_map(fn(Frame $frame): ?string => $frame->getFunctionName(), $frames))->toBe(['connect', 'outer', 'load', 'inner']);
});

test('the scrubber never drops an event', function () {
    $event = scrubberEvent(['url' => 'https://panel.example.com/servers']);
    $transaction = Event::createTransaction();
    $bare = Event::createEvent();

    expect(SentryScrubber::beforeSend($event))->toBe($event)
        ->and(SentryScrubber::beforeSendTransaction($transaction))->toBe($transaction)
        ->and(SentryScrubber::beforeSend($bare))->toBe($bare);
});

test('a reset link is filtered wherever it appears in breadcrumb data', function () {
    $breadcrumb = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb(null, [
        'from' => '/reset-password/9f8e7d6c5b4a39281706f5e4d3c2b1a0?email=maya%40monoven.io',
        'to' => '/acme/email/verify/7/1f2e3d4c5b6a79881706f5e4d3c2b1a0aabbccdd?signature=ab12cd34',
        'note' => 'opened https://panel.example.com/reset-password/9f8e7d6c5b4a39281706f5e4d3c2b1a0?email=maya%40monoven.io',
    ]));

    expect($breadcrumb->getMetadata())->toBe([
        'from' => '/reset-password/%5BFiltered%5D?email=%5BFiltered%5D',
        'to' => '/acme/email/verify/7/%5BFiltered%5D?signature=%5BFiltered%5D',
        'note' => 'opened https://panel.example.com/reset-password/%5BFiltered%5D?email=%5BFiltered%5D',
    ]);
});

test('an object in breadcrumb metadata is scrubbed through its public properties', function () {
    $breadcrumb = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb('Processing queue job', [
        'context' => scrubberSecretObject(),
        'count' => 2,
    ]));

    expect($breadcrumb->getMetadata())->toBe([
        'context' => ['db_password' => '[Filtered]', 'note' => 'DB_PASSWORD=[REDACTED]', 'attempts' => 2],
        'count' => 2,
    ]);
});

test('an object in message params is scrubbed the same way', function () {
    $event = Event::createEvent()->setMessage('Rejected %s', [scrubberSecretObject()], 'Rejected an object');

    expect(SentryScrubber::beforeSend($event)->getMessageParams())->toBe([
        ['db_password' => '[Filtered]', 'note' => 'DB_PASSWORD=[REDACTED]', 'attempts' => 2],
    ]);
});

test('an object in extra is scrubbed the same way', function () {
    $event = Event::createEvent()->setExtra(['subject' => scrubberSecretObject()]);

    expect(SentryScrubber::beforeSend($event)->getExtra())->toBe([
        'subject' => ['db_password' => '[Filtered]', 'note' => 'DB_PASSWORD=[REDACTED]', 'attempts' => 2],
    ]);
});

test('a json serializable object is scrubbed through what it serializes to', function () {
    $model = new class implements JsonSerializable
    {
        public function jsonSerialize(): array
        {
            return [
                'ssh_private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----',
                'owner' => (object) ['summary' => 'DB_PASSWORD=hunter2hunter2'],
                'status' => ServerStatus::Active,
                'seen' => new DateTimeImmutable('2026-10-30 22:18:00', new DateTimeZone('UTC')),
            ];
        }
    };

    $breadcrumb = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb('Saved', ['server' => $model]));

    expect($breadcrumb->getMetadata())->toBe(['server' => [
        'ssh_private_key' => '[Filtered]',
        'owner' => ['summary' => 'DB_PASSWORD=[REDACTED]'],
        'status' => 'active',
        'seen' => ['date' => '2026-10-30 22:18:00.000000', 'timezone_type' => 3, 'timezone' => 'UTC'],
    ]]);
});

test('an object that cannot be rendered is filtered whole', function () {
    $broken = new class implements JsonSerializable
    {
        public function jsonSerialize(): never
        {
            throw new RuntimeException('This object refuses to render.');
        }
    };

    $breadcrumb = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb('Saved', ['server' => $broken, 'count' => 2]));

    expect($breadcrumb->getMetadata())->toBe(['server' => '[Filtered]', 'count' => 2]);
});

test('objects that refer to each other and very deep arrays are cut short', function () {
    $first = new stdClass;
    $second = new stdClass;
    $first->other = $second;
    $second->other = $first;
    $first->note = 'DB_PASSWORD=hunter2hunter2';

    $deep = 'DB_PASSWORD=hunter2hunter2';

    for ($level = 0; $level < 15; $level++) {
        $deep = ['next' => $deep];
    }

    $extra = SentryScrubber::beforeSend(Event::createEvent()->setExtra(['loop' => $first, 'deep' => $deep]))->getExtra();

    expect($extra['loop']['note'])->toBe('DB_PASSWORD=[REDACTED]')
        ->and(json_encode($extra))->not->toContain('hunter2hunter2')
        ->and(json_encode($extra['deep']))->toContain('"[Filtered]"');
});

test('extra values under secret-looking names are filtered', function () {
    $event = Event::createEvent()->setExtra([
        'api_key' => 'abc',
        'Authorization' => 'Bearer abc.def.ghi',
        'session_id' => 'abc',
        'cookies' => ['coresmith_session' => 's'],
        'client_secret' => 'abc',
        'password_hash' => '$2y$04$abcdefghijklmnopqrstuu',
        'private-key' => 'abc',
        'DB_PASSWORD' => 'abc',
        'stripe_credentials' => ['id' => 'abc'],
        'webhook_signature' => 'abc',
        'passwd' => 'abc',
        'email' => 'maya@monoven.io',
        'owner_email' => 'maya@monoven.io',
        'otp' => '123456',
        'recovery_codes' => ['abc', 'def'],
        'two_factor_recovery_code' => 'abc',
        'private key' => 'abc',
        'Private-Key' => 'abc',
        'code' => 'abc123',
        'state' => 'running',
        'status_code' => 502,
        'attempts' => 3,
        'output' => 'build finished',
        'cache_key' => 'servers:7:status',
    ]);

    expect(SentryScrubber::beforeSend($event)->getExtra())->toBe([
        'api_key' => '[Filtered]',
        'Authorization' => '[Filtered]',
        'session_id' => '[Filtered]',
        'cookies' => '[Filtered]',
        'client_secret' => '[Filtered]',
        'password_hash' => '[Filtered]',
        'private-key' => '[Filtered]',
        'DB_PASSWORD' => '[Filtered]',
        'stripe_credentials' => '[Filtered]',
        'webhook_signature' => '[Filtered]',
        'passwd' => '[Filtered]',
        'email' => '[Filtered]',
        'owner_email' => '[Filtered]',
        'otp' => '[Filtered]',
        'recovery_codes' => '[Filtered]',
        'two_factor_recovery_code' => '[Filtered]',
        'private key' => '[Filtered]',
        'Private-Key' => '[Filtered]',
        'code' => 'abc123',
        'state' => 'running',
        'status_code' => 502,
        'attempts' => 3,
        'output' => 'build finished',
        'cache_key' => 'servers:7:status',
    ]);
});

test('breadcrumb data under secret-looking names is filtered at any depth', function () {
    $breadcrumb = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb('Processing queue job', [
        'password' => 'hunter2hunter2',
        'Api-Key' => 'abc',
        'headers' => ['Authorization' => 'Bearer abc.def.ghi', 'accept' => 'text/html'],
        'session_id' => 'abc',
        'status_code' => 200,
        'job' => 'App\\Jobs\\DeployApplication',
    ]));

    expect($breadcrumb->getMetadata())->toBe([
        'password' => '[Filtered]',
        'Api-Key' => '[Filtered]',
        'headers' => ['Authorization' => '[Filtered]', 'accept' => 'text/html'],
        'session_id' => '[Filtered]',
        'status_code' => 200,
        'job' => 'App\\Jobs\\DeployApplication',
    ]);
});

test('tags are sanitised and secret-looking names are filtered', function () {
    $event = Event::createEvent()->setTags([
        'command' => 'coresmith:deploy token=abc12345',
        'session_id' => 'abc',
        'region' => 'eu',
    ]);

    expect(SentryScrubber::beforeSend($event)->getTags())->toBe([
        'command' => 'coresmith:deploy token=[REDACTED]',
        'session_id' => '[Filtered]',
        'region' => 'eu',
    ]);
});

test('contexts are sanitised and secret-looking names are filtered', function () {
    $event = Event::createEvent()
        ->setContext('laravel', [
            'api_key' => 'abc',
            'note' => 'DB_PASSWORD=hunter2hunter2',
            'attempts' => 2,
            'nested' => ['cookie' => 'abc', 'url' => 'https://panel.example.com/callback?code=abc123'],
        ])
        ->setContext('trace', ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'op' => 'http.server']);

    expect(SentryScrubber::beforeSend($event)->getContexts())->toBe([
        'laravel' => [
            'api_key' => '[Filtered]',
            'note' => 'DB_PASSWORD=[REDACTED]',
            'attempts' => 2,
            'nested' => ['cookie' => '[Filtered]', 'url' => 'https://panel.example.com/callback?code=%5BFiltered%5D'],
        ],
        'trace' => ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'op' => 'http.server'],
    ]);
});

test('transaction spans are scrubbed like errors', function () {
    $span = new Span;
    $span->setDescription('GET https://api.example.com/cb?code=abc123&page=2 failed');
    $span->setData([
        'url' => 'https://api.example.com/cb',
        'http.url' => 'https://panel.example.com/reset-password/9f8e7d6c5b4a39281706f5e4d3c2b1a0?email=maya%40monoven.io',
        'http.query' => 'code=abc123&page=2',
        'http.request.method' => 'GET',
        'http.response.status_code' => 200,
        'authorization' => 'Bearer abc.def.ghi',
        'note' => 'DB_PASSWORD=hunter2hunter2',
    ]);
    $span->setTags(['session_id' => 'abc', 'op' => 'http.client']);

    SentryScrubber::beforeSendTransaction(Event::createTransaction()->setSpans([$span]));

    expect($span->getDescription())->toBe('GET https://api.example.com/cb?code=%5BFiltered%5D&page=2 failed')
        ->and($span->getData())->toBe([
            'url' => 'https://api.example.com/cb',
            'http.url' => 'https://panel.example.com/reset-password/%5BFiltered%5D?email=%5BFiltered%5D',
            'http.query' => 'code=%5BFiltered%5D&page=2',
            'http.request.method' => 'GET',
            'http.response.status_code' => 200,
            'authorization' => '[Filtered]',
            'note' => 'DB_PASSWORD=[REDACTED]',
        ])
        ->and($span->getTags())->toBe(['session_id' => '[Filtered]', 'op' => 'http.client']);
});

test('a failed query keeps its sql placeholders and loses its bound values', function () {
    $failure = scrubberFailedInsert();
    $event = Event::createEvent()->setExceptions([new ExceptionDataBag($failure)]);

    $value = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $failure]))->getExceptions()[0]->getValue();

    $grammar = DB::getQueryGrammar();

    expect($failure->getMessage())->toContain('maya@monoven.io')
        ->and($value)->toContain(match (DB::getDriverName()) {
            'pgsql' => 'duplicate key value violates unique constraint "users_email_unique"',
            'mysql', 'mariadb' => "Duplicate entry '?' for key",
            default => 'UNIQUE constraint failed: users.email',
        })
        ->toContain('insert into ' . $grammar->wrapTable('users') . ' (' . $grammar->columnize(['id', 'name', 'email', 'password']) . ') values (?, ?, ?, ?)')
        ->not->toContain('maya@monoven.io')
        ->not->toContain('Maya Okafor')
        ->not->toContain('$2y$04$');
});

test('a failed query inside another exception is rebuilt too', function () {
    $failure = scrubberFailedInsert();
    $outer = new RuntimeException('Could not save the user.', 0, $failure);
    $event = Event::createEvent()->setExceptions([new ExceptionDataBag($outer), new ExceptionDataBag($failure)]);

    [$first, $second] = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $outer]))->getExceptions();

    expect($first->getValue())->toBe('Could not save the user.')
        ->and($second->getValue())->toContain('values (?, ?, ?, ?)')
        ->not->toContain('maya@monoven.io');
});

test('a value the database driver echoes back is removed from a failed query too', function () {
    $failure = new QueryException(
        'mysql',
        'insert into `users` (`email`) values (?)',
        ['maya@monoven.io'],
        new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'maya@monoven.io' for key 'users.users_email_unique'"),
    );
    $event = Event::createEvent()->setExceptions([new ExceptionDataBag($failure)]);

    expect(SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $failure]))->getExceptions()[0]->getValue())
        ->toBe("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?' for key 'users.users_email_unique' (Connection: mysql, SQL: insert into `users` (`email`) values (?))");
});

test('a hint for a different exception leaves the value to the text rules', function () {
    $event = Event::createEvent()->setExceptions([new ExceptionDataBag(new RuntimeException('DB_PASSWORD=hunter2hunter2 was rejected'))]);

    expect(SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => new LogicException('other')]))->getExceptions()[0]->getValue())
        ->toBe('DB_PASSWORD=[REDACTED] was rejected');
});

test('the driver exception behind a failed query loses the bound values too', function (string $driver, string $expected) {
    $failure = scrubberQueryFailure($driver, ['maya@monoven.io', 'Maya Okafor', 'acme-eu-west']);
    $event = Event::createEvent()->setExceptions([new ExceptionDataBag($failure), new ExceptionDataBag($failure->getPrevious())]);

    [$query, $chained] = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $failure]))->getExceptions();

    expect($chained->getValue())->toBe($expected)
        ->and($query->getValue())->toBe($expected . ' (Connection: pgsql, SQL: insert into "users" ("email", "password") values (?, ?))');
})->with([
    'a postgres detail line with an email address' => [
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(maya@monoven.io) already exists.",
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(?) already exists.",
    ],
    'a postgres detail line with a name' => [
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_name_unique\"\nDETAIL:  Key (name)=(Maya Okafor) already exists.",
        "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_name_unique\"\nDETAIL:  Key (name)=(?) already exists.",
    ],
    'a mysql duplicate entry with an email address' => [
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'maya@monoven.io' for key 'users.users_email_unique'",
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?' for key 'users.users_email_unique'",
    ],
    'a mysql duplicate entry with a slug' => [
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'acme-eu-west' for key 'teams.teams_slug_unique'",
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '?' for key 'teams.teams_slug_unique'",
    ],
]);

test('bound values replace whole occurrences only and leave codes and identifiers alone', function () {
    $driver = "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(maya@monoven.io) already exists.";
    $failure = scrubberQueryFailure($driver, ['maya', 'maya@monoven.io', 'users', '23505', '7000']);
    $event = Event::createEvent()->setExceptions([new ExceptionDataBag($failure), new ExceptionDataBag($failure->getPrevious())]);

    $chained = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $failure]))->getExceptions()[1];

    expect($chained->getValue())->toBe("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(?) already exists.");
});

test('an exception that wraps a failed query loses the bound values too', function () {
    $failure = scrubberQueryFailure('SQLSTATE[23505]: Unique violation', ['maya@monoven.io', 'Maya Okafor']);
    $outer = new RuntimeException('Could not save: ' . $failure->getMessage(), 0, $failure);
    $event = Event::createEvent()->setExceptions([
        new ExceptionDataBag($outer),
        new ExceptionDataBag($failure),
        new ExceptionDataBag($failure->getPrevious()),
    ]);

    $first = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $outer]))->getExceptions()[0];

    expect($outer->getMessage())->toContain('maya@monoven.io')
        ->and($first->getValue())->toBe('Could not save: SQLSTATE[23505]: Unique violation (Connection: pgsql, SQL: insert into "users" ("email", "password") values (?, ?))');
});

test('the row postgres echoes back from a refused update is filtered whole', function (string $driver, string $expected) {
    $failure = scrubberFailedUpdate($driver);
    $event = Event::createEvent()->setExceptions([new ExceptionDataBag($failure), new ExceptionDataBag($failure->getPrevious())]);

    [$query, $chained] = SentryScrubber::beforeSend($event, EventHint::fromArray(['exception' => $failure]))->getExceptions();

    expect($failure->getMessage())->toContain('$2y$12$')
        ->and($chained->getValue())->toBe($expected)
        ->and($query->getValue())->toBe($expected . ' (Connection: pgsql, SQL: update "users" set "name" = ?, "updated_at" = ? where "id" = ?)');
})->with([
    'a not-null violation' => [
        "SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" of relation \"users\" violates not-null constraint\nDETAIL:  Failing row contains (01K8ABCDEFGHJKMNPQRSTVWXYZ, null, maya@monoven.io, \$2y\$12\$abcdefghijklmnopqrstuuMq0b3f1xkD1e8Gz5Yw9bW6n7y4c2eHa, 2026-10-31 00:00:00, remembertokenremembertoken12345678901234567890123456789012345).",
        "SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" of relation \"users\" violates not-null constraint\nDETAIL:  Failing row contains ([Filtered]).",
    ],
    'a check violation' => [
        "SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"users\" violates check constraint \"users_name_check\"\nDETAIL:  Failing row contains (01K8ABCDEFGHJKMNPQRSTVWXYZ, , maya@monoven.io, \$2y\$12\$abcdefghijklmnopqrstuuMq0b3f1xkD1e8Gz5Yw9bW6n7y4c2eHa, 2026-10-31 00:00:00, remembertokenremembertoken12345678901234567890123456789012345).",
        "SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"users\" violates check constraint \"users_name_check\"\nDETAIL:  Failing row contains ([Filtered]).",
    ],
    'values holding brackets, full stops and line breaks' => [
        "SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"users\" violates check constraint \"users_name_check\"\nDETAIL:  Failing row contains (01K8ABCDEFGHJKMNPQRSTVWXYZ, Maya (ops). Okafor, first note).\nsecond note, \$2y\$12\$abcdefghijklmnopqrstuuMq0b3f1xkD1e8Gz5Yw9bW6n7y4c2eHa, remembertokenremembertoken12345678901234567890123456789012345).",
        "SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation \"users\" violates check constraint \"users_name_check\"\nDETAIL:  Failing row contains ([Filtered]).",
    ],
    'a context line after the row' => [
        "SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" of relation \"users\" violates not-null constraint\nDETAIL:  Failing row contains (01K8ABCDEFGHJKMNPQRSTVWXYZ, null, \$2y\$12\$abcdefghijklmnopqrstuuMq0b3f1xkD1e8Gz5Yw9bW6n7y4c2eHa).\nCONTEXT:  PL/pgSQL function touch_user() line 3 at SQL statement",
        "SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" of relation \"users\" violates not-null constraint\nDETAIL:  Failing row contains ([Filtered]).\nCONTEXT:  PL/pgSQL function touch_user() line 3 at SQL statement",
    ],
]);

test('a failing row is filtered wherever its text is quoted, to the end when nothing closes it', function () {
    $failure = scrubberFailedUpdate("SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"name\" of relation \"users\" violates not-null constraint\nDETAIL:  Failing row contains (01K8ABCDEFGHJKMNPQRSTVWXYZ, null, \$2y\$12\$abcdefghijklmnopqrstuuMq0b3f1xkD1e8Gz5Yw9bW6n7y4c2eHa, remembertokenremembertoken12345678901234567890123456789012345).");

    $quoted = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb('Could not save: ' . $failure->getMessage()))->getMessage();
    $rendered = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb((string) $failure->getPrevious()))->getMessage();

    expect($quoted)->toContain('DETAIL:  Failing row contains ([Filtered]). (Connection: pgsql, SQL: update "users" set "name" = ')
        ->not->toContain('$2y$12$')
        ->not->toContain('remembertoken')
        ->and($rendered)->toStartWith('PDOException: SQLSTATE[23502]: Not null violation')
        ->toEndWith('DETAIL:  Failing row contains ([Filtered])');
});

test('a logged exception keeps only its type and message in breadcrumb data', function () {
    $failure = new PDOException('SQLSTATE[HY000]: General error: 1 no such table: servers');
    $failure->errorInfo = ['HY000', 1, 'no such table: servers, Key (slug)=(acme-eu-west)'];

    $breadcrumb = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb('Query failed', ['exception' => $failure, 'attempt' => 2]));

    expect($breadcrumb->getMetadata())->toBe([
        'exception' => ['type' => PDOException::class, 'message' => 'SQLSTATE[HY000]: General error: 1 no such table: servers'],
        'attempt' => 2,
    ]);
});

test('a logged failed query is replaced by its placeholder form', function () {
    $driver = "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(maya@monoven.io) already exists.";
    $hash = '$2y$04$abcdefghijklmnopqrstuuMq0b3f1xkD1e8Gz5Yw9bW6n7y4c2eHa';
    $failure = scrubberQueryFailure($driver, ['maya@monoven.io', $hash]);
    $failure->getPrevious()->errorInfo = ['23505', 7, 'DETAIL:  Key (email)=(maya@monoven.io) already exists.'];

    $breadcrumb = SentryScrubber::beforeBreadcrumb(new Breadcrumb(
        Breadcrumb::LEVEL_ERROR,
        Breadcrumb::TYPE_DEFAULT,
        'log.error',
        $failure->getMessage(),
        ['exception' => $failure],
    ));

    $placeholder = "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"users_email_unique\"\nDETAIL:  Key (email)=(?) already exists. (Connection: pgsql, SQL: insert into \"users\" (\"email\", \"password\") values (?, ?))";

    expect($failure->getMessage())->toContain('maya@monoven.io')->toContain($hash)
        ->and($breadcrumb->getMessage())->toBe($placeholder)
        ->and($breadcrumb->getMetadata())->toBe(['exception' => ['type' => QueryException::class, 'message' => $placeholder]])
        ->and(json_encode($breadcrumb->getMetadata()))->not->toContain('errorInfo');
});

test('a breadcrumb message that quotes a failed query loses the bound values too', function () {
    $failure = scrubberQueryFailure('SQLSTATE[23505]: Unique violation', ['maya@monoven.io', 'Maya Okafor']);

    $breadcrumb = SentryScrubber::beforeBreadcrumb(scrubberBreadcrumb('Could not save: ' . $failure->getMessage(), ['exception' => $failure]));

    expect($breadcrumb->getMessage())->toBe('Could not save: SQLSTATE[23505]: Unique violation (Connection: pgsql, SQL: insert into "users" ("email", "password") values (?, ?))');
});

test('exceptions in extra and message params are summarised the same way', function () {
    $failure = scrubberQueryFailure('SQLSTATE[23505]: Unique violation', ['maya@monoven.io', 'Maya Okafor']);
    $summary = ['type' => QueryException::class, 'message' => 'SQLSTATE[23505]: Unique violation (Connection: pgsql, SQL: insert into "users" ("email", "password") values (?, ?))'];

    $event = Event::createEvent()
        ->setMessage('Failed %s', [$failure], 'Failed a query')
        ->setExtra(['previous' => $failure]);

    $scrubbed = SentryScrubber::beforeSend($event);

    expect($scrubbed->getMessageParams())->toBe([$summary])
        ->and($scrubbed->getExtra())->toBe(['previous' => $summary]);
});

test('a context whose own name looks secret is filtered whole', function () {
    $event = Event::createEvent()
        ->setContext('session', ['id' => 'abc123def456', 'driver' => 'database'])
        ->setContext('credentials', ['user' => 'maya'])
        ->setContext('runtime', ['name' => 'php', 'version' => '8.5.12']);

    expect(SentryScrubber::beforeSend($event)->getContexts())->toBe([
        'session' => ['value' => '[Filtered]'],
        'credentials' => ['value' => '[Filtered]'],
        'runtime' => ['name' => 'php', 'version' => '8.5.12'],
    ]);
});

test('transaction names are filtered like addresses', function (string $name, string $expected) {
    $error = Event::createEvent()->setTransaction($name);
    $trace = Event::createTransaction()->setTransaction($name);

    expect(SentryScrubber::beforeSend($error)->getTransaction())->toBe($expected)
        ->and(SentryScrubber::beforeSendTransaction($trace)->getTransaction())->toBe($expected);
})->with([
    'a raw reset path' => [
        'GET /acme/reset-password/9f8e7d6c5b4a39281706f5e4d3c2b1a0',
        'GET /acme/reset-password/%5BFiltered%5D',
    ],
    'a raw verification path' => [
        '/email/verify/01K8ABCDEF/1f2e3d4c5b6a79881706f5e4d3c2b1a0aabbccdd',
        '/email/verify/01K8ABCDEF/%5BFiltered%5D',
    ],
    'a raw path with a query' => [
        '/callback?code=abc123&state=xyz789',
        '/callback?code=%5BFiltered%5D&state=%5BFiltered%5D',
    ],
    'a route pattern' => [
        '/servers/{server}/apps',
        '/servers/{server}/apps',
    ],
    'a command name' => [
        'coresmith:prune-metrics',
        'coresmith:prune-metrics',
    ],
]);

test('an event without a transaction name keeps none', function () {
    expect(SentryScrubber::beforeSend(Event::createEvent())->getTransaction())->toBeNull();
});
