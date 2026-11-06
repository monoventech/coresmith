<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Enums\CloudRefusal;
use App\Exceptions\CloudProviderException;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Cloud\Providers\HetznerApi;
use App\Services\Cloud\Providers\HttpCloudApi;
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

function cloudHetznerNotice(bool $inForce = true): array
{
    return [
        'announced' => now()->subMonths(8)->toIso8601String(),
        'unavailable_after' => ($inForce ? now()->subDay() : now()->addMonths(4))->toIso8601String(),
    ];
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

test('hetzner skips a size once its deprecation has passed', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(CloudFixtures::with('hetzner/server_types-1', [
        'server_types.0.deprecation' => cloudHetznerNotice(),
        'meta.pagination.next_page' => null,
    ]))]);

    expect(cloudHetzner()->sizes()->pluck('id')->all())->toBe(['cax11']);
});

test('hetzner keeps a size whose deprecation is only announced', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(CloudFixtures::with('hetzner/server_types-1', [
        'server_types.0.deprecated' => true,
        'server_types.0.deprecation' => cloudHetznerNotice(false),
        'meta.pagination.next_page' => null,
    ]))]);

    expect(cloudHetzner()->sizes()->pluck('id')->all())->toBe(['cx22', 'cax11']);
});

test('hetzner skips a size whose deprecation has no date it can read', function (array $changes) {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(CloudFixtures::with('hetzner/server_types-1', [
        ...$changes,
        'meta.pagination.next_page' => null,
    ]))]);

    expect(cloudHetzner()->sizes()->pluck('id')->all())->toBe(['cax11']);
})->with([
    'a flag and no notice' => [['server_types.0.deprecated' => true]],
    'a notice without a date' => [['server_types.0.deprecation' => ['announced' => '2024-06-06T00:00:00+00:00']]],
    'a notice whose date is not one' => [['server_types.0.deprecation' => ['unavailable_after' => 'soon']]],
    'a notice whose date is not text' => [['server_types.0.deprecation' => ['unavailable_after' => 20270101]]],
]);

test('hetzner offers a size only in the locations that are not past their deprecation', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(CloudFixtures::with('hetzner/server_types-2', [
        'server_types.0.locations.1.deprecation' => cloudHetznerNotice(),
        'meta.pagination.next_page' => null,
    ]))]);

    $cpx21 = cloudHetzner()->sizes()->first();

    expect($cpx21->id)->toBe('cpx21')
        ->and($cpx21->regions)->toBe(['fsn1'])
        ->and($cpx21->prices)->toHaveCount(2);
});

test('hetzner keeps a location whose deprecation is only announced', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(CloudFixtures::with('hetzner/server_types-2', [
        'server_types.0.locations.1.deprecation' => cloudHetznerNotice(false),
        'meta.pagination.next_page' => null,
    ]))]);

    expect(cloudHetzner()->sizes()->first()->regions)->toBe(['fsn1', 'ash']);
});

test('hetzner skips a size that leaves out how big it is or what it runs on', function (string $field) {
    $page = CloudFixtures::with('hetzner/server_types-1', ['meta.pagination.next_page' => null]);
    unset($page['server_types'][0][$field]);
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response($page)]);

    expect(cloudHetzner()->sizes()->pluck('id')->all())->toBe(['cax11']);
})->with(['cores', 'memory', 'disk', 'architecture']);

test('hetzner skips a size whose numbers are not numbers', function (string $field, mixed $value) {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(CloudFixtures::with('hetzner/server_types-1', [
        "server_types.0.{$field}" => $value,
        'meta.pagination.next_page' => null,
    ]))]);

    expect(cloudHetzner()->sizes()->pluck('id')->all())->toBe(['cax11']);
})->with([
    'cores that are null' => ['cores', null],
    'cores that are a word' => ['cores', 'many'],
    'memory that is a flag' => ['memory', true],
    'a disk that is a list' => ['disk', [40]],
    'an architecture that is empty' => ['architecture', ''],
    'an architecture that is a number' => ['architecture', 86],
]);

test('hetzner keeps a size that reports its numbers as digits', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(CloudFixtures::with('hetzner/server_types-1', [
        'server_types.0.cores' => '2',
        'server_types.0.memory' => '4.0',
        'server_types.0.disk' => '40',
        'meta.pagination.next_page' => null,
    ]))]);

    $cx22 = cloudHetzner()->sizes()->first();

    expect($cx22->cpus)->toBe(2)->and($cx22->memoryMb)->toBe(4096)->and($cx22->diskGb)->toBe(40);
});

