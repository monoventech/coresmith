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
use App\Services\Cloud\Providers\HttpCloudApi;
use App\Services\Cloud\Providers\LinodeApi;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\Support\CloudFixtures;
use Tests\Support\TraceSecrets;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00'));
});

function cloudLinode(): LinodeApi
{
    return new LinodeApi('linode-token');
}

function cloudLinodeTag(): string
{
    return 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d';
}

function cloudLinodeKey(): string
{
    return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4 coresmith-test';
}

function cloudLinodeSpec(): CloudServerSpec
{
    return new CloudServerSpec('outpost-02', 'eu-central', 'g6-standard-1', 'linode/ubuntu24.04', cloudLinodeTag(), cloudLinodeKey(), null);
}

function cloudLinodePage(array $items, int $page = 1, int $pages = 1): array
{
    return ['data' => $items, 'page' => $page, 'pages' => $pages, 'results' => count($items)];
}

function cloudLinodeProfile(array $headers = []): PromiseInterface
{
    return Http::response(CloudFixtures::json('linode/profile'), 200, $headers);
}

test('linode is the cloud api for linode', function () {
    expect(cloudLinode())->toBeInstanceOf(CloudApi::class)
        ->toBeInstanceOf(HttpCloudApi::class)
        ->and(cloudLinode()->type())->toBe(CloudProviderType::Linode)
        ->and((new ReflectionClass(LinodeApi::class))->isFinal())->toBeTrue();
});

test('linode names the account by its username', function () {
    Http::fake(['api.linode.com/v4/profile' => cloudLinodeProfile()]);

    $account = cloudLinode()->verify();

    expect($account->label)->toBe('monoven-ops');
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.linode.com/v4/profile'
        && $request->hasHeader('Authorization', 'Bearer linode-token'));
});

test('linode accepts a token whose scopes include writing linodes and reading images, or every scope, or that sends no scope header', function (array $headers) {
    Http::fake(['api.linode.com/v4/profile' => cloudLinodeProfile($headers)]);

    expect(cloudLinode()->verify()->label)->toBe('monoven-ops');

    Http::assertSentCount(1);
})->with([
    'writing linodes and reading images' => [['X-OAuth-Scopes' => 'linodes:read_write images:read_only']],
    'writing linodes and writing images' => [['X-OAuth-Scopes' => 'linodes:read_write images:read_write']],
    'writing linodes among others' => [['X-OAuth-Scopes' => 'account:read_only domains:read_write linodes:read_write images:read_only']],
    'the scopes in another order' => [['X-OAuth-Scopes' => 'images:read_only linodes:read_write']],
    'scopes split by commas' => [['X-OAuth-Scopes' => 'images:read_only,linodes:read_write']],
    'scopes split by commas and spaces' => [['X-OAuth-Scopes' => 'images:read_only, linodes:read_write']],
    'every scope' => [['X-OAuth-Scopes' => '*']],
    'no scope header' => [[]],
]);

test('linode refuses a token that can only read linodes', function () {
    Http::fake(['api.linode.com/v4/profile' => cloudLinodeProfile(['X-OAuth-Scopes' => 'linodes:read_only'])]);

    expect(fn() => cloudLinode()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)
            ->and($e->provider)->toBe(CloudProviderType::Linode)
            ->and($e->detail)->toBeNull()
            ->and($e->status)->toBeNull()
            ->and($e->retryable())->toBeFalse()
            ->and($e->getMessage())->toContain('read/write access to Linodes')
            ->and($e->connectMessage())->toBe("Linode accepted the token, but it isn't allowed to manage servers. Give it read/write access to Linodes and read access to Images.");
    });

    Http::assertSentCount(1);
});

test('linode refuses a token whose scopes leave out writing linodes or reading images', function (string $scopes) {
    Http::fake(['api.linode.com/v4/profile' => cloudLinodeProfile(['X-OAuth-Scopes' => $scopes])]);

    expect(fn() => cloudLinode()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)
            ->and($e->retryable())->toBeFalse()
            ->and($e->getMessage())->toContain('read/write access to Linodes and read access to Images');
    });

    Http::assertSentCount(1);
})->with([
    'reading linodes' => ['linodes:read_only'],
    'reading linodes and images' => ['linodes:read_only images:read_only'],
    'reading linodes and writing images' => ['images:read_write linodes:read_only'],
    'writing linodes and nothing on images' => ['linodes:read_write'],
    'writing linodes and other things but not images' => ['linodes:read_write account:read_write domains:read_write'],
    'writing everything but linodes' => ['account:read_write domains:read_write images:read_write'],
    'images alone' => ['images:read_only'],
    'a linodes scope that only starts with it' => ['linodes:read_write_all images:read_only'],
    'a linodes scope that only ends with it' => ['all_linodes:read_write images:read_only'],
    'an images scope that only starts with it' => ['linodes:read_write images:read_only_all'],
    'an images scope that only ends with it' => ['linodes:read_write all_images:read_only'],
    'a scope header that is present but empty, so the token has no scopes' => [''],
    'a scope header of whitespace' => [' '],
    'a scope header of a tab' => ["\t"],
    'a scope header of commas' => [' , '],
]);

test('linode does not take an answer without a username for a verified account', function (array $body) {
    Http::fake(['api.linode.com/v4/profile' => Http::response($body)]);

    expect(fn() => cloudLinode()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Linode had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'a json object without a username' => [['unexpected' => true]],
    'an empty username' => [['uid' => 1234, 'username' => '']],
    'a username that is not text' => [['uid' => 1234, 'username' => 42]],
    'a list' => [['monoven-ops']],
    'nothing in it' => [[]],
]);

test('linode reports a refused token in words', function () {
    Http::fake(['api.linode.com/v4/profile' => Http::response(CloudFixtures::json('linode/error-invalid-token'), 401)]);

    expect(fn() => cloudLinode()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::TokenRejected)
            ->and($e->status)->toBe(401)
            ->and($e->retryable())->toBeFalse()
            ->and($e->detail)->toBe('Invalid Token')
            ->and($e->connectMessage())->toBe("Linode didn't accept that token. Linode said: Invalid Token. Copy it again from Linode, then paste it here.");
    });

    Http::assertSentCount(1);
});

test('linode never repeats a token that its error message echoes', function () {
    $token = 'LINODETOKEN' . str_repeat('ab12', 16);
    Http::fake(['api.linode.com/v4/profile' => Http::response(['errors' => [['reason' => "Invalid Token {$token}"]]], 401)]);

    expect(fn() => (new LinodeApi($token))->verify())->toThrow(function (CloudProviderException $e) use ($token) {
        expect($e->detail)->toBe('Invalid Token [REDACTED]')
            ->and($e->getMessage())->not->toContain($token)
            ->and($e->connectMessage())->not->toContain($token);
    });
});

test('linode keeps core regions that host linodes', function () {
    Http::fake(['api.linode.com/v4/regions*' => Http::response(CloudFixtures::json('linode/regions'))]);

    $regions = cloudLinode()->regions();

    expect($regions->pluck('id')->all())->toBe(['eu-central', 'us-east', 'id-cgk'])
        ->and($regions->first()->label)->toBe('Frankfurt, DE (eu-central)')
        ->and($regions->first()->country)->toBe('DE')
        ->and($regions->get(1)->label)->toBe('Newark, NJ (us-east)')
        ->and($regions->get(1)->country)->toBe('US')
        ->and($regions->last()->label)->toBe('Jakarta, ID (id-cgk)')
        ->and($regions->last()->country)->toBe('ID');
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.linode.com/v4/regions?page_size=500');
});

