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
use App\Services\Cloud\Providers\DigitalOceanApi;
use App\Services\Cloud\Providers\HttpCloudApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Tests\Support\CloudFixtures;

beforeEach(fn() => Http::preventStrayRequests());

function cloudDigitalOcean(): DigitalOceanApi
{
    return new DigitalOceanApi('digitalocean-token');
}

function cloudDigitalOceanTag(): string
{
    return 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d';
}

function cloudDigitalOceanKey(): string
{
    return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIBBoM6eEh29z9GyfaiV/OH8xLRwdtOyw0trj85BenDW4 coresmith-test';
}

function cloudDigitalOceanSpec(?string $keyId = '512189'): CloudServerSpec
{
    return new CloudServerSpec('outpost-02', 'fra1', 's-1vcpu-2gb', 'ubuntu-24-04-x64', cloudDigitalOceanTag(), cloudDigitalOceanKey(), $keyId);
}

function cloudDigitalOceanPage(string $list, array $items, array $pages): array
{
    return [$list => $items, 'links' => ['pages' => $pages], 'meta' => ['total' => 4]];
}

test('digitalocean is the cloud api for digitalocean', function () {
    expect(cloudDigitalOcean())->toBeInstanceOf(CloudApi::class)
        ->toBeInstanceOf(HttpCloudApi::class)
        ->and(cloudDigitalOcean()->type())->toBe(CloudProviderType::DigitalOcean)
        ->and((new ReflectionClass(DigitalOceanApi::class))->isFinal())->toBeTrue();
});

test('digitalocean names the account by its team', function () {
    Http::fake(['api.digitalocean.com/v2/account' => Http::response(CloudFixtures::json('digitalocean/account'))]);

    $account = cloudDigitalOcean()->verify();

    expect($account->label)->toBe('Monoven');
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.digitalocean.com/v2/account'
        && $request->hasHeader('Authorization', 'Bearer digitalocean-token'));
});

test('digitalocean names the account by its email when it has no team', function () {
    $body = CloudFixtures::json('digitalocean/account');
    Arr::forget($body, 'account.team');
    Http::fake(['api.digitalocean.com/v2/account' => Http::response($body)]);

    expect(cloudDigitalOcean()->verify()->label)->toBe('maya@monoven.io');
});

test('digitalocean names the account by its email when its team has no name', function (mixed $name) {
    Http::fake(['api.digitalocean.com/v2/account' => Http::response(CloudFixtures::with('digitalocean/account', ['account.team.name' => $name]))]);

    expect(cloudDigitalOcean()->verify()->label)->toBe('maya@monoven.io');
})->with([
    'an empty name' => [''],
    'no name' => [null],
    'a name that is not text' => [42],
]);

test('digitalocean leaves the account unnamed when it has neither a team nor an email', function () {
    $body = CloudFixtures::json('digitalocean/account');
    Arr::forget($body, ['account.team', 'account.email']);
    Http::fake(['api.digitalocean.com/v2/account' => Http::response($body)]);

    expect(cloudDigitalOcean()->verify()->label)->toBeNull();
});

test('digitalocean refuses a locked account with its reason', function () {
    Http::fake(['api.digitalocean.com/v2/account' => Http::response(CloudFixtures::json('digitalocean/account-locked'))]);

    expect(fn() => cloudDigitalOcean()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)
            ->and($e->provider)->toBe(CloudProviderType::DigitalOcean)
            ->and($e->detail)->toBe('Your account is locked pending payment')
            ->and($e->status)->toBeNull()
            ->and($e->retryable())->toBeFalse()
            ->and($e->connectMessage())->toBe('DigitalOcean refused the request. DigitalOcean said: Your account is locked pending payment.');
    });

    Http::assertSentCount(1);
});

test('digitalocean refuses an account that is not active even when it does not say why', function (string $status) {
    Http::fake(['api.digitalocean.com/v2/account' => Http::response(CloudFixtures::with('digitalocean/account', [
        'account.status' => $status,
        'account.status_message' => '',
    ]))]);

    expect(fn() => cloudDigitalOcean()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)
            ->and($e->detail)->toBeNull()
            ->and($e->getMessage())->toBe('DigitalOcean refused the request.');
    });
})->with(['warning', 'locked', 'something-new']);

test('digitalocean does not take an answer without an account status for a verified account', function (array $body) {
    Http::fake(['api.digitalocean.com/v2/account' => Http::response($body)]);

    expect(fn() => cloudDigitalOcean()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('DigitalOcean had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'a json object without an account' => [['unexpected' => true]],
    'an account that is not an object' => [['account' => 'active']],
    'an account with no status' => [['account' => ['email' => 'maya@monoven.io']]],
    'an account whose status is not text' => [['account' => ['email' => 'maya@monoven.io', 'status' => true]]],
]);

test('digitalocean reports a refused token in words', function () {
    Http::fake(['api.digitalocean.com/v2/account' => Http::response(CloudFixtures::json('digitalocean/error-unauthorized'), 401)]);

    expect(fn() => cloudDigitalOcean()->verify())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::TokenRejected)
            ->and($e->status)->toBe(401)
            ->and($e->retryable())->toBeFalse()
            ->and($e->connectMessage())->toContain('Unable to authenticate you');
    });

    Http::assertSentCount(1);
});

test('digitalocean never repeats a token that its error message echoes', function () {
    $token = 'dop_v1_' . str_repeat('ab12', 16);
    Http::fake(['api.digitalocean.com/v2/account' => Http::response(['id' => 'unauthorized', 'message' => "Unable to authenticate {$token}"], 401)]);

    expect(fn() => (new DigitalOceanApi($token))->verify())->toThrow(function (CloudProviderException $e) use ($token) {
        expect($e->detail)->toBe('Unable to authenticate [REDACTED]')
            ->and($e->getMessage())->not->toContain($token)
            ->and($e->connectMessage())->not->toContain($token);
    });
});

test('digitalocean lists only available regions', function () {
    Http::fake(['api.digitalocean.com/v2/regions*' => Http::response(CloudFixtures::json('digitalocean/regions'))]);

    $regions = cloudDigitalOcean()->regions();

    expect($regions->pluck('id')->all())->toBe(['fra1', 'nyc3', 'sgp1'])
        ->and($regions->first()->label)->toBe('Frankfurt 1 (fra1)')
        ->and($regions->get(1)->label)->toBe('New York 3 (nyc3)')
        ->and($regions->last()->label)->toBe('Singapore 1 (sgp1)')
        ->and($regions->first()->country)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.digitalocean.com/v2/regions?per_page=200');
});

test('digitalocean labels a region that has no name by its slug', function () {
    $body = CloudFixtures::json('digitalocean/regions');
    unset($body['regions'][0]['name']);
    Http::fake(['api.digitalocean.com/v2/regions*' => Http::response($body)]);

    expect(cloudDigitalOcean()->regions()->first()->label)->toBe('fra1');
});

