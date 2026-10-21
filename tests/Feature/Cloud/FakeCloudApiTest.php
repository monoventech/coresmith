<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudInstanceState;
use App\Enums\CloudProviderType;
use App\Exceptions\CloudProviderException;
use App\Models\CloudProvider;
use App\Services\Cloud\CloudAccount;
use App\Services\Cloud\CloudImage;
use App\Services\Cloud\CloudInstance;
use App\Services\Cloud\CloudPrice;
use App\Services\Cloud\CloudRegion;
use App\Services\Cloud\CloudServerSpec;
use App\Services\Cloud\CloudSize;
use App\Services\Cloud\Contracts\CloudApi;
use App\Services\Cloud\Contracts\CloudApiFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\CloudFixtures;
use Tests\Support\FakeCloudApi;
use Tests\Support\FakeCloudApiFactory;

beforeEach(fn() => Http::preventStrayRequests());

function cloudFakeInstance(string $id = '4711', CloudInstanceState $state = CloudInstanceState::Running, ?string $ipv4 = '203.0.113.40'): CloudInstance
{
    return new CloudInstance($id, $state, $ipv4, 'cx22');
}

function cloudFakeSpec(): CloudServerSpec
{
    return new CloudServerSpec('outpost-02', 'fsn1', 'cx22', 'ubuntu-24.04', 'coresmith-01j', 'ssh-ed25519 AAAA', 'key-1');
}

function cloudFakeFailure(CloudFailure $failure = CloudFailure::Unavailable): CloudProviderException
{
    return new CloudProviderException($failure, CloudProviderType::Hetzner, null, 503);
}

test('a fake provider answers with sensible defaults', function () {
    $api = new FakeCloudApi;

    expect($api)->toBeInstanceOf(CloudApi::class)
        ->and($api->type())->toBe(CloudProviderType::Hetzner)
        ->and($api->uploadsKeys())->toBeTrue()
        ->and($api->resizeNeedsPowerOff())->toBeTrue()
        ->and($api->verify())->toEqual(new CloudAccount(null))
        ->and($api->regions()->all())->toBe([])
        ->and($api->sizes()->all())->toBe([])
        ->and($api->images()->all())->toBe([])
        ->and($api->uploadKey('coresmith-outpost-02', 'ssh-ed25519 AAAA'))->toBe('key-1')
        ->and($api->create(cloudFakeSpec()))->toEqual(new CloudInstance('4711', CloudInstanceState::Starting, null, 'cx22'))
        ->and($api->find('4711'))->toBeNull()
        ->and($api->findTagged('coresmith-01j'))->toBeNull();
});

test('a fake provider can pose as one that takes keys inline and cycles power itself', function () {
    $api = new FakeCloudApi(CloudProviderType::Linode, uploads: false, powerCycle: false);

    expect($api->type())->toBe(CloudProviderType::Linode)
        ->and($api->uploadsKeys())->toBeFalse()
        ->and($api->resizeNeedsPowerOff())->toBeFalse();
});

test('a fake provider hands back what it was programmed with', function () {
    $account = new CloudAccount('ops@monoven.io');
    $region = new CloudRegion('fsn1', 'Falkenstein (fsn1)', 'DE');
    $size = new CloudSize('cx22', 2, 4096, 40, 'x86', new CloudPrice('3.79', 'EUR'));
    $image = new CloudImage('ubuntu-24.04', 'ubuntu', '24.04', ['x86']);
    $created = cloudFakeInstance('99', CloudInstanceState::Starting, null);

    $api = new FakeCloudApi;

    expect($api->account($account))->toBe($api)
        ->and($api->catalog([$region], [$size], [$image]))->toBe($api)
        ->and($api->creates($created))->toBe($api)
        ->and($api->keyId('key-77'))->toBe($api)
        ->and($api->verify())->toBe($account)
        ->and($api->regions()->all())->toBe([$region])
        ->and($api->sizes()->all())->toBe([$size])
        ->and($api->images()->all())->toBe([$image])
        ->and($api->create(cloudFakeSpec()))->toBe($created)
        ->and($api->uploadKey('coresmith-outpost-02', 'ssh-ed25519 AAAA'))->toBe('key-77');
});