test('linode skips a region that cannot host linodes, is not healthy or is not a core site', function (array $changes) {
    Http::fake(['api.linode.com/v4/regions*' => Http::response(CloudFixtures::with('linode/regions', $changes))]);

    expect(cloudLinode()->regions()->pluck('id')->all())->toBe(['us-east', 'id-cgk']);
})->with([
    'no capabilities' => [['data.0.capabilities' => []]],
    'capabilities without linodes' => [['data.0.capabilities' => ['Kubernetes', 'Object Storage']]],
    'linodes in another case' => [['data.0.capabilities' => ['linodes']]],
    'capabilities given as text' => [['data.0.capabilities' => 'Linodes']],
    'capabilities that are null' => [['data.0.capabilities' => null]],
    'a flag among the capabilities' => [['data.0.capabilities' => [true]]],
    'a list among the capabilities' => [['data.0.capabilities' => [['Linodes']]]],
    'an outage' => [['data.0.status' => 'outage']],
    'a status in another case' => [['data.0.status' => 'OK']],
    'no status' => [['data.0.status' => null]],
    'a distributed site' => [['data.0.site_type' => 'distributed']],
    'a site type it has not heard of' => [['data.0.site_type' => 'edge']],
    'a site type that is not text' => [['data.0.site_type' => 42]],
]);

test('linode keeps a region that does not say what kind of site it is', function () {
    $body = CloudFixtures::json('linode/regions');
    unset($body['data'][0]['site_type']);
    $body['data'][1]['site_type'] = null;
    Http::fake(['api.linode.com/v4/regions*' => Http::response($body)]);

    expect(cloudLinode()->regions()->pluck('id')->all())->toBe(['eu-central', 'us-east', 'id-cgk']);
});

test('linode labels a region that has no label by its id', function () {
    $body = CloudFixtures::json('linode/regions');
    unset($body['data'][0]['label'], $body['data'][0]['country']);
    Http::fake(['api.linode.com/v4/regions*' => Http::response($body)]);

    $first = cloudLinode()->regions()->first();

    expect($first->id)->toBe('eu-central')->and($first->label)->toBe('eu-central')->and($first->country)->toBeNull();
});

test('linode reads the country of a region in capitals', function (mixed $country, ?string $expected) {
    Http::fake(['api.linode.com/v4/regions*' => Http::response(CloudFixtures::with('linode/regions', ['data.0.country' => $country]))]);

    expect(cloudLinode()->regions()->first()->country)->toBe($expected);
})->with([
    'in lower case' => ['de', 'DE'],
    'in capitals already' => ['DE', 'DE'],
    'in mixed case' => ['De', 'DE'],
    'an empty country' => ['', null],
    'no country' => [null, null],
    'a country that is not text' => [49, null],
]);

test('linode skips a region that has no id', function (mixed $id) {
    Http::fake(['api.linode.com/v4/regions*' => Http::response(CloudFixtures::with('linode/regions', ['data.0.id' => $id]))]);

    expect(cloudLinode()->regions()->pluck('id')->all())->toBe(['us-east', 'id-cgk']);
})->with([
    'an empty id' => [''],
    'no id' => [null],
    'an id that is not text' => [42],
]);

test('linode passes over a list entry that is not an object', function (string $call, string $fixture, array $expected) {
    $body = CloudFixtures::json("linode/{$fixture}");
    array_unshift($body['data'], 'oops', null, 42);
    Http::fake(['api.linode.com/v4/*' => Http::response($body)]);

    expect(cloudLinode()->{$call}()->pluck('id')->all())->toBe($expected);
})->with([
    'regions' => ['regions', 'regions', ['eu-central', 'us-east', 'id-cgk']],
    'plans' => ['sizes', 'types', ['g6-nanode-1', 'g6-standard-1', 'g6-standard-2']],
    'images' => ['images', 'images', ['linode/ubuntu24.04', 'linode/ubuntu22.04', 'linode/debian12']],
]);

test('linode lists plans with their regional prices and disk in gigabytes', function () {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response(CloudFixtures::json('linode/types'))]);

    $sizes = cloudLinode()->sizes();
    $byId = $sizes->keyBy('id');

    expect($sizes->pluck('id')->all())->toBe(['g6-nanode-1', 'g6-standard-1', 'g6-standard-2'])
        ->and($byId->has('g1-gpu-rtx6000-1'))->toBeFalse();

    $nanode = $byId['g6-nanode-1'];
    expect($nanode->cpus)->toBe(1)
        ->and($nanode->memoryMb)->toBe(1024)
        ->and($nanode->diskGb)->toBe(25)
        ->and($nanode->architecture)->toBe('x86')
        ->and($nanode->price)->toEqual(new CloudPrice('5.00', 'USD'))
        ->and($nanode->prices)->toEqual(['id-cgk' => new CloudPrice('6.00', 'USD')])
        ->and($nanode->priceIn('id-cgk'))->toEqual(new CloudPrice('6.00', 'USD'))
        ->and($nanode->priceIn('eu-central'))->toEqual(new CloudPrice('5.00', 'USD'))
        ->and($nanode->regions)->toBeNull();

    $small = $byId['g6-standard-1'];
    expect($small->cpus)->toBe(1)
        ->and($small->memoryMb)->toBe(2048)
        ->and($small->diskGb)->toBe(50)
        ->and($small->architecture)->toBe('x86')
        ->and($small->price)->toEqual(new CloudPrice('12.00', 'USD'))
        ->and($small->priceIn('id-cgk')->monthly)->toBe('14.40')
        ->and($small->priceIn('id-cgk')->currency)->toBe('USD')
        ->and($small->priceIn('eu-central')->monthly)->toBe('12.00')
        ->and($small->regions)->toBeNull()
        ->and($small->availableIn('eu-central'))->toBeTrue()
        ->and($small->availableIn('id-cgk'))->toBeTrue();

    $large = $byId['g6-standard-2'];
    expect($large->cpus)->toBe(2)
        ->and($large->memoryMb)->toBe(4096)
        ->and($large->diskGb)->toBe(80)
        ->and($large->price)->toEqual(new CloudPrice('24.00', 'USD'))
        ->and($large->prices)->toBe([])
        ->and($large->priceIn('id-cgk'))->toEqual(new CloudPrice('24.00', 'USD'));

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.linode.com/v4/linode/types?page_size=500');
});

test('linode keeps the plan classes it provisions and no other', function (mixed $class, bool $kept) {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response(CloudFixtures::with('linode/types', ['data.1.class' => $class]))]);

    expect(cloudLinode()->sizes()->pluck('id')->contains('g6-standard-1'))->toBe($kept);
})->with([
    'nanode' => ['nanode', true],
    'standard' => ['standard', true],
    'dedicated' => ['dedicated', true],
    'high memory' => ['highmem', true],
    'premium' => ['premium', true],
    'gpu' => ['gpu', false],
    'accelerated' => ['accelerated', false],
    'a class in another case' => ['Standard', false],
    'a class that is not text' => [42, false],
    'a class that is a flag' => [true, false],
    'a class that is a list' => [['standard'], false],
    'no class' => [null, false],
]);

test('linode skips a plan that has no id', function (mixed $id) {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response(CloudFixtures::with('linode/types', ['data.0.id' => $id]))]);

    expect(cloudLinode()->sizes()->pluck('id')->all())->toBe(['g6-standard-1', 'g6-standard-2']);
})->with([
    'an empty id' => [''],
    'no id' => [null],
    'an id that is not text' => [42],
]);

test('linode skips a plan that leaves out how big it is', function (string $field) {
    $body = CloudFixtures::json('linode/types');
    unset($body['data'][1][$field]);
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response($body)]);

    expect(cloudLinode()->sizes()->pluck('id')->all())->toBe(['g6-nanode-1', 'g6-standard-2']);
})->with(['vcpus', 'memory', 'disk']);

test('linode skips a plan whose numbers are not numbers', function (string $field, mixed $value) {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response(CloudFixtures::with('linode/types', ["data.1.{$field}" => $value]))]);

    expect(cloudLinode()->sizes()->pluck('id')->all())->toBe(['g6-nanode-1', 'g6-standard-2']);
})->with([
    'cores that are null' => ['vcpus', null],
    'cores that are a word' => ['vcpus', 'many'],
    'memory that is a flag' => ['memory', true],
    'a disk that is a list' => ['disk', [51200]],
]);