test('digitalocean lists sizes without gpu or unavailable ones', function () {
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(CloudFixtures::json('digitalocean/sizes'))]);

    $sizes = cloudDigitalOcean()->sizes();
    $bySlug = $sizes->keyBy('id');

    expect($sizes->pluck('id')->all())->toBe(['s-1vcpu-512mb-10gb', 's-1vcpu-1gb', 's-1vcpu-2gb', 's-2vcpu-4gb']);

    $tiny = $bySlug['s-1vcpu-512mb-10gb'];
    expect($tiny->cpus)->toBe(1)
        ->and($tiny->memoryMb)->toBe(512)
        ->and($tiny->diskGb)->toBe(10)
        ->and($tiny->architecture)->toBe('x86')
        ->and($tiny->price)->toEqual(new CloudPrice('4.00', 'USD'))
        ->and($tiny->prices)->toBe([])
        ->and($tiny->regions)->toBe(['fra1', 'nyc3'])
        ->and($tiny->availableIn('fra1'))->toBeTrue()
        ->and($tiny->availableIn('sgp1'))->toBeFalse();

    $small = $bySlug['s-1vcpu-1gb'];
    expect($small->memoryMb)->toBe(1024)
        ->and($small->diskGb)->toBe(25)
        ->and($small->price)->toEqual(new CloudPrice('6.00', 'USD'));

    $medium = $bySlug['s-1vcpu-2gb'];
    expect($medium->cpus)->toBe(1)
        ->and($medium->memoryMb)->toBe(2048)
        ->and($medium->diskGb)->toBe(50)
        ->and($medium->price)->toEqual(new CloudPrice('12.00', 'USD'))
        ->and($medium->priceIn('sgp1'))->toEqual(new CloudPrice('12.00', 'USD'))
        ->and($medium->regions)->toBe(['fra1', 'nyc3', 'sgp1'])
        ->and($medium->availableIn('sgp1'))->toBeTrue()
        ->and($medium->availableIn('ams3'))->toBeFalse();

    $large = $bySlug['s-2vcpu-4gb'];
    expect($large->cpus)->toBe(2)
        ->and($large->memoryMb)->toBe(4096)
        ->and($large->diskGb)->toBe(80)
        ->and($large->price)->toEqual(new CloudPrice('24.00', 'USD'));

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.digitalocean.com/v2/sizes?per_page=200');
});

test('digitalocean skips a gpu size whether it shows in its slug or in its details', function (string $change) {
    $body = CloudFixtures::json('digitalocean/sizes');

    if ($change === 'slug') {
        unset($body['sizes'][4]['gpu_info']);
    } else {
        $body['sizes'][4]['slug'] = 'h100-80gb';
    }

    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response($body)]);

    expect(cloudDigitalOcean()->sizes()->pluck('id')->all())->toBe(['s-1vcpu-512mb-10gb', 's-1vcpu-1gb', 's-1vcpu-2gb', 's-2vcpu-4gb']);
})->with([
    'a gpu slug without details' => ['slug'],
    'details without a gpu slug' => ['details'],
]);

test('digitalocean keeps a size whose gpu details are empty', function (mixed $details) {
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(CloudFixtures::with('digitalocean/sizes', ['sizes.2.gpu_info' => $details]))]);

    expect(cloudDigitalOcean()->sizes()->pluck('id')->all())->toContain('s-1vcpu-2gb');
})->with([
    'null' => [null],
    'an empty object' => [[]],
]);

test('digitalocean reads a size with no price or no regions without inventing either', function () {
    $body = CloudFixtures::json('digitalocean/sizes');
    unset($body['sizes'][2]['price_monthly'], $body['sizes'][2]['regions']);
    $body['sizes'][3]['price_monthly'] = 'free';
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response($body)]);

    $sizes = cloudDigitalOcean()->sizes()->keyBy('id');

    expect($sizes['s-1vcpu-2gb']->price)->toBeNull()
        ->and($sizes['s-1vcpu-2gb']->regions)->toBe([])
        ->and($sizes['s-1vcpu-2gb']->availableIn('fra1'))->toBeFalse()
        ->and($sizes['s-2vcpu-4gb']->price)->toBeNull();
});

test('digitalocean skips a size that leaves out how big it is', function (string $field) {
    $body = CloudFixtures::json('digitalocean/sizes');
    unset($body['sizes'][2][$field]);
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response($body)]);

    expect(cloudDigitalOcean()->sizes()->pluck('id')->all())->toBe(['s-1vcpu-512mb-10gb', 's-1vcpu-1gb', 's-2vcpu-4gb']);
})->with(['vcpus', 'memory', 'disk']);

test('digitalocean skips a size whose numbers are not numbers', function (string $field, mixed $value) {
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(CloudFixtures::with('digitalocean/sizes', ["sizes.2.{$field}" => $value]))]);

    expect(cloudDigitalOcean()->sizes()->pluck('id')->all())->toBe(['s-1vcpu-512mb-10gb', 's-1vcpu-1gb', 's-2vcpu-4gb']);
})->with([
    'vcpus that are null' => ['vcpus', null],
    'vcpus that are a word' => ['vcpus', 'many'],
    'memory that is a flag' => ['memory', true],
    'a disk that is a list' => ['disk', [50]],
]);

test('digitalocean keeps a size that reports its numbers as digits', function () {
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(CloudFixtures::with('digitalocean/sizes', [
        'sizes.2.vcpus' => '1',
        'sizes.2.memory' => '2048',
        'sizes.2.disk' => '50',
    ]))]);

    $medium = cloudDigitalOcean()->sizes()->keyBy('id')['s-1vcpu-2gb'];

    expect($medium->cpus)->toBe(1)->and($medium->memoryMb)->toBe(2048)->and($medium->diskGb)->toBe(50);
});

test('digitalocean lists only the regions of a size that are named', function (mixed $regions, array $expected) {
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(CloudFixtures::with('digitalocean/sizes', ['sizes.2.regions' => $regions]))]);

    expect(cloudDigitalOcean()->sizes()->keyBy('id')['s-1vcpu-2gb']->regions)->toBe($expected);
})->with([
    'entries that are not region names' => [['fra1', '', null, 42, ['nyc3'], 'nyc3'], ['fra1', 'nyc3']],
    'a region name on its own' => ['fra1', []],
    'nothing' => [[], []],
]);

