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
use App\Services\Cloud\Providers\HttpCloudApi;
use App\Services\Cloud\Providers\VultrApi;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CloudFixtures;

beforeEach(fn() => Http::preventStrayRequests());

function cloudVultr(): VultrApi
{
    return new VultrApi('vultr-token');
}

function cloudVultrTag(): string
{
    return 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d';
}

function cloudVultrInstanceId(): string
{
    return 'cb676a46-66fd-4dfb-b839-443f2e6c0b60';
}

function cloudVultrKeyId(): string
{
    return 'd8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57';
}

function cloudVultrKey(): string
{
    return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4 coresmith-test';
}

function cloudVultrOtherKey(): string
{
    return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGq0sP1pXQ7YyfX9o3b0n4i0rTqgTq3m0QmQJc2m5oQd someone@desktop';
}

function cloudVultrSpec(?string $keyId = 'd8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57'): CloudServerSpec
{
    return new CloudServerSpec('outpost-02', 'fra', 'vc2-1c-2gb', '2284', cloudVultrTag(), cloudVultrKey(), $keyId);
}

function cloudVultrMissing(): PromiseInterface
{
    return Http::response(CloudFixtures::json('vultr/error-not-found'), 404);
}

function cloudVultrFixture(string $name, array $changes = []): array
{
    $body = CloudFixtures::json("vultr/{$name}");

    foreach ($changes as $path => $value) {
        data_set($body, $path, $value);
    }

    return $body;
}

function cloudVultrPage(string $list, array $items, string $next = ''): array
{
    return [$list => $items, 'meta' => ['total' => count($items), 'links' => ['next' => $next, 'prev' => '']]];
}

test('vultr is the cloud api for vultr', function () {
    expect(cloudVultr())->toBeInstanceOf(CloudApi::class)
        ->toBeInstanceOf(HttpCloudApi::class)
        ->and(cloudVultr()->type())->toBe(CloudProviderType::Vultr)
        ->and((new ReflectionClass(VultrApi::class))->isFinal())->toBeTrue();
});

test('vultr names the account by its email', function () {
    Http::fake(['api.vultr.com/v2/account' => Http::response(CloudFixtures::json('vultr/account'))]);

    $account = cloudVultr()->verify();

    expect($account->label)->toBe('ops@monoven.io');
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.vultr.com/v2/account'
        && $request->hasHeader('Authorization', 'Bearer vultr-token'));
});

test('vultr leaves the account unnamed when it has no email it can use', function (mixed $email) {
    Http::fake(['api.vultr.com/v2/account' => Http::response(cloudVultrFixture('account', ['account.email' => $email]))]);

    expect(cloudVultr()->verify()->label)->toBeNull();
})->with([
    'an empty email' => [''],
    'no email' => [null],
    'an email that is not text' => [42],
]);

test('vultr does not take an answer without an account for a verified account', function (array $body) {
    Http::fake(['api.vultr.com/v2/account' => Http::response($body)]);

    expect(fn() => cloudVultr()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Vultr had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'a json object without an account' => [['unexpected' => true]],
    'an account that is not an object' => [['account' => 'ops@monoven.io']],
    'an account with nothing in it' => [['account' => []]],
    'an account that is a list' => [['account' => ['ops@monoven.io']]],
]);

test("vultr says when the key is refused for the panel's address", function () {
    Http::fake(['api.vultr.com/v2/account' => Http::response(CloudFixtures::json('vultr/error-acl'), 401)]);

    expect(fn() => cloudVultr()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::TokenRejected)
            ->and($e->status)->toBe(401)
            ->and($e->retryable())->toBeFalse()
            ->and($e->detail)->toBe('Unauthorized IP address: 198.51.100.7')
            ->and($e->connectMessage())->toContain('Unauthorized IP address');
    });

    Http::assertSentCount(1);
});

test('vultr reports a refused key in words', function () {
    Http::fake(['api.vultr.com/v2/account' => Http::response(CloudFixtures::json('vultr/error-invalid-key'), 401)]);

    expect(fn() => cloudVultr()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::TokenRejected)
            ->and($e->status)->toBe(401)
            ->and($e->retryable())->toBeFalse()
            ->and($e->connectMessage())->toBe("Vultr didn't accept that token. Vultr said: Invalid API token. Copy it again from Vultr, then paste it here.");
    });

    Http::assertSentCount(1);
});

test('vultr never repeats a key that its error message echoes', function () {
    $token = 'VULTRAPIKEY' . str_repeat('AB12', 8);
    Http::fake(['api.vultr.com/v2/account' => Http::response(['error' => "Invalid API token {$token}", 'status' => 401], 401)]);

    expect(fn() => (new VultrApi($token))->verify())->toThrow(function (CloudProviderException $e) use ($token) {
        expect($e->detail)->toBe('Invalid API token [REDACTED]')
            ->and($e->getMessage())->not->toContain($token)
            ->and($e->connectMessage())->not->toContain($token);
    });
});

test('vultr lists regions with their city and country', function () {
    Http::fake(['api.vultr.com/v2/regions*' => Http::response(CloudFixtures::json('vultr/regions'))]);

    $regions = cloudVultr()->regions();

    expect($regions->pluck('id')->all())->toBe(['ams', 'ewr', 'fra'])
        ->and($regions->first()->label)->toBe('Amsterdam (ams)')
        ->and($regions->first()->country)->toBe('NL')
        ->and($regions->get(1)->label)->toBe('New Jersey (ewr)')
        ->and($regions->get(1)->country)->toBe('US')
        ->and($regions->last()->label)->toBe('Frankfurt (fra)')
        ->and($regions->last()->country)->toBe('DE');
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.vultr.com/v2/regions?per_page=500');
});

test('vultr labels a region that has no city by its id', function () {
    $body = CloudFixtures::json('vultr/regions');
    unset($body['regions'][0]['city'], $body['regions'][0]['country']);
    Http::fake(['api.vultr.com/v2/regions*' => Http::response($body)]);

    $first = cloudVultr()->regions()->first();

    expect($first->id)->toBe('ams')->and($first->label)->toBe('ams')->and($first->country)->toBeNull();
});