test('linode keeps a plan that reports its numbers as digits', function () {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response(CloudFixtures::with('linode/types', [
        'data.2.vcpus' => '2',
        'data.2.memory' => '4096',
        'data.2.disk' => '81920',
        'data.2.price.monthly' => '24',
    ]))]);

    $large = cloudLinode()->sizes()->keyBy('id')['g6-standard-2'];

    expect($large->cpus)->toBe(2)
        ->and($large->memoryMb)->toBe(4096)
        ->and($large->diskGb)->toBe(80)
        ->and($large->price)->toEqual(new CloudPrice('24.00', 'USD'));
});

test('linode counts only the whole gigabytes of a disk', function (int $megabytes, int $gigabytes) {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response(CloudFixtures::with('linode/types', ['data.1.disk' => $megabytes]))]);

    expect(cloudLinode()->sizes()->keyBy('id')['g6-standard-1']->diskGb)->toBe($gigabytes);
})->with([
    'a whole number of gigabytes' => [51200, 50],
    'a little over' => [51201, 50],
    'just under the next' => [52223, 50],
    'the next one' => [52224, 51],
    'under a gigabyte' => [1000, 0],
]);

test('linode reads a plan with no price without inventing one', function () {
    $body = CloudFixtures::json('linode/types');
    unset($body['data'][1]['price']);
    $body['data'][2]['price']['monthly'] = 'free';
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response($body)]);

    $sizes = cloudLinode()->sizes()->keyBy('id');

    expect($sizes['g6-standard-1']->price)->toBeNull()
        ->and($sizes['g6-standard-1']->priceIn('id-cgk'))->toEqual(new CloudPrice('14.40', 'USD'))
        ->and($sizes['g6-standard-1']->priceIn('eu-central'))->toBeNull()
        ->and($sizes['g6-standard-2']->price)->toBeNull();
});

test('linode reads the regional price of every region a plan names and an unreadable one as unknown', function (mixed $prices, array $expected) {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response(CloudFixtures::with('linode/types', ['data.1.region_prices' => $prices]))]);

    expect(cloudLinode()->sizes()->keyBy('id')['g6-standard-1']->prices)->toEqual($expected);
})->with([
    'prices for several regions' => [
        [['id' => 'id-cgk', 'monthly' => 14.4], ['id' => 'br-gru', 'monthly' => 13.2]],
        ['id-cgk' => new CloudPrice('14.40', 'USD'), 'br-gru' => new CloudPrice('13.20', 'USD')],
    ],
    'a price given as digits' => [[['id' => 'id-cgk', 'monthly' => '14.4']], ['id-cgk' => new CloudPrice('14.40', 'USD')]],
    'entries without a region' => [[['monthly' => 14.4], ['id' => '', 'monthly' => 14.4], ['id' => 42, 'monthly' => 14.4]], []],
    'entries without a price' => [[['id' => 'id-cgk'], ['id' => 'br-gru', 'monthly' => 'free'], ['id' => 'us-sea', 'monthly' => null]], ['id-cgk' => null, 'br-gru' => null, 'us-sea' => null]],
    'entries that are not objects' => [['id-cgk', null, 42], []],
    'prices given as text' => ['id-cgk', []],
    'no prices' => [null, []],
]);

test('linode does not charge the default price in a region whose own price it cannot read', function () {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response(CloudFixtures::with('linode/types', [
        'data.1.region_prices' => [['id' => 'id-cgk', 'hourly' => null, 'monthly' => null], ['id' => 'br-gru', 'hourly' => 0.0198, 'monthly' => 13.2]],
    ]))]);

    $size = cloudLinode()->sizes()->keyBy('id')['g6-standard-1'];

    expect($size->price)->toEqual(new CloudPrice('12.00', 'USD'))
        ->and($size->priceIn('id-cgk'))->toBeNull()
        ->and($size->priceIn('br-gru'))->toEqual(new CloudPrice('13.20', 'USD'))
        ->and($size->priceIn('eu-central'))->toEqual(new CloudPrice('12.00', 'USD'))
        ->and($size->availableIn('id-cgk'))->toBeTrue();
});

test('linode reads every page of plans', function () {
    $types = CloudFixtures::json('linode/types')['data'];
    Http::fake([
        'api.linode.com/v4/linode/types?page_size=500' => Http::response(cloudLinodePage(array_slice($types, 0, 2), 1, 2)),
        'api.linode.com/v4/linode/types?page_size=500&page=2' => Http::response(cloudLinodePage(array_slice($types, 2), 2, 2)),
    ]);

    expect(cloudLinode()->sizes()->pluck('id')->all())->toBe(['g6-nanode-1', 'g6-standard-1', 'g6-standard-2']);

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.linode.com/v4/linode/types?page_size=500');
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.linode.com/v4/linode/types?page_size=500&page=2');
});

test('linode keeps its page size on every page of regions and images', function (string $call, string $fixture, array $expected) {
    $items = CloudFixtures::json("linode/{$fixture}")['data'];
    Http::fake([
        "api.linode.com/v4/{$fixture}?page_size=500" => Http::response(cloudLinodePage(array_slice($items, 0, 2), 1, 3)),
        "api.linode.com/v4/{$fixture}?page_size=500&page=2" => Http::response(cloudLinodePage(array_slice($items, 2, 1), 2, 3)),
        "api.linode.com/v4/{$fixture}?page_size=500&page=3" => Http::response(cloudLinodePage(array_slice($items, 3), 3, 3)),
    ]);

    expect(cloudLinode()->{$call}()->pluck('id')->all())->toBe($expected);

    Http::assertSentCount(3);
    Http::assertSent(fn(Request $request) => $request->uri()->query()->get('page') === '2' && $request->uri()->query()->get('page_size') === '500');
    Http::assertSent(fn(Request $request) => $request->uri()->query()->get('page') === '3' && $request->uri()->query()->get('page_size') === '500');
})->with([
    'regions' => ['regions', 'regions', ['eu-central', 'us-east', 'id-cgk']],
    'images' => ['images', 'images', ['linode/ubuntu24.04', 'linode/ubuntu22.04', 'linode/debian12']],
]);

test('linode keeps its image filter on every page of images', function () {
    $images = CloudFixtures::json('linode/images')['data'];
    Http::fake([
        'api.linode.com/v4/images?page_size=500' => Http::response(cloudLinodePage(array_slice($images, 0, 2), 1, 2)),
        'api.linode.com/v4/images?page_size=500&page=2' => Http::response(cloudLinodePage(array_slice($images, 2), 2, 2)),
    ]);

    expect(cloudLinode()->images()->pluck('id')->all())->toBe(['linode/ubuntu24.04', 'linode/ubuntu22.04', 'linode/debian12']);

    Http::assertSentCount(2);
    Http::recorded()->each(function (array $exchange) {
        expect($exchange[0]->header('X-Filter'))->toBe(['{"is_public":true}']);
    });
});

test('linode stops at a page count it cannot follow', function (mixed $pages) {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response(CloudFixtures::with('linode/types', ['pages' => $pages]))]);

    expect(cloudLinode()->sizes())->toHaveCount(3);

    Http::assertSentCount(1);
})->with([
    'one page' => [1],
    'no page count' => [null],
    'a page count as text' => ['3'],
    'a page count of zero' => [0],
    'a negative page count' => [-2],
    'a fractional page count' => [2.5],
    'a page count given as a list' => [[3]],
]);

test('linode counts its own pages, so an answer that repeats a page number cannot loop it', function () {
    $types = CloudFixtures::json('linode/types')['data'];
    Http::fake([
        'api.linode.com/v4/linode/types?page_size=500' => Http::response(cloudLinodePage(array_slice($types, 0, 2), 1, 2)),
        'api.linode.com/v4/linode/types?page_size=500&page=2' => Http::response(cloudLinodePage(array_slice($types, 2), 1, 2)),
    ]);

    expect(cloudLinode()->sizes()->pluck('id')->all())->toBe(['g6-nanode-1', 'g6-standard-1', 'g6-standard-2']);

    Http::assertSentCount(2);
});