test('successive lookups walk the programmed sequence and the last answer repeats', function () {
    $starting = cloudFakeInstance('4711', CloudInstanceState::Starting, null);
    $running = cloudFakeInstance();
    $api = (new FakeCloudApi)->finds($starting, null, $running)->tagged($running);

    expect($api->find('4711'))->toBe($starting)
        ->and($api->find('4711'))->toBeNull()
        ->and($api->find('4711'))->toBe($running)
        ->and($api->find('4711'))->toBe($running)
        ->and($api->findTagged('coresmith-01j'))->toBe($running)
        ->and($api->findTagged('coresmith-01j'))->toBe($running);
});

test('programming a lookup again starts its sequence over', function () {
    $first = cloudFakeInstance('1');
    $second = cloudFakeInstance('2');
    $api = (new FakeCloudApi)->finds($first, $second);

    $api->find('1');
    $api->finds($second, $first);

    expect($api->find('1'))->toBe($second)->and($api->find('1'))->toBe($first)->and($api->find('1'))->toBe($first);
});

test('a lookup programmed with nothing finds nothing', function () {
    $api = (new FakeCloudApi)->finds()->tagged();

    expect($api->find('4711'))->toBeNull()->and($api->findTagged('coresmith-01j'))->toBeNull();
});

test('a queued failure is thrown for its own method, as many times as asked, then the method works again', function () {
    $failure = cloudFakeFailure();
    $api = (new FakeCloudApi)->failing('create', $failure, 2);
    $outcomes = [];

    foreach (range(1, 3) as $attempt) {
        try {
            $outcomes[] = $api->create(cloudFakeSpec());
        } catch (CloudProviderException $e) {
            $outcomes[] = $e;
        }
    }

    expect($outcomes[0])->toBe($failure)
        ->and($outcomes[1])->toBe($failure)
        ->and($outcomes[2])->toBeInstanceOf(CloudInstance::class)
        ->and($api->find('4711'))->toBeNull();
});

test('failures queued for a method are thrown in the order they were queued', function () {
    $first = cloudFakeFailure(CloudFailure::Unavailable);
    $second = cloudFakeFailure(CloudFailure::Unreachable);
    $api = (new FakeCloudApi)->failing('destroy', $first)->failing('destroy', $second);
    $thrown = [];

    foreach (range(1, 3) as $attempt) {
        try {
            $api->destroy('4711');
            $thrown[] = null;
        } catch (CloudProviderException $e) {
            $thrown[] = $e;
        }
    }

    expect($thrown)->toBe([$first, $second, null]);
});

test('a failure can be queued for any method of the contract', function (string $method, array $arguments) {
    $failure = cloudFakeFailure();
    $api = (new FakeCloudApi)->failing($method, $failure);

    expect(fn() => $api->{$method}(...$arguments))->toThrow($failure)
        ->and($api->calls($method))->toHaveCount(1);
})->with([
    ['verify', []],
    ['regions', []],
    ['sizes', []],
    ['images', []],
    ['uploadKey', ['name', 'ssh-ed25519 AAAA']],
    ['deleteKey', ['key-1']],
    ['find', ['4711']],
    ['findTagged', ['coresmith-01j']],
    ['reboot', ['4711']],
    ['powerOff', ['4711']],
    ['powerOn', ['4711']],
    ['resize', ['4711', 'cx32']],
    ['destroy', ['4711']],
]);