test('vultr reads every page of plans and keeps only compute plans', function () {
    Http::fake([
        'api.vultr.com/v2/plans?per_page=500' => Http::response(CloudFixtures::json('vultr/plans-1')),
        'api.vultr.com/v2/plans?per_page=500&cursor=*' => Http::response(CloudFixtures::json('vultr/plans-2')),
    ]);

    $sizes = cloudVultr()->sizes();
    $byId = $sizes->keyBy('id');

    expect($sizes->pluck('id')->all())->toBe(['vc2-1c-1gb', 'vc2-1c-2gb', 'vc2-2c-4gb', 'vhf-1c-2gb'])
        ->and($byId->has('vcg-a100-1c-6g-4vram'))->toBeFalse();

    $small = $byId['vc2-1c-1gb'];
    expect($small->cpus)->toBe(1)
        ->and($small->memoryMb)->toBe(1024)
        ->and($small->diskGb)->toBe(25)
        ->and($small->architecture)->toBe('x86')
        ->and($small->price)->toEqual(new CloudPrice('5.00', 'USD'));

    $medium = $byId['vc2-1c-2gb'];
    expect($medium->cpus)->toBe(1)
        ->and($medium->memoryMb)->toBe(2048)
        ->and($medium->diskGb)->toBe(55)
        ->and($medium->architecture)->toBe('x86')
        ->and($medium->price)->toEqual(new CloudPrice('10.00', 'USD'))
        ->and($medium->price->monthly)->toBe('10.00')
        ->and($medium->price->currency)->toBe('USD')
        ->and($medium->priceIn('fra'))->toEqual(new CloudPrice('10.00', 'USD'))
        ->and($medium->prices)->toBe([])
        ->and($medium->regions)->toBe(['ams', 'ewr', 'fra'])
        ->and($medium->availableIn('fra'))->toBeTrue()
        ->and($medium->availableIn('sgp'))->toBeFalse();

    $large = $byId['vc2-2c-4gb'];
    expect($large->cpus)->toBe(2)
        ->and($large->memoryMb)->toBe(4096)
        ->and($large->diskGb)->toBe(80)
        ->and($large->price)->toEqual(new CloudPrice('20.00', 'USD'));

    $fast = $byId['vhf-1c-2gb'];
    expect($fast->diskGb)->toBe(64)
        ->and($fast->price)->toEqual(new CloudPrice('12.00', 'USD'))
        ->and($fast->regions)->toBe(['ewr', 'fra'])
        ->and($fast->availableIn('fra'))->toBeTrue()
        ->and($fast->availableIn('ams'))->toBeFalse();

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.vultr.com/v2/plans?per_page=500');
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->uri()->path() === 'v2/plans'
        && $request->uri()->query()->get('per_page') === '500'
        && $request->uri()->query()->get('cursor') === 'bmV4dF9fdmMyLTJjLTRnYg==');
});

test('vultr keeps the compute plan types and no other', function (mixed $type, bool $kept) {
    Http::fake(['api.vultr.com/v2/plans*' => Http::response(cloudVultrFixture('plans-2', ['plans.0.type' => $type]))]);

    expect(cloudVultr()->sizes()->pluck('id')->contains('vc2-2c-4gb'))->toBe($kept);
})->with([
    'cloud compute' => ['vc2', true],
    'high frequency' => ['vhf', true],
    'high performance' => ['vhp', true],
    'optimized cloud' => ['voc', true],
    'cloud gpu' => ['vcg', false],
    'bare metal' => ['vbm', false],
    'dedicated cloud' => ['vdc', false],
    'a type in another case' => ['VC2', false],
    'a type that is not text' => [42, false],
    'no type' => [null, false],
]);

test('vultr skips a plan that has no id', function (mixed $id) {
    Http::fake(['api.vultr.com/v2/plans*' => Http::response(cloudVultrFixture('plans-2', ['plans.0.id' => $id]))]);

    expect(cloudVultr()->sizes()->pluck('id')->all())->toBe(['vhf-1c-2gb']);
})->with([
    'an empty id' => [''],
    'no id' => [null],
    'an id that is not text' => [42],
]);

test('vultr skips a plan that leaves out how big it is', function (string $field) {
    $body = CloudFixtures::json('vultr/plans-2');
    unset($body['plans'][0][$field]);
    Http::fake(['api.vultr.com/v2/plans*' => Http::response($body)]);

    expect(cloudVultr()->sizes()->pluck('id')->all())->toBe(['vhf-1c-2gb']);
})->with(['vcpu_count', 'ram', 'disk']);

test('vultr skips a plan whose numbers are not numbers', function (string $field, mixed $value) {
    Http::fake(['api.vultr.com/v2/plans*' => Http::response(cloudVultrFixture('plans-2', ["plans.0.{$field}" => $value]))]);

    expect(cloudVultr()->sizes()->pluck('id')->all())->toBe(['vhf-1c-2gb']);
})->with([
    'cores that are null' => ['vcpu_count', null],
    'cores that are a word' => ['vcpu_count', 'many'],
    'memory that is a flag' => ['ram', true],
    'a disk that is a list' => ['disk', [80]],
]);

test('vultr keeps a plan that reports its numbers as digits', function () {
    Http::fake(['api.vultr.com/v2/plans*' => Http::response(cloudVultrFixture('plans-2', [
        'plans.0.vcpu_count' => '2',
        'plans.0.ram' => '4096',
        'plans.0.disk' => '80',
        'plans.0.monthly_cost' => '20',
    ]))]);

    $large = cloudVultr()->sizes()->keyBy('id')['vc2-2c-4gb'];

    expect($large->cpus)->toBe(2)
        ->and($large->memoryMb)->toBe(4096)
        ->and($large->diskGb)->toBe(80)
        ->and($large->price)->toEqual(new CloudPrice('20.00', 'USD'));
});

test('vultr reads a plan with no price or no locations without inventing either', function () {
    $body = CloudFixtures::json('vultr/plans-2');
    unset($body['plans'][0]['monthly_cost'], $body['plans'][0]['locations']);
    $body['plans'][1]['monthly_cost'] = 'free';
    Http::fake(['api.vultr.com/v2/plans*' => Http::response($body)]);

    $sizes = cloudVultr()->sizes()->keyBy('id');

    expect($sizes['vc2-2c-4gb']->price)->toBeNull()
        ->and($sizes['vc2-2c-4gb']->regions)->toBe([])
        ->and($sizes['vc2-2c-4gb']->availableIn('fra'))->toBeFalse()
        ->and($sizes['vhf-1c-2gb']->price)->toBeNull()
        ->and($sizes['vhf-1c-2gb']->regions)->toBe(['ewr', 'fra']);
});

