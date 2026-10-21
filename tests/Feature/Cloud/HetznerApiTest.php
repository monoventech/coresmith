<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Exceptions\CloudProviderException;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Cloud\Providers\HetznerApi;
use App\Services\Cloud\Providers\HttpCloudApi;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CloudFixtures;

beforeEach(fn() => Http::preventStrayRequests());

function cloudHetzner(): HetznerApi
{
    return new HetznerApi('hetzner-token');
}

function cloudHetznerTag(): string
{
    return 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d';
}

function cloudHetznerKey(): string
{
    return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4 coresmith-test';
}

function cloudHetznerSpec(?string $keyId = '2324'): CloudServerSpec
{
    return new CloudServerSpec('outpost-02', 'fsn1', 'cx22', 'ubuntu-24.04', cloudHetznerTag(), cloudHetznerKey(), $keyId);
}

function cloudHetznerMissing(): PromiseInterface
{
    return Http::response(['error' => ['code' => 'not_found', 'message' => 'server with ID 4711 not found', 'details' => []]], 404);
}

function cloudHetznerFixture(string $name, array $changes = []): array
{
    $body = CloudFixtures::json("hetzner/{$name}");

    foreach ($changes as $path => $value) {
        data_set($body, $path, $value);
    }

    return $body;
}

function cloudHetznerNotice(): array
{
    return ['announced' => '2026-10-01T00:00:00+00:00', 'unavailable_after' => '2027-01-01T00:00:00+00:00'];
}

test('hetzner is the cloud api for hetzner cloud', function () {
    expect(cloudHetzner())->toBeInstanceOf(CloudApi::class)
        ->toBeInstanceOf(HttpCloudApi::class)
        ->and(cloudHetzner()->type())->toBe(CloudProviderType::Hetzner)
        ->and((new ReflectionClass(HetznerApi::class))->isFinal())->toBeTrue();
});

test('hetzner verifies a token by listing one server', function () {
    Http::fake(['api.hetzner.cloud/v1/servers*' => Http::response(CloudFixtures::json('hetzner/servers-empty'))]);

    $account = cloudHetzner()->verify();

    expect($account->label)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.hetzner.cloud/v1/servers?per_page=1'
        && $request->hasHeader('Authorization', 'Bearer hetzner-token'));
});

test('hetzner reports a refused token in words', function () {
    Http::fake(['api.hetzner.cloud/v1/servers*' => Http::response(CloudFixtures::json('hetzner/error-unauthorized'), 401)]);

    expect(fn() => cloudHetzner()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::TokenRejected)
            ->and($e->status)->toBe(401)
            ->and($e->retryable())->toBeFalse()
            ->and($e->connectMessage())->toContain('unable to authenticate');
    });

    Http::assertSentCount(1);
});

test('hetzner lists locations as regions', function () {
    Http::fake(['api.hetzner.cloud/v1/locations' => Http::response(CloudFixtures::json('hetzner/locations'))]);

    $regions = cloudHetzner()->regions();

    expect($regions->pluck('id')->all())->toBe(['fsn1', 'nbg1', 'ash'])
        ->and($regions->first()->label)->toBe('Falkenstein (fsn1)')
        ->and($regions->first()->country)->toBe('DE')
        ->and($regions->get(1)->label)->toBe('Nuremberg (nbg1)')
        ->and($regions->last()->label)->toBe('Ashburn, VA (ash)')
        ->and($regions->last()->country)->toBe('US');
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.hetzner.cloud/v1/locations');
});