test('hetzner falls back to the priced locations when a size lists none', function () {
    $page = CloudFixtures::with('hetzner/server_types-2', ['meta.pagination.next_page' => null]);
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
    $first = CloudFixtures::with('hetzner/images', ['meta.pagination.next_page' => 2]);
    $second = CloudFixtures::with('hetzner/images', ['meta.pagination.page' => 2, 'meta.pagination.previous_page' => 1]);
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

test('hetzner skips images once their deprecation has passed', function () {
    Http::fake(['api.hetzner.cloud/v1/images*' => Http::response(CloudFixtures::with('hetzner/images', [
        'images.1.deprecated' => now()->subDay()->toIso8601String(),
        'images.2.deprecated' => now()->subYear()->toIso8601String(),
        'images.3.deprecation' => cloudHetznerNotice(),
    ]))]);

    $images = cloudHetzner()->images();

    expect($images->pluck('id')->all())->toBe(['ubuntu-24.04'])
        ->and($images->first()->architectures)->toBe(['x86']);
});

test('hetzner keeps images whose deprecation is only announced, so an operating system does not vanish early', function () {
    Http::fake(['api.hetzner.cloud/v1/images*' => Http::response(CloudFixtures::with('hetzner/images', [
        'images.1.deprecated' => now()->addMonths(6)->toIso8601String(),
        'images.2.deprecated' => now()->addDay()->toIso8601String(),
        'images.3.deprecation' => cloudHetznerNotice(false),
    ]))]);

    $images = cloudHetzner()->images();

    expect($images->pluck('id')->all())->toBe(['ubuntu-24.04', 'debian-12', 'rocky-9'])
        ->and($images->first()->architectures)->toBe(['x86', 'arm']);
});

test('hetzner skips an image whose deprecation has no date it can read', function (array $changes) {
    Http::fake(['api.hetzner.cloud/v1/images*' => Http::response(CloudFixtures::with('hetzner/images', $changes))]);

    expect(cloudHetzner()->images()->pluck('id')->all())->toBe(['ubuntu-24.04', 'rocky-9']);
})->with([
    'a flag and no date' => [['images.2.deprecated' => true]],
    'a date that is not one' => [['images.2.deprecated' => 'soon']],
    'a notice without a date' => [['images.2.deprecation' => ['announced' => '2024-06-06T00:00:00+00:00']]],
]);

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

test('hetzner adopts the key that carries the fingerprint itself, not just the first one listed', function (string $fingerprint) {
    $body = CloudFixtures::with('hetzner/ssh_keys-found', ['ssh_keys.0.fingerprint' => $fingerprint]);
    $mine = $body['ssh_keys'][0];
    $elsewhere = CloudFixtures::with('hetzner/ssh_keys-found', [
        'ssh_keys.0.id' => 2400,
        'ssh_keys.0.fingerprint' => 'aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99',
    ])['ssh_keys'][0];
    $body['ssh_keys'] = [$elsewhere, 'oops', $mine];
    Http::fake(['api.hetzner.cloud/v1/ssh_keys*' => Http::response($body)]);

    $id = cloudHetzner()->uploadKey('coresmith-outpost-02', cloudHetznerKey());

    expect($id)->toBe('2323');
    Http::assertSentCount(1);
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
})->with([
    'as the key reports it' => ['17:35:e1:e3:93:03:56:7d:6d:58:c3:98:5c:5b:c1:c2'],
    'in capitals' => ['17:35:E1:E3:93:03:56:7D:6D:58:C3:98:5C:5B:C1:C2'],
    'without colons' => ['1735e1e39303567d6d58c3985c5bc1c2'],
    'in capitals without colons' => ['1735E1E39303567D6D58C3985C5BC1C2'],
]);

test('hetzner fails a key lookup that lists keys but none that carries the fingerprint, so an unrelated key is never adopted', function (mixed $fingerprint) {
    Http::fake(['api.hetzner.cloud/v1/ssh_keys*' => Http::response(CloudFixtures::with('hetzner/ssh_keys-found', ['ssh_keys.0.fingerprint' => $fingerprint]))]);

    expect(fn() => cloudHetzner()->uploadKey('coresmith-outpost-02', cloudHetznerKey()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Hetzner Cloud had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
})->with([
    'another fingerprint' => ['aa:bb:cc:dd:ee:ff:00:11:22:33:44:55:66:77:88:99'],
    'the fingerprint with more after it' => ['17:35:e1:e3:93:03:56:7d:6d:58:c3:98:5c:5b:c1:c2:00'],
    'part of the fingerprint' => ['17:35:e1:e3:93:03:56:7d'],
    'an empty fingerprint' => [''],
    'no fingerprint' => [null],
    'a fingerprint that is not text' => [42],
    'a fingerprint given as a list' => [['17:35:e1:e3:93:03:56:7d:6d:58:c3:98:5c:5b:c1:c2']],
]);

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

test('hetzner ignores the root password a create answers with', function () {
    $password = 'Zx9-root-Pass-not-for-logs';
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(CloudFixtures::with('hetzner/server-initializing', ['root_password' => $password]), 201)]);

    $instance = cloudHetzner()->create(cloudHetznerSpec());

    expect($instance)->toEqual(new CloudInstance('4711', CloudInstanceState::Starting, '203.0.113.40', 'cx22'))
        ->and(print_r($instance, true))->not->toContain($password)
        ->and((string) json_encode($instance))->not->toContain($password);
});

test('hetzner will not create a server without a key to put on it', function () {
    expect(fn() => cloudHetzner()->create(cloudHetznerSpec(null)))->toThrow(LogicException::class, 'needs the id of an uploaded key');

    Http::assertNothingSent();
});

test('hetzner finds a running server and its public address', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers/4711' => Http::response(CloudFixtures::json('hetzner/server-running')),
        'api.hetzner.cloud/v1/servers/9999' => Http::response(CloudFixtures::json('hetzner/error-not-found'), 404),
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
    Http::fake(['api.hetzner.cloud/v1/servers/4711' => Http::response(CloudFixtures::with('hetzner/server-running', ['server.status' => $status]))]);

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
    $body = CloudFixtures::with('hetzner/server-running');
    unset($body['server']['status']);
    Http::fake(['api.hetzner.cloud/v1/servers/4711' => Http::response($body)]);

    expect(cloudHetzner()->find('4711')->state)->toBe(CloudInstanceState::Unknown);
});

test('hetzner reads a running server with no public ipv4 as not ready', function () {
    Http::fake(['api.hetzner.cloud/v1/servers/4711' => Http::response(CloudFixtures::with('hetzner/server-running', ['server.public_net.ipv4' => null]))]);

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

test('hetzner returns the server that carries the tag itself, not just the first one listed', function () {
    $body = CloudFixtures::with('hetzner/servers-tagged');
    $mine = $body['servers'][0];
    $elsewhere = CloudFixtures::with('hetzner/servers-tagged', ['servers.0.id' => 4712, 'servers.0.labels.coresmith' => 'coresmith-01jother'])['servers'][0];
    $unlabelled = CloudFixtures::with('hetzner/servers-tagged', ['servers.0.id' => 4713, 'servers.0.labels' => []])['servers'][0];
    $body['servers'] = [$elsewhere, $unlabelled, 'oops', $mine];
    Http::fake(['api.hetzner.cloud/v1/servers*' => Http::response($body)]);

    expect(cloudHetzner()->findTagged(cloudHetznerTag())->id)->toBe('4711');
});

test('hetzner fails a tag lookup that lists servers but none that carries the tag, so an unrelated server is never handed back', function (mixed $labels) {
    Http::fake(['api.hetzner.cloud/v1/servers*' => Http::response(CloudFixtures::with('hetzner/servers-tagged', ['servers.0.labels' => $labels]))]);

    expect(fn() => cloudHetzner()->findTagged(cloudHetznerTag()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Hetzner Cloud had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'another tag' => [['coresmith' => 'coresmith-01jother', 'managed-by' => 'coresmith']],
    'the tag under another label' => [['managed-by' => 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d']],
    'the tag with more after it' => [['coresmith' => 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0dx']],
    'the tag in another case' => [['coresmith' => 'CORESMITH-01JCP7M3ZQ9X4V6T8W2Y5B1N0D']],
    'no labels' => [[]],
    'labels given as a list' => [['coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d']],
    'labels given as text' => ['coresmith'],
    'labels that are null' => [null],
]);

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
        'api.hetzner.cloud/v1/servers/4711' => Http::response(CloudFixtures::json('hetzner/error-not-found'), 404),
        'api.hetzner.cloud/v1/ssh_keys/2324' => Http::response(CloudFixtures::json('hetzner/error-not-found'), 404),
    ]);
    $api = cloudHetzner();

    $api->destroy('4711');
    $api->deleteKey('2324');

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.hetzner.cloud/v1/servers/4711');
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.hetzner.cloud/v1/ssh_keys/2324');
});

test('hetzner refuses an id that names nothing before it sends anything', function (string $call, string $id) {
    $arguments = $call === 'resize' ? [$id, 'cx32'] : [$id];

    expect(fn() => cloudHetzner()->{$call}(...$arguments))->toThrow(LogicException::class, 'A cloud id must name one resource.');

    Http::assertNothingSent();
})->with(['find', 'reboot', 'powerOff', 'powerOn', 'resize', 'destroy', 'deleteKey'])->with(['', ' ', '.', '..']);

test('hetzner accepts an id it is given as text', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers/4711' => Http::response(CloudFixtures::with('hetzner/server-running', ['server.id' => '4711'])),
        'api.hetzner.cloud/v1/ssh_keys?fingerprint=*' => Http::response(CloudFixtures::with('hetzner/ssh_keys-found', ['ssh_keys.0.id' => '2323'])),
    ]);
    $api = cloudHetzner();

    expect($api->find('4711')->id)->toBe('4711')
        ->and($api->uploadKey('coresmith-outpost-02', cloudHetznerKey()))->toBe('2323');
});

test('hetzner reads an id that is not a whole number as unavailable, so the tag is checked before anything is made again', function (string $call, mixed $id) {
    Http::fake([
        'api.hetzner.cloud/v1/ssh_keys?fingerprint=*' => Http::response($call === 'adopt'
            ? CloudFixtures::with('hetzner/ssh_keys-found', ['ssh_keys.0.id' => $id])
            : CloudFixtures::json('hetzner/ssh_keys-empty')),
        'api.hetzner.cloud/v1/ssh_keys' => Http::response(CloudFixtures::with('hetzner/ssh_key-created', ['ssh_key.id' => $id]), 201),
        'api.hetzner.cloud/v1/servers/4711' => Http::response(CloudFixtures::with('hetzner/server-running', ['server.id' => $id])),
        'api.hetzner.cloud/v1/servers?*' => Http::response(CloudFixtures::with('hetzner/servers-tagged', ['servers.0.id' => $id])),
        'api.hetzner.cloud/v1/servers' => Http::response(CloudFixtures::with('hetzner/server-initializing', ['server.id' => $id]), 201),
    ]);
    $api = cloudHetzner();
    $attempt = match ($call) {
        'create' => fn() => $api->create(cloudHetznerSpec()),
        'find' => fn() => $api->find('4711'),
        'findTagged' => fn() => $api->findTagged(cloudHetznerTag()),
        'adopt', 'upload' => fn() => $api->uploadKey('coresmith-outpost-02', cloudHetznerKey()),
    };

    expect($attempt)->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Hetzner Cloud had a problem answering. Try again in a minute.');
    });
})->with(['create', 'find', 'findTagged', 'adopt', 'upload'])->with([
    'zero' => [0],
    'a negative number' => [-4711],
    'a fraction' => [4711.5],
    'a flag' => [true],
    'a word' => ['abc'],
    'empty text' => [''],
    'a path' => ['../4711'],
    'a list' => [[4711]],
]);

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

