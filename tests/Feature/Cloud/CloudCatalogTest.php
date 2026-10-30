<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudProviderType;
use App\Exceptions\CloudProviderException;
use App\Models\CloudProvider;
use App\Services\Cloud\CatalogData;
use App\Services\Cloud\CloudCatalog;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\HttpCloudApiFactory;
use App\Services\Cloud\Providers\DigitalOceanApi;
use App\Services\Cloud\Providers\HetznerApi;
use App\Services\Cloud\Providers\LinodeApi;
use App\Services\Cloud\Providers\VultrApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\CloudFixtures;
use Tests\Support\FakeCloudApi;
use Tests\Support\FakeCloudApiFactory;

beforeEach(fn() => Http::preventStrayRequests());

const CLOUD_CATALOG_LOCATIONS = 'api.hetzner.cloud/v1/locations';
const CLOUD_CATALOG_TYPES = 'api.hetzner.cloud/v1/server_types?per_page=50';
const CLOUD_CATALOG_IMAGES = 'api.hetzner.cloud/v1/images?type=system&status=available&per_page=50';

function cloudCatalogAccount(array $attributes = []): CloudProvider
{
    return CloudProvider::factory()->create($attributes);
}

function cloudCatalogHetznerFakes(array $replacements = []): void
{
    Http::fake([
        CLOUD_CATALOG_LOCATIONS => Http::response(CloudFixtures::json('hetzner/locations')),
        CLOUD_CATALOG_TYPES => Http::response(CloudFixtures::json('hetzner/server_types-1')),
        CLOUD_CATALOG_TYPES . '&page=2' => Http::response(CloudFixtures::json('hetzner/server_types-2')),
        CLOUD_CATALOG_IMAGES => Http::response(CloudFixtures::json('hetzner/images')),
        ...$replacements,
    ]);
}

function cloudCatalogHetzner(): CatalogData
{
    cloudCatalogHetznerFakes();

    return app(CloudCatalog::class)->for(cloudCatalogAccount());
}

function cloudCatalogPrices(array $amounts, string $currency = 'USD'): array
{
    return array_map(fn(?string $monthly): ?CloudPrice => $monthly === null ? null : new CloudPrice($monthly, $currency), $amounts);
}

function cloudCatalogSize(
    string $id,
    int $memoryMb = 4096,
    int $diskGb = 40,
    ?string $price = '5.00',
    string $architecture = 'x86',
    ?array $regions = null,
    array $prices = [],
): CloudSize {
    return new CloudSize($id, 2, $memoryMb, $diskGb, $architecture, $price === null ? null : new CloudPrice($price, 'USD'), cloudCatalogPrices($prices), $regions);
}

function cloudCatalogRegion(string $id, ?string $label = null): CloudRegion
{
    return new CloudRegion($id, $label ?? ucfirst($id) . " ({$id})");
}

function cloudCatalogImage(string $family, string $version, array $architectures = ['x86', 'arm']): CloudImage
{
    return new CloudImage("{$family}-{$version}", $family, $version, $architectures);
}

function cloudCatalogData(array $regions, array $sizes, array $images = []): CatalogData
{
    return new CatalogData(new Collection($regions), new Collection($sizes), new Collection($images));
}

function cloudCatalogServe(array $regions, array $sizes, array $images = []): CatalogData
{
    app()->instance(CloudApiFactory::class, new FakeCloudApiFactory((new FakeCloudApi)->catalog($regions, $sizes, $images)));

    return app(CloudCatalog::class)->for(cloudCatalogAccount());
}

function cloudCatalogIds(Collection $items): array
{
    return $items->pluck('id')->all();
}

test('each provider type is built as its own client', function (CloudProviderType $type, string $class) {
    Http::fake(['*' => Http::response(['locations' => [], 'regions' => [], 'data' => [], 'pages' => 1])]);
    $factory = app(CloudApiFactory::class);
    $account = cloudCatalogAccount(['type' => $type->value, 'api_token' => 'account-token']);

    $fromAccount = $factory->for($account);
    $fromToken = $factory->make($type, 'pasted-token');
    $fromAccount->regions();
    $fromToken->regions();

    expect($factory)->toBeInstanceOf(HttpCloudApiFactory::class)
        ->and($fromAccount)->toBeInstanceOf($class)
        ->and($fromToken)->toBeInstanceOf($class)
        ->and($fromAccount->type())->toBe($type)
        ->and($fromToken->type())->toBe($type);
    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === $type->apiHost()
        && $request->hasHeader('Authorization', 'Bearer account-token'));
    Http::assertSent(fn(Request $request): bool => parse_url($request->url(), PHP_URL_HOST) === $type->apiHost()
        && $request->hasHeader('Authorization', 'Bearer pasted-token'));
})->with([
    'hetzner' => [CloudProviderType::Hetzner, HetznerApi::class],
    'digitalocean' => [CloudProviderType::DigitalOcean, DigitalOceanApi::class],
    'vultr' => [CloudProviderType::Vultr, VultrApi::class],
    'linode' => [CloudProviderType::Linode, LinodeApi::class],
]);