test('vultr lists only the locations of a plan that are named', function (mixed $locations, array $expected) {
    Http::fake(['api.vultr.com/v2/plans*' => Http::response(cloudVultrFixture('plans-2', ['plans.0.locations' => $locations]))]);

    expect(cloudVultr()->sizes()->keyBy('id')['vc2-2c-4gb']->regions)->toBe($expected);
})->with([
    'entries that are not region ids' => [['fra', '', null, 42, ['ewr'], 'ewr'], ['fra', 'ewr']],
    'a region id on its own' => ['fra', []],
    'nothing' => [[], []],
]);

test('vultr passes over a list entry that is not an object', function (string $call, string $fixture, string $list, array $expected) {
    $body = CloudFixtures::json("vultr/{$fixture}");
    array_unshift($body[$list], 'oops', null, 42);
    Http::fake(['api.vultr.com/v2/*' => Http::response($body)]);

    expect(cloudVultr()->{$call}()->pluck('id')->all())->toBe($expected);
})->with([
    'regions' => ['regions', 'regions', 'regions', ['ams', 'ewr', 'fra']],
    'plans' => ['sizes', 'plans-2', 'plans', ['vc2-2c-4gb', 'vhf-1c-2gb']],
    'systems' => ['images', 'os', 'os', ['2284', '1743', '2136']],
]);

test('vultr skips a region that has no id', function (mixed $id) {
    Http::fake(['api.vultr.com/v2/regions*' => Http::response(cloudVultrFixture('regions', ['regions.0.id' => $id]))]);

    expect(cloudVultr()->regions()->pluck('id')->all())->toBe(['ewr', 'fra']);
})->with([
    'an empty id' => [''],
    'no id' => [null],
    'an id that is not text' => [42],
]);

test('vultr stops at a cursor it cannot follow', function (mixed $next) {
    Http::fake(['api.vultr.com/v2/plans*' => Http::response(cloudVultrFixture('plans-2', ['meta.links.next' => $next]))]);

    expect(cloudVultr()->sizes())->toHaveCount(2);

    Http::assertSentCount(1);
})->with([
    'an empty cursor' => [''],
    'no cursor' => [null],
    'a cursor that is not text' => [42],
    'a cursor given as a list' => [['bmV4dA==']],
]);

test('vultr keeps its page size on every page of regions and systems', function (string $call, string $fixture, string $list, string $cursor, array $expected) {
    $items = CloudFixtures::json("vultr/{$fixture}")[$list];
    Http::fake([
        "api.vultr.com/v2/{$fixture}?per_page=500" => Http::response(cloudVultrPage($list, array_slice($items, 0, 2), $cursor)),
        "api.vultr.com/v2/{$fixture}?per_page=500&cursor=*" => Http::response(cloudVultrPage($list, array_slice($items, 2))),
    ]);

    expect(cloudVultr()->{$call}()->pluck('id')->all())->toBe($expected);

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->uri()->query()->get('cursor') === $cursor
        && $request->uri()->query()->get('per_page') === '500');
})->with([
    'regions' => ['regions', 'regions', 'regions', 'cmVnaW9ucw==', ['ams', 'ewr', 'fra']],
    'systems' => ['images', 'os', 'os', 'b3NfX25leHQ=', ['2284', '1743', '2136']],
]);

test('vultr keeps ubuntu and debian systems', function () {
    Http::fake(['api.vultr.com/v2/os*' => Http::response(CloudFixtures::json('vultr/os'))]);

    $images = cloudVultr()->images();
    $byId = $images->keyBy('id');

    expect($images->pluck('id')->all())->toBe(['2284', '1743', '2136'])
        ->and($images->pluck('version')->all())->toBe(['24.04', '22.04', '12'])
        ->and($byId['2284']->family)->toBe('ubuntu')
        ->and($byId['2284']->version)->toBe('24.04')
        ->and($byId['2284']->architectures)->toBe(['x86'])
        ->and($byId['1743']->family)->toBe('ubuntu')
        ->and($byId['1743']->version)->toBe('22.04')
        ->and($byId['2136']->family)->toBe('debian')
        ->and($byId['2136']->version)->toBe('12')
        ->and($byId['2136']->architectures)->toBe(['x86'])
        ->and($byId->has('2514'))->toBeFalse();
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.vultr.com/v2/os?per_page=500');
});

test('vultr skips systems it does not provision', function (array $changes) {
    Http::fake(['api.vultr.com/v2/os*' => Http::response(cloudVultrFixture('os', $changes))]);

    expect(cloudVultr()->images()->pluck('id')->all())->toBe(['1743', '2136']);
})->with([
    'another family' => [['os.0.family' => 'centos']],
    'a family in another case' => [['os.0.family' => 'Ubuntu']],
    'a family that is not text' => [['os.0.family' => 42]],
    'no family' => [['os.0.family' => null]],
    'another architecture' => [['os.0.arch' => 'i386']],
    'an architecture in another case' => [['os.0.arch' => 'X64']],
    'no architecture' => [['os.0.arch' => null]],
    'a name without a number' => [['os.0.name' => 'Ubuntu LTS']],
    'a name that is not text' => [['os.0.name' => 24]],
    'no name' => [['os.0.name' => null]],
    'an id of zero' => [['os.0.id' => 0]],
    'a negative id' => [['os.0.id' => -2284]],
    'a fractional id' => [['os.0.id' => 2284.5]],
    'an id that is a flag' => [['os.0.id' => true]],
    'an id that is a word' => [['os.0.id' => 'ubuntu']],
    'an id that is a list' => [['os.0.id' => [2284]]],
    'an id with a leading zero' => [['os.0.id' => '02284']],
    'an id with a newline after it' => [['os.0.id' => "2284\n"]],
    'no id' => [['os.0.id' => null]],
]);

test('vultr keeps a system whose id is given as digits', function () {
    Http::fake(['api.vultr.com/v2/os*' => Http::response(cloudVultrFixture('os', ['os.0.id' => '2284']))]);

    expect(cloudVultr()->images()->first()->id)->toBe('2284');
});

test('vultr reads the version out of the system name', function (string $family, string $name, string $version) {
    Http::fake(['api.vultr.com/v2/os*' => Http::response(cloudVultrFixture('os', ['os.0.family' => $family, 'os.0.name' => $name]))]);

    $image = cloudVultr()->images()->first();

    expect($image->family)->toBe($family)->and($image->version)->toBe($version);
})->with([
    'an ubuntu lts' => ['ubuntu', 'Ubuntu 24.04 LTS x64', '24.04'],
    'an ubuntu interim release' => ['ubuntu', 'Ubuntu 25.10 x64', '25.10'],
    'a debian release' => ['debian', 'Debian 12 x64 (bookworm)', '12'],
    'a later debian release' => ['debian', 'Debian 13 x64 (trixie)', '13'],
]);