test('hetzner reads every page of server types and skips deprecated ones', function () {
    Http::fake([
        'api.hetzner.cloud/v1/server_types?per_page=50' => Http::response(CloudFixtures::json('hetzner/server_types-1')),
        'api.hetzner.cloud/v1/server_types?per_page=50&page=2' => Http::response(CloudFixtures::json('hetzner/server_types-2')),
    ]);

    $sizes = cloudHetzner()->sizes();
    $bySlug = $sizes->keyBy('id');

    expect($sizes->pluck('id')->all())->toBe(['cx22', 'cax11', 'cpx21']);

    $cx22 = $bySlug['cx22'];
    expect($cx22->cpus)->toBe(2)
        ->and($cx22->memoryMb)->toBe(4096)
        ->and($cx22->diskGb)->toBe(40)
        ->and($cx22->architecture)->toBe('x86')
        ->and($cx22->regions)->toBe(['fsn1'])
        ->and($cx22->price)->toBeNull()
        ->and($cx22->priceIn('fsn1'))->toEqual(new CloudPrice('3.79', 'EUR'))
        ->and($cx22->availableIn('nbg1'))->toBeFalse();

    $cax11 = $bySlug['cax11'];
    expect($cax11->architecture)->toBe('arm')
        ->and($cax11->cpus)->toBe(2)
        ->and($cax11->memoryMb)->toBe(4096)
        ->and($cax11->diskGb)->toBe(40)
        ->and($cax11->regions)->toBe(['fsn1'])
        ->and($cax11->priceIn('fsn1'))->toEqual(new CloudPrice('3.79', 'EUR'));

    $cpx21 = $bySlug['cpx21'];
    expect($cpx21->cpus)->toBe(3)
        ->and($cpx21->diskGb)->toBe(80)
        ->and($cpx21->regions)->toBe(['fsn1', 'ash'])
        ->and($cpx21->priceIn('fsn1')->monthly)->toBe('7.55')
        ->and($cpx21->priceIn('ash'))->toEqual(new CloudPrice('8.49', 'EUR'));

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->url() === 'https://api.hetzner.cloud/v1/server_types?per_page=50&page=2');
});

test('hetzner skips a size that carries a deprecation notice', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(cloudHetznerFixture('server_types-1', [
        'server_types.0.deprecation' => cloudHetznerNotice(),
        'meta.pagination.next_page' => null,
    ]))]);

    expect(cloudHetzner()->sizes()->pluck('id')->all())->toBe(['cax11']);
});

test('hetzner offers a size only in the locations that still have it', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(cloudHetznerFixture('server_types-2', [
        'server_types.0.locations.1.deprecation' => cloudHetznerNotice(),
        'meta.pagination.next_page' => null,
    ]))]);

    $cpx21 = cloudHetzner()->sizes()->first();

    expect($cpx21->id)->toBe('cpx21')
        ->and($cpx21->regions)->toBe(['fsn1'])
        ->and($cpx21->prices)->toHaveCount(2);
});

test('hetzner falls back to the priced locations when a size lists none', function () {
    $page = cloudHetznerFixture('server_types-2', ['meta.pagination.next_page' => null]);
    unset($page['server_types'][0]['locations']);
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response($page)]);

    expect(cloudHetzner()->sizes()->first()->regions)->toBe(['fsn1', 'ash']);
});

test('hetzner groups system images by name across architectures', function () {
    Http::fake(['api.hetzner.cloud/v1/images*' => Http::response(CloudFixtures::json('hetzner/images'))]);

    $images = cloudHetzner()->images();
    $byName = $images->keyBy('id');

    expect($images->pluck('id')->all())->toBe(['ubuntu-24.04', 'debian-12', 'rocky-9'])
        ->and($byName['ubuntu-24.04']->architectures)->toBe(['x86', 'arm'])
        ->and($byName['ubuntu-24.04']->family)->toBe('ubuntu')
        ->and($byName['ubuntu-24.04']->version)->toBe('24.04')
        ->and($byName['debian-12']->architectures)->toBe(['x86'])
        ->and($byName['debian-12']->family)->toBe('debian')
        ->and($byName['debian-12']->version)->toBe('12')
        ->and($byName['rocky-9']->architectures)->toBe(['x86'])
        ->and($byName['rocky-9']->family)->toBe('rocky');
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.hetzner.cloud/v1/images?type=system&status=available&per_page=50'
        && $request->uri()->query()->get('type') === 'system');
});