test('hetzner reads its documented error codes before the status', function (int $status, mixed $code, CloudFailure $failure) {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['error' => ['code' => $code, 'message' => 'said so', 'details' => (object) []]], $status)]);

    expect(fn() => cloudHetzner()->create(cloudHetznerSpec()))->toThrow(function (CloudProviderException $e) use ($status, $failure) {
        expect($e->failure)->toBe($failure)
            ->and($e->status)->toBe($status)
            ->and($e->detail)->toBe('said so');
    });

    Http::assertSentCount(1);
})->with([
    'delete protection' => [423, 'protected', CloudFailure::Refused],
    'a resource limit' => [403, 'resource_limit_exceeded', CloudFailure::Refused],
    'a name already in use' => [409, 'uniqueness_error', CloudFailure::Refused],
    'a change made meanwhile' => [409, 'conflict', CloudFailure::Busy],
    'a read-only token' => [403, 'token_readonly', CloudFailure::Forbidden],
    'a lock' => [423, 'locked', CloudFailure::Busy],
    'a code it does not know' => [403, 'forbidden', CloudFailure::Forbidden],
    'a code that is not text' => [423, ['protected'], CloudFailure::Busy],
]);

test('hetzner says when a refusal is a name in use or a limit', function (int $status, string $code, ?CloudRefusal $refusal) {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['error' => ['code' => $code, 'message' => 'said so', 'details' => (object) []]], $status)]);

    expect(fn() => cloudHetzner()->create(cloudHetznerSpec()))->toThrow(function (CloudProviderException $e) use ($refusal) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->refusal)->toBe($refusal);
    });
})->with([
    'a name in use' => [409, 'uniqueness_error', CloudRefusal::NameTaken],
    'a resource limit' => [403, 'resource_limit_exceeded', CloudRefusal::LimitReached],
    'delete protection' => [423, 'protected', null],
    'anything else' => [422, 'invalid_input', null],
]);