test('linode reads a catalog answer without its list as unavailable, so an empty catalog is never taken for a real one', function (string $call) {
    Http::fake(['api.linode.com/v4/*' => Http::response(['unexpected' => true])]);

    expect(fn() => cloudLinode()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Linode had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with(['regions', 'sizes', 'images']);

test('linode reads a list that is not a list as unavailable', function (string $call, mixed $value) {
    Http::fake(['api.linode.com/v4/*' => Http::response(['data' => $value])]);

    expect(fn() => cloudLinode()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });
})->with([
    'regions that are null' => ['regions', null],
    'plans that are text' => ['sizes', 'none'],
    'images that are an object' => ['images', ['linode/ubuntu24.04' => ['id' => 'linode/ubuntu24.04']]],
    'regions that are an empty object' => ['regions', new stdClass],
    'plans that are an empty object' => ['sizes', new stdClass],
    'images that are an empty object' => ['images', new stdClass],
]);

test('linode fails a catalog read when a later page lacks the list', function () {
    $types = CloudFixtures::json('linode/types')['data'];
    Http::fake([
        'api.linode.com/v4/linode/types?page_size=500' => Http::response(cloudLinodePage(array_slice($types, 0, 2), 1, 2)),
        'api.linode.com/v4/linode/types?page_size=500&page=2' => Http::response(['unexpected' => true, 'page' => 2, 'pages' => 2]),
    ]);

    expect(fn() => cloudLinode()->sizes())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(2);
});

test('linode reads a catalog with an empty list as empty', function (string $call) {
    Http::fake(['api.linode.com/v4/*' => Http::response(cloudLinodePage([]))]);

    expect(cloudLinode()->{$call}()->all())->toBe([]);
})->with(['regions', 'sizes', 'images']);

test('linode does not verify a token, or read a catalog, from an answer that is not json', function (string $call) {
    Http::fake(['api.linode.com/*' => Http::response('<html><body>Gateway</body></html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn() => cloudLinode()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->detail)->toBe('answered without JSON');
    });

    Http::assertSentCount(1);
})->with(['verify', 'regions', 'sizes', 'images']);

test('linode lists public, current ubuntu and debian images', function () {
    Http::fake(['api.linode.com/v4/images*' => Http::response(CloudFixtures::json('linode/images'))]);

    $images = cloudLinode()->images();
    $byId = $images->keyBy('id');

    expect($images->pluck('id')->all())->toBe(['linode/ubuntu24.04', 'linode/ubuntu22.04', 'linode/debian12'])
        ->and($images->pluck('version')->all())->toBe(['24.04', '22.04', '12'])
        ->and($byId['linode/ubuntu24.04']->family)->toBe('ubuntu')
        ->and($byId['linode/ubuntu24.04']->architectures)->toBe(['x86'])
        ->and($byId['linode/ubuntu22.04']->family)->toBe('ubuntu')
        ->and($byId['linode/debian12']->family)->toBe('debian')
        ->and($byId['linode/debian12']->version)->toBe('12')
        ->and($byId['linode/debian12']->architectures)->toBe(['x86'])
        ->and($byId->has('linode/ubuntu20.04'))->toBeFalse()
        ->and($byId->has('linode/rocky9'))->toBeFalse();
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.linode.com/v4/images?page_size=500'
        && $request->header('X-Filter') === ['{"is_public":true}']);
});

test('linode skips images it does not provision', function (array $changes) {
    Http::fake(['api.linode.com/v4/images*' => Http::response(CloudFixtures::with('linode/images', $changes))]);

    expect(cloudLinode()->images()->pluck('id')->all())->toBe(['linode/ubuntu22.04', 'linode/debian12']);
})->with([
    'a deprecated image' => [['data.0.deprecated' => true]],
    'an image still being made' => [['data.0.status' => 'creating']],
    'an image waiting for an upload' => [['data.0.status' => 'pending_upload']],
    'a status in another case' => [['data.0.status' => 'Available']],
    'no status' => [['data.0.status' => null]],
    'another family' => [['data.0.id' => 'linode/alpine3.19']],
    'a private image' => [['data.0.id' => 'private/12345']],
    'an id without a prefix' => [['data.0.id' => 'ubuntu24.04']],
    'an id in another case' => [['data.0.id' => 'linode/Ubuntu24.04']],
    'an id with a suffix' => [['data.0.id' => 'linode/ubuntu24.04-arm']],
    'an id with a third number' => [['data.0.id' => 'linode/ubuntu24.04.1']],
    'an id with no version' => [['data.0.id' => 'linode/ubuntu']],
    'an id ending in a newline' => [['data.0.id' => "linode/ubuntu24.04\n"]],
    'an id that is not text' => [['data.0.id' => 42]],
    'no id' => [['data.0.id' => null]],
]);

test('linode reads the family and version out of an image id', function (string $id, string $family, string $version) {
    Http::fake(['api.linode.com/v4/images*' => Http::response(CloudFixtures::with('linode/images', ['data.0.id' => $id]))]);

    $image = cloudLinode()->images()->first();

    expect($image->id)->toBe($id)->and($image->family)->toBe($family)->and($image->version)->toBe($version);
})->with([
    'an ubuntu lts' => ['linode/ubuntu24.04', 'ubuntu', '24.04'],
    'an ubuntu interim release' => ['linode/ubuntu25.10', 'ubuntu', '25.10'],
    'a debian release' => ['linode/debian12', 'debian', '12'],
    'a later debian release' => ['linode/debian13', 'debian', '13'],
]);

test('linode skips an image once its end of life has passed', function (string $eol) {
    Http::fake(['api.linode.com/v4/images*' => Http::response(CloudFixtures::with('linode/images', ['data.1.eol' => $eol]))]);

    expect(cloudLinode()->images()->pluck('id')->all())->toBe(['linode/ubuntu24.04', 'linode/debian12']);
})->with([
    'a second ago' => ['2026-10-21T11:59:59'],
    'this very second' => ['2026-10-21T12:00:00'],
    'yesterday' => ['2026-10-20T12:00:00'],
    'years ago' => ['2020-01-01T00:00:00'],
    'a time with an offset, just passed' => ['2026-10-21T13:59:59+02:00'],
    'a time in universal time, just passed' => ['2026-10-21T11:59:59Z'],
]);

test('linode keeps an image whose end of life has not come yet, so an operating system does not vanish early', function (?string $eol) {
    Http::fake(['api.linode.com/v4/images*' => Http::response(CloudFixtures::with('linode/images', ['data.1.eol' => $eol]))]);

    expect(cloudLinode()->images()->pluck('id')->all())->toBe(['linode/ubuntu24.04', 'linode/ubuntu22.04', 'linode/debian12']);
})->with([
    'a second away' => ['2026-10-21T12:00:01'],
    'tomorrow' => ['2026-10-22T12:00:00'],
    'a year away' => ['2027-10-21T12:00:00'],
    'a time with an offset, just ahead' => ['2026-10-21T14:00:01+02:00'],
    'no planned end of life' => [null],
]);

test('linode reads an end of life without a zone as universal time', function () {
    $zone = date_default_timezone_get();
    date_default_timezone_set('Pacific/Auckland');

    try {
        Http::fake(['api.linode.com/v4/images*' => Http::response(CloudFixtures::with('linode/images', [
            'data.0.eol' => '2026-10-21T12:00:01',
            'data.1.eol' => '2026-10-21T11:59:59',
        ]))]);

        expect(cloudLinode()->images()->pluck('id')->all())->toBe(['linode/ubuntu24.04', 'linode/debian12']);
    } finally {
        date_default_timezone_set($zone);
    }
});

test('linode skips an image the provider marks deprecated, whatever its end of life', function (?string $eol) {
    Http::fake(['api.linode.com/v4/images*' => Http::response(CloudFixtures::with('linode/images', ['data.1.deprecated' => true, 'data.1.eol' => $eol]))]);

    expect(cloudLinode()->images()->pluck('id')->all())->toBe(['linode/ubuntu24.04', 'linode/debian12']);
})->with([
    'a date in the future' => ['2029-01-01T00:00:00'],
    'a date in the past' => ['2020-01-01T00:00:00'],
    'no date' => [null],
]);

test('linode keeps an image whose end of life it cannot read, since only a date that has passed counts', function (mixed $eol) {
    $this->travelTo(CarbonImmutable::parse('2100-01-01 00:00:00'));
    Http::fake(['api.linode.com/v4/images*' => Http::response(CloudFixtures::with('linode/images', [
        'data.0.eol' => null,
        'data.1.eol' => $eol,
        'data.2.eol' => null,
    ]))]);

    expect(cloudLinode()->images()->pluck('id')->all())->toBe(['linode/ubuntu24.04', 'linode/ubuntu22.04', 'linode/debian12']);
})->with([
    'text that is not a date' => ['soon'],
    'an empty date' => [''],
    'a date that is not text' => [20200101],
    'a date given as a list' => [['2020-01-01T00:00:00']],
    'a flag' => [true],
]);

test('linode has no key to upload or delete, since it takes the public key with the instance', function () {
    $api = cloudLinode();

    expect($api->uploadsKeys())->toBeFalse()
        ->and(fn() => $api->uploadKey('coresmith-outpost-02-b1n0d', cloudLinodeKey()))->toThrow(LogicException::class, 'Linode takes the public key with the instance')
        ->and(fn() => $api->deleteKey('42'))->toThrow(LogicException::class, 'Linode takes the public key with the instance');

    Http::assertNothingSent();
});

test('linode takes the public key inline and sets a strong root password it never keeps', function () {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::sequence()
        ->push(CloudFixtures::json('linode/instance-provisioning'))
        ->push(CloudFixtures::json('linode/error-type'), 400)]);
    $api = cloudLinode();

    $instance = $api->create(cloudLinodeSpec());

    [$post] = Http::recorded(fn(Request $request) => $request->method() === 'POST')->first();
    $body = $post->data();
    $password = $body['root_pass'];

    expect($api->uploadsKeys())->toBeFalse()
        ->and($post->method())->toBe('POST')
        ->and($post->url())->toBe('https://api.linode.com/v4/linode/instances')
        ->and(Arr::except($body, 'root_pass'))->toBe([
            'region' => 'eu-central',
            'type' => 'g6-standard-1',
            'image' => 'linode/ubuntu24.04',
            'label' => 'outpost-02',
            'authorized_keys' => [cloudLinodeKey()],
            'tags' => ['coresmith', 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d'],
            'booted' => true,
        ])
        ->and($password)->toBeString()->toHaveLength(48)
        ->and($instance)->toEqual(new CloudInstance('123456', CloudInstanceState::Starting, '203.0.113.43', 'g6-standard-1'))
        ->and(serialize($instance))->not->toContain($password)
        ->and(print_r($instance, true))->not->toContain($password)
        ->and((string) json_encode($instance))->not->toContain($password);

    $error = TraceSecrets::thrownBy(fn() => $api->create(cloudLinodeSpec()));
    $refused = Http::recorded()->last()[0]->data()['root_pass'];
    $own = array_filter(
        (new ReflectionClass(LinodeApi::class))->getProperties(),
        fn(ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === LinodeApi::class,
    );

    expect($refused)->toBeString()->toHaveLength(48)->not->toBe($password)
        ->and($error)->toBeInstanceOf(CloudProviderException::class)
        ->and($error->failure)->toBe(CloudFailure::Refused)
        ->and($error->detail)->toBe('type: Region does not support this Linode type')
        ->and($error->getMessage())->not->toContain($refused)->not->toContain($password)
        ->and($error->connectMessage())->not->toContain($refused)->not->toContain($password)
        ->and(TraceSecrets::held($error, $refused))->toBeFalse()
        ->and($error->getPrevious())->toBeNull()
        ->and($own)->toBe([]);
    Http::assertSentCount(2);
});

test('linode keeps the root password out of an exception from a create that could not connect', function () {
    Http::fake(['api.linode.com/*' => Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received')]);

    $error = TraceSecrets::thrownBy(fn() => cloudLinode()->create(cloudLinodeSpec()));
    $password = Http::recorded()->first()[0]->data()['root_pass'];

    expect($password)->toBeString()->toHaveLength(48)
        ->and($error)->toBeInstanceOf(CloudProviderException::class)
        ->and($error->failure)->toBe(CloudFailure::Unreachable)
        ->and($error->getMessage())->not->toContain($password)
        ->and($error->getPrevious()->getMessage())->toBe('cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received')
        ->and(TraceSecrets::held($error, $password))->toBeFalse();
    Http::assertSentCount(1);
});

test('linode will not create an instance without a key to put on it', function (string $publicKey) {
    $spec = new CloudServerSpec('outpost-02', 'eu-central', 'g6-standard-1', 'linode/ubuntu24.04', cloudLinodeTag(), $publicKey, null);

    expect(fn() => cloudLinode()->create($spec))->toThrow(LogicException::class, 'needs a public key');

    Http::assertNothingSent();
})->with([
    'an empty key' => [''],
    'a key of whitespace' => [" \t\n"],
]);

test('linode makes a new strong root password for every instance', function () {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(CloudFixtures::json('linode/instance-provisioning'))]);
    $api = cloudLinode();

    $api->create(cloudLinodeSpec());
    $api->create(cloudLinodeSpec());

    $passwords = Http::recorded()
        ->map(fn(array $exchange): string => $exchange[0]->data()['root_pass'])
        ->values()
        ->all();

    expect($passwords)->toHaveCount(2)->and($passwords[0])->not->toBe($passwords[1]);

    foreach ($passwords as $password) {
        expect($password)->toHaveLength(48)
            ->and(preg_match('/[A-Za-z]/D', $password))->toBe(1)
            ->and(preg_match('/\d/D', $password))->toBe(1)
            ->and(preg_match('/[^A-Za-z0-9]/D', $password))->toBe(1);
    }
});

test('linode uses the public address when a private one is listed first', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::json('linode/instance-running'))]);

    $found = cloudLinode()->find('123456');

    expect($found->ipv4)->toBe('203.0.113.43')
        ->and($found->ipv4)->not->toBe('192.168.128.10')
        ->and($found->isReady())->toBeTrue();
});