test('digitalocean keeps an entry that does not say whether it is available', function (string $list, int $at, array $expected) {
    $body = CloudFixtures::json("digitalocean/{$list}");
    unset($body[$list][$at]['available']);
    Http::fake(['api.digitalocean.com/v2/*' => Http::response($body)]);

    expect(cloudDigitalOcean()->{$list}()->pluck('id')->all())->toBe($expected);
})->with([
    'a region' => ['regions', 2, ['fra1', 'nyc3', 'ams2', 'sgp1']],
    'a size' => ['sizes', 5, ['s-1vcpu-512mb-10gb', 's-1vcpu-1gb', 's-1vcpu-2gb', 's-2vcpu-4gb', 's-8vcpu-16gb']],
]);

test('digitalocean reads every page of sizes', function () {
    $sizes = CloudFixtures::json('digitalocean/sizes')['sizes'];
    Http::fake([
        'api.digitalocean.com/v2/sizes?per_page=200' => Http::response(cloudDigitalOceanPage('sizes', array_slice($sizes, 0, 3), [
            'next' => 'https://api.digitalocean.com/v2/sizes?page=2&per_page=200',
            'last' => 'https://api.digitalocean.com/v2/sizes?page=2&per_page=200',
        ])),
        'api.digitalocean.com/v2/sizes?per_page=200&page=2' => Http::response(cloudDigitalOceanPage('sizes', array_slice($sizes, 3), [
            'first' => 'https://api.digitalocean.com/v2/sizes?page=1&per_page=200',
            'prev' => 'https://api.digitalocean.com/v2/sizes?page=1&per_page=200',
        ])),
    ]);

    expect(cloudDigitalOcean()->sizes()->pluck('id')->all())->toBe(['s-1vcpu-512mb-10gb', 's-1vcpu-1gb', 's-1vcpu-2gb', 's-2vcpu-4gb']);

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->url() === 'https://api.digitalocean.com/v2/sizes?per_page=200&page=2');
});

test('digitalocean keeps its image filter on every page of images', function () {
    $images = CloudFixtures::json('digitalocean/images')['images'];
    Http::fake([
        'api.digitalocean.com/v2/images?type=distribution&per_page=200' => Http::response(cloudDigitalOceanPage('images', array_slice($images, 0, 2), [
            'next' => 'https://api.digitalocean.com/v2/images?page=2&per_page=200&type=distribution',
        ])),
        'api.digitalocean.com/v2/images?type=distribution&per_page=200&page=2' => Http::response(cloudDigitalOceanPage('images', array_slice($images, 2), [
            'prev' => 'https://api.digitalocean.com/v2/images?page=1&per_page=200&type=distribution',
        ])),
    ]);

    expect(cloudDigitalOcean()->images()->pluck('id')->all())->toBe(['ubuntu-24-04-x64', 'ubuntu-22-04-x64', 'debian-12-x64']);

    Http::assertSentCount(2);
});

test('digitalocean stops at a next link it cannot follow', function (mixed $next) {
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(CloudFixtures::with('digitalocean/sizes', ['links.pages.next' => $next]))]);

    expect(cloudDigitalOcean()->sizes())->toHaveCount(4);

    Http::assertSentCount(1);
})->with([
    'a link with no page' => ['https://api.digitalocean.com/v2/sizes?per_page=200'],
    'a link that is not text' => [42],
    'a link given as a list' => [['https://api.digitalocean.com/v2/sizes?page=2&per_page=200']],
    'a page that is not a number' => ['https://api.digitalocean.com/v2/sizes?page=next&per_page=200'],
    'a page with text after the number' => ['https://api.digitalocean.com/v2/sizes?page=2abc&per_page=200'],
    'a page given as a list' => ['https://api.digitalocean.com/v2/sizes?page[]=2&per_page=200'],
    'page zero' => ['https://api.digitalocean.com/v2/sizes?page=0&per_page=200'],
]);

test('digitalocean skips a list entry that has no slug', function (string $list, array $expected) {
    $body = CloudFixtures::json("digitalocean/{$list}");
    unset($body[$list][0]['slug']);
    Http::fake(['api.digitalocean.com/v2/*' => Http::response($body)]);

    expect(cloudDigitalOcean()->{$list}()->pluck('id')->all())->toBe($expected);
})->with([
    'regions' => ['regions', ['nyc3', 'sgp1']],
    'sizes' => ['sizes', ['s-1vcpu-1gb', 's-1vcpu-2gb', 's-2vcpu-4gb']],
    'images' => ['images', ['ubuntu-22-04-x64', 'debian-12-x64']],
]);

test('digitalocean reads distribution images from their slugs', function () {
    Http::fake(['api.digitalocean.com/v2/images*' => Http::response(CloudFixtures::json('digitalocean/images'))]);

    $images = cloudDigitalOcean()->images();
    $bySlug = $images->keyBy('id');

    expect($images->pluck('id')->all())->toBe(['ubuntu-24-04-x64', 'ubuntu-22-04-x64', 'debian-12-x64'])
        ->and($bySlug['ubuntu-24-04-x64']->family)->toBe('ubuntu')
        ->and($bySlug['ubuntu-24-04-x64']->version)->toBe('24.04')
        ->and($bySlug['ubuntu-24-04-x64']->architectures)->toBe(['x86'])
        ->and($bySlug['ubuntu-22-04-x64']->family)->toBe('ubuntu')
        ->and($bySlug['ubuntu-22-04-x64']->version)->toBe('22.04')
        ->and($bySlug['debian-12-x64']->family)->toBe('debian')
        ->and($bySlug['debian-12-x64']->version)->toBe('12')
        ->and($bySlug['debian-12-x64']->architectures)->toBe(['x86'])
        ->and($bySlug->has('centos-stream-9-x64'))->toBeFalse();
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.digitalocean.com/v2/images?type=distribution&per_page=200'
        && $request->uri()->query()->get('type') === 'distribution');
});

test('digitalocean passes over a list entry that is not an object', function (string $list, array $expected) {
    $body = CloudFixtures::json("digitalocean/{$list}");
    array_unshift($body[$list], 'oops', null, 42);
    Http::fake(['api.digitalocean.com/v2/*' => Http::response($body)]);

    expect(cloudDigitalOcean()->{$list}()->pluck('id')->all())->toBe($expected);
})->with([
    'regions' => ['regions', ['fra1', 'nyc3', 'sgp1']],
    'sizes' => ['sizes', ['s-1vcpu-512mb-10gb', 's-1vcpu-1gb', 's-1vcpu-2gb', 's-2vcpu-4gb']],
    'images' => ['images', ['ubuntu-24-04-x64', 'ubuntu-22-04-x64', 'debian-12-x64']],
]);