test('vultr reads a catalog answer without its list as unavailable, so an empty catalog is never taken for a real one', function (string $call) {
    Http::fake(['api.vultr.com/v2/*' => Http::response(['unexpected' => true])]);

    expect(fn() => cloudVultr()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Vultr had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with(['regions', 'sizes', 'images']);

test('vultr reads a list that is not a list as unavailable', function (string $call, string $list, mixed $value) {
    Http::fake(['api.vultr.com/v2/*' => Http::response([$list => $value])]);

    expect(fn() => cloudVultr()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });
})->with([
    'regions that are null' => ['regions', 'regions', null],
    'plans that are text' => ['sizes', 'plans', 'none'],
    'systems that are an object' => ['images', 'os', ['ubuntu' => ['id' => 2284]]],
]);

test('vultr fails a catalog read when a later page lacks the list', function () {
    Http::fake([
        'api.vultr.com/v2/plans?per_page=500' => Http::response(CloudFixtures::json('vultr/plans-1')),
        'api.vultr.com/v2/plans?per_page=500&cursor=*' => Http::response(['unexpected' => true]),
    ]);

    expect(fn() => cloudVultr()->sizes())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(2);
});

test('vultr reads a catalog with an empty list as empty', function (string $call, string $list) {
    Http::fake(['api.vultr.com/v2/*' => Http::response(cloudVultrPage($list, []))]);

    expect(cloudVultr()->{$call}()->all())->toBe([]);
})->with([
    'regions' => ['regions', 'regions'],
    'plans' => ['sizes', 'plans'],
    'systems' => ['images', 'os'],
]);

test('vultr does not verify a key, or read a catalog, from an answer that is not json', function (string $call) {
    Http::fake(['api.vultr.com/*' => Http::response('<html><body>Gateway</body></html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn() => cloudVultr()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->detail)->toBe('answered without JSON');
    });

    Http::assertSentCount(1);
})->with(['verify', 'regions', 'sizes', 'images']);

test('vultr adopts a key whose body matches whatever its comment', function () {
    Http::fake(['api.vultr.com/v2/ssh-keys*' => Http::response(CloudFixtures::json('vultr/ssh_keys'))]);

    $id = cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey());

    expect($id)->toBe('3b8066a7-b438-455a-9688-44afc9a3597f');
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.vultr.com/v2/ssh-keys?per_page=500');
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
});

test('vultr adopts the key that carries the same body, not just the first one listed', function (string $held) {
    $body = cloudVultrFixture('ssh_keys', ['ssh_keys.0.ssh_key' => $held]);
    $mine = $body['ssh_keys'][0];
    $elsewhere = cloudVultrFixture('ssh_keys', [
        'ssh_keys.0.id' => '7c2f9a10-3d4e-4b58-8a61-0e9f1b2c3d4e',
        'ssh_keys.0.ssh_key' => cloudVultrOtherKey(),
    ])['ssh_keys'][0];
    $body['ssh_keys'] = [$elsewhere, 'oops', $mine];
    Http::fake(['api.vultr.com/v2/ssh-keys*' => Http::response($body)]);

    $id = cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey());

    expect($id)->toBe('3b8066a7-b438-455a-9688-44afc9a3597f');
    Http::assertSentCount(1);
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
})->with([
    'with another comment' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4 someone@laptop'],
    'with no comment' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4'],
    'with a comment of several words' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4 work laptop 2026'],
    'with whitespace around it' => ["  ssh-ed25519\tAAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4  someone@laptop\n"],
]);

test('vultr looks for a key on every page before it uploads one', function () {
    $held = CloudFixtures::json('vultr/ssh_keys')['ssh_keys'];
    $elsewhere = cloudVultrFixture('ssh_keys', [
        'ssh_keys.0.id' => '7c2f9a10-3d4e-4b58-8a61-0e9f1b2c3d4e',
        'ssh_keys.0.ssh_key' => cloudVultrOtherKey(),
    ])['ssh_keys'];
    Http::fake([
        'api.vultr.com/v2/ssh-keys?per_page=500' => Http::response(cloudVultrPage('ssh_keys', $elsewhere, 'c3NoX19uZXh0')),
        'api.vultr.com/v2/ssh-keys?per_page=500&cursor=*' => Http::response(cloudVultrPage('ssh_keys', $held)),
    ]);

    $id = cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey());

    expect($id)->toBe('3b8066a7-b438-455a-9688-44afc9a3597f');
    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->uri()->query()->get('cursor') === 'c3NoX19uZXh0');
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
});

test('vultr uploads a key it does not hold', function () {
    Http::fake([
        'api.vultr.com/v2/ssh-keys?*' => Http::response(CloudFixtures::json('vultr/ssh_keys-empty')),
        'api.vultr.com/v2/ssh-keys' => Http::response(CloudFixtures::json('vultr/ssh_key'), 201),
    ]);

    $id = cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey());

    [$post] = Http::recorded(fn(Request $request) => $request->method() === 'POST')->first();

    expect($id)->toBe('d8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57')
        ->and($post->url())->toBe('https://api.vultr.com/v2/ssh-keys')
        ->and($post->data())->toBe([
            'name' => 'coresmith-outpost-02-b1n0d',
            'ssh_key' => cloudVultrKey(),
        ]);
    Http::assertSentCount(2);
});

test('vultr uploads a key when the account holds only other keys', function () {
    Http::fake([
        'api.vultr.com/v2/ssh-keys?*' => Http::response(cloudVultrFixture('ssh_keys', ['ssh_keys.0.ssh_key' => cloudVultrOtherKey()])),
        'api.vultr.com/v2/ssh-keys' => Http::response(CloudFixtures::json('vultr/ssh_key'), 201),
    ]);

    expect(cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey()))->toBe('d8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57');

    Http::assertSentCount(2);
});

