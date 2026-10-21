<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Exceptions\CloudProviderException;
use App\Exceptions\ExplainsFailure;
use App\Services\Cloud\CloudAccount;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\Providers\HttpCloudApi;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(fn() => Http::preventStrayRequests());

function cloudBaseApi(string $token = 'secret-token-123'): object
{
    return new class($token) extends HttpCloudApi
    {
        public function type(): CloudProviderType
        {
            return CloudProviderType::Hetzner;
        }

        protected function message(Response $response): ?string
        {
            return $response->json('error.message');
        }

        public function probe(): Response
        {
            return $this->getJson('/servers');
        }

        public function write(): Response
        {
            return $this->postJson('/servers', ['name' => 'x']);
        }

        public function call(string $method, mixed ...$arguments): mixed
        {
            return $this->{$method}(...$arguments);
        }
    };
}

function cloudBaseFailure(CloudFailure $failure, ?string $detail = 'server is locked', ?int $status = null, CloudProviderType $provider = CloudProviderType::Hetzner): CloudProviderException
{
    return new CloudProviderException($failure, $provider, $detail, $status);
}

test('requests carry the token, json headers and the panel agent', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['servers' => []])]);

    cloudBaseApi()->probe();

    Http::assertSent(fn($request) => $request->hasHeader('Authorization', 'Bearer secret-token-123')
        && $request->hasHeader('Accept', 'application/json')
        && str_starts_with($request->header('User-Agent')[0], 'Coresmith/'));
});

test('each response class becomes the failure its status means', function (int $status, CloudFailure $failure) {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['error' => ['code' => 'x', 'message' => 'nope']], $status)]);

    try {
        cloudBaseApi()->write();
        $this->fail('no exception');
    } catch (CloudProviderException $e) {
        expect($e->failure)->toBe($failure)->and($e->status)->toBe($status)->and($e->detail)->toBe('nope');
    }
})->with([
    [401, CloudFailure::TokenRejected],
    [403, CloudFailure::Forbidden],
    [404, CloudFailure::NotFound],
    [409, CloudFailure::Refused],
    [422, CloudFailure::Refused],
    [423, CloudFailure::Busy],
    [429, CloudFailure::RateLimited],
    [503, CloudFailure::Unavailable],
]);

test('a redirect is refused, never followed', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

    try {
        cloudBaseApi()->write();
        $this->fail('no exception');
    } catch (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->detail)->toBe('answered with a redirect');
    }

    Http::assertSentCount(1);
});

test('a provider that cannot be reached is unreachable', function () {
    Http::fake(['api.hetzner.cloud/*' => Http::failedConnection()]);

    try {
        cloudBaseApi()->probe();
        $this->fail('no exception');
    } catch (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unreachable)
            ->and($e->getMessage())->toBe("Coresmith couldn't reach Hetzner Cloud at api.hetzner.cloud. Check this panel can make outbound HTTPS requests, then try again.")
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

test('reads are retried after a server error but a write is sent once', function () {
    Http::fakeSequence('api.hetzner.cloud/v1/servers')->push([], 503)->push(['servers' => []])->push([], 503);

    cloudBaseApi()->probe();

    expect(fn() => cloudBaseApi()->write())->toThrow(CloudProviderException::class);

    Http::assertSentCount(3);
});

test('a provider message that echoes the token never carries it into the exception', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['error' => ['message' => "token secret-token-123 is invalid\nfor real"]], 401)]);

    try {
        cloudBaseApi()->probe();
        $this->fail('no exception');
    } catch (CloudProviderException $e) {
        expect($e->getMessage())->not->toContain('secret-token-123')
            ->and($e->connectMessage())->not->toContain('secret-token-123')
            ->and($e->detail)->toBe('token [REDACTED] is invalid for real');
    }
});