test('the factory marks the token it is given as sensitive', function () {
    $parameter = (new ReflectionMethod(HttpCloudApiFactory::class, 'make'))->getParameters()[1];

    expect($parameter->getName())->toBe('token')
        ->and($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1)
        ->and((new ReflectionClass(HttpCloudApiFactory::class))->isFinal())->toBeTrue();
});

test('an account with no token stored cannot be used', function (?string $token) {
    $account = cloudCatalogAccount(['type' => CloudProviderType::Linode->value, 'api_token' => $token]);

    expect(fn() => app(CloudApiFactory::class)->for($account))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::TokenRejected)
            ->and($e->provider)->toBe(CloudProviderType::Linode)
            ->and($e->retryable())->toBeFalse()
            ->and($e->getMessage())->toContain('Linode');
    });
    expect(fn() => app(CloudCatalog::class)->for($account))->toThrow(CloudProviderException::class)
        ->and(Cache::has(CloudCatalog::cacheKey($account)))->toBeFalse();
    Http::assertNothingSent();
})->with([
    'no token at all' => [null],
    'an empty token' => [''],
    'a blank token' => ["  \n "],
]);

test('the catalog is fetched once and then served from the cache until forgotten', function () {
    cloudCatalogHetznerFakes();
    $account = cloudCatalogAccount();
    $catalog = app(CloudCatalog::class);

    $first = $catalog->for($account);
    $second = $catalog->for($account);

    expect($second)->toEqual($first);
    Http::assertSentCount(4);
    expect(Http::recorded()->map(fn(array $pair): string => $pair[0]->url())->countBy()->all())->toBe([
        'https://api.hetzner.cloud/v1/locations' => 1,
        'https://api.hetzner.cloud/v1/server_types?per_page=50' => 1,
        'https://api.hetzner.cloud/v1/server_types?per_page=50&page=2' => 1,
        'https://api.hetzner.cloud/v1/images?type=system&status=available&per_page=50' => 1,
    ]);

    $catalog->forget($account);
    expect(Cache::has(CloudCatalog::cacheKey($account)))->toBeFalse();

    expect($catalog->for($account))->toEqual($first);
    Http::assertSentCount(8);
});

test('the catalog is kept for the configured number of seconds', function () {
    $this->freezeTime();
    config(['coresmith.cloud.catalog_seconds' => 120]);
    cloudCatalogHetznerFakes();
    $account = cloudCatalogAccount();
    $catalog = app(CloudCatalog::class);

    $catalog->for($account);
    $this->travel(119)->seconds();
    $catalog->for($account);
    Http::assertSentCount(4);

    $this->travel(2)->seconds();
    $catalog->for($account);
    Http::assertSentCount(8);
});

test('the cache key belongs to this environment and this account', function () {
    $production = cloudCatalogAccount();
    $staging = cloudCatalogAccount();

    expect(CloudCatalog::cacheKey($production))->toBe('cloud-catalog:testing:' . $production->getKey())
        ->and(CloudCatalog::cacheKey($staging))->toBe('cloud-catalog:testing:' . $staging->getKey())
        ->and(CloudCatalog::cacheKey($staging))->not->toBe(CloudCatalog::cacheKey($production));

    app()->detectEnvironment(fn(): string => 'production');

    expect(CloudCatalog::cacheKey($production))->toBe('cloud-catalog:production:' . $production->getKey());
});

test('each account is fetched with its own token and cached on its own', function () {
    cloudCatalogHetznerFakes();
    $production = cloudCatalogAccount(['api_token' => 'token-production']);
    $staging = cloudCatalogAccount(['api_token' => 'token-staging']);
    $catalog = app(CloudCatalog::class);

    $catalog->for($production);
    $catalog->for($staging);

    expect(Http::recorded()->map(fn(array $pair): string => $pair[0]->header('Authorization')[0])->countBy()->all())
        ->toBe(['Bearer token-production' => 4, 'Bearer token-staging' => 4]);

    $catalog->forget($staging);

    expect(Cache::has(CloudCatalog::cacheKey($production)))->toBeTrue()
        ->and(Cache::has(CloudCatalog::cacheKey($staging)))->toBeFalse();
});