test('hetzner reads every page of images', function () {
    $first = cloudHetznerFixture('images', ['meta.pagination.next_page' => 2]);
    $second = cloudHetznerFixture('images', ['meta.pagination.page' => 2, 'meta.pagination.previous_page' => 1]);
    $first['images'] = array_slice($first['images'], 0, 2);
    $second['images'] = array_slice($second['images'], 2);
    Http::fake([
        'api.hetzner.cloud/v1/images?type=system&status=available&per_page=50' => Http::response($first),
        'api.hetzner.cloud/v1/images?type=system&status=available&per_page=50&page=2' => Http::response($second),
    ]);

    $images = cloudHetzner()->images();

    expect($images->pluck('id')->all())->toBe(['ubuntu-24.04', 'debian-12', 'rocky-9'])
        ->and($images->first()->architectures)->toBe(['x86', 'arm']);
    Http::assertSentCount(2);
});

test('hetzner skips deprecated images', function () {
    Http::fake(['api.hetzner.cloud/v1/images*' => Http::response(cloudHetznerFixture('images', [
        'images.1.deprecated' => '2026-09-01T00:00:00+00:00',
        'images.2.deprecated' => '2026-09-01T00:00:00+00:00',
        'images.3.deprecation' => cloudHetznerNotice(),
    ]))]);

    $images = cloudHetzner()->images();

    expect($images->pluck('id')->all())->toBe(['ubuntu-24.04'])
        ->and($images->first()->architectures)->toBe(['x86']);
});

test('hetzner adopts a key it already holds', function () {
    Http::fake(['api.hetzner.cloud/v1/ssh_keys*' => Http::response(CloudFixtures::json('hetzner/ssh_keys-found'))]);

    $id = cloudHetzner()->uploadKey('coresmith-outpost-02', cloudHetznerKey());

    expect($id)->toBe('2323');
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->uri()->path() === 'v1/ssh_keys'
        && $request->uri()->query()->get('fingerprint') === '17:35:e1:e3:93:03:56:7d:6d:58:c3:98:5c:5b:c1:c2');
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
});

test('hetzner uploads a key it does not hold', function () {
    Http::fake([
        'api.hetzner.cloud/v1/ssh_keys?fingerprint=*' => Http::response(CloudFixtures::json('hetzner/ssh_keys-empty')),
        'api.hetzner.cloud/v1/ssh_keys' => Http::response(CloudFixtures::json('hetzner/ssh_key-created'), 201),
    ]);

    $id = cloudHetzner()->uploadKey('coresmith-outpost-02', cloudHetznerKey());

    [$post] = Http::recorded(fn(Request $request) => $request->method() === 'POST')->first();

    expect($id)->toBe('2324')
        ->and($post->url())->toBe('https://api.hetzner.cloud/v1/ssh_keys')
        ->and($post->data())->toBe([
            'name' => 'coresmith-outpost-02',
            'public_key' => cloudHetznerKey(),
            'labels' => ['managed-by' => 'coresmith'],
        ]);
    Http::assertSentCount(2);
});

test('hetzner uploads a key it cannot fingerprint without asking whether it holds it', function () {
    Http::fake(['api.hetzner.cloud/v1/ssh_keys' => Http::response(['error' => ['code' => 'invalid_input', 'message' => 'invalid public key', 'details' => []]], 422)]);

    expect(fn() => cloudHetzner()->uploadKey('coresmith-outpost-02', 'not a key'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->detail)->toBe('invalid public key');
    });

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'POST');
});