test('vultr does not adopt a key that only looks like the one it wants', function (mixed $listed) {
    Http::fake([
        'api.vultr.com/v2/ssh-keys?*' => Http::response(cloudVultrFixture('ssh_keys', ['ssh_keys.0.ssh_key' => $listed])),
        'api.vultr.com/v2/ssh-keys' => Http::response(CloudFixtures::json('vultr/ssh_key'), 201),
    ]);

    $id = cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey());

    expect($id)->toBe('d8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57');
    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'POST');
})->with([
    'the body with more after it' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4AAAA coresmith-test'],
    'part of the body' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj coresmith-test'],
    'the body in the comment field' => ['ssh-ed25519 coresmith-test AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4'],
    'a type with no body' => ['ssh-ed25519'],
    'an empty key' => [''],
    'no key' => [null],
    'a key that is not text' => [42],
    'a key given as a list' => [['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4 coresmith-test']],
]);

test('vultr uploads a key it cannot fingerprint without asking whether it holds it', function () {
    Http::fake(['api.vultr.com/v2/ssh-keys' => Http::response(['error' => 'SSH key is not valid.', 'status' => 400], 400)]);

    expect(fn() => cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', 'not a key'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->detail)->toBe('SSH key is not valid.');
    });

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'POST');
});

test('vultr does not upload a second key because a lookup it could not read', function (string $body, array $headers) {
    Http::fake(['api.vultr.com/v2/ssh-keys?*' => Http::response($body, 200, $headers)]);

    expect(fn() => cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(1);
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
})->with([
    'a json object without a list' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'a list that is an object' => ['{"ssh_keys":{"a":1}}', ['Content-Type' => 'application/json']],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
    'nothing at all' => ['', []],
]);

test('vultr does not upload a second key because the lookup was refused', function (int $status) {
    Http::fake(['api.vultr.com/v2/ssh-keys?*' => Http::response(['error' => 'No.', 'status' => $status], $status)]);

    expect(fn() => cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey()))->toThrow(CloudProviderException::class);

    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
})->with([401, 403, 429, 503]);

test('vultr reads a key upload whose answer has no key in it as unavailable', function (string $body, array $headers) {
    Http::fake([
        'api.vultr.com/v2/ssh-keys?*' => Http::response(CloudFixtures::json('vultr/ssh_keys-empty')),
        'api.vultr.com/v2/ssh-keys' => Http::response($body, 201, $headers),
    ]);

    expect(fn() => cloudVultr()->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(2);
})->with([
    'a json object without a key' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'a key with no id' => ['{"ssh_key":{"name":"coresmith-outpost-02-b1n0d"}}', ['Content-Type' => 'application/json']],
    'an empty body' => ['', []],
]);

test('vultr creates an instance with the key, both tags and backups off', function () {
    Http::fake(['api.vultr.com/v2/instances' => Http::response(CloudFixtures::json('vultr/instance-pending'), 202)]);

    $instance = cloudVultr()->create(cloudVultrSpec());

    [$post] = Http::recorded(fn(Request $request) => $request->method() === 'POST')->first();

    expect($post->method())->toBe('POST')
        ->and($post->url())->toBe('https://api.vultr.com/v2/instances')
        ->and($post->data())->toBe([
            'region' => 'fra',
            'plan' => 'vc2-1c-2gb',
            'os_id' => 2284,
            'label' => 'outpost-02',
            'hostname' => 'outpost-02',
            'sshkey_id' => ['d8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57'],
            'tags' => ['coresmith', 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d'],
            'enable_ipv6' => true,
            'backups' => 'disabled',
        ])
        ->and($instance->id)->toBe('cb676a46-66fd-4dfb-b839-443f2e6c0b60')
        ->and($instance->state)->toBe(CloudInstanceState::Starting)
        ->and($instance->ipv4)->toBeNull()
        ->and($instance->isReady())->toBeFalse()
        ->and($instance)->toEqual(new CloudInstance('cb676a46-66fd-4dfb-b839-443f2e6c0b60', CloudInstanceState::Starting, null, 'vc2-1c-2gb'));
    Http::assertSentCount(1);
});

test('vultr never keeps the default password', function () {
    Http::fake(['api.vultr.com/v2/instances' => Http::response(CloudFixtures::json('vultr/instance-pending'), 202)]);
    $api = cloudVultr();

    $instance = $api->create(cloudVultrSpec());

    expect(serialize($instance))->not->toContain('v5{Ex4mple}Pass')
        ->and(print_r($instance, true))->not->toContain('v5{Ex4mple}Pass')
        ->and((string) json_encode($instance))->not->toContain('v5{Ex4mple}Pass');
});

test('vultr drops a default password wherever an answer carries one', function () {
    $password = 'v5{Ex4mple}Pass';
    Http::fake([
        'api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => Http::response(cloudVultrFixture('instance-ready', ['instance.default_password' => $password])),
        'api.vultr.com/v2/instances?*' => Http::response(cloudVultrFixture('instances-tagged', ['instances.0.default_password' => $password])),
    ]);
    $api = cloudVultr();

    expect(serialize($api->find(cloudVultrInstanceId())))->not->toContain($password)
        ->and(serialize($api->findTagged(cloudVultrTag())))->not->toContain($password);
});

test('vultr will not create an instance without a key to put on it', function () {
    expect(fn() => cloudVultr()->create(cloudVultrSpec(null)))->toThrow(LogicException::class, 'needs the id of an uploaded key');

    Http::assertNothingSent();
});

test('vultr is only ready once the os reports ok', function () {
    Http::fake(['api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => Http::sequence()
        ->push(CloudFixtures::json('vultr/instance-booting'))
        ->push(CloudFixtures::json('vultr/instance-ready'))]);
    $api = cloudVultr();

    $booting = $api->find(cloudVultrInstanceId());
    $ready = $api->find(cloudVultrInstanceId());

    expect($booting->state)->toBe(CloudInstanceState::Starting)
        ->and($booting->ipv4)->toBe('203.0.113.42')
        ->and($booting->isReady())->toBeFalse()
        ->and($ready->id)->toBe('cb676a46-66fd-4dfb-b839-443f2e6c0b60')
        ->and($ready->state)->toBe(CloudInstanceState::Running)
        ->and($ready->ipv4)->toBe('203.0.113.42')
        ->and($ready->size)->toBe('vc2-1c-2gb')
        ->and($ready->isReady())->toBeTrue();
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60');
});

test('vultr maps every instance state', function (?string $status, ?string $power, ?string $server, CloudInstanceState $state) {
    Http::fake(['api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => Http::response(cloudVultrFixture('instance-ready', [
        'instance.status' => $status,
        'instance.power_status' => $power,
        'instance.server_status' => $server,
    ]))]);

    expect(cloudVultr()->find(cloudVultrInstanceId())->state)->toBe($state);
})->with([
    'pending' => ['pending', 'running', 'none', CloudInstanceState::Starting],
    'pending while the power reads stopped' => ['pending', 'stopped', 'none', CloudInstanceState::Starting],
    'pending with the os reporting ok' => ['pending', 'running', 'ok', CloudInstanceState::Starting],
    'resizing' => ['resizing', 'running', 'ok', CloudInstanceState::Busy],
    'resizing while the power reads stopped' => ['resizing', 'stopped', 'ok', CloudInstanceState::Busy],
    'active and stopped' => ['active', 'stopped', 'none', CloudInstanceState::Stopped],
    'active and stopped with the os reporting ok' => ['active', 'stopped', 'ok', CloudInstanceState::Stopped],
    'active, running and the os reporting ok' => ['active', 'running', 'ok', CloudInstanceState::Running],
    'active, running and the os still installing' => ['active', 'running', 'installingbooting', CloudInstanceState::Starting],
    'active, running and the os not reporting yet' => ['active', 'running', 'none', CloudInstanceState::Starting],
    'active, running and the instance locked' => ['active', 'running', 'locked', CloudInstanceState::Starting],
    'active, running and no server status at all' => ['active', 'running', null, CloudInstanceState::Starting],
    'suspended' => ['suspended', 'running', 'ok', CloudInstanceState::Unknown],
    'a status it has not heard of' => ['something-new', 'running', 'ok', CloudInstanceState::Unknown],
    'no status' => [null, 'running', 'ok', CloudInstanceState::Unknown],
    'active and no power status' => ['active', null, 'ok', CloudInstanceState::Unknown],
    'active and a power status it has not heard of' => ['active', 'rebooting', 'ok', CloudInstanceState::Unknown],
]);