test('a catalog put in the cache is served without asking the provider', function () {
    $this->freezeTime();
    $account = cloudCatalogAccount();
    $seeded = cloudCatalogData(
        [cloudCatalogRegion('fsn1')],
        [cloudCatalogSize('cx22', prices: ['fsn1' => '3.79'], price: null, regions: ['fsn1'])],
        [cloudCatalogImage('ubuntu', '24.04')->withLabel('Ubuntu 24.04')],
    );

    CloudCatalog::put($account, $seeded, 300);

    expect(app(CloudCatalog::class)->for($account))->toEqual($seeded);
    Http::assertNothingSent();

    $this->travel(299)->seconds();

    expect(Cache::has(CloudCatalog::cacheKey($account)))->toBeTrue();

    $this->travel(2)->seconds();

    expect(Cache::has(CloudCatalog::cacheKey($account)))->toBeFalse();
});

test('a provider failure is not cached', function (string $failing, string $fixture) {
    cloudCatalogHetznerFakes([
        $failing => Http::sequence()
            ->push(CloudFixtures::json('hetzner/error-unavailable'), 503)
            ->push(CloudFixtures::json('hetzner/error-unavailable'), 503)
            ->push(CloudFixtures::json('hetzner/error-unavailable'), 503)
            ->push(CloudFixtures::json($fixture)),
    ]);
    $account = cloudCatalogAccount();
    $catalog = app(CloudCatalog::class);

    expect(fn() => $catalog->for($account))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->status)->toBe(503);
    });
    expect(Cache::has(CloudCatalog::cacheKey($account)))->toBeFalse();
    $sent = Http::recorded()->count();

    $recovered = $catalog->for($account);

    expect(cloudCatalogIds($recovered->regions()))->toBe(['ash', 'fsn1'])
        ->and(Http::recorded()->count() - $sent)->toBe(4)
        ->and(Cache::has(CloudCatalog::cacheKey($account)))->toBeTrue();
})->with([
    'the regions' => [CLOUD_CATALOG_LOCATIONS, 'hetzner/locations'],
    'the sizes' => [CLOUD_CATALOG_TYPES, 'hetzner/server_types-1'],
    'the images' => [CLOUD_CATALOG_IMAGES, 'hetzner/images'],
]);

test('only images coresmith provisions are offered, labelled for people', function () {
    $images = cloudCatalogHetzner()->images();

    expect(cloudCatalogIds($images))->toBe(['ubuntu-24.04', 'debian-12'])
        ->and($images->pluck('label')->all())->toBe(['Ubuntu 24.04', 'Debian 12'])
        ->and($images->first()->architectures)->toBe(['x86', 'arm'])
        ->and($images->last()->architectures)->toBe(['x86']);
});

test('images are offered in the order coresmith lists them, matched on the whole version', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1')],
        [cloudCatalogSize('cx22')],
        [
            cloudCatalogImage('rocky', '12'),
            cloudCatalogImage('debian', '12'),
            cloudCatalogImage('ubuntu', '22.04'),
            cloudCatalogImage('ubuntu', '24'),
            cloudCatalogImage('ubuntu', '24.04.1'),
            cloudCatalogImage('debian', '1'),
            cloudCatalogImage('debian', '64'),
            cloudCatalogImage('ubuntu', '24.04'),
            cloudCatalogImage('rocky', '9'),
        ],
    );

    expect(cloudCatalogIds($catalog->images()))->toBe(['ubuntu-24.04', 'ubuntu-22.04', 'debian-12'])
        ->and($catalog->images()->pluck('label')->all())->toBe(['Ubuntu 24.04', 'Ubuntu 22.04', 'Debian 12']);
});

test('the images on offer follow the configuration', function () {
    config(['coresmith.cloud.images' => [['family' => 'debian', 'version' => '13', 'label' => 'Debian 13 (trixie)']]]);

    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1')],
        [cloudCatalogSize('cx22')],
        [cloudCatalogImage('debian', '12'), cloudCatalogImage('debian', '13', ['x86'])],
    );

    expect($catalog->images()->all())->toEqual([cloudCatalogImage('debian', '13', ['x86'])->withLabel('Debian 13 (trixie)')]);
});

test('sizes below the floor and regions with nothing left are dropped', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1', 'Falkenstein (fsn1)'), cloudCatalogRegion('nbg1', 'Nuremberg (nbg1)')],
        [
            cloudCatalogSize('cx11', memoryMb: 512, diskGb: 20, price: '3.29', regions: ['nbg1']),
            cloudCatalogSize('cx22', memoryMb: 4096, diskGb: 40, price: '3.79', regions: ['fsn1']),
        ],
    );

    expect(cloudCatalogIds($catalog->sizes()))->toBe(['cx22'])
        ->and(cloudCatalogIds($catalog->regions()))->toBe(['fsn1'])
        ->and($catalog->size('cx11'))->toBeNull()
        ->and($catalog->region('nbg1'))->toBeNull();
});