test('linode uses the public address wherever the instance lists it', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::with('linode/instance-running', [
        'ipv4' => ['203.0.113.43', '192.168.128.10'],
    ]))]);

    expect(cloudLinode()->find('123456')->ipv4)->toBe('203.0.113.43');
});

test('linode reads an instance with no usable public address as not ready', function (mixed $addresses) {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::with('linode/instance-running', ['ipv4' => $addresses]))]);

    $found = cloudLinode()->find('123456');

    expect($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBeNull()
        ->and($found->isReady())->toBeFalse();
})->with([
    'a private address only' => [['192.168.128.10']],
    'an empty list' => [[]],
    'no addresses' => [null],
    'a single address given as text' => ['203.0.113.43'],
    'entries that are not addresses' => [['pending', '', null, 42, ['203.0.113.43']]],
    'an ipv6 address' => [['2001:db8::1']],
    'an address with a newline after it' => [["203.0.113.43\n"]],
]);

test('linode creates an instance that is not ready until it runs', function () {
    Http::fake([
        'api.linode.com/v4/linode/instances' => Http::response(CloudFixtures::json('linode/instance-provisioning')),
        'api.linode.com/v4/linode/instances/123456' => Http::sequence()
            ->push(CloudFixtures::json('linode/instance-provisioning'))
            ->push(CloudFixtures::json('linode/instance-running')),
    ]);
    $api = cloudLinode();

    $created = $api->create(cloudLinodeSpec());
    $provisioning = $api->find('123456');
    $running = $api->find('123456');

    expect($created->id)->toBe('123456')
        ->and($created->state)->toBe(CloudInstanceState::Starting)
        ->and($created->isReady())->toBeFalse()
        ->and($provisioning->state)->toBe(CloudInstanceState::Starting)
        ->and($provisioning->isReady())->toBeFalse()
        ->and($running->id)->toBe('123456')
        ->and($running->state)->toBe(CloudInstanceState::Running)
        ->and($running->size)->toBe('g6-standard-1')
        ->and($running->isReady())->toBeTrue();
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.linode.com/v4/linode/instances/123456');
});