test('digitalocean reads the family and version out of a distribution slug', function (string $slug, string $family, string $version) {
    Http::fake(['api.digitalocean.com/v2/images*' => Http::response(CloudFixtures::with('digitalocean/images', ['images.0.slug' => $slug]))]);

    $image = cloudDigitalOcean()->images()->first();

    expect($image->id)->toBe($slug)->and($image->family)->toBe($family)->and($image->version)->toBe($version);
})->with([
    'an ubuntu lts' => ['ubuntu-24-04-x64', 'ubuntu', '24.04'],
    'an ubuntu interim release' => ['ubuntu-25-10-x64', 'ubuntu', '25.10'],
    'a debian release' => ['debian-12-x64', 'debian', '12'],
    'a later debian release' => ['debian-13-x64', 'debian', '13'],
]);

test('digitalocean skips distribution slugs it does not provision', function (mixed $slug) {
    Http::fake(['api.digitalocean.com/v2/images*' => Http::response(CloudFixtures::with('digitalocean/images', ['images.0.slug' => $slug]))]);

    expect(cloudDigitalOcean()->images()->pluck('id')->all())->toBe(['ubuntu-22-04-x64', 'debian-12-x64']);
})->with([
    'another distribution' => ['fedora-40-x64'],
    'another architecture' => ['ubuntu-24-04-arm64'],
    'a slug with a suffix' => ['ubuntu-24-04-x64-lts'],
    'a slug with a prefix' => ['custom-ubuntu-24-04-x64'],
    'a slug with no version' => ['debian-x64'],
    'a slug in another case' => ['Ubuntu-24-04-x64'],
    'a slug ending in a newline' => ["ubuntu-24-04-x64\n"],
    'a slug that is not text' => [42],
    'no slug' => [null],
]);

test('digitalocean skips images that are not available', function (?string $status) {
    Http::fake(['api.digitalocean.com/v2/images*' => Http::response(CloudFixtures::with('digitalocean/images', ['images.0.status' => $status]))]);

    expect(cloudDigitalOcean()->images()->pluck('id')->all())->toBe(['ubuntu-22-04-x64', 'debian-12-x64']);
})->with([
    'pending' => ['pending'],
    'deleted' => ['deleted'],
    'retired' => ['retired'],
    'no status' => [null],
]);