test('a size needs the memory, the disk and a price that can be paid', function (int $memoryMb, int $diskGb, ?string $price, array $prices, ?array $regions, bool $kept) {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1')],
        [
            cloudCatalogSize('anchor', price: '99.00'),
            cloudCatalogSize('probe', $memoryMb, $diskGb, $price, regions: $regions, prices: $prices),
        ],
    );

    expect(cloudCatalogIds($catalog->sizes()))->toBe($kept ? ['probe', 'anchor'] : ['anchor']);
})->with([
    'exactly the floor' => [1024, 10, '5.00', [], null, true],
    'a megabyte short' => [1023, 10, '5.00', [], null, false],
    'a gigabyte short' => [1024, 9, '5.00', [], null, false],
    'priced by region only' => [1024, 10, null, ['fsn1' => '3.79'], ['fsn1'], true],
    'no price at all' => [1024, 10, null, [], null, false],
    'priced only where it is not offered' => [1024, 10, null, ['nbg1' => '3.79'], ['fsn1'], false],
    'only prices nobody knows' => [1024, 10, null, ['fsn1' => null], null, false],
    'a price but offered nowhere' => [1024, 10, '5.00', [], [], false],
]);

test('the floor follows the configuration', function () {
    config(['coresmith.cloud.min_memory_mb' => 4096, 'coresmith.cloud.min_disk_gb' => 80]);

    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1')],
        [
            cloudCatalogSize('little-memory', memoryMb: 2048, diskGb: 80),
            cloudCatalogSize('little-disk', memoryMb: 4096, diskGb: 40),
            cloudCatalogSize('enough', memoryMb: 4096, diskGb: 80),
        ],
    );

    expect(cloudCatalogIds($catalog->sizes()))->toBe(['enough']);
});

test('sizes are ordered by price, then memory, then x86 before arm, then id', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1')],
        [
            cloudCatalogSize('dear', memoryMb: 8192, price: '20.00'),
            cloudCatalogSize('arm-small', memoryMb: 2048, price: '5.00', architecture: 'arm'),
            cloudCatalogSize('zeta-small', memoryMb: 2048, price: '5.00'),
            cloudCatalogSize('big-memory', memoryMb: 4096, price: '5.00'),
            cloudCatalogSize('alpha-small', memoryMb: 2048, price: '5.00'),
        ],
    );

    expect(cloudCatalogIds($catalog->sizes()))->toBe(['alpha-small', 'zeta-small', 'arm-small', 'big-memory', 'dear']);
});

test('a size is ranked by the lowest price it can be bought at', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1'), cloudCatalogRegion('ash')],
        [
            cloudCatalogSize('by-region', price: null, regions: ['fsn1', 'ash'], prices: ['fsn1' => '9.00', 'ash' => '4.50']),
            cloudCatalogSize('with-default', price: '6.00', regions: null, prices: ['ash' => '8.00']),
            cloudCatalogSize('cheaper-regional', price: '10.00', regions: null, prices: ['ash' => '5.00']),
        ],
    );

    expect(cloudCatalogIds($catalog->sizes()))->toBe(['by-region', 'cheaper-regional', 'with-default']);
});

test('a price in a region where the size is not offered does not rank it', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1'), cloudCatalogRegion('nbg1')],
        [
            cloudCatalogSize('listed-cheap-elsewhere', price: null, regions: ['fsn1'], prices: ['fsn1' => '5.00', 'nbg1' => '3.00']),
            cloudCatalogSize('honest', price: null, regions: ['fsn1'], prices: ['fsn1' => '4.00']),
        ],
    );

    expect(cloudCatalogIds($catalog->sizes()))->toBe(['honest', 'listed-cheap-elsewhere']);
});

test('regions are ordered by label, ignoring case and accents', function () {
    $catalog = cloudCatalogServe(
        [
            cloudCatalogRegion('sin', 'Singapore (sin)'),
            cloudCatalogRegion('ams', 'amsterdam (ams)'),
            cloudCatalogRegion('sao', 'São Paulo (sao)'),
            cloudCatalogRegion('ash', 'Ashburn, VA (ash)'),
        ],
        [cloudCatalogSize('cx22')],
    );

    expect(cloudCatalogIds($catalog->regions()))->toBe(['ams', 'ash', 'sao', 'sin']);
});