test('the task and connect copy say what happened and what to do', function (CloudFailure $failure, string $task, string $connect) {
    $e = new CloudProviderException($failure, CloudProviderType::Hetzner, 'unable to authenticate', 401);

    expect($e->getMessage())->toBe($task)->and($e->connectMessage())->toBe($connect);
})->with([
    [CloudFailure::TokenRejected, "Hetzner Cloud refused this account's token. Hetzner Cloud said: unable to authenticate. Replace it in Settings → Team, then try again.", "Hetzner Cloud didn't accept that token. Hetzner Cloud said: unable to authenticate. Copy it again from Hetzner Cloud, then paste it here."],
    [CloudFailure::Forbidden, "Hetzner Cloud says this token isn't allowed to do that. Hetzner Cloud said: unable to authenticate. Generate a Read & Write token for the project. Then replace the token in Settings → Team.", "Hetzner Cloud accepted the token, but it isn't allowed to manage servers. Hetzner Cloud said: unable to authenticate. Generate a Read & Write token for the project."],
]);

test('the client never shows its token when dumped, and its token parameter is sensitive', function () {
    $api = cloudBaseApi();

    expect(print_r($api, true))->not->toContain('secret-token-123')
        ->and((new ReflectionMethod(HttpCloudApi::class, '__construct'))->getParameters()[0]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});

test('only a public ipv4 address is used', function (mixed $ip, ?string $expected) {
    expect(HttpCloudApi::publicIpv4($ip))->toBe($expected);
})->with([
    ['203.0.113.40', '203.0.113.40'],
    ['0.0.0.0', null],
    ['10.0.0.5', null],
    ['192.168.128.10', null],
    ['127.0.0.1', null],
    ['2001:db8::1', null],
    [null, null],
]);

test('a size knows where it runs and what it costs there', function () {
    $size = new CloudSize('cpx21', 3, 4096, 80, 'x86', null, ['fsn1' => new CloudPrice('7.55', 'EUR'), 'ash' => new CloudPrice('8.49', 'EUR')], ['fsn1', 'ash']);

    expect($size->availableIn('fsn1'))->toBeTrue()
        ->and($size->availableIn('hel1'))->toBeFalse()
        ->and($size->priceIn('ash')->monthly)->toBe('8.49')
        ->and($size->label())->toBe('4 GB · 3 vCPU')
        ->and(CloudPrice::fromNumber(6, 'USD')->monthly)->toBe('6.00');
});

test('the agent names the installed version', function () {
    config(['coresmith.version' => '9.8.7']);
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['servers' => []])]);

    cloudBaseApi()->probe();

    Http::assertSent(fn($request) => $request->hasHeader('User-Agent', 'Coresmith/9.8.7') && $request->hasHeader('Content-Type', 'application/json'));
});

test('requests use short timeouts, no redirects and the configured base url', function () {
    config(['services.hetzner.api_url' => 'http://127.0.0.1:9/v1/']);
    $seen = [];
    Http::fake(function ($request, $options) use (&$seen) {
        $seen = [$request->url(), $options['allow_redirects'] ?? null, $options['connect_timeout'] ?? null, $options['timeout'] ?? null];

        return Http::response(['servers' => []]);
    });

    cloudBaseApi()->probe();

    expect($seen)->toBe(['http://127.0.0.1:9/v1/servers', false, 5, 10])
        ->and(HttpCloudApi::CONNECT_SECONDS)->toBe(5)
        ->and(HttpCloudApi::TIMEOUT_SECONDS)->toBe(10);
});

test('a write carries its fields as json', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['server' => ['id' => 7]], 201)]);

    $response = cloudBaseApi()->write();

    expect($response->json('server.id'))->toBe(7);
    Http::assertSent(fn($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.hetzner.cloud/v1/servers'
        && $request->body() === '{"name":"x"}');
});

test('a write with no fields still sends a json object', function () {
    $bodies = [];
    Http::fake(function ($request) use (&$bodies) {
        $bodies[] = [$request->method(), $request->body()];

        return Http::response([]);
    });
    $api = cloudBaseApi();

    $api->call('postJson', '/servers/7/actions/reboot');
    $api->call('patchJson', '/servers/7');

    expect($bodies)->toBe([['POST', '{}'], ['PATCH', '{}']]);
});

test('a read that meets a redirect is refused and not retried', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);

    expect(fn() => cloudBaseApi()->probe())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)
            ->and($e->detail)->toBe('answered with a redirect')
            ->and($e->status)->toBe(302);
    });

    Http::assertSentCount(1);
});