test('every call is recorded with its arguments in the order it was made', function () {
    $spec = cloudFakeSpec();
    $api = new FakeCloudApi;

    $api->uploadKey('coresmith-outpost-02', 'ssh-ed25519 AAAA');
    $api->create($spec);
    $api->find('4711');
    $api->find('4712');
    $api->reboot('4711');
    $api->resize('4711', 'cx32');
    $api->deleteKey('key-1');

    expect($api->calls('find'))->toBe([['find', ['4711']], ['find', ['4712']]])
        ->and($api->calls('create'))->toBe([['create', [$spec]]])
        ->and($api->calls('resize'))->toBe([['resize', ['4711', 'cx32']]])
        ->and($api->calls('destroy'))->toBe([])
        ->and(array_column($api->calls(), 0))->toBe(['uploadKey', 'create', 'find', 'find', 'reboot', 'resize', 'deleteKey'])
        ->and($api->calls()[0])->toBe(['uploadKey', ['coresmith-outpost-02', 'ssh-ed25519 AAAA']]);
});

test('a call that fails is recorded too', function () {
    $api = (new FakeCloudApi)->failing('create', cloudFakeFailure());

    expect(fn() => $api->create(cloudFakeSpec()))->toThrow(CloudProviderException::class);
    expect($api->calls('create'))->toHaveCount(1);
});

test('the fake answers every method of the contract and records each one', function () {
    $api = new FakeCloudApi;

    $api->type();
    $api->verify();
    $api->regions();
    $api->sizes();
    $api->images();
    $api->uploadsKeys();
    $api->uploadKey('name', 'ssh-ed25519 AAAA');
    $api->deleteKey('key-1');
    $api->create(cloudFakeSpec());
    $api->find('4711');
    $api->findTagged('coresmith-01j');
    $api->reboot('4711');
    $api->resizeNeedsPowerOff();
    $api->powerOff('4711');
    $api->powerOn('4711');
    $api->resize('4711', 'cx32');
    $api->destroy('4711');

    $contract = get_class_methods(CloudApi::class);
    $recorded = array_column($api->calls(), 0);

    sort($contract);
    sort($recorded);

    expect($recorded)->toBe($contract)->and($contract)->toHaveCount(17);
});

test('the factory hands out its one api and remembers how it was asked', function () {
    $api = new FakeCloudApi(CloudProviderType::Vultr);
    $factory = new FakeCloudApiFactory($api);
    $first = CloudProvider::factory()->create();
    $second = CloudProvider::factory()->create();

    expect($factory)->toBeInstanceOf(CloudApiFactory::class)
        ->and($factory->api)->toBe($api)
        ->and($factory->made())->toBe([])
        ->and($factory->accounts())->toBe([])
        ->and($factory->for($first))->toBe($api)
        ->and($factory->for($second))->toBe($api)
        ->and($factory->make(CloudProviderType::Linode, 'tok-1'))->toBe($api)
        ->and($factory->make(CloudProviderType::Hetzner, 'tok-2'))->toBe($api)
        ->and($factory->accounts())->toBe([$first->id, $second->id])
        ->and($factory->made())->toBe([[CloudProviderType::Linode, 'tok-1'], [CloudProviderType::Hetzner, 'tok-2']]);
});

test('a factory built with nothing hands out a fake of its own', function () {
    $factory = new FakeCloudApiFactory;

    expect($factory->api)->toBeInstanceOf(FakeCloudApi::class)
        ->and($factory->make(CloudProviderType::Hetzner, 'tok'))->toBe($factory->api);
});

test('the token parameter is sensitive on the contract and on the fake', function (string $class) {
    $parameters = (new ReflectionMethod($class, 'make'))->getParameters();

    expect($parameters[1]->getName())->toBe('token')
        ->and($parameters[1]->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([CloudApiFactory::class, FakeCloudApiFactory::class]);

test('a fixture that does not exist says where it looked', function () {
    expect(fn() => CloudFixtures::json('nowhere/nothing'))
        ->toThrow(InvalidArgumentException::class, 'tests/Fixtures/Cloud/nowhere/nothing.json');
});