test('the catalog hands out its parts whole and in order', function () {
    $catalog = cloudCatalogHetzner();

    expect(cloudCatalogIds($catalog->regions()))->toBe(['ash', 'fsn1'])
        ->and($catalog->regions()->pluck('label')->all())->toBe(['Ashburn, VA (ash)', 'Falkenstein (fsn1)'])
        ->and(cloudCatalogIds($catalog->sizes()))->toBe(['cx22', 'cax11', 'cpx21'])
        ->and(cloudCatalogIds($catalog->images()))->toBe(['ubuntu-24.04', 'debian-12'])
        ->and($catalog->size('cx22')->prices)->toEqual(['fsn1' => new CloudPrice('3.79', 'EUR'), 'nbg1' => new CloudPrice('3.79', 'EUR')])
        ->and($catalog->size('cx22')->regions)->toBe(['fsn1'])
        ->and($catalog->size('cpx21')->priceIn('ash'))->toEqual(new CloudPrice('8.49', 'EUR'));
});

test('what the catalog hands out cannot change the catalog', function () {
    $catalog = cloudCatalogHetzner();

    $catalog->regions()->push(cloudCatalogRegion('zzz'));
    $catalog->sizes()->forget(0);
    $catalog->images()->splice(0);

    expect(cloudCatalogIds($catalog->regions()))->toBe(['ash', 'fsn1'])
        ->and(cloudCatalogIds($catalog->sizes()))->toBe(['cx22', 'cax11', 'cpx21'])
        ->and(cloudCatalogIds($catalog->images()))->toBe(['ubuntu-24.04', 'debian-12']);
});

test('the catalog is a final readonly value', function () {
    $reflection = new ReflectionClass(CatalogData::class);

    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
});

test('a catalog built from keyed collections still holds plain lists', function () {
    $catalog = new CatalogData(
        new Collection(['b' => cloudCatalogRegion('b'), 'a' => cloudCatalogRegion('a')]),
        new Collection(['x' => cloudCatalogSize('x')]),
        new Collection(['y' => cloudCatalogImage('ubuntu', '24.04')]),
    );

    expect($catalog->regions()->keys()->all())->toBe([0, 1])
        ->and($catalog->sizes()->keys()->all())->toBe([0])
        ->and($catalog->images()->keys()->all())->toBe([0])
        ->and(array_is_list($catalog->toArray()['regions']))->toBeTrue()
        ->and(array_is_list($catalog->toArray()['sizes']))->toBeTrue()
        ->and(array_is_list($catalog->toArray()['images']))->toBeTrue()
        ->and(CatalogData::fromArray($catalog->toArray()))->toEqual($catalog);
});

test('a region, a size or an image is found by its id', function () {
    $catalog = cloudCatalogHetzner();

    expect($catalog->region('fsn1')->label)->toBe('Falkenstein (fsn1)')
        ->and($catalog->size('cpx21')->diskGb)->toBe(80)
        ->and($catalog->image('debian-12')->label)->toBe('Debian 12')
        ->and($catalog->region('nbg1'))->toBeNull()
        ->and($catalog->size('cx11'))->toBeNull()
        ->and($catalog->image('rocky-9'))->toBeNull();
});

test('the sizes of a region are those it offers, and the images of a size are those that run on it', function () {
    $catalog = cloudCatalogHetzner();

    expect(cloudCatalogIds($catalog->sizesIn('fsn1')))->toBe(['cx22', 'cax11', 'cpx21'])
        ->and(cloudCatalogIds($catalog->sizesIn('ash')))->toBe(['cpx21'])
        ->and($catalog->sizesIn('nbg1')->all())->toBe([])
        ->and(cloudCatalogIds($catalog->imagesFor($catalog->size('cx22'))))->toBe(['ubuntu-24.04', 'debian-12'])
        ->and(cloudCatalogIds($catalog->imagesFor($catalog->size('cax11'))))->toBe(['ubuntu-24.04']);
});

test('defaults pick the widest region, a size with room to build, and an image that runs on it', function () {
    $catalog = cloudCatalogHetzner();

    expect($catalog->defaults())->toBe(['region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04'])
        ->and(cloudCatalogIds($catalog->regions()))->toBe(['ash', 'fsn1'])
        ->and($catalog->region('nbg1'))->toBeNull();
});

test('defaults go to the first region by label when regions offer as much', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('nyc3', 'New York 3 (nyc3)'), cloudCatalogRegion('fra1', 'Frankfurt 1 (fra1)'), cloudCatalogRegion('sgp1', 'Singapore 1 (sgp1)')],
        [cloudCatalogSize('s-2vcpu-4gb', regions: ['nyc3', 'fra1', 'sgp1']), cloudCatalogSize('s-1vcpu-1gb', memoryMb: 1024, price: '4.00', regions: ['nyc3', 'fra1'])],
        [cloudCatalogImage('ubuntu', '24.04')],
    );

    expect($catalog->defaults()['region'])->toBe('fra1');
});