test('reads wait half a second between attempts and give up after three', function () {
    Http::fakeSequence('api.hetzner.cloud/v1/servers')->push([], 503)->push([], 502)->push([], 503);

    expect(fn() => cloudBaseApi()->probe())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->status)->toBe(503);
    });

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn(CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 500, 2);
});

test('a read that cannot connect is tried three times and a write once', function () {
    $attempts = 0;
    Http::fake(function ($request) use (&$attempts) {
        $attempts++;

        return Http::failedConnection()($request);
    });
    $api = cloudBaseApi();

    expect(fn() => $api->probe())->toThrow(fn(CloudProviderException $e) => expect($e->failure)->toBe(CloudFailure::Unreachable));
    expect($attempts)->toBe(3);

    $attempts = 0;

    expect(fn() => $api->write())->toThrow(fn(CloudProviderException $e) => expect($e->failure)->toBe(CloudFailure::Unreachable));
    expect($attempts)->toBe(1);
});

test('a read is tried again only for the statuses that can pass', function (int $status) {
    Http::fakeSequence('api.hetzner.cloud/v1/servers')->push([], $status)->push(['servers' => []]);

    expect(cloudBaseApi()->probe()->json('servers'))->toBe([]);

    Http::assertSentCount(2);
})->with([423, 429, 500, 502, 503, 504]);

test('a read is not tried again when the answer will not change', function (int $status) {
    Http::fakeSequence('api.hetzner.cloud/v1/servers')->push(['error' => ['message' => 'no']], $status)->push(['servers' => []]);

    expect(fn() => cloudBaseApi()->probe())->toThrow(CloudProviderException::class);

    Http::assertSentCount(1);
})->with([400, 401, 403, 404, 409, 422]);

test('a lookup answers null for a missing resource and the response otherwise', function () {
    Http::fakeSequence('api.hetzner.cloud/v1/servers/7')->pushStatus(404)->push(['server' => ['id' => 7]]);
    $api = cloudBaseApi();

    expect($api->call('getJsonOrNull', '/servers/7'))->toBeNull()
        ->and($api->call('getJsonOrNull', '/servers/7')->json('server.id'))->toBe(7);

    Http::assertSentCount(2);
});

test('a lookup still fails for anything but a missing resource', function (int $status, CloudFailure $failure) {
    Http::fake(['api.hetzner.cloud/v1/servers/7' => Http::response(['error' => ['message' => 'nope']], $status)]);

    expect(fn() => cloudBaseApi()->call('getJsonOrNull', '/servers/7'))->toThrow(function (CloudProviderException $e) use ($failure) {
        expect($e->failure)->toBe($failure);
    });
})->with([
    [401, CloudFailure::TokenRejected],
    [403, CloudFailure::Forbidden],
    [422, CloudFailure::Refused],
    [429, CloudFailure::RateLimited],
]);

test('a lookup carries its query and extra headers', function () {
    Http::fake(['api.hetzner.cloud/v1/images*' => Http::response(['images' => []])]);

    cloudBaseApi()->call('getJsonOrNull', '/images', ['per_page' => 50], ['X-Filter' => '{"is_public": true}']);

    Http::assertSent(fn($request) => $request->url() === 'https://api.hetzner.cloud/v1/images?per_page=50'
        && $request->hasHeader('X-Filter', '{"is_public": true}')
        && $request->hasHeader('Authorization', 'Bearer secret-token-123'));
});

test('deleting something that is already gone is done', function () {
    Http::fake(['api.hetzner.cloud/v1/servers/7' => Http::response(['error' => ['message' => 'not found']], 404)]);

    cloudBaseApi()->call('deleteOrGone', '/servers/7');

    Http::assertSent(fn($request) => $request->method() === 'DELETE' && $request->url() === 'https://api.hetzner.cloud/v1/servers/7');
    Http::assertSentCount(1);
});

test('a delete is tried again after a server error and fails like any other call', function () {
    Http::fakeSequence('api.hetzner.cloud/v1/servers/7')->push([], 503)->push([], 204)->push(['error' => ['message' => 'no']], 403);
    $api = cloudBaseApi();

    $api->call('deleteOrGone', '/servers/7');

    expect(fn() => $api->call('deleteOrGone', '/servers/7'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)->and($e->detail)->toBe('no');
    });

    Http::assertSentCount(3);
});