test('hetzner sends the delete of a protected server once and says it was refused', function () {
    Http::fake(['api.hetzner.cloud/v1/servers/4711' => Http::response(['error' => ['code' => 'protected', 'message' => 'server is protected', 'details' => (object) []]], 423)]);

    expect(fn() => cloudHetzner()->destroy('4711'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)
            ->and($e->retryable())->toBeFalse()
            ->and($e->getMessage())->toBe('Hetzner Cloud refused the request. Hetzner Cloud said: server is protected.');
    });

    Http::assertSentCount(1);
});

test('hetzner still tries a locked server again within the read', function () {
    Http::fakeSequence('api.hetzner.cloud/v1/servers/4711')
        ->push(CloudFixtures::json('hetzner/error-locked'), 423)
        ->push(CloudFixtures::json('hetzner/server-running'));

    expect(cloudHetzner()->find('4711')?->id)->toBe('4711');

    Http::assertSentCount(2);
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

test('hetzner fails a catalog read whose answer has no list in it, so an empty catalog is never kept', function (string $call, array $body) {
    Http::fake(['api.hetzner.cloud/*' => Http::response($body)]);

    expect(fn() => cloudHetzner()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Hetzner Cloud had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'locations' => ['regions', ['unexpected' => true]],
    'server types' => ['sizes', ['unexpected' => true]],
    'images' => ['images', ['unexpected' => true]],
    'locations that are null' => ['regions', ['locations' => null]],
    'server types that are text' => ['sizes', ['server_types' => 'none']],
    'images that are an object' => ['images', ['images' => ['ubuntu-24.04' => ['id' => 1]]]],
    'locations that are an empty object' => ['regions', ['locations' => new stdClass]],
    'server types that are an empty object' => ['sizes', ['server_types' => new stdClass]],
    'images that are an empty object' => ['images', ['images' => new stdClass]],
]);

test('hetzner does not take an empty object for an empty list of servers or keys', function (string $call) {
    Http::fake([
        'api.hetzner.cloud/v1/servers*' => Http::response('{"servers":{}}', 200, ['Content-Type' => 'application/json']),
        'api.hetzner.cloud/v1/ssh_keys*' => Http::response('{"ssh_keys":{}}', 200, ['Content-Type' => 'application/json']),
    ]);
    $attempt = match ($call) {
        'findTagged' => fn() => cloudHetzner()->findTagged(cloudHetznerTag()),
        'uploadKey' => fn() => cloudHetzner()->uploadKey('coresmith-outpost-02', cloudHetznerKey()),
    };

    expect($attempt)->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Hetzner Cloud had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
})->with(['findTagged', 'uploadKey']);

test('hetzner does not take a not found page for a server or key that is gone', function (string $call, string $id) {
    Http::fake(['api.hetzner.cloud/v1/*' => Http::response('<html><body>404 Not Found</body></html>', 404, ['Content-Type' => 'text/html'])]);

    expect(fn() => cloudHetzner()->{$call}($id))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->status)->toBe(404)
            ->and($e->getMessage())->toBe('Hetzner Cloud had a problem answering (HTTP 404). Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'a lookup' => ['find', '4711'],
    'a destroy' => ['destroy', '4711'],
    'a key delete' => ['deleteKey', '2324'],
]);

test('hetzner fails a catalog read when a later page has no list in it', function () {
    Http::fake([
        'api.hetzner.cloud/v1/server_types?per_page=50' => Http::response(CloudFixtures::json('hetzner/server_types-1')),
        'api.hetzner.cloud/v1/server_types?per_page=50&page=2' => Http::response(['unexpected' => true]),
    ]);

    expect(fn() => cloudHetzner()->sizes())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(2);
});

test('hetzner reads a catalog with an empty list as empty', function (string $call, string $list) {
    Http::fake(['api.hetzner.cloud/*' => Http::response([$list => []])]);

    expect(cloudHetzner()->{$call}()->all())->toBe([]);
})->with([
    'locations' => ['regions', 'locations'],
    'server types' => ['sizes', 'server_types'],
    'images' => ['images', 'images'],
]);