test('hetzner creates a server with the key, its tag and both address families', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(CloudFixtures::json('hetzner/server-initializing'), 201)]);

    $instance = cloudHetzner()->create(cloudHetznerSpec());

    [$post] = Http::recorded(fn(Request $request) => $request->method() === 'POST')->first();

    expect($post->method())->toBe('POST')
        ->and($post->url())->toBe('https://api.hetzner.cloud/v1/servers')
        ->and($post->data())->toBe([
            'name' => 'outpost-02',
            'server_type' => 'cx22',
            'image' => 'ubuntu-24.04',
            'location' => 'fsn1',
            'ssh_keys' => [2324],
            'labels' => ['coresmith' => 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d', 'managed-by' => 'coresmith'],
            'start_after_create' => true,
            'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true],
        ])
        ->and($instance->id)->toBe('4711')
        ->and($instance->state)->toBe(CloudInstanceState::Starting)
        ->and($instance->isReady())->toBeFalse()
        ->and($instance)->toEqual(new CloudInstance('4711', CloudInstanceState::Starting, '203.0.113.40', 'cx22'));
    Http::assertSentCount(1);
});

test('hetzner will not create a server without a key to put on it', function () {
    expect(fn() => cloudHetzner()->create(cloudHetznerSpec(null)))->toThrow(LogicException::class, 'needs the id of an uploaded key');

    Http::assertNothingSent();
});

test('hetzner finds a running server and its public address', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers/4711' => Http::response(CloudFixtures::json('hetzner/server-running')),
        'api.hetzner.cloud/v1/servers/9999' => cloudHetznerMissing(),
    ]);

    $found = cloudHetzner()->find('4711');

    expect($found->id)->toBe('4711')
        ->and($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBe('203.0.113.40')
        ->and($found->size)->toBe('cx22')
        ->and($found->isReady())->toBeTrue()
        ->and(cloudHetzner()->find('9999'))->toBeNull();
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.hetzner.cloud/v1/servers/4711');
});

test('hetzner maps every server status', function (string $status, CloudInstanceState $state) {
    Http::fake(['api.hetzner.cloud/v1/servers/4711' => Http::response(cloudHetznerFixture('server-running', ['server.status' => $status]))]);

    expect(cloudHetzner()->find('4711')->state)->toBe($state);
})->with([
    'initializing' => ['initializing', CloudInstanceState::Starting],
    'starting' => ['starting', CloudInstanceState::Starting],
    'running' => ['running', CloudInstanceState::Running],
    'off' => ['off', CloudInstanceState::Stopped],
    'stopping' => ['stopping', CloudInstanceState::Busy],
    'migrating' => ['migrating', CloudInstanceState::Busy],
    'rebuilding' => ['rebuilding', CloudInstanceState::Busy],
    'deleting' => ['deleting', CloudInstanceState::Deleting],
    'a status it has not heard of' => ['something-new', CloudInstanceState::Unknown],
]);

test('hetzner reads a server with no status as unknown', function () {
    $body = cloudHetznerFixture('server-running');
    unset($body['server']['status']);
    Http::fake(['api.hetzner.cloud/v1/servers/4711' => Http::response($body)]);

    expect(cloudHetzner()->find('4711')->state)->toBe(CloudInstanceState::Unknown);
});