test('digitalocean reads a catalog answer without its list as unavailable, so an empty catalog is never taken for a real one', function (string $call) {
    Http::fake(['api.digitalocean.com/v2/*' => Http::response(['unexpected' => true])]);

    expect(fn() => cloudDigitalOcean()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('DigitalOcean had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with(['regions', 'sizes', 'images']);

test('digitalocean fails a catalog read when any page of it lacks the list', function (bool $firstPageLacksIt, int $requests) {
    $sizes = CloudFixtures::json('digitalocean/sizes')['sizes'];
    $next = ['next' => 'https://api.digitalocean.com/v2/sizes?page=2&per_page=200'];
    $withList = cloudDigitalOceanPage('sizes', array_slice($sizes, 0, 3), $next);
    $withoutList = ['links' => ['pages' => $next]];
    Http::fake([
        'api.digitalocean.com/v2/sizes?per_page=200' => Http::response($firstPageLacksIt ? $withoutList : $withList),
        'api.digitalocean.com/v2/sizes?per_page=200&page=2' => Http::response(['unexpected' => true]),
    ]);

    expect(fn() => cloudDigitalOcean()->sizes())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount($requests);
})->with([
    'the first page' => [true, 1],
    'a later page' => [false, 2],
]);

test('digitalocean adopts a key it already holds by fingerprint', function () {
    Http::fake(['api.digitalocean.com/v2/account/keys/*' => Http::response(CloudFixtures::json('digitalocean/ssh_key'))]);

    $id = cloudDigitalOcean()->uploadKey('coresmith-outpost-02-b1n0d', cloudDigitalOceanKey());

    expect($id)->toBe('512189');
    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.digitalocean.com/v2/account/keys/17:35:e1:e3:93:03:56:7d:6d:58:c3:98:5c:5b:c1:c2');
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
});

test('digitalocean uploads a key it does not hold', function () {
    Http::fake([
        'api.digitalocean.com/v2/account/keys/*' => Http::response(CloudFixtures::json('digitalocean/error-not-found'), 404),
        'api.digitalocean.com/v2/account/keys' => Http::response(CloudFixtures::json('digitalocean/ssh_key'), 201),
    ]);

    $id = cloudDigitalOcean()->uploadKey('coresmith-outpost-02-b1n0d', cloudDigitalOceanKey());

    [$post] = Http::recorded(fn(Request $request) => $request->method() === 'POST')->first();

    expect($id)->toBe('512189')
        ->and($post->url())->toBe('https://api.digitalocean.com/v2/account/keys')
        ->and($post->data())->toBe([
            'name' => 'coresmith-outpost-02-b1n0d',
            'public_key' => cloudDigitalOceanKey(),
        ]);
    Http::assertSentCount(2);
});

test('digitalocean uploads a key it cannot fingerprint without asking whether it holds it', function () {
    Http::fake(['api.digitalocean.com/v2/account/keys' => Http::response(['id' => 'unprocessable_entity', 'message' => 'SSH Key is invalid'], 422)]);

    expect(fn() => cloudDigitalOcean()->uploadKey('coresmith-outpost-02-b1n0d', 'not a key'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->detail)->toBe('SSH Key is invalid');
    });

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === 'POST');
});

test('digitalocean does not upload a second key because a lookup it could not read', function (string $body, array $headers) {
    Http::fake(['api.digitalocean.com/v2/account/keys/*' => Http::response($body, 200, $headers)]);

    expect(fn() => cloudDigitalOcean()->uploadKey('coresmith-outpost-02-b1n0d', cloudDigitalOceanKey()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(1);
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
})->with([
    'a json object without a key' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'a key with no id' => ['{"ssh_key":{"name":"coresmith-outpost-02-b1n0d"}}', ['Content-Type' => 'application/json']],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
    'nothing at all' => ['', []],
]);

test('digitalocean reads a key upload whose answer has no key in it as unavailable', function (string $body, array $headers) {
    Http::fake([
        'api.digitalocean.com/v2/account/keys/*' => Http::response(CloudFixtures::json('digitalocean/error-not-found'), 404),
        'api.digitalocean.com/v2/account/keys' => Http::response($body, 201, $headers),
    ]);

    expect(fn() => cloudDigitalOcean()->uploadKey('coresmith-outpost-02-b1n0d', cloudDigitalOceanKey()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(2);
})->with([
    'a json object without a key' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'a key with no id' => ['{"ssh_key":{"name":"coresmith-outpost-02-b1n0d"}}', ['Content-Type' => 'application/json']],
    'an empty body' => ['', []],
]);

test('digitalocean creates a droplet with the key and both tags, without backups', function () {
    Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(CloudFixtures::json('digitalocean/droplet-new'), 202)]);

    $instance = cloudDigitalOcean()->create(cloudDigitalOceanSpec());

    [$post] = Http::recorded(fn(Request $request) => $request->method() === 'POST')->first();

    expect($post->method())->toBe('POST')
        ->and($post->url())->toBe('https://api.digitalocean.com/v2/droplets')
        ->and($post->data())->toBe([
            'name' => 'outpost-02',
            'region' => 'fra1',
            'size' => 's-1vcpu-2gb',
            'image' => 'ubuntu-24-04-x64',
            'ssh_keys' => [512189],
            'ipv6' => true,
            'monitoring' => false,
            'backups' => false,
            'tags' => ['coresmith', 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d'],
        ])
        ->and($instance->id)->toBe('3164444')
        ->and($instance->state)->toBe(CloudInstanceState::Starting)
        ->and($instance->ipv4)->toBeNull()
        ->and($instance->isReady())->toBeFalse()
        ->and($instance)->toEqual(new CloudInstance('3164444', CloudInstanceState::Starting, null, 's-1vcpu-2gb'));
    Http::assertSentCount(1);
});

test('digitalocean will not create a droplet without a key to put on it', function () {
    expect(fn() => cloudDigitalOcean()->create(cloudDigitalOceanSpec(null)))->toThrow(LogicException::class, 'needs the id of an uploaded key');

    Http::assertNothingSent();
});

test('digitalocean picks the public address of an active droplet', function () {
    Http::fake(['api.digitalocean.com/v2/droplets/3164444' => Http::response(CloudFixtures::json('digitalocean/droplet-active'))]);

    $found = cloudDigitalOcean()->find('3164444');

    expect($found->id)->toBe('3164444')
        ->and($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBe('203.0.113.41')
        ->and($found->ipv4)->not->toBe('10.128.192.124')
        ->and($found->size)->toBe('s-1vcpu-2gb')
        ->and($found->isReady())->toBeTrue();
    Http::assertSent(fn(Request $request) => $request->method() === 'GET' && $request->url() === 'https://api.digitalocean.com/v2/droplets/3164444');
});

test('digitalocean picks the public address wherever the droplet lists it', function () {
    $body = CloudFixtures::json('digitalocean/droplet-active');
    $body['droplet']['networks']['v4'] = array_reverse($body['droplet']['networks']['v4']);
    Http::fake(['api.digitalocean.com/v2/droplets/3164444' => Http::response($body)]);

    expect(cloudDigitalOcean()->find('3164444')->ipv4)->toBe('203.0.113.41');
});

test('digitalocean reads a droplet with only a private address as not ready', function () {
    $body = CloudFixtures::json('digitalocean/droplet-active');
    $body['droplet']['networks']['v4'] = [$body['droplet']['networks']['v4'][0]];
    Http::fake(['api.digitalocean.com/v2/droplets/3164444' => Http::response($body)]);

    $found = cloudDigitalOcean()->find('3164444');

    expect($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBeNull()
        ->and($found->isReady())->toBeFalse();
});

test('digitalocean reads a droplet whose networks are missing or malformed as having no address', function (mixed $networks) {
    Http::fake(['api.digitalocean.com/v2/droplets/3164444' => Http::response(CloudFixtures::with('digitalocean/droplet-active', ['droplet.networks.v4' => $networks]))]);

    $found = cloudDigitalOcean()->find('3164444');

    expect($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBeNull()
        ->and($found->isReady())->toBeFalse();
})->with([
    'no addresses' => [null],
    'an empty list' => [[]],
    'a single address given as text' => ['203.0.113.41'],
    'entries that are not objects' => [['203.0.113.41', null, 42]],
    'a public entry with no address' => [[['type' => 'public']]],
]);

test('digitalocean ignores an address that the droplet does not list as public', function () {
    $body = CloudFixtures::json('digitalocean/droplet-active');
    $body['droplet']['networks']['v4'] = [['ip_address' => '203.0.113.50', 'netmask' => '255.255.240.0', 'gateway' => '203.0.113.1', 'type' => 'private']];
    Http::fake(['api.digitalocean.com/v2/droplets/3164444' => Http::response($body)]);

    expect(cloudDigitalOcean()->find('3164444')->ipv4)->toBeNull();
});

test('digitalocean reads a droplet whose public entry is not a public address as having none', function () {
    Http::fake(['api.digitalocean.com/v2/droplets/3164444' => Http::response(CloudFixtures::with('digitalocean/droplet-active', [
        'droplet.networks.v4.1.ip_address' => '10.128.0.9',
    ]))]);

    expect(cloudDigitalOcean()->find('3164444')->ipv4)->toBeNull();
});

test('digitalocean reads a droplet that is not there as nothing', function () {
    Http::fake(['api.digitalocean.com/v2/droplets/9999' => Http::response(CloudFixtures::json('digitalocean/error-not-found'), 404)]);

    expect(cloudDigitalOcean()->find('9999'))->toBeNull();

    Http::assertSentCount(1);
});

test('digitalocean maps every droplet status', function (string $status, CloudInstanceState $state) {
    Http::fake(['api.digitalocean.com/v2/droplets/3164444' => Http::response(CloudFixtures::with('digitalocean/droplet-active', ['droplet.status' => $status]))]);

    expect(cloudDigitalOcean()->find('3164444')->state)->toBe($state);
})->with([
    'new' => ['new', CloudInstanceState::Starting],
    'active' => ['active', CloudInstanceState::Running],
    'off' => ['off', CloudInstanceState::Stopped],
    'archive' => ['archive', CloudInstanceState::Deleting],
    'a status it has not heard of' => ['weird', CloudInstanceState::Unknown],
]);

test('digitalocean accepts an id it is given as text', function () {
    Http::fake([
        'api.digitalocean.com/v2/droplets/3164444' => Http::response(CloudFixtures::with('digitalocean/droplet-active', ['droplet.id' => '3164444'])),
        'api.digitalocean.com/v2/account/keys/*' => Http::response(CloudFixtures::with('digitalocean/ssh_key', ['ssh_key.id' => '512189'])),
    ]);
    $api = cloudDigitalOcean();

    expect($api->find('3164444')->id)->toBe('3164444')
        ->and($api->uploadKey('coresmith-outpost-02-b1n0d', cloudDigitalOceanKey()))->toBe('512189');
});

test('digitalocean reads a droplet with no status as unknown', function () {
    $body = CloudFixtures::json('digitalocean/droplet-active');
    unset($body['droplet']['status']);
    Http::fake(['api.digitalocean.com/v2/droplets/3164444' => Http::response($body)]);

    expect(cloudDigitalOcean()->find('3164444')->state)->toBe(CloudInstanceState::Unknown);
});

test('digitalocean finds a droplet by tag', function () {
    Http::fake(['api.digitalocean.com/v2/droplets*' => Http::sequence()
        ->push(CloudFixtures::json('digitalocean/droplets-tagged'))
        ->push(CloudFixtures::json('digitalocean/droplets-empty'))]);
    $api = cloudDigitalOcean();

    $found = $api->findTagged(cloudDigitalOceanTag());

    expect($found->id)->toBe('3164444')
        ->and($found->state)->toBe(CloudInstanceState::Running)
        ->and($found->ipv4)->toBe('203.0.113.41')
        ->and($found->size)->toBe('s-1vcpu-2gb')
        ->and($api->findTagged(cloudDigitalOceanTag()))->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'GET'
        && $request->uri()->path() === 'v2/droplets'
        && $request->uri()->query()->get('tag_name') === 'coresmith-01jcp7m3zq9x4v6t8w2y5b1n0d');
});

test('digitalocean takes the first droplet when a tag matches several', function () {
    $body = CloudFixtures::json('digitalocean/droplets-tagged');
    $second = $body['droplets'][0];
    $second['id'] = 3164445;
    $body['droplets'][] = $second;
    Http::fake(['api.digitalocean.com/v2/droplets*' => Http::response($body)]);

    expect(cloudDigitalOcean()->findTagged(cloudDigitalOceanTag())->id)->toBe('3164444');
});

test('digitalocean returns the droplet that carries the tag itself, not just the first one listed', function () {
    $body = CloudFixtures::with('digitalocean/droplets-tagged');
    $mine = $body['droplets'][0];
    $elsewhere = CloudFixtures::with('digitalocean/droplets-tagged', ['droplets.0.id' => 3164445, 'droplets.0.tags' => ['coresmith', 'coresmith-01jother']])['droplets'][0];
    $untagged = CloudFixtures::with('digitalocean/droplets-tagged', ['droplets.0.id' => 3164446, 'droplets.0.tags' => []])['droplets'][0];
    $body['droplets'] = [$elsewhere, $untagged, 'oops', $mine];
    Http::fake(['api.digitalocean.com/v2/droplets*' => Http::response($body)]);

    expect(cloudDigitalOcean()->findTagged(cloudDigitalOceanTag())->id)->toBe('3164444');
});

test('digitalocean fails a tag lookup that lists droplets but none that carries the tag, so an unrelated droplet is never handed back', function (mixed $tags) {
    Http::fake(['api.digitalocean.com/v2/droplets*' => Http::response(CloudFixtures::with('digitalocean/droplets-tagged', ['droplets.0.tags' => $tags]))]);

    expect(fn() => cloudDigitalOcean()->findTagged(cloudDigitalOceanTag()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('DigitalOcean had a problem answering. Try again in a minute.');
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

test('digitalocean reads a list that is an object, even an empty one, as unavailable', function (string $call, string $list, mixed $value) {
    Http::fake(['api.digitalocean.com/v2/*' => Http::response([$list => $value])]);
    $attempt = match ($call) {
        'findTagged' => fn() => cloudDigitalOcean()->findTagged(cloudDigitalOceanTag()),
        default => fn() => cloudDigitalOcean()->{$call}(),
    };

    expect($attempt)->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('DigitalOcean had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'regions that are an object' => ['regions', 'regions', ['fra1' => ['slug' => 'fra1']]],
    'regions that are an empty object' => ['regions', 'regions', new stdClass],
    'sizes that are an empty object' => ['sizes', 'sizes', new stdClass],
    'images that are an empty object' => ['images', 'images', new stdClass],
    'droplets that are an empty object' => ['findTagged', 'droplets', new stdClass],
]);

test('digitalocean reads the size of a droplet from its size slug', function () {
    Http::fake(['api.digitalocean.com/v2/droplets/3164444' => Http::sequence()
        ->push(CloudFixtures::with('digitalocean/droplet-active', ['droplet.size_slug' => 's-2vcpu-4gb']))
        ->push(Arr::except(CloudFixtures::with('digitalocean/droplet-active'), 'droplet.size_slug'))]);
    $api = cloudDigitalOcean();

    expect($api->find('3164444')->size)->toBe('s-2vcpu-4gb')
        ->and($api->find('3164444')->size)->toBeNull();
});

test('digitalocean sends reboot, shutdown, power on and a disk-keeping resize as actions', function () {
    Http::fake(['api.digitalocean.com/v2/droplets/3164444/actions' => Http::response(CloudFixtures::json('digitalocean/action'), 201)]);
    $api = cloudDigitalOcean();

    $api->reboot('3164444');
    $api->powerOff('3164444');
    $api->powerOn('3164444');
    $api->resize('3164444', 's-2vcpu-4gb');

    $sent = Http::recorded(fn(Request $request) => $request->method() === 'POST')
        ->map(fn(array $exchange): array => [$exchange[0]->method(), $exchange[0]->url(), $exchange[0]->body()])
        ->values()
        ->all();

    expect($sent)->toBe([
        ['POST', 'https://api.digitalocean.com/v2/droplets/3164444/actions', '{"type":"reboot"}'],
        ['POST', 'https://api.digitalocean.com/v2/droplets/3164444/actions', '{"type":"shutdown"}'],
        ['POST', 'https://api.digitalocean.com/v2/droplets/3164444/actions', '{"type":"power_on"}'],
        ['POST', 'https://api.digitalocean.com/v2/droplets/3164444/actions', '{"type":"resize","size":"s-2vcpu-4gb","disk":false}'],
    ])
        ->and($api->uploadsKeys())->toBeTrue()
        ->and($api->resizeNeedsPowerOff())->toBeTrue();
});

test('digitalocean accepts an action or a delete that answers with no body', function () {
    Http::fake(['api.digitalocean.com/v2/*' => Http::response('', 204)]);
    $api = cloudDigitalOcean();

    $api->reboot('3164444');
    $api->powerOff('3164444');
    $api->powerOn('3164444');
    $api->resize('3164444', 's-2vcpu-4gb');
    $api->destroy('3164444');
    $api->deleteKey('512189');

    Http::assertSentCount(6);
});

test('digitalocean deletes a droplet and a key it holds', function () {
    Http::fake(['api.digitalocean.com/v2/*' => Http::response('', 204)]);
    $api = cloudDigitalOcean();

    $api->destroy('3164444');
    $api->deleteKey('512189');

    $sent = Http::recorded(fn(Request $request) => $request->method() === 'DELETE')
        ->map(fn(array $exchange): array => [$exchange[0]->method(), $exchange[0]->url()])
        ->values()
        ->all();

    expect($sent)->toBe([
        ['DELETE', 'https://api.digitalocean.com/v2/droplets/3164444'],
        ['DELETE', 'https://api.digitalocean.com/v2/account/keys/512189'],
    ]);
});

test('digitalocean does not take a not found page for a droplet or key that is gone', function (string $call, string $id) {
    Http::fake(['api.digitalocean.com/v2/*' => Http::response('<html><body>404 Not Found</body></html>', 404, ['Content-Type' => 'text/html'])]);

    expect(fn() => cloudDigitalOcean()->{$call}($id))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->status)->toBe(404)
            ->and($e->getMessage())->toBe('DigitalOcean had a problem answering (HTTP 404). Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'a lookup' => ['find', '3164444'],
    'a destroy' => ['destroy', '3164444'],
    'a key delete' => ['deleteKey', '512189'],
]);

test('digitalocean does not upload a key because a lookup answered with a not found page', function () {
    Http::fake(['api.digitalocean.com/v2/account/keys/*' => Http::response('<html><body>404 Not Found</body></html>', 404, ['Content-Type' => 'text/html'])]);

    expect(fn() => cloudDigitalOcean()->uploadKey('coresmith-outpost-02-b1n0d', cloudDigitalOceanKey()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->retryable())->toBeTrue()->and($e->status)->toBe(404);
    });

    Http::assertSentCount(1);
    Http::assertNotSent(fn(Request $request) => $request->method() === 'POST');
});

test('digitalocean treats a droplet that is already gone as destroyed', function () {
    Http::fake([
        'api.digitalocean.com/v2/droplets/3164444' => Http::response(CloudFixtures::json('digitalocean/error-not-found'), 404),
        'api.digitalocean.com/v2/account/keys/512189' => Http::response(CloudFixtures::json('digitalocean/error-not-found'), 404),
    ]);
    $api = cloudDigitalOcean();

    $api->destroy('3164444');
    $api->deleteKey('512189');

    Http::assertSentCount(2);
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.digitalocean.com/v2/droplets/3164444');
    Http::assertSent(fn(Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://api.digitalocean.com/v2/account/keys/512189');
});

test('digitalocean refuses an id that names nothing before it sends anything', function (string $call, string $id) {
    $arguments = $call === 'resize' ? [$id, 's-2vcpu-4gb'] : [$id];

    expect(fn() => cloudDigitalOcean()->{$call}(...$arguments))->toThrow(LogicException::class, 'A cloud id must name one resource.');

    Http::assertNothingSent();
})->with(['find', 'reboot', 'powerOff', 'powerOn', 'resize', 'destroy', 'deleteKey'])->with(['', ' ', '.', '..']);

test('digitalocean reads an id that is not a whole number as unavailable, so the tag is checked before anything is made again', function (string $call, mixed $id) {
    Http::fake([
        'api.digitalocean.com/v2/account/keys/*' => $call === 'adopt'
            ? Http::response(CloudFixtures::with('digitalocean/ssh_key', ['ssh_key.id' => $id]))
            : Http::response(CloudFixtures::json('digitalocean/error-not-found'), 404),
        'api.digitalocean.com/v2/account/keys' => Http::response(CloudFixtures::with('digitalocean/ssh_key', ['ssh_key.id' => $id]), 201),
        'api.digitalocean.com/v2/droplets/3164444' => Http::response(CloudFixtures::with('digitalocean/droplet-active', ['droplet.id' => $id])),
        'api.digitalocean.com/v2/droplets?*' => Http::response(CloudFixtures::with('digitalocean/droplets-tagged', ['droplets.0.id' => $id])),
        'api.digitalocean.com/v2/droplets' => Http::response(CloudFixtures::with('digitalocean/droplet-new', ['droplet.id' => $id]), 202),
    ]);
    $api = cloudDigitalOcean();
    $attempt = match ($call) {
        'create' => fn() => $api->create(cloudDigitalOceanSpec()),
        'find' => fn() => $api->find('3164444'),
        'findTagged' => fn() => $api->findTagged(cloudDigitalOceanTag()),
        'adopt', 'upload' => fn() => $api->uploadKey('coresmith-outpost-02-b1n0d', cloudDigitalOceanKey()),
    };

    expect($attempt)->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('DigitalOcean had a problem answering. Try again in a minute.');
    });
})->with(['create', 'find', 'findTagged', 'adopt', 'upload'])->with([
    'zero' => [0],
    'a negative number' => [-3164444],
    'a fraction' => [3164444.5],
    'a flag' => [true],
    'a word' => ['abc'],
    'empty text' => [''],
    'a path' => ['../3164444'],
    'a list' => [[3164444]],
]);

test('digitalocean keeps an id inside its own path segment', function (string $call, array $arguments, string $method, string $url) {
    Http::fake(['api.digitalocean.com/*' => Http::response(['droplet' => ['id' => 7, 'status' => 'active']])]);

    cloudDigitalOcean()->{$call}(...$arguments);

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request) => $request->method() === $method && $request->url() === $url);
})->with([
    'find' => ['find', ['../7'], 'GET', 'https://api.digitalocean.com/v2/droplets/..%2F7'],
    'reboot' => ['reboot', ['../7'], 'POST', 'https://api.digitalocean.com/v2/droplets/..%2F7/actions'],
    'power off' => ['powerOff', ['../7'], 'POST', 'https://api.digitalocean.com/v2/droplets/..%2F7/actions'],
    'power on' => ['powerOn', ['../7'], 'POST', 'https://api.digitalocean.com/v2/droplets/..%2F7/actions'],
    'resize' => ['resize', ['../7', 's-2vcpu-4gb'], 'POST', 'https://api.digitalocean.com/v2/droplets/..%2F7/actions'],
    'destroy' => ['destroy', ['../account/keys/7'], 'DELETE', 'https://api.digitalocean.com/v2/droplets/..%2Faccount%2Fkeys%2F7'],
    'delete key' => ['deleteKey', ['../droplets/7'], 'DELETE', 'https://api.digitalocean.com/v2/account/keys/..%2Fdroplets%2F7'],
]);

test('digitalocean says a token without droplet access cannot create', function () {
    Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(CloudFixtures::json('digitalocean/error-forbidden'), 403)]);

    expect(fn() => cloudDigitalOcean()->create(cloudDigitalOceanSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Forbidden)
            ->and($e->retryable())->toBeFalse()
            ->and($e->detail)->toBe('You do not have access for the attempted action.')
            ->and($e->getMessage())->toContain('full access');
    });

    Http::assertSentCount(1);
});

test('digitalocean says a size is not offered in that region in its own words', function () {
    Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(CloudFixtures::json('digitalocean/error-unprocessable'), 422)]);

    expect(fn() => cloudDigitalOcean()->create(cloudDigitalOceanSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)
            ->and($e->retryable())->toBeFalse()
            ->and($e->status)->toBe(422)
            ->and($e->detail)->toBe('Size is not available in this region.')
            ->and($e->getMessage())->toContain('Size is not available in this region')
            ->and($e->getMessage())->toBe('DigitalOcean refused the request. DigitalOcean said: Size is not available in this region.');
    });

    Http::assertSentCount(1);
});

test('digitalocean reads a droplet that already has a pending event as busy, so a retry waits for it', function (string $call, array $arguments) {
    Http::fake(['api.digitalocean.com/v2/droplets/3164444*' => Http::response(['id' => 'unprocessable_entity', 'message' => 'Droplet already has a pending event.'], 422)]);

    expect(fn() => cloudDigitalOcean()->{$call}(...$arguments))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Busy)
            ->and($e->retryable())->toBeTrue()
            ->and($e->status)->toBe(422)
            ->and($e->detail)->toBe('Droplet already has a pending event.')
            ->and($e->getMessage())->toBe('DigitalOcean is busy with another change to it. DigitalOcean said: Droplet already has a pending event. Coresmith tries again shortly.');
    });

    Http::assertSentCount(1);
})->with([
    'reboot' => ['reboot', ['3164444']],
    'power off' => ['powerOff', ['3164444']],
    'power on' => ['powerOn', ['3164444']],
    'resize' => ['resize', ['3164444', 's-2vcpu-4gb']],
    'destroy' => ['destroy', ['3164444']],
]);