test('vultr reads an instance with none of its states as unknown', function () {
    $body = CloudFixtures::json('vultr/instance-ready');
    unset($body['instance']['status'], $body['instance']['power_status'], $body['instance']['server_status']);
    Http::fake(['api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => Http::response($body)]);

    expect(cloudVultr()->find(cloudVultrInstanceId())->state)->toBe(CloudInstanceState::Unknown);
});

test('vultr reads an instance with no usable public address as not ready', function (mixed $address) {
    Http::fake(['api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => Http::response(cloudVultrFixture('instance-ready', ['instance.main_ip' => $address]))]);

    $found = cloudVultr()->find(cloudVultrInstanceId());

    expect($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBeNull()
        ->and($found->isReady())->toBeFalse();
})->with([
    'an address not assigned yet' => ['0.0.0.0'],
    'an empty address' => [''],
    'no address' => [null],
    'an address that is not text' => [42],
    'a private address' => ['10.1.2.3'],
    'an ipv6 address' => ['2001:db8:0:1::1'],
    'text that is not an address' => ['pending'],
]);

test('vultr reads an instance that is not there as nothing', function () {
    Http::fake(['api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => cloudVultrMissing()]);

    expect(cloudVultr()->find(cloudVultrInstanceId()))->toBeNull();

    Http::assertSentCount(1);
});

test('vultr reads the size of an instance from its plan', function () {
    Http::fake(['api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => Http::sequence()
        ->push(cloudVultrFixture('instance-ready', ['instance.plan' => 'vc2-2c-4gb']))
        ->push(cloudVultrFixture('instance-ready', ['instance.plan' => '']))]);
    $api = cloudVultr();

    expect($api->find(cloudVultrInstanceId())->size)->toBe('vc2-2c-4gb')
        ->and($api->find(cloudVultrInstanceId())->size)->toBeNull();
});

test('vultr finds an instance by tag', function () {
    Http::fake(['api.vultr.com/v2/instances*' => Http::sequence()
        ->push(CloudFixtures::json('vultr/instances-tagged'))
        ->push(CloudFixtures::json('vultr/instances-empty'))]);
    $api = cloudVultr();

    $found = $api->findTagged(cloudVultrTag());

    expect($found->id)->toBe('cb676a46-66fd-4dfb-b839-443f2e6c0b60')
        ->and($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBe('203.0.113.42')
        ->and($found->size)->toBe('vc2-1c-2gb')
        ->and($api->findTagged(cloudVultrTag()))->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->uri()->path() === 'v2/instances'
        && $request->uri()->query()->get('tag') === 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d');
});

test('vultr takes the first instance when a tag matches several', function () {
    $body = CloudFixtures::json('vultr/instances-tagged');
    $second = $body['instances'][0];
    $second['id'] = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
    $body['instances'][] = $second;
    Http::fake(['api.vultr.com/v2/instances*' => Http::response($body)]);

    expect(cloudVultr()->findTagged(cloudVultrTag())->id)->toBe('cb676a46-66fd-4dfb-b839-443f2e6c0b60');
});

test('vultr returns the instance that carries the tag itself, not just the first one listed', function () {
    $body = cloudVultrFixture('instances-tagged');
    $mine = $body['instances'][0];
    $elsewhere = cloudVultrFixture('instances-tagged', [
        'instances.0.id' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
        'instances.0.tags' => ['coresmith', 'coresmith-01jother'],
    ])['instances'][0];
    $untagged = cloudVultrFixture('instances-tagged', [
        'instances.0.id' => '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e',
        'instances.0.tags' => [],
    ])['instances'][0];
    $body['instances'] = [$elsewhere, $untagged, 'oops', $mine];
    Http::fake(['api.vultr.com/v2/instances*' => Http::response($body)]);

    expect(cloudVultr()->findTagged(cloudVultrTag())->id)->toBe('cb676a46-66fd-4dfb-b839-443f2e6c0b60');
});

test('vultr fails a tag lookup that lists instances but none that carries the tag, so an unrelated instance is never handed back', function (array $changes) {
    Http::fake(['api.vultr.com/v2/instances*' => Http::response(cloudVultrFixture('instances-tagged', $changes))]);

    expect(fn() => cloudVultr()->findTagged(cloudVultrTag()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Vultr had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'another tag' => [['instances.0.tags' => ['coresmith', 'coresmith-01jother']]],
    'only the shared tag' => [['instances.0.tags' => ['coresmith']]],
    'the tag with more after it' => [['instances.0.tags' => ['coresmith', 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0dx']]],
    'the tag in another case' => [['instances.0.tags' => ['coresmith', 'CORESMITH-01JCP7M3ZQ9X4V6T8W2Y5B1N0D']]],
    'no tags' => [['instances.0.tags' => []]],
    'tags given as text' => [['instances.0.tags' => 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d']],
    'tags that are null' => [['instances.0.tags' => null]],
    'only the old single tag field' => [['instances.0.tags' => [], 'instances.0.tag' => 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d']],
]);

test('vultr resizes by changing the plan and reboots, halts and starts', function () {
    Http::fake([
        'api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60/*' => Http::response('', 204),
        'api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => Http::response(CloudFixtures::json('vultr/instance-pending'), 202),
    ]);
    $api = cloudVultr();

    $api->reboot(cloudVultrInstanceId());
    $api->powerOff(cloudVultrInstanceId());
    $api->powerOn(cloudVultrInstanceId());
    $api->resize(cloudVultrInstanceId(), 'vc2-2c-4gb');

    $sent = Http::recorded()
        ->map(fn(array $exchange): array => [$exchange[0]->method(), $exchange[0]->url(), $exchange[0]->body()])
        ->values()
        ->all();

    expect($sent)->toBe([
        ['POST', 'https://api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60/reboot', '{}'],
        ['POST', 'https://api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60/halt', '{}'],
        ['POST', 'https://api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60/start', '{}'],
        ['PATCH', 'https://api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60', '{"plan":"vc2-2c-4gb"}'],
    ])
        ->and($api->uploadsKeys())->toBeTrue()
        ->and($api->resizeNeedsPowerOff())->toBeFalse();
});

test('vultr accepts an action, a resize or a delete that answers with no body', function () {
    Http::fake(['api.vultr.com/v2/*' => Http::response('', 204)]);
    $api = cloudVultr();

    $api->reboot(cloudVultrInstanceId());
    $api->powerOff(cloudVultrInstanceId());
    $api->powerOn(cloudVultrInstanceId());
    $api->resize(cloudVultrInstanceId(), 'vc2-2c-4gb');
    $api->destroy(cloudVultrInstanceId());
    $api->deleteKey(cloudVultrKeyId());

    Http::assertSentCount(6);
});

test('vultr deletes an instance and a key it holds', function () {
    Http::fake(['api.vultr.com/v2/*' => Http::response('', 204)]);
    $api = cloudVultr();

    $api->destroy(cloudVultrInstanceId());
    $api->deleteKey(cloudVultrKeyId());

    $sent = Http::recorded(fn(Request $request) => $request->method() === 'DELETE')
        ->map(fn(array $exchange): array => [$exchange[0]->method(), $exchange[0]->url()])
        ->values()
        ->all();

    expect($sent)->toBe([
        ['DELETE', 'https://api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60'],
        ['DELETE', 'https://api.vultr.com/v2/ssh-keys/d8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57'],
    ]);
});

test('vultr treats an instance that is already gone as destroyed', function () {
    Http::fake([
        'api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => cloudVultrMissing(),
        'api.vultr.com/v2/ssh-keys/d8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57' => cloudVultrMissing(),
    ]);
    $api = cloudVultr();

    $api->destroy(cloudVultrInstanceId());
    $api->deleteKey(cloudVultrKeyId());

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60');
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.vultr.com/v2/ssh-keys/d8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57');
});

test('vultr still fails a delete that is refused', function (string $call, string $id) {
    Http::fake(['api.vultr.com/v2/*' => Http::response(['error' => 'Forbidden.', 'status' => 403], 403)]);

    expect(fn() => cloudVultr()->{$call}($id))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)->and($e->detail)->toBe('Forbidden.');
    });

    Http::assertSentCount(1);
})->with([
    'an instance' => ['destroy', 'cb676a46-66fd-4dfb-b839-443f2e6c0b60'],
    'a key' => ['deleteKey', 'd8a4c1f3-52b6-4e7d-9a30-6c1b8e2f4d57'],
]);

test('vultr refuses an id that names nothing before it sends anything', function (string $call, string $id) {
    $arguments = $call === 'resize' ? [$id, 'vc2-2c-4gb'] : [$id];

    expect(fn() => cloudVultr()->{$call}(...$arguments))->toThrow(LogicException::class, 'A cloud id must name one resource.');

    Http::assertNothingSent();
})->with(['find', 'reboot', 'powerOff', 'powerOn', 'resize', 'destroy', 'deleteKey'])->with(['', ' ', '.', '..']);

test('vultr keeps an id inside its own path segment', function (string $call, array $arguments, string $method, string $url) {
    Http::fake(['api.vultr.com/*' => Http::response(['instance' => ['id' => 'cb676a46-66fd-4dfb-b839-443f2e6c0b60', 'status' => 'active']])]);

    cloudVultr()->{$call}(...$arguments);

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === $method && $request->url() === $url);
})->with([
    'find' => ['find', ['../7'], 'GET', 'https://api.vultr.com/v2/instances/..%2F7'],
    'reboot' => ['reboot', ['../7'], 'POST', 'https://api.vultr.com/v2/instances/..%2F7/reboot'],
    'power off' => ['powerOff', ['../7'], 'POST', 'https://api.vultr.com/v2/instances/..%2F7/halt'],
    'power on' => ['powerOn', ['../7'], 'POST', 'https://api.vultr.com/v2/instances/..%2F7/start'],
    'resize' => ['resize', ['../7', 'vc2-2c-4gb'], 'PATCH', 'https://api.vultr.com/v2/instances/..%2F7'],
    'destroy' => ['destroy', ['../ssh-keys/7'], 'DELETE', 'https://api.vultr.com/v2/instances/..%2Fssh-keys%2F7'],
    'delete key' => ['deleteKey', ['../instances/7'], 'DELETE', 'https://api.vultr.com/v2/ssh-keys/..%2Finstances%2F7'],
]);

test('vultr accepts an id however its uuid is cased', function (string $id) {
    Http::fake([
        'api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => Http::response(cloudVultrFixture('instance-ready', ['instance.id' => $id])),
        'api.vultr.com/v2/ssh-keys?*' => Http::response(cloudVultrFixture('ssh_keys', ['ssh_keys.0.id' => $id])),
    ]);
    $api = cloudVultr();

    expect($api->find(cloudVultrInstanceId())->id)->toBe($id)
        ->and($api->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey()))->toBe($id);
})->with([
    'in lower case' => ['cb676a46-66fd-4dfb-b839-443f2e6c0b60'],
    'in capitals' => ['CB676A46-66FD-4DFB-B839-443F2E6C0B60'],
    'in mixed case' => ['Cb676a46-66fD-4dfb-B839-443f2e6c0b60'],
]);

test('vultr reads an id that is not a uuid as unavailable, so the tag is checked before anything is made again', function (string $call, mixed $id) {
    Http::fake([
        'api.vultr.com/v2/ssh-keys?*' => Http::response($call === 'adopt'
            ? cloudVultrFixture('ssh_keys', ['ssh_keys.0.id' => $id])
            : CloudFixtures::json('vultr/ssh_keys-empty')),
        'api.vultr.com/v2/ssh-keys' => Http::response(cloudVultrFixture('ssh_key', ['ssh_key.id' => $id]), 201),
        'api.vultr.com/v2/instances/cb676a46-66fd-4dfb-b839-443f2e6c0b60' => Http::response(cloudVultrFixture('instance-ready', ['instance.id' => $id])),
        'api.vultr.com/v2/instances?*' => Http::response(cloudVultrFixture('instances-tagged', ['instances.0.id' => $id])),
        'api.vultr.com/v2/instances' => Http::response(cloudVultrFixture('instance-pending', ['instance.id' => $id]), 202),
    ]);
    $api = cloudVultr();
    $attempt = match ($call) {
        'create' => fn() => $api->create(cloudVultrSpec()),
        'find' => fn() => $api->find(cloudVultrInstanceId()),
        'findTagged' => fn() => $api->findTagged(cloudVultrTag()),
        'adopt', 'upload' => fn() => $api->uploadKey('coresmith-outpost-02-b1n0d', cloudVultrKey()),
    };

    expect($attempt)->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Vultr had a problem answering. Try again in a minute.');
    });
})->with(['create', 'find', 'findTagged', 'adopt', 'upload'])->with([
    'no id' => [null],
    'zero' => [0],
    'a number' => [3164444],
    'a fraction' => [3164444.5],
    'a flag' => [true],
    'a word' => ['abc'],
    'digits' => ['3164444'],
    'empty text' => [''],
    'a path' => ['../cb676a46-66fd-4dfb-b839-443f2e6c0b60'],
    'a list' => [['cb676a46-66fd-4dfb-b839-443f2e6c0b60']],
    'a uuid with a newline after it' => ["cb676a46-66fd-4dfb-b839-443f2e6c0b60\n"],
    'a uuid with a space before it' => [' cb676a46-66fd-4dfb-b839-443f2e6c0b60'],
    'a uuid with more after it' => ['cb676a46-66fd-4dfb-b839-443f2e6c0b60-1'],
    'a uuid without dashes' => ['cb676a4666fd4dfbb839443f2e6c0b60'],
    'a uuid with a letter that is not a digit' => ['gb676a46-66fd-4dfb-b839-443f2e6c0b60'],
]);

test('vultr reports a plan the region does not offer', function () {
    Http::fake(['api.vultr.com/v2/instances' => Http::response(CloudFixtures::json('vultr/error-plan'), 400)]);

    expect(fn() => cloudVultr()->create(cloudVultrSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)
            ->and($e->retryable())->toBeFalse()
            ->and($e->status)->toBe(400)
            ->and($e->detail)->toBe('Plan is not available in the selected region.')
            ->and($e->getMessage())->toBe('Vultr refused the request. Vultr said: Plan is not available in the selected region.');
    });

    Http::assertSentCount(1);
});

test('vultr says a key without access cannot create', function () {
    Http::fake(['api.vultr.com/v2/instances' => Http::response(['error' => 'Insufficient permissions.', 'status' => 403], 403)]);

    expect(fn() => cloudVultr()->create(cloudVultrSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)
            ->and($e->retryable())->toBeFalse()
            ->and($e->detail)->toBe('Insufficient permissions.')
            ->and($e->getMessage())->toContain("Allow this panel's IP address in Vultr's API access control.");
    });

    Http::assertSentCount(1);
});

test('vultr never retries a create or a resize that met a server error', function (string $call) {
    Http::fake(['api.vultr.com/v2/instances*' => Http::response(['error' => 'Service unavailable.', 'status' => 503], 503)]);
    $attempt = match ($call) {
        'create' => fn() => cloudVultr()->create(cloudVultrSpec()),
        'resize' => fn() => cloudVultr()->resize(cloudVultrInstanceId(), 'vc2-2c-4gb'),
    };

    expect($attempt)->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->status)->toBe(503)
            ->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(1);
})->with(['create', 'resize']);

test('vultr reads a rate limit as one to wait out', function () {
    Http::fake(['api.vultr.com/v2/instances' => Http::response(['error' => 'Rate limit exceeded.', 'status' => 429], 429)]);

    expect(fn() => cloudVultr()->create(cloudVultrSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::RateLimited)
            ->and($e->retryable())->toBeTrue()
            ->and($e->detail)->toBe('Rate limit exceeded.');
    });

    Http::assertSentCount(1);
});

test('vultr ignores an error message that is not text', function (mixed $message) {
    Http::fake(['api.vultr.com/v2/instances' => Http::response(['error' => $message, 'status' => 400], 400)]);

    expect(fn() => cloudVultr()->create(cloudVultrSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->detail)->toBeNull();
    });
})->with([
    'a list' => [['plan', 'region']],
    'a number' => [42],
    'a flag' => [true],
]);

test('vultr reads a create whose answer has no instance in it as unavailable, so its tag is checked before another is made', function (string $body, array $headers) {
    Http::fake(['api.vultr.com/v2/instances' => Http::response($body, 202, $headers)]);

    expect(fn() => cloudVultr()->create(cloudVultrSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Vultr had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'a json object without an instance' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'an instance that is not an object' => ['{"instance":"gone"}', ['Content-Type' => 'application/json']],
    'an instance with no id' => ['{"instance":{"status":"pending"}}', ['Content-Type' => 'application/json']],
    'an empty body' => ['', []],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
]);

test('vultr does not mistake a lookup it cannot read for an instance that is not there', function (string $body, array $headers) {
    Http::fake(['api.vultr.com/v2/instances*' => Http::response($body, 200, $headers)]);
    $api = cloudVultr();

    foreach ([fn() => $api->find(cloudVultrInstanceId()), fn() => $api->findTagged(cloudVultrTag())] as $lookup) {
        expect($lookup)->toThrow(function (CloudProviderException $e) {
            expect($e->failure)->toBe(CloudFailure::Unavailable)
                ->and($e->getMessage())->toBe('Vultr had a problem answering. Try again in a minute.');
        });
    }

    Http::assertSentCount(2);
})->with([
    'a json object without the data' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
    'nothing at all' => ['', []],
]);