test('defaults move to a region that offers more even when it sorts later', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('a-region', 'Alpha (a-region)'), cloudCatalogRegion('b-region', 'Beta (b-region)')],
        [
            cloudCatalogSize('one', price: '4.00', regions: ['a-region', 'b-region']),
            cloudCatalogSize('two', price: '5.00', regions: ['b-region']),
        ],
        [cloudCatalogImage('ubuntu', '24.04')],
    );

    expect($catalog->defaults()['region'])->toBe('b-region');
});

test('defaults pick the first size with room to build, or the first size there is', function (array $memories, string $expected) {
    $sizes = [];

    foreach ($memories as $index => $memoryMb) {
        $sizes[] = cloudCatalogSize("size-{$index}", memoryMb: $memoryMb, price: number_format(5 + $index, 2, '.', ''));
    }

    $catalog = cloudCatalogServe([cloudCatalogRegion('fsn1')], $sizes, [cloudCatalogImage('ubuntu', '24.04')]);

    expect($catalog->defaults()['size'])->toBe($expected);
})->with([
    'the first with 2 GB' => [[1024, 2048, 4096], 'size-1'],
    'one byte short of 2 GB is not enough' => [[2047, 4096], 'size-1'],
    'the first is already enough' => [[2048, 4096], 'size-0'],
    'none has enough, so the first' => [[1024, 1536], 'size-0'],
]);

test('defaults pick the first image that runs on the size they chose', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1')],
        [cloudCatalogSize('arm-first', architecture: 'arm', price: '3.00'), cloudCatalogSize('x86-second', price: '4.00')],
        [cloudCatalogImage('ubuntu', '24.04', ['x86']), cloudCatalogImage('ubuntu', '22.04', ['x86', 'arm']), cloudCatalogImage('debian', '12', ['arm'])],
    );

    expect($catalog->defaults())->toBe(['region' => 'fsn1', 'size' => 'arm-first', 'image' => 'ubuntu-22.04']);
});

test('defaults are empty when there is nothing to choose from', function (array $regions, array $sizes, array $images) {
    expect(cloudCatalogServe($regions, $sizes, $images)->defaults())->toBe(['region' => null, 'size' => null, 'image' => null]);
})->with([
    'a provider that offers nothing' => [[], [], []],
    'regions but no sizes' => [[cloudCatalogRegion('fsn1')], [], [cloudCatalogImage('ubuntu', '24.04')]],
    'sizes that are all too small' => [[cloudCatalogRegion('fsn1')], [cloudCatalogSize('tiny', memoryMb: 512)], [cloudCatalogImage('ubuntu', '24.04')]],
]);

test('defaults carry no image when none runs on the size', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1')],
        [cloudCatalogSize('arm-only', architecture: 'arm')],
        [cloudCatalogImage('ubuntu', '24.04', ['x86'])],
    );

    expect($catalog->defaults())->toBe(['region' => 'fsn1', 'size' => 'arm-only', 'image' => null]);
});

test('defaults name the first region even when none of them has a size on offer', function () {
    $catalog = cloudCatalogData([cloudCatalogRegion('fsn1'), cloudCatalogRegion('nbg1')], [], [cloudCatalogImage('ubuntu', '24.04')]);

    expect($catalog->defaults())->toBe(['region' => 'fsn1', 'size' => null, 'image' => null]);
});

test('a resize keeps the disk and the architecture', function () {
    $catalog = cloudCatalogHetzner();
    $current = $catalog->size('cx22');

    expect(cloudCatalogIds($catalog->resizeOptions($current, 'fsn1', false)))->toBe(['cpx21'])
        ->and(cloudCatalogIds($catalog->resizeOptions($current, 'fsn1', true)))->toBe(['cpx21'])
        ->and($catalog->resizeOptions($catalog->size('cpx21'), 'fsn1', false)->all())->toBe([]);
});