test('a patch carries its fields and is sent once', function () {
    Http::fakeSequence('api.hetzner.cloud/v1/servers/7')->push([], 503)->push([]);

    expect(fn() => cloudBaseApi()->call('patchJson', '/servers/7', ['plan' => 'vc2-2c-4gb']))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable);
    });

    Http::assertSent(fn($request) => $request->method() === 'PATCH' && $request->body() === '{"plan":"vc2-2c-4gb"}');
    Http::assertSentCount(1);
});

test('pagination follows the provider until it names no next page', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::sequence()
        ->push(['server_types' => [['name' => 'a'], ['name' => 'b']], 'meta' => ['pagination' => ['next_page' => 2]]])
        ->push(['server_types' => [['name' => 'c']], 'meta' => ['pagination' => ['next_page' => null]]])]);

    $items = cloudBaseApi()->call(
        'paginate',
        '/server_types',
        ['per_page' => 2],
        'server_types',
        fn(Response $response): ?array => ($page = $response->json('meta.pagination.next_page')) === null ? null : ['per_page' => 2, 'page' => $page],
    );

    expect($items->pluck('name')->all())->toBe(['a', 'b', 'c']);
    Http::assertSentCount(2);
    Http::assertSent(fn($request) => $request->url() === 'https://api.hetzner.cloud/v1/server_types?per_page=2');
    Http::assertSent(fn($request) => $request->url() === 'https://api.hetzner.cloud/v1/server_types?per_page=2&page=2');
});

test('every page is read with the same headers', function () {
    Http::fake(['api.hetzner.cloud/v1/images*' => Http::sequence()
        ->push(['data' => [['id' => 1]], 'next' => 'two'])
        ->push(['data' => [['id' => 2]], 'next' => null])]);

    $items = cloudBaseApi()->call(
        'paginate',
        '/images',
        [],
        'data',
        fn(Response $response): ?array => $response->json('next') === null ? null : ['cursor' => $response->json('next')],
        ['X-Filter' => '{"is_public": true}'],
    );

    expect($items->pluck('id')->all())->toBe([1, 2]);
    Http::assertSentCount(2);
    Http::assertSent(fn($request) => $request->hasHeader('X-Filter', '{"is_public": true}'));
});

test('pagination stops after twenty pages whatever the provider says', function () {
    Http::fake(['api.hetzner.cloud/v1/items*' => Http::response(['items' => [['id' => 1]]])]);

    $items = cloudBaseApi()->call('paginate', '/items', [], 'items', fn(Response $response): ?array => ['page' => 2]);

    expect($items)->toHaveCount(20)->and(HttpCloudApi::MAX_PAGES)->toBe(20);
    Http::assertSentCount(20);
});

test('a page without the list contributes nothing', function () {
    Http::fake(['api.hetzner.cloud/v1/items*' => Http::response(['other' => 1])]);

    $items = cloudBaseApi()->call('paginate', '/items', [], 'items', fn(Response $response): ?array => null);

    expect($items->all())->toBe([]);
    Http::assertSentCount(1);
});

test('a provider failure on a later page is a failure of the whole read', function () {
    Http::fake(['api.hetzner.cloud/v1/items*' => Http::sequence()
        ->push(['items' => [['id' => 1]]])
        ->push(['error' => ['message' => 'slow down']], 429)
        ->push(['error' => ['message' => 'slow down']], 429)
        ->push(['error' => ['message' => 'slow down']], 429)]);

    expect(fn() => cloudBaseApi()->call('paginate', '/items', [], 'items', fn(Response $response): ?array => ['page' => 2]))
        ->toThrow(function (CloudProviderException $e) {
            expect($e->failure)->toBe(CloudFailure::RateLimited)->and($e->detail)->toBe('slow down');
        });
});

test('a message that says nothing leaves no detail', function (mixed $body, int $status) {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response($body, $status)]);

    expect(fn() => cloudBaseApi()->write())->toThrow(function (CloudProviderException $e) {
        expect($e->detail)->toBeNull()->and($e->getMessage())->not->toContain(' said: ');
    });
})->with([
    'an empty json body' => [[], 422],
    'a blank message' => [['error' => ['message' => '']], 422],
    'a message of whitespace' => [['error' => ['message' => " \n\t "]], 409],
    'an html page from a proxy' => ['<html><body>Bad gateway</body></html>', 502],
]);