test('digitalocean reads the words of a pending event in any case', function (string $message) {
    Http::fake(['api.digitalocean.com/v2/droplets/3164444/actions' => Http::response(['id' => 'unprocessable_entity', 'message' => $message], 422)]);

    expect(fn() => cloudDigitalOcean()->powerOn('3164444'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Busy);
    });
})->with([
    'in capitals' => ['DROPLET ALREADY HAS A PENDING EVENT.'],
    'with more said' => ['Droplet already has a pending event. Wait for it to finish.'],
]);

test('digitalocean keeps every other refusal a refusal', function (int $status, mixed $message) {
    Http::fake(['api.digitalocean.com/v2/droplets/3164444/actions' => Http::response(['id' => 'unprocessable_entity', 'message' => $message], $status)]);

    expect(fn() => cloudDigitalOcean()->powerOn('3164444'))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->retryable())->toBeFalse();
    });
})->with([
    'a 422 about something else' => [422, 'Size is not available in this region.'],
    'a 422 about an event that is not pending' => [422, 'Droplet has no such event.'],
    'a 422 with no message' => [422, null],
    'a 422 whose message is not text' => [422, ['Droplet already has a pending event.']],
    'the pending event words on a 400' => [400, 'Droplet already has a pending event.'],
    'the pending event words on a 409' => [409, 'Droplet already has a pending event.'],
]);