test('a resize offers what the region sells, nearest in price there first', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('fsn1'), cloudCatalogRegion('hel1')],
        [
            cloudCatalogSize('current', price: null, regions: ['fsn1', 'hel1'], prices: ['fsn1' => '4.00', 'hel1' => '4.00']),
            cloudCatalogSize('late-in-fsn1', memoryMb: 8192, diskGb: 80, price: null, regions: ['fsn1', 'hel1'], prices: ['fsn1' => '9.00', 'hel1' => '5.00']),
            cloudCatalogSize('early-in-fsn1', memoryMb: 8192, diskGb: 80, price: null, regions: ['fsn1', 'hel1'], prices: ['fsn1' => '7.00', 'hel1' => '8.00']),
            cloudCatalogSize('only-helsinki', memoryMb: 8192, diskGb: 80, price: null, regions: ['hel1'], prices: ['hel1' => '6.00']),
            cloudCatalogSize('less-disk', memoryMb: 4096, diskGb: 20, price: null, regions: ['fsn1', 'hel1'], prices: ['fsn1' => '3.00', 'hel1' => '3.00']),
            cloudCatalogSize('other-architecture', memoryMb: 4096, diskGb: 40, architecture: 'arm', price: null, regions: ['fsn1', 'hel1'], prices: ['fsn1' => '4.00', 'hel1' => '4.00']),
        ],
    );
    $current = $catalog->size('current');

    expect(cloudCatalogIds($catalog->resizeOptions($current, 'fsn1', false)))->toBe(['early-in-fsn1', 'late-in-fsn1'])
        ->and(cloudCatalogIds($catalog->resizeOptions($current, 'hel1', false)))->toBe(['late-in-fsn1', 'only-helsinki', 'early-in-fsn1']);
});

test('a resize is ordered by price whatever order a hand-built catalog was given in', function () {
    $everywhere = ['fsn1', 'nbg1', 'hel1'];
    $catalog = cloudCatalogData(
        [cloudCatalogRegion('fsn1'), cloudCatalogRegion('nbg1'), cloudCatalogRegion('hel1')],
        [
            cloudCatalogSize('cx42', memoryMb: 16384, diskGb: 160, price: null, regions: $everywhere, prices: ['fsn1' => '16.40', 'nbg1' => '16.40', 'hel1' => '16.40']),
            cloudCatalogSize('cx22', price: null, regions: $everywhere, prices: ['fsn1' => '3.79', 'nbg1' => '3.79', 'hel1' => '3.79']),
            cloudCatalogSize('cpx21', diskGb: 80, price: null, regions: $everywhere, prices: ['fsn1' => '7.55', 'nbg1' => '7.55', 'hel1' => '7.55']),
            cloudCatalogSize('cax11', architecture: 'arm', price: null, regions: $everywhere, prices: ['fsn1' => '3.79', 'nbg1' => '3.79', 'hel1' => '3.79']),
            cloudCatalogSize('cx32', memoryMb: 8192, diskGb: 80, price: null, regions: $everywhere, prices: ['fsn1' => '6.80', 'nbg1' => '6.80', 'hel1' => '6.80']),
        ],
    );

    expect(cloudCatalogIds($catalog->resizeOptions($catalog->size('cx22'), 'fsn1', false)))->toBe(['cx32', 'cpx21', 'cx42']);
});

test('vultr only offers larger plans', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('ewr')],
        [
            cloudCatalogSize('vc2-1c-2gb', memoryMb: 2048, diskGb: 55, price: '10.00'),
            cloudCatalogSize('vc2-1c-2gb-cheaper', memoryMb: 2048, diskGb: 55, price: '5.00'),
            cloudCatalogSize('vc2-2c-4gb', memoryMb: 4096, diskGb: 80, price: '20.00'),
        ],
    );
    $current = $catalog->size('vc2-1c-2gb');

    expect(cloudCatalogIds($catalog->resizeOptions($current, 'ewr', true)))->toBe(['vc2-2c-4gb'])
        ->and(cloudCatalogIds($catalog->resizeOptions($current, 'ewr', false)))->toBe(['vc2-1c-2gb-cheaper', 'vc2-2c-4gb']);
});

test('a plan that costs the same is not larger', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('ewr')],
        [
            cloudCatalogSize('current', diskGb: 55, price: '10.00'),
            cloudCatalogSize('same-price', diskGb: 60, price: '10.00'),
            cloudCatalogSize('dearer', diskGb: 80, price: '10.01'),
        ],
    );

    expect(cloudCatalogIds($catalog->resizeOptions($catalog->size('current'), 'ewr', true)))->toBe(['dearer']);
});

test('a size is the same size as the one it replaces only by id', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('ewr')],
        [cloudCatalogSize('current', price: '10.00'), cloudCatalogSize('twin', price: '10.00')],
    );

    expect(cloudCatalogIds($catalog->resizeOptions($catalog->size('current'), 'ewr', false)))->toBe(['twin']);
});

test('a size whose price in one region is unknown never takes the default price there', function () {
    $size = new CloudSize('g6-standard-1', 1, 2048, 50, 'x86', new CloudPrice('12.00', 'USD'), [
        'id-cgk' => null,
        'br-gru' => new CloudPrice('13.20', 'USD'),
    ]);

    expect($size->priceIn('id-cgk'))->toBeNull()
        ->and($size->priceIn('br-gru'))->toEqual(new CloudPrice('13.20', 'USD'))
        ->and($size->priceIn('eu-central'))->toEqual(new CloudPrice('12.00', 'USD'))
        ->and($size->availableIn('id-cgk'))->toBeTrue();
});