test('hetzner reads a running server with no public ipv4 as not ready', function () {
    Http::fake(['api.hetzner.cloud/v1/servers/4711' => Http::response(cloudHetznerFixture('server-running', ['server.public_net.ipv4' => null]))]);

    $found = cloudHetzner()->find('4711');

    expect($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBeNull()
        ->and($found->isReady())->toBeFalse();
});

test('hetzner finds a server by its coresmith label', function () {
    Http::fake(['api.hetzner.cloud/v1/servers*' => Http::sequence()
        ->push(CloudFixtures::json('hetzner/servers-tagged'))
        ->push(CloudFixtures::json('hetzner/servers-empty'))]);
    $api = cloudHetzner();

    $found = $api->findTagged('coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d');

    expect($found->id)->toBe('4711')
        ->and($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBe('203.0.113.40')
        ->and($api->findTagged('coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d'))->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->uri()->path() === 'v1/servers'
        && $request->uri()->query()->get('label_selector') === 'coresmith=coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d');
});

test('hetzner reboots, shuts down, starts and resizes through actions', function () {
    Http::fake(['api.hetzner.cloud/v1/servers/4711/actions/*' => Http::response(CloudFixtures::json('hetzner/action'))]);
    $api = cloudHetzner();

    $api->reboot('4711');
    $api->powerOff('4711');
    $api->powerOn('4711');
    $api->resize('4711', 'cx32');

    $sent = Http::recorded(fn(Request $request) => $request->method() === 'POST')
        ->map(fn(array $exchange): array => [$exchange[0]->method(), $exchange[0]->url(), $exchange[0]->body()])
        ->values()
        ->all();

    expect($sent)->toBe([
        ['POST', 'https://api.hetzner.cloud/v1/servers/4711/actions/reboot', '{}'],
        ['POST', 'https://api.hetzner.cloud/v1/servers/4711/actions/shutdown', '{}'],
        ['POST', 'https://api.hetzner.cloud/v1/servers/4711/actions/poweron', '{}'],
        ['POST', 'https://api.hetzner.cloud/v1/servers/4711/actions/change_type', '{"server_type":"cx32","upgrade_disk":false}'],
    ])
        ->and($api->uploadsKeys())->toBeTrue()
        ->and($api->resizeNeedsPowerOff())->toBeTrue();
});

test('hetzner accepts an action or a delete that answers with no body', function () {
    Http::fake(['api.hetzner.cloud/v1/*' => Http::response('', 204)]);
    $api = cloudHetzner();

    $api->reboot('4711');
    $api->powerOff('4711');
    $api->powerOn('4711');
    $api->resize('4711', 'cx32');
    $api->destroy('4711');
    $api->deleteKey('2324');

    Http::assertSentCount(6);
});

test('hetzner deletes a server and a key it holds', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers/4711' => Http::response(CloudFixtures::json('hetzner/action')),
        'api.hetzner.cloud/v1/ssh_keys/2324' => Http::response('', 204),
    ]);
    $api = cloudHetzner();

    $api->destroy('4711');
    $api->deleteKey('2324');

    $sent = Http::recorded(fn(Request $request) => $request->method() === 'DELETE')
        ->map(fn(array $exchange): array => [$exchange[0]->method(), $exchange[0]->url()])
        ->values()
        ->all();

    expect($sent)->toBe([
        ['DELETE', 'https://api.hetzner.cloud/v1/servers/4711'],
        ['DELETE', 'https://api.hetzner.cloud/v1/ssh_keys/2324'],
    ]);
});

test('hetzner treats a server that is already gone as destroyed', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers/4711' => cloudHetznerMissing(),
        'api.hetzner.cloud/v1/ssh_keys/2324' => cloudHetznerMissing(),
    ]);
    $api = cloudHetzner();

    $api->destroy('4711');
    $api->deleteKey('2324');

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.hetzner.cloud/v1/servers/4711');
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.hetzner.cloud/v1/ssh_keys/2324');
});

test('hetzner keeps an id inside its own path segment', function () {
    Http::fake(['api.hetzner.cloud/*' => Http::response('', 204)]);

    cloudHetzner()->destroy('../ssh_keys/2324');

    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.hetzner.cloud/v1/servers/..%2Fssh_keys%2F2324');
});

test('hetzner says a read-only token cannot create servers', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(CloudFixtures::json('hetzner/error-forbidden'), 403)]);

    expect(fn() => cloudHetzner()->create(cloudHetznerSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)
            ->and($e->retryable())->toBeFalse()
            ->and($e->detail)->toBe('insufficient permissions for this action')
            ->and($e->getMessage())->toContain('Read & Write');
    });

    Http::assertSentCount(1);
});

test('hetzner never retries a create that met a server error', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['error' => ['code' => 'service_error', 'message' => 'service unavailable', 'details' => []]], 503)]);

    expect(fn() => cloudHetzner()->create(cloudHetznerSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->status)->toBe(503)
            ->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(1);
});