test('a long provider message is cut', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['error' => ['message' => str_repeat('a long reason ', 60)]], 422)]);

    expect(fn() => cloudBaseApi()->write())->toThrow(function (CloudProviderException $e) {
        expect($e->detail)->toBe(Str::limit(rtrim(str_repeat('a long reason ', 60)), 240));
        expect(mb_strlen((string) $e->detail))->toBeLessThanOrEqual(243);
    });
});

test('provider messages are cleaned of whitespace and credentials', function () {
    $secret = 'dop_v1_' . str_repeat('ab12', 16);
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['error' => ['message' => "  rejected\t{$secret}\r\n\r\nand  Bearer abc.def.ghi  "]], 401)]);

    expect(fn() => cloudBaseApi()->probe())->toThrow(function (CloudProviderException $e) use ($secret) {
        expect($e->detail)->toBe('rejected [REDACTED_DIGITALOCEAN_TOKEN] and Bearer [REDACTED]')
            ->and($e->getMessage())->not->toContain($secret);
    });
});

test('the token is removed before the message is cut', function () {
    $token = 'tok-' . str_repeat('z', 40);
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['error' => ['message' => str_repeat('x', 230) . " {$token}"]], 401)]);

    expect(fn() => cloudBaseApi($token)->probe())->toThrow(function (CloudProviderException $e) {
        expect($e->detail)->not->toContain('zzzz')->and($e->detail)->not->toContain('tok-');
    });
});

test('every failure has its own copy for tasks and for token forms', function (CloudFailure $failure, ?int $status, string $task, string $connect) {
    $e = cloudBaseFailure($failure, 'server is locked', $status);

    expect($e->getMessage())->toBe($task)->and($e->connectMessage())->toBe($connect);
})->with([
    'not found' => [CloudFailure::NotFound, 404, "Hetzner Cloud can't find it any more. Hetzner Cloud said: server is locked.", "Hetzner Cloud can't find it any more. Hetzner Cloud said: server is locked."],
    'busy' => [CloudFailure::Busy, 423, 'Hetzner Cloud is busy with another change to it. Hetzner Cloud said: server is locked. Coresmith tries again shortly.', 'Hetzner Cloud is busy with another change. Hetzner Cloud said: server is locked. Try again in a minute.'],
    'rate limited' => [CloudFailure::RateLimited, 429, 'Hetzner Cloud is rate limiting this account. Hetzner Cloud said: server is locked. Coresmith tries again shortly.', 'Hetzner Cloud is rate limiting this account. Hetzner Cloud said: server is locked. Wait a minute, then try again.'],
    'refused' => [CloudFailure::Refused, 422, 'Hetzner Cloud refused the request. Hetzner Cloud said: server is locked.', 'Hetzner Cloud refused the request. Hetzner Cloud said: server is locked.'],
    'unavailable' => [CloudFailure::Unavailable, 503, 'Hetzner Cloud had a problem answering (HTTP 503). Try again in a minute.', 'Hetzner Cloud had a problem answering (HTTP 503). Try again in a minute.'],
    'unreachable' => [CloudFailure::Unreachable, null, "Coresmith couldn't reach Hetzner Cloud at api.hetzner.cloud. Check this panel can make outbound HTTPS requests, then try again.", "Coresmith couldn't reach Hetzner Cloud at api.hetzner.cloud. Check this panel can make outbound HTTPS requests, then try again."],
]);