test('a size whose price somewhere is unknown is still offered there, unpriced, after the sizes with a price', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('eu-central'), cloudCatalogRegion('id-cgk')],
        [
            cloudCatalogSize('current', price: '5.00', prices: ['id-cgk' => '6.00']),
            cloudCatalogSize('unknown-in-jakarta', memoryMb: 8192, diskGb: 80, price: '12.00', prices: ['id-cgk' => null]),
            cloudCatalogSize('known-in-jakarta', memoryMb: 8192, diskGb: 80, price: '15.00', prices: ['id-cgk' => '18.00']),
        ],
    );
    $current = $catalog->size('current');
    $unknown = $catalog->size('unknown-in-jakarta');

    expect(cloudCatalogIds($catalog->sizesIn('id-cgk')))->toBe(['current', 'unknown-in-jakarta', 'known-in-jakarta'])
        ->and($unknown->priceIn('id-cgk'))->toBeNull()
        ->and($unknown->priceIn('eu-central'))->toEqual(new CloudPrice('12.00', 'USD'))
        ->and(cloudCatalogIds($catalog->resizeOptions($current, 'id-cgk', false)))->toBe(['known-in-jakarta', 'unknown-in-jakarta'])
        ->and(cloudCatalogIds($catalog->resizeOptions($current, 'id-cgk', true)))->toBe(['known-in-jakarta'])
        ->and(cloudCatalogIds($catalog->resizeOptions($current, 'eu-central', true)))->toBe(['unknown-in-jakarta', 'known-in-jakarta']);
});

test('a resize cannot be called larger from a price that is not known', function () {
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('id-cgk')],
        [
            cloudCatalogSize('current', price: '5.00', prices: ['id-cgk' => null]),
            cloudCatalogSize('dearer', memoryMb: 8192, diskGb: 80, price: '12.00'),
        ],
    );

    expect(cloudCatalogIds($catalog->resizeOptions($catalog->size('current'), 'id-cgk', true)))->toBe([])
        ->and(cloudCatalogIds($catalog->resizeOptions($catalog->size('current'), 'id-cgk', false)))->toBe(['dearer']);
});

test('a catalog survives the cache round trip', function () {
    $fetched = cloudCatalogHetzner();
    $odd = cloudCatalogData(
        [new CloudRegion('id-cgk', 'Jakarta (id-cgk)', 'ID'), new CloudRegion('xx', 'Nowhere (xx)')],
        [
            cloudCatalogSize('g6-standard-1', price: '12.00', prices: ['id-cgk' => null, 'br-gru' => '13.20'], regions: null),
            cloudCatalogSize('nowhere', price: null, prices: ['xx' => '1.00'], regions: []),
            cloudCatalogSize('arm', architecture: 'arm', price: null, prices: ['xx' => '2.50'], regions: ['xx']),
        ],
        [cloudCatalogImage('ubuntu', '24.04', [])->withLabel('Ubuntu 24.04'), cloudCatalogImage('debian', '12', ['x86'])],
    );

    foreach ([$fetched, $odd, cloudCatalogData([], [], [])] as $data) {
        expect(CatalogData::fromArray($data->toArray()))->toEqual($data)
            ->and(CatalogData::fromArray(json_decode(json_encode($data->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)))->toEqual($data);
    }

    $restored = CatalogData::fromArray($odd->toArray());

    expect($restored->size('g6-standard-1')->priceIn('id-cgk'))->toBeNull()
        ->and($restored->size('g6-standard-1')->priceIn('eu-central'))->toEqual(new CloudPrice('12.00', 'USD'))
        ->and($restored->size('nowhere')->regions)->toBe([])
        ->and($restored->size('g6-standard-1')->regions)->toBeNull()
        ->and($restored->region('xx')->country)->toBeNull()
        ->and($restored->region('id-cgk')->country)->toBe('ID');
});

test('an unknown price is still unknown after a trip through the cache', function () {
    $account = cloudCatalogAccount();
    $catalog = cloudCatalogServe(
        [cloudCatalogRegion('id-cgk')],
        [cloudCatalogSize('g6-standard-1', price: '12.00', prices: ['id-cgk' => null])],
    );

    CloudCatalog::put($account, $catalog, 60);
    $served = app(CloudCatalog::class)->for($account);

    expect($served->size('g6-standard-1')->priceIn('id-cgk'))->toBeNull()
        ->and($served->size('g6-standard-1')->price)->toEqual(new CloudPrice('12.00', 'USD'));
});