test('hetzner treats a locked server as busy', function () {
    Http::fake(['api.hetzner.cloud/v1/servers/4711/actions/reboot' => Http::response(CloudFixtures::json('hetzner/error-locked'), 423)]);

    expect(fn() => cloudHetzner()->reboot('4711'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Busy)
            ->and($e->retryable())->toBeTrue()
            ->and($e->status)->toBe(423)
            ->and($e->detail)->toBe('server is locked');
    });

    Http::assertSentCount(1);
});

test('hetzner passes on why a size cannot be created where it was asked for', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(CloudFixtures::json('hetzner/error-unavailable'), 422)]);

    expect(fn() => cloudHetzner()->create(cloudHetznerSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)
            ->and($e->retryable())->toBeFalse()
            ->and($e->detail)->toBe('server type cx22 is not available in hel1')
            ->and($e->getMessage())->toBe('Hetzner Cloud refused the request. Hetzner Cloud said: server type cx22 is not available in hel1.');
    });

    Http::assertSentCount(1);
});

test('hetzner ignores an error message that is not text', function (mixed $message) {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['error' => ['code' => 'invalid_input', 'message' => $message, 'details' => []]], 422)]);

    expect(fn() => cloudHetzner()->create(cloudHetznerSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->detail)->toBeNull();
    });
})->with([
    'a list' => [['name', 'image']],
    'a number' => [42],
    'a flag' => [true],
]);

test('hetzner reads a create whose answer has no server in it as unavailable, so its tag is checked before another is made', function (string $body, array $headers) {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response($body, 201, $headers)]);

    expect(fn() => cloudHetzner()->create(cloudHetznerSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Hetzner Cloud had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'a json object without a server' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'a server that is not an object' => ['{"server":"gone"}', ['Content-Type' => 'application/json']],
    'a server with no id' => ['{"server":{"status":"initializing"}}', ['Content-Type' => 'application/json']],
    'an empty body' => ['', []],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
]);

test('hetzner does not mistake a lookup it cannot read for a server that is not there', function (string $body, array $headers) {
    Http::fake(['api.hetzner.cloud/v1/servers*' => Http::response($body, 200, $headers)]);
    $api = cloudHetzner();

    foreach ([fn() => $api->find('4711'), fn() => $api->findTagged(cloudHetznerTag())] as $lookup) {
        expect($lookup)->toThrow(function (CloudProviderException $e) {
            expect($e->failure)->toBe(CloudFailure::Unavailable)
                ->and($e->getMessage())->toBe('Hetzner Cloud had a problem answering. Try again in a minute.');
        });
    }

    Http::assertSentCount(2);
})->with([
    'a json object without the data' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
    'nothing at all' => ['', []],
]);

test('hetzner reads a key upload whose answer has no key in it as unavailable', function (string $body, array $headers) {
    Http::fake([
        'api.hetzner.cloud/v1/ssh_keys?fingerprint=*' => Http::response(CloudFixtures::json('hetzner/ssh_keys-empty')),
        'api.hetzner.cloud/v1/ssh_keys' => Http::response($body, 201, $headers),
    ]);

    expect(fn() => cloudHetzner()->uploadKey('coresmith-outpost-02', cloudHetznerKey()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(2);
})->with([
    'a json object without a key' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'a key with no id' => ['{"ssh_key":{"name":"coresmith-outpost-02"}}', ['Content-Type' => 'application/json']],
    'an empty body' => ['', []],
]);

test('hetzner does not verify a token, or read a catalog, from an answer that is not json', function (string $call) {
    Http::fake(['api.hetzner.cloud/*' => Http::response('<html><body>Gateway</body></html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn() => cloudHetzner()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->detail)->toBe('answered without JSON');
    });

    Http::assertSentCount(1);
})->with(['verify', 'regions', 'sizes', 'images']);