test('linode needs no uploaded key id to create an instance', function (?string $keyId) {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(CloudFixtures::json('linode/instance-provisioning'))]);
    $spec = new CloudServerSpec('outpost-02', 'eu-central', 'g6-standard-1', 'linode/ubuntu24.04', cloudLinodeTag(), cloudLinodeKey(), $keyId);

    expect(cloudLinode()->create($spec)->id)->toBe('123456');

    Http::assertSent(fn(Request $request) => $request->data()['authorized_keys'] === [cloudLinodeKey()] && !isset($request->data()['sshkey_id']));
})->with([
    'none' => [null],
    'one it was given anyway' => ['42'],
]);

test('linode reads an instance that is not there as nothing', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::json('linode/error-not-found'), 404)]);

    expect(cloudLinode()->find('123456'))->toBeNull();

    Http::assertSentCount(1);
});

test('linode reads the size of an instance from its type', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::sequence()
        ->push(CloudFixtures::with('linode/instance-running', ['type' => 'g6-standard-2']))
        ->push(CloudFixtures::with('linode/instance-running', ['type' => '']))]);
    $api = cloudLinode();

    expect($api->find('123456')->size)->toBe('g6-standard-2')
        ->and($api->find('123456')->size)->toBeNull();
});

test('linode finds an instance by tag', function () {
    Http::fake(['api.linode.com/v4/linode/instances*' => Http::sequence()
        ->push(CloudFixtures::json('linode/instances-tagged'))
        ->push(cloudLinodePage([]))]);
    $api = cloudLinode();

    $found = $api->findTagged(cloudLinodeTag());

    expect($found->id)->toBe('123456')
        ->and($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBe('203.0.113.43')
        ->and($found->size)->toBe('g6-standard-1')
        ->and($api->findTagged(cloudLinodeTag()))->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.linode.com/v4/linode/instances'
        && $request->header('X-Filter') === ['{"tags":"coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d"}']);
});

test('linode takes the first instance when a tag matches several', function () {
    $body = CloudFixtures::json('linode/instances-tagged');
    $second = $body['data'][0];
    $second['id'] = 123457;
    $body['data'][] = $second;
    Http::fake(['api.linode.com/v4/linode/instances*' => Http::response($body)]);

    expect(cloudLinode()->findTagged(cloudLinodeTag())->id)->toBe('123456');
});

test('linode returns the instance that carries the tag itself, not just the first one listed', function () {
    $body = CloudFixtures::with('linode/instances-tagged');
    $mine = $body['data'][0];
    $elsewhere = CloudFixtures::with('linode/instances-tagged', ['data.0.id' => 123457, 'data.0.tags' => ['coresmith', 'coresmith-01jother']])['data'][0];
    $untagged = CloudFixtures::with('linode/instances-tagged', ['data.0.id' => 123458, 'data.0.tags' => []])['data'][0];
    $body['data'] = [$elsewhere, $untagged, 'oops', $mine];
    Http::fake(['api.linode.com/v4/linode/instances*' => Http::response($body)]);

    expect(cloudLinode()->findTagged(cloudLinodeTag())->id)->toBe('123456');
});

test('linode fails a tag lookup that lists instances but none that carries the tag, so an unrelated instance is never handed back', function (mixed $tags) {
    Http::fake(['api.linode.com/v4/linode/instances*' => Http::response(CloudFixtures::with('linode/instances-tagged', ['data.0.tags' => $tags]))]);

    expect(fn() => cloudLinode()->findTagged(cloudLinodeTag()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Linode had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'another tag' => [['coresmith', 'coresmith-01jother']],
    'only the shared tag' => [['coresmith']],
    'the tag with more after it' => [['coresmith', 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0dx']],
    'the tag in another case' => [['coresmith', 'CORESMITH-01JCP7M3ZQ9X4V6T8W2Y5B1N0D']],
    'no tags' => [[]],
    'tags given as text' => ['coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d'],
    'tags that are null' => [null],
    'a flag among the tags' => [[true]],
    'a list among the tags' => [[['coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d']]],
]);

test('linode does not take an empty object for an empty list of instances', function () {
    Http::fake(['api.linode.com/v4/linode/instances*' => Http::response('{"data":{},"page":1,"pages":1,"results":0}', 200, ['Content-Type' => 'application/json'])]);

    expect(fn() => cloudLinode()->findTagged(cloudLinodeTag()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Linode had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
});

test('linode maps every status', function (mixed $status, CloudInstanceState $state) {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::with('linode/instance-running', ['status' => $status]))]);

    expect(cloudLinode()->find('123456')->state)->toBe($state);
})->with([
    'provisioning' => ['provisioning', CloudInstanceState::Starting],
    'booting' => ['booting', CloudInstanceState::Starting],
    'running' => ['running', CloudInstanceState::Running],
    'offline' => ['offline', CloudInstanceState::Stopped],
    'stopped' => ['stopped', CloudInstanceState::Stopped],
    'shutting down' => ['shutting_down', CloudInstanceState::Busy],
    'rebooting' => ['rebooting', CloudInstanceState::Busy],
    'migrating' => ['migrating', CloudInstanceState::Busy],
    'rebuilding' => ['rebuilding', CloudInstanceState::Busy],
    'cloning' => ['cloning', CloudInstanceState::Busy],
    'restoring' => ['restoring', CloudInstanceState::Busy],
    'resizing' => ['resizing', CloudInstanceState::Busy],
    'busy' => ['busy', CloudInstanceState::Busy],
    'deleting' => ['deleting', CloudInstanceState::Deleting],
    'suspended for billing' => ['billing_suspension', CloudInstanceState::Unknown],
    'a status it has not heard of' => ['something-new', CloudInstanceState::Unknown],
    'a status in another case' => ['Running', CloudInstanceState::Unknown],
    'a status that is not text' => [42, CloudInstanceState::Unknown],
    'no status' => [null, CloudInstanceState::Unknown],
]);

test('linode reads an instance with no status as unknown', function () {
    $body = CloudFixtures::json('linode/instance-running');
    unset($body['status']);
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response($body)]);

    expect(cloudLinode()->find('123456')->state)->toBe(CloudInstanceState::Unknown);
});

test('linode accepts an id it is given as text', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::with('linode/instance-running', ['id' => '123456']))]);

    expect(cloudLinode()->find('123456')->id)->toBe('123456');
});

test('linode reads an id that is not a whole number as unavailable, so the tag is checked before anything is made again', function (string $call, mixed $id) {
    Http::fake(match ($call) {
        'create' => ['api.linode.com/v4/linode/instances' => Http::response(CloudFixtures::with('linode/instance-provisioning', ['id' => $id]))],
        'find' => ['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::with('linode/instance-running', ['id' => $id]))],
        'findTagged' => ['api.linode.com/v4/linode/instances' => Http::response(CloudFixtures::with('linode/instances-tagged', ['data.0.id' => $id]))],
    });
    $api = cloudLinode();
    $attempt = match ($call) {
        'create' => fn() => $api->create(cloudLinodeSpec()),
        'find' => fn() => $api->find('123456'),
        'findTagged' => fn() => $api->findTagged(cloudLinodeTag()),
    };

    expect($attempt)->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Linode had a problem answering. Try again in a minute.');
    });
})->with(['create', 'find', 'findTagged'])->with([
    'no id' => [null],
    'zero' => [0],
    'a negative number' => [-123456],
    'a fraction' => [123456.5],
    'a flag' => [true],
    'a word' => ['abc'],
    'empty text' => [''],
    'a path' => ['../123456'],
    'a list' => [[123456]],
    'digits with a leading zero' => ['0123456'],
    'digits with a newline after' => ["123456\n"],
    'digits with a space before' => [' 123456'],
    'a uuid' => ['cb676a46-66fd-4dfb-b839-443f2e6c0b60'],
]);