test('digitalocean never retries a create that met a server error', function () {
    Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(['id' => 'server_error', 'message' => 'Server Error'], 503)]);

    expect(fn() => cloudDigitalOcean()->create(cloudDigitalOceanSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->status)->toBe(503)
            ->and($e->retryable())->toBeTrue();
    });

    Http::assertSentCount(1);
});

test('digitalocean reads a rate limit as one to wait out', function () {
    Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(['id' => 'too_many_requests', 'message' => 'API Rate limit exceeded.'], 429)]);

    expect(fn() => cloudDigitalOcean()->create(cloudDigitalOceanSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::RateLimited)
            ->and($e->retryable())->toBeTrue()
            ->and($e->detail)->toBe('API Rate limit exceeded.');
    });

    Http::assertSentCount(1);
});

test('digitalocean ignores an error message that is not text', function (mixed $message) {
    Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(['id' => 'unprocessable_entity', 'message' => $message], 422)]);

    expect(fn() => cloudDigitalOcean()->create(cloudDigitalOceanSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->detail)->toBeNull();
    });
})->with([
    'a list' => [['name', 'image']],
    'a number' => [42],
    'a flag' => [true],
]);

test('digitalocean reads a create whose answer has no droplet in it as unavailable, so its tag is checked before another is made', function (string $body, array $headers) {
    Http::fake(['api.digitalocean.com/v2/droplets' => Http::response($body, 202, $headers)]);

    expect(fn() => cloudDigitalOcean()->create(cloudDigitalOceanSpec()))->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)
            ->and($e->retryable())->toBeTrue()
            ->and($e->getMessage())->toBe('DigitalOcean had a problem answering. Try again in a minute.');
    });

    Http::assertSentCount(1);
})->with([
    'a json object without a droplet' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'a droplet that is not an object' => ['{"droplet":"gone"}', ['Content-Type' => 'application/json']],
    'a droplet with no id' => ['{"droplet":{"status":"new"}}', ['Content-Type' => 'application/json']],
    'an empty body' => ['', []],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
]);