test('the copy names the provider, its host and its own permission advice', function (CloudProviderType $provider, string $label, string $host, string $hint) {
    $forbidden = cloudBaseFailure(CloudFailure::Forbidden, 'denied', 403, $provider);
    $unreachable = cloudBaseFailure(CloudFailure::Unreachable, null, null, $provider);

    expect($forbidden->getMessage())->toBe("{$label} says this token isn't allowed to do that. {$label} said: denied. {$hint} Then replace the token in Settings → Team.")
        ->and($forbidden->connectMessage())->toBe("{$label} accepted the token, but it isn't allowed to manage servers. {$label} said: denied. {$hint}")
        ->and($unreachable->getMessage())->toBe("Coresmith couldn't reach {$label} at {$host}. Check this panel can make outbound HTTPS requests, then try again.");
})->with([
    [CloudProviderType::Hetzner, 'Hetzner Cloud', 'api.hetzner.cloud', 'Generate a Read & Write token for the project.'],
    [CloudProviderType::DigitalOcean, 'DigitalOcean', 'api.digitalocean.com', 'Give it full access, or custom scopes for droplets, SSH keys and tags.'],
    [CloudProviderType::Vultr, 'Vultr', 'api.vultr.com', "Allow this panel's IP address in Vultr's API access control."],
    [CloudProviderType::Linode, 'Linode', 'api.linode.com', 'Give it read/write access to Linodes.'],
]);

test('a failure with no detail says nothing about what the provider said', function () {
    $e = cloudBaseFailure(CloudFailure::TokenRejected, null, 401);

    expect($e->getMessage())->toBe("Hetzner Cloud refused this account's token. Replace it in Settings → Team, then try again.")
        ->and($e->connectMessage())->toBe("Hetzner Cloud didn't accept that token. Copy it again from Hetzner Cloud, then paste it here.");
});

test('the full stops a provider ends its message with are not doubled', function (string $detail) {
    $e = cloudBaseFailure(CloudFailure::Refused, $detail, 422);

    expect($e->getMessage())->toBe('Hetzner Cloud refused the request. Hetzner Cloud said: server is locked.')
        ->and($e->detail)->toBe($detail);
})->with(['server is locked.', 'server is locked...', 'server is locked. ', 'server is locked']);

test('a message made only of full stops is treated as no detail', function () {
    expect(cloudBaseFailure(CloudFailure::Refused, '...', 422)->getMessage())->toBe('Hetzner Cloud refused the request.');
});

test('an unavailable provider with no status still says to wait', function () {
    expect(cloudBaseFailure(CloudFailure::Unavailable, null)->getMessage())->toBe('Hetzner Cloud had a problem answering. Try again in a minute.');
});

test('a missing token reads as a refused one', function () {
    $e = CloudProviderException::noToken(CloudProviderType::Linode);

    expect($e->failure)->toBe(CloudFailure::TokenRejected)
        ->and($e->provider)->toBe(CloudProviderType::Linode)
        ->and($e->detail)->toBe('no token is stored')
        ->and($e->status)->toBeNull()
        ->and($e->getMessage())->toBe("Linode refused this account's token. Linode said: no token is stored. Replace it in Settings → Team, then try again.")
        ->and($e->getPrevious())->toBeNull()
        ->and($e)->toBeInstanceOf(ExplainsFailure::class)->toBeInstanceOf(RuntimeException::class);
});

test('only the failures that can pass are worth trying again', function (CloudFailure $failure, bool $retryable) {
    expect($failure->retryable())->toBe($retryable)
        ->and(cloudBaseFailure($failure)->retryable())->toBe($retryable);
})->with([
    [CloudFailure::TokenRejected, false],
    [CloudFailure::Forbidden, false],
    [CloudFailure::NotFound, false],
    [CloudFailure::Busy, true],
    [CloudFailure::RateLimited, true],
    [CloudFailure::Refused, false],
    [CloudFailure::Unavailable, true],
    [CloudFailure::Unreachable, true],
]);

test('a provider price is kept to two decimals', function (float|int|string $monthly, string $expected) {
    expect(CloudPrice::fromNumber($monthly, 'EUR')->monthly)->toBe($expected)
        ->and(CloudPrice::fromNumber($monthly, 'EUR')->currency)->toBe('EUR');
})->with([
    [6, '6.00'],
    [7.5, '7.50'],
    ['3.7900000000', '3.79'],
    ['12', '12.00'],
    [14.4, '14.40'],
    ['0.0060000000', '0.01'],
    [1234.5, '1234.50'],
]);