test('linode resizes without touching the disk', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456/*' => Http::response(CloudFixtures::json('linode/empty'))]);
    $api = cloudLinode();

    $api->reboot('123456');
    $api->powerOff('123456');
    $api->powerOn('123456');
    $api->resize('123456', 'g6-standard-2');

    $sent = Http::recorded()
        ->map(fn(array $exchange): array => [$exchange[0]->method(), $exchange[0]->url(), $exchange[0]->body()])
        ->values()
        ->all();

    expect($sent)->toBe([
        ['POST', 'https://api.linode.com/v4/linode/instances/123456/reboot', '{}'],
        ['POST', 'https://api.linode.com/v4/linode/instances/123456/shutdown', '{}'],
        ['POST', 'https://api.linode.com/v4/linode/instances/123456/boot', '{}'],
        ['POST', 'https://api.linode.com/v4/linode/instances/123456/resize', '{"type":"g6-standard-2","allow_auto_disk_resize":false,"migration_type":"cold"}'],
    ])
        ->and($api->uploadsKeys())->toBeFalse()
        ->and($api->resizeNeedsPowerOff())->toBeFalse();
});

test('linode accepts an action, a resize or a delete that answers with no body', function () {
    Http::fake(['api.linode.com/v4/*' => Http::response('', 204)]);
    $api = cloudLinode();

    $api->reboot('123456');
    $api->powerOff('123456');
    $api->powerOn('123456');
    $api->resize('123456', 'g6-standard-2');
    $api->destroy('123456');

    Http::assertSentCount(5);
});

test('linode deletes an instance it holds', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::json('linode/empty'))]);

    cloudLinode()->destroy('123456');

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linode.com/v4/linode/instances/123456');
});

test('linode does not take a not found page for an instance that is gone', function (string $call) {
    Http::fake(['api.linode.com/v4/*' => Http::response('<html><body>404 Not Found</body></html>', 404, ['Content-Type' => 'text/html'])]);

    expect(fn() => cloudLinode()->{$call}('123456'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->status)->toBe(404)
            ->and($e->getMessage())->toBe('Linode had a problem answering (HTTP 404). Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with(['find', 'destroy']);

test('linode treats an instance that is already gone as destroyed', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::json('linode/error-not-found'), 404)]);

    cloudLinode()->destroy('123456');

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.linode.com/v4/linode/instances/123456');
});

test('linode still fails a delete that is refused', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456' => Http::response(CloudFixtures::json('linode/error-unauthorized'), 403)]);

    expect(fn() => cloudLinode()->destroy('123456'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)->and($e->detail)->toBe('Unauthorized');
    });

    Http::assertSentCount(1);
});

test('linode refuses an id that names nothing before it sends anything', function (string $call, string $id) {
    $arguments = $call === 'resize' ? [$id, 'g6-standard-2'] : [$id];

    expect(fn() => cloudLinode()->{$call}(...$arguments))->toThrow(LogicException::class, 'A cloud id must name one resource.');

    Http::assertNothingSent();
})->with(['find', 'reboot', 'powerOff', 'powerOn', 'resize', 'destroy'])->with(['', ' ', '.', '..']);

test('linode keeps an id inside its own path segment', function (string $call, array $arguments, string $method, string $url) {
    Http::fake(['api.linode.com/*' => Http::response(['id' => 7, 'status' => 'running'])]);

    cloudLinode()->{$call}(...$arguments);

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === $method && $request->url() === $url);
})->with([
    'find' => ['find', ['../7'], 'GET', 'https://api.linode.com/v4/linode/instances/..%2F7'],
    'reboot' => ['reboot', ['../7'], 'POST', 'https://api.linode.com/v4/linode/instances/..%2F7/reboot'],
    'power off' => ['powerOff', ['../7'], 'POST', 'https://api.linode.com/v4/linode/instances/..%2F7/shutdown'],
    'power on' => ['powerOn', ['../7'], 'POST', 'https://api.linode.com/v4/linode/instances/..%2F7/boot'],
    'resize' => ['resize', ['../7', 'g6-standard-2'], 'POST', 'https://api.linode.com/v4/linode/instances/..%2F7/resize'],
    'destroy' => ['destroy', ['../account/tokens/7'], 'DELETE', 'https://api.linode.com/v4/linode/instances/..%2Faccount%2Ftokens%2F7'],
]);

test('linode names the field it refused', function () {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(CloudFixtures::json('linode/error-type'), 400)]);

    expect(fn() => cloudLinode()->create(cloudLinodeSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)
            ->and($e->retryable())->toBeFalse()
            ->and($e->status)->toBe(400)
            ->and($e->detail)->toBe('type: Region does not support this Linode type')
            ->and($e->getMessage())->toBe('Linode refused the request. Linode said: type: Region does not support this Linode type.');
    });

    Http::assertSentCount(1);
});

test('linode gives the reason alone when no field is named', function () {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(['errors' => [['reason' => 'Account limit reached. Please open a support ticket.']]], 400)]);

    expect(fn() => cloudLinode()->create(cloudLinodeSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)
            ->and($e->detail)->toBe('Account limit reached. Please open a support ticket.')
            ->and($e->getMessage())->toBe('Linode refused the request. Linode said: Account limit reached. Please open a support ticket.');
    });
});

test('linode reads the first error when it lists several', function () {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(['errors' => [
        ['reason' => 'Region does not support this Linode type', 'field' => 'type'],
        ['reason' => 'Label is already in use', 'field' => 'label'],
    ]], 400)]);

    expect(fn() => cloudLinode()->create(cloudLinodeSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->detail)->toBe('type: Region does not support this Linode type');
    });
});

test('linode ignores a field that is not text', function (mixed $field) {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(['errors' => [['reason' => 'Region does not support this Linode type', 'field' => $field]]], 400)]);

    expect(fn() => cloudLinode()->create(cloudLinodeSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->detail)->toBe('Region does not support this Linode type');
    });
})->with([
    'an empty field' => [''],
    'no field' => [null],
    'a field that is a number' => [42],
    'a field that is a list' => [['type']],
]);

test('linode leaves out what the provider said when it says nothing it can use', function (mixed $errors) {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(['errors' => $errors], 400)]);

    expect(fn() => cloudLinode()->create(cloudLinodeSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->detail)->toBeNull();
    });
})->with([
    'no errors' => [[]],
    'an error with nothing in it' => [[[]]],
    'an error with only a field' => [[['field' => 'type']]],
    'a reason that is a list' => [[['reason' => ['bad'], 'field' => 'type']]],
    'a reason that is a number' => [[['reason' => 42]]],
    'an empty reason' => [[['reason' => '', 'field' => 'type']]],
    'an error that is text' => [['bad']],
    'errors that are text' => ['bad'],
    'errors that are an object' => [['reason' => 'bad']],
]);

test('linode says a token without access to linodes cannot create', function () {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(CloudFixtures::json('linode/error-unauthorized'), 403)]);

    expect(fn() => cloudLinode()->create(cloudLinodeSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)
            ->and($e->retryable())->toBeFalse()
            ->and($e->status)->toBe(403)
            ->and($e->detail)->toBe('Unauthorized')
            ->and($e->getMessage())->toBe("Linode says this token isn't allowed to do that. Linode said: Unauthorized. Give it read/write access to Linodes and read access to Images. Then replace the token in Settings, Team.");
    });

    Http::assertSentCount(1);
});

test('linode reads a token the endpoint does not allow as forbidden, not as a rejected one', function (string $call) {
    Http::fake(['api.linode.com/v4/*' => Http::response(['errors' => [['reason' => 'Your OAuth token is not authorized to use this endpoint.']]], 401)]);
    $attempt = match ($call) {
        'images' => fn() => cloudLinode()->images(),
        'regions' => fn() => cloudLinode()->regions(),
        'find' => fn() => cloudLinode()->find('123456'),
        'create' => fn() => cloudLinode()->create(cloudLinodeSpec()),
        'reboot' => fn() => cloudLinode()->reboot('123456'),
        'destroy' => fn() => cloudLinode()->destroy('123456'),
    };

    expect($attempt)->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)
            ->and($e->retryable())->toBeFalse()
            ->and($e->status)->toBe(401)
            ->and($e->detail)->toBe('Your OAuth token is not authorized to use this endpoint.')
            ->and($e->getMessage())->toBe("Linode says this token isn't allowed to do that. Linode said: Your OAuth token is not authorized to use this endpoint. Give it read/write access to Linodes and read access to Images. Then replace the token in Settings, Team.")
            ->and($e->connectMessage())->toBe("Linode accepted the token, but it isn't allowed to manage servers. Linode said: Your OAuth token is not authorized to use this endpoint. Give it read/write access to Linodes and read access to Images.");
    });

    Http::assertSentCount(1);
})->with(['images', 'regions', 'find', 'create', 'reboot', 'destroy']);

test('linode reads the words of an endpoint the token may not use in any case, and with more said', function (string $reason) {
    Http::fake(['api.linode.com/v4/profile' => Http::response(['errors' => [['reason' => $reason]]], 401)]);

    expect(fn() => cloudLinode()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden);
    });
})->with([
    'in capitals' => ['YOUR OAUTH TOKEN IS NOT AUTHORIZED TO USE THIS ENDPOINT.'],
    'without the full stop' => ['Your OAuth token is not authorized to use this endpoint'],
    'with more said' => ['Your OAuth token is not authorized to use this endpoint. Check its scopes.'],
    'about the endpoint in other words' => ['This token is not authorized to use the endpoint.'],
]);

test('linode keeps every other refusal what it was', function (int $status, mixed $reason, CloudFailure $failure) {
    Http::fake(['api.linode.com/v4/profile' => Http::response(['errors' => [['reason' => $reason]]], $status)]);

    expect(fn() => cloudLinode()->verify())->toThrow(function (CloudProviderException $e) use ($failure) {
        expect($e->failure)->toBe($failure);
    });
})->with([
    'an invalid token' => [401, 'Invalid Token', CloudFailure::TokenRejected],
    'an unauthorized answer' => [401, 'Unauthorized', CloudFailure::TokenRejected],
    'a 401 with no reason' => [401, null, CloudFailure::TokenRejected],
    'a 401 whose reason is not text' => [401, ['Your OAuth token is not authorized to use this endpoint.'], CloudFailure::TokenRejected],
    'a 401 about a busy linode' => [401, 'Linode busy.', CloudFailure::TokenRejected],
    'the words of an unusable endpoint on a 400' => [400, 'Your OAuth token is not authorized to use this endpoint.', CloudFailure::Refused],
    'the words of an unusable endpoint on a 422' => [422, 'Your OAuth token is not authorized to use this endpoint.', CloudFailure::Refused],
    'the words of an unusable endpoint on a 403' => [403, 'Your OAuth token is not authorized to use this endpoint.', CloudFailure::Forbidden],
    'a 400 about a bad field' => [400, 'Region does not support this Linode type', CloudFailure::Refused],
    'a 400 about something else being busy' => [400, 'The load balancer is busy.', CloudFailure::Refused],
    'the words of a busy linode on a 409' => [409, 'Linode busy.', CloudFailure::Refused],
    'the words of a busy linode on a 422' => [422, 'Linode busy.', CloudFailure::Refused],
    'the words of a busy linode on a 503' => [503, 'Linode busy.', CloudFailure::Unavailable],
]);

test('linode reads a linode with a job in progress as busy, so a retry waits for it', function (string $call, array $arguments) {
    Http::fake(['api.linode.com/v4/linode/instances/123456*' => Http::response(['errors' => [['reason' => 'Linode busy.']]], 400)]);

    expect(fn() => cloudLinode()->{$call}(...$arguments))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Busy)
            ->and($e->retryable())->toBeTrue()
            ->and($e->status)->toBe(400)
            ->and($e->detail)->toBe('Linode busy.')
            ->and($e->getMessage())->toBe('Linode is busy with another change to it. Linode said: Linode busy. Coresmith tries again shortly.');
    });

    Http::assertSentCount(1);
})->with([
    'reboot' => ['reboot', ['123456']],
    'power off' => ['powerOff', ['123456']],
    'power on' => ['powerOn', ['123456']],
    'resize' => ['resize', ['123456', 'g6-standard-2']],
    'destroy' => ['destroy', ['123456']],
]);

test('linode reads the words of a busy linode in any case, and with more said', function (string $reason) {
    Http::fake(['api.linode.com/v4/linode/instances/123456/reboot' => Http::response(['errors' => [['reason' => $reason]]], 400)]);

    expect(fn() => cloudLinode()->reboot('123456'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Busy);
    });
})->with([
    'in capitals' => ['LINODE BUSY.'],
    'without the full stop' => ['Linode busy'],
    'with more said' => ['Linode busy. Wait for its current job to finish.'],
]);

test('linode reads a field with a busy linode in its reason as busy too', function () {
    Http::fake(['api.linode.com/v4/linode/instances/123456/resize' => Http::response(['errors' => [['reason' => 'Linode busy.', 'field' => 'type']]], 400)]);

    expect(fn() => cloudLinode()->resize('123456', 'g6-standard-2'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Busy)
            ->and($e->detail)->toBe('type: Linode busy.');
    });
});

test('linode never retries a create or a resize that met a server error', function (string $call) {
    Http::fake(['api.linode.com/v4/linode/instances*' => Http::response(['errors' => [['reason' => 'Service unavailable']]], 503)]);
    $attempt = match ($call) {
        'create' => fn() => cloudLinode()->create(cloudLinodeSpec()),
        'resize' => fn() => cloudLinode()->resize('123456', 'g6-standard-2'),
    };

    expect($attempt)->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->status)->toBe(503)
            ->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(1);
})->with(['create', 'resize']);

test('linode reads a rate limit as one to wait out', function () {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(['errors' => [['reason' => 'Rate limit exceeded.']]], 429)]);

    expect(fn() => cloudLinode()->create(cloudLinodeSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::RateLimited)
            ->and($e->retryable())->toBeTrue()
            ->and($e->detail)->toBe('Rate limit exceeded.');
    });

    Http::assertSentCount(1);
});

test('linode reads a create whose answer has no instance in it as unavailable, so its tag is checked before another is made', function (string $body, array $headers) {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response($body, 200, $headers)]);

    expect(fn() => cloudLinode()->create(cloudLinodeSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('Linode had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'a json object without an id' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'an instance with no id' => ['{"label":"outpost-02","status":"provisioning"}', ['Content-Type' => 'application/json']],
    'an instance inside another object' => ['{"linode":{"id":123456,"status":"provisioning"}}', ['Content-Type' => 'application/json']],
    'a list of instances' => ['[{"id":123456,"status":"provisioning"}]', ['Content-Type' => 'application/json']],
    'an empty body' => ['', []],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
]);

test('linode does not mistake a lookup it cannot read for an instance that is not there', function (string $body, array $headers) {
    Http::fake(['api.linode.com/v4/linode/instances*' => Http::response($body, 200, $headers)]);
    $api = cloudLinode();

    foreach ([fn() => $api->find('123456'), fn() => $api->findTagged(cloudLinodeTag())] as $lookup) {
        expect($lookup)->toThrow(function (CloudProviderException $e) {
            expect($e->failure)->toBe(CloudFailure::Unavailable)
                ->and($e->getMessage())->toBe('Linode had a problem answering. Try again in a minute.');
        });
    }

    Http::assertSentCount(2);
})->with([
    'a json object without the data' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
    'nothing at all' => ['', []],
]);

test('linode says when a refused create is a label already in use', function (array $error, ?CloudRefusal $refusal) {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(['errors' => [$error]], 400)]);

    expect(fn() => cloudLinode()->create(cloudLinodeSpec()))->toThrow(function (CloudProviderException $e) use ($refusal) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->refusal)->toBe($refusal);
    });
})->with([
    'a label in use' => [['field' => 'label', 'reason' => 'Label must be unique among your Linodes'], CloudRefusal::NameTaken],
    'a label in use, in capitals' => [['field' => 'label', 'reason' => 'LABEL MUST BE UNIQUE'], CloudRefusal::NameTaken],
    'a label it will not take for another reason' => [['field' => 'label', 'reason' => 'Label must be between 3 and 64 characters'], null],
    'something else that must be unique' => [['field' => 'root_pass', 'reason' => 'Password must be unique'], null],
    'no field' => [['reason' => 'Label must be unique among your Linodes'], null],
]);