test('digitalocean does not mistake a lookup it cannot read for a droplet that is not there', function (string $body, array $headers) {
    Http::fake(['api.digitalocean.com/v2/droplets*' => Http::response($body, 200, $headers)]);
    $api = cloudDigitalOcean();

    foreach ([fn() => $api->find('3164444'), fn() => $api->findTagged(cloudDigitalOceanTag())] as $lookup) {
        expect($lookup)->toThrow(function (CloudProviderException $e) {
            expect($e->failure)->toBe(CloudFailure::Unavailable)
                ->and($e->getMessage())->toBe('DigitalOcean had a problem answering. Try again in a minute.');
        });
    }

    Http::assertSentCount(2);
})->with([
    'a json object without the data' => ['{"unexpected":true}', ['Content-Type' => 'application/json']],
    'an html page' => ['<html><body>Gateway</body></html>', ['Content-Type' => 'text/html']],
    'nothing at all' => ['', []],
]);

test('digitalocean does not verify a token, or read a catalog, from an answer that is not json', function (string $call) {
    Http::fake(['api.digitalocean.com/*' => Http::response('<html><body>Gateway</body></html>', 200, ['Content-Type' => 'text/html'])]);

    expect(fn() => cloudDigitalOcean()->{$call}())->toThrow(function (CloudProviderException $e) {
        expect($e->failure)->toBe(CloudFailure::Unavailable)->and($e->detail)->toBe('answered without JSON');
    });

    Http::assertSentCount(1);
})->with(['verify', 'regions', 'sizes', 'images']);

test('digitalocean says when a refused create is the droplet limit', function (string $message, ?CloudRefusal $refusal) {
    Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(['id' => 'unprocessable_entity', 'message' => $message], 422)]);

    expect(fn() => cloudDigitalOcean()->create(cloudDigitalOceanSpec()))->toThrow(function (CloudProviderException $e) use ($refusal) {
        expect($e->failure)->toBe(CloudFailure::Refused)->and($e->refusal)->toBe($refusal);
    });
})->with([
    'the droplet limit' => ['creating this/these droplet(s) will exceed your droplet limit', CloudRefusal::LimitReached],
    'the droplet limit, in capitals' => ['Your DROPLET LIMIT is 10', CloudRefusal::LimitReached],
    'anything else' => ['Region is not available', null],
]);