test('a size without a region list runs anywhere and falls back to its one price', function () {
    $size = new CloudSize('vc2-1c-2gb', 1, 2048, 55, 'x86', new CloudPrice('10.00', 'USD'));

    expect($size->availableIn('anywhere'))->toBeTrue()
        ->and($size->priceIn('anywhere'))->toBe($size->price)
        ->and($size->prices)->toBe([])
        ->and($size->regions)->toBeNull();
});

test('a size without any price says so rather than guessing', function () {
    $size = new CloudSize('x', 1, 2048, 25, 'x86', null, ['fsn1' => new CloudPrice('3.79', 'EUR')], ['fsn1']);

    expect($size->priceIn('ash'))->toBeNull()->and($size->availableIn('ash'))->toBeFalse();
});

test('a size under a gigabyte is labelled in megabytes', function () {
    expect((new CloudSize('s-1vcpu-512mb-10gb', 1, 512, 10, 'x86', null))->label())->toBe('512 MB · 1 vCPU');
});

test('an image knows which architectures it runs on and can be relabelled', function () {
    $image = new CloudImage('ubuntu-24.04', 'ubuntu', '24.04', ['x86', 'arm']);
    $labelled = $image->withLabel('Ubuntu 24.04');

    expect($image->supports('x86'))->toBeTrue()
        ->and($image->supports('arm'))->toBeTrue()
        ->and((new CloudImage('debian-12', 'debian', '12', ['x86']))->supports('arm'))->toBeFalse()
        ->and($image->label)->toBe('')
        ->and($labelled->label)->toBe('Ubuntu 24.04')
        ->and($labelled->id)->toBe('ubuntu-24.04')
        ->and($labelled->family)->toBe('ubuntu')
        ->and($labelled->version)->toBe('24.04')
        ->and($labelled->architectures)->toBe(['x86', 'arm'])
        ->and($labelled)->not->toBe($image);
});

test('an instance is ready only when it runs and has an address', function (CloudInstanceState $state, ?string $ipv4, bool $ready) {
    expect((new CloudInstance('4711', $state, $ipv4))->isReady())->toBe($ready);
})->with([
    [CloudInstanceState::Running, '203.0.113.40', true],
    [CloudInstanceState::Running, null, false],
    [CloudInstanceState::Starting, '203.0.113.40', false],
    [CloudInstanceState::Stopped, '203.0.113.40', false],
    [CloudInstanceState::Busy, '203.0.113.40', false],
    [CloudInstanceState::Deleting, '203.0.113.40', false],
    [CloudInstanceState::Unknown, '203.0.113.40', false],
]);

test('the instance states are the ones the providers map onto', function () {
    expect(array_map(fn(CloudInstanceState $state): string => $state->value, CloudInstanceState::cases()))
        ->toBe(['starting', 'running', 'stopped', 'busy', 'deleting', 'unknown']);
});

test('the values a client returns are final and readonly', function (string $class) {
    $reflection = new ReflectionClass($class);

    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
})->with([CloudAccount::class, CloudRegion::class, CloudPrice::class, CloudSize::class, CloudImage::class, CloudServerSpec::class, CloudInstance::class]);

test('the small values keep what they are given', function () {
    $region = new CloudRegion('fsn1', 'Falkenstein (fsn1)');
    $spec = new CloudServerSpec('outpost-02', 'fsn1', 'cx22', 'ubuntu-24.04', 'coresmith-01j', 'ssh-ed25519 AAAA', null);

    expect((new CloudAccount(null))->label)->toBeNull()
        ->and((new CloudAccount('ops@monoven.io'))->label)->toBe('ops@monoven.io')
        ->and($region->country)->toBeNull()
        ->and((new CloudRegion('fsn1', 'Falkenstein (fsn1)', 'DE'))->country)->toBe('DE')
        ->and([$spec->name, $spec->region, $spec->size, $spec->image, $spec->tag, $spec->publicKey, $spec->keyId])
        ->toBe(['outpost-02', 'fsn1', 'cx22', 'ubuntu-24.04', 'coresmith-01j', 'ssh-ed25519 AAAA', null])
        ->and((new CloudInstance('1', CloudInstanceState::Running, '203.0.113.9'))->size)->toBeNull()
        ->and((new CloudInstance('1', CloudInstanceState::Running, '203.0.113.9', 'cx22'))->size)->toBe('cx22');
});
