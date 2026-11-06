<?php

declare(strict_types=1);

use App\Enums\CloudFailure;
use App\Enums\CloudProviderType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Exceptions\CloudProviderException;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Cloud\HttpCloudApiFactory;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Panel\CloudPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\CloudFixtures;
use Tests\Support\FakeCloudApiFactory;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00'));
});

function cloudPresenterFake(): FakeCloudApiFactory
{
    $fake = new FakeCloudApiFactory;
    CloudFixtures::catalogue($fake->api);
    app()->instance(CloudApiFactory::class, $fake);

    return $fake;
}

function cloudPresenterMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function cloudPresenterServer(?string $state = null, ?string $step = null, array $attributes = []): Server
{
    $account = CloudProvider::factory()->create(['name' => 'Hetzner production']);
    $server = Server::factory()->fromProvider($account)->create(['name' => 'outpost-02', 'status' => ServerStatus::Creating, 'ip_address' => null, ...$attributes]);

    if ($state !== null) {
        CloudTask::start($server, CloudTask::CREATE, 'create', ['image_label' => 'Ubuntu 24.04', 'region_label' => 'Falkenstein (fsn1)']);

        if ($step !== null && $step !== 'create') {
            CloudTask::advance($server, $step);
        }

        match ($state) {
            CloudTask::FAILED => CloudTask::fail($server, 'Hetzner Cloud refused the size.'),
            CloudTask::DONE => CloudTask::finish($server),
            default => null,
        };
    }

    return $server->fresh();
}

test('the catalog payload carries prices formatted per region', function () {
    cloudPresenterFake();
    $account = CloudProvider::factory()->create();

    expect(app(CloudPresenter::class)->catalog($account))->toBe([
        'error' => null,
        'provider' => 'Hetzner Cloud',
        'regions' => [['id' => 'fsn1', 'label' => 'Falkenstein (fsn1)']],
        'sizes' => [
            ['id' => 'cx22', 'label' => '4 GB · 2 vCPU', 'cpus' => 2, 'memory_mb' => 4096, 'disk_gb' => 40, 'architecture' => 'x86', 'regions' => ['fsn1'], 'prices' => ['fsn1' => '€3.79'], 'price' => null],
            ['id' => 'cax11', 'label' => '4 GB · 2 vCPU', 'cpus' => 2, 'memory_mb' => 4096, 'disk_gb' => 40, 'architecture' => 'arm', 'regions' => ['fsn1'], 'prices' => ['fsn1' => '€4.49'], 'price' => null],
        ],
        'images' => [
            ['id' => 'ubuntu-24.04', 'label' => 'Ubuntu 24.04', 'architectures' => ['x86', 'arm']],
            ['id' => 'debian-12', 'label' => 'Debian 12', 'architectures' => ['x86']],
        ],
        'defaults' => ['region' => 'fsn1', 'size' => 'cx22', 'image' => 'ubuntu-24.04'],
    ]);
});

test("a refused account's catalog says so without asking the provider", function () {
    $fake = cloudPresenterFake();
    $account = CloudProvider::factory()->refused()->create();

    $catalog = app(CloudPresenter::class)->catalog($account);

    expect($catalog['error'])->toBe("Hetzner Cloud refused this account's token on Oct 20. Replace it in Settings → Team, then try again.")
        ->and([$catalog['regions'], $catalog['sizes'], $catalog['images']])->toBe([[], [], []])
        ->and($fake->api->calls())->toBe([]);
});

test('a provider that cannot answer is reported in the catalog, and a refused token marks the account', function (CloudFailure $failure, bool $refused) {
    $fake = cloudPresenterFake();
    $fake->api->failing('regions', new CloudProviderException($failure, CloudProviderType::Hetzner));
    $account = CloudProvider::factory()->create();

    $catalog = app(CloudPresenter::class)->catalog($account);

    expect($catalog['error'])->toStartWith("Coresmith couldn't load Hetzner Cloud's regions and sizes. ")
        ->and($catalog['sizes'])->toBe([])
        ->and($account->fresh()->isRefused())->toBe($refused);
})->with([
    'unreachable' => [CloudFailure::Unreachable, false],
    'a rejected token' => [CloudFailure::TokenRejected, true],
]);

test('creation steps follow the task', function (string $state, string $step, array $states, int $position, string $label) {
    $team = Team::factory()->create();
    $server = cloudPresenterServer($state, $step, ['team_id' => $team->id]);

    $block = app(CloudPresenter::class)->server($server, cloudPresenterMember($team, TeamRole::Owner));

    expect(array_column($block['task']['steps'], 'state'))->toBe($states)
        ->and(array_column($block['task']['steps'], 'name'))->toBe(['Create the server', 'Boot', 'Sign in over SSH', 'Let first-boot setup finish'])
        ->and(array_column($block['task']['steps'], 'desc'))->toBe(["with Coresmith's SSH key on it", 'and get a public IP address', 'with the key made for this server', 'cloud-init and package updates'])
        ->and($block['task']['position'])->toBe($position)
        ->and($block['task']['total'])->toBe(4)
        ->and($block['task']['step_label'])->toBe($label)
        ->and($block['task']['state'])->toBe($state);
})->with([
    'booting' => [CloudTask::RUNNING, 'boot', ['done', 'running', 'pending', 'pending'], 2, 'waiting for it to boot'],
    'failed signing in' => [CloudTask::FAILED, 'ssh', ['done', 'done', 'failed', 'pending'], 3, 'signing in over SSH'],
    'done' => [CloudTask::DONE, 'settle', ['done', 'done', 'done', 'done'], 4, 'waiting for first-boot setup'],
    'just started' => [CloudTask::RUNNING, 'create', ['running', 'pending', 'pending', 'pending'], 1, 'creating it'],
]);

test('the server block names where it runs and what it costs', function () {
    $team = Team::factory()->create();
    $server = cloudPresenterServer(CloudTask::FAILED, 'boot', ['team_id' => $team->id, 'provider_monthly_cost' => '4.35', 'provider_currency' => 'EUR']);

    $block = app(CloudPresenter::class)->server($server, cloudPresenterMember($team, TeamRole::Owner));

    expect(collect($block)->except('task', 'can')->all())->toBe([
        'provider' => 'Hetzner Cloud',
        'type' => 'hetzner',
        'account' => 'Hetzner production',
        'instance' => '4711',
        'ip' => $server->ip_address,
        'region' => 'fsn1',
        'size' => 'cx22',
        'image' => 'Ubuntu 24.04',
        'monthly' => '€4.35',
        'power_cycle' => true,
        'uncertain' => false,
    ])->and($block['task']['type'])->toBe('create')
        ->and($block['task']['error'])->toBe('Hetzner Cloud refused the size.')
        ->and($block['task']['to'])->toBeNull();
});

test('the server block falls back to the stored image when the task did not name it', function () {
    $team = Team::factory()->create();
    $server = cloudPresenterServer(attributes: ['team_id' => $team->id, 'status' => ServerStatus::Active, 'provider_image' => 'ubuntu-24.04']);

    $block = app(CloudPresenter::class)->server($server, cloudPresenterMember($team, TeamRole::Owner));

    expect($block['image'])->toBe('ubuntu-24.04')
        ->and($block['task'])->toBeNull();
});

test('the server block offers retry, remove and destroy only when they apply', function (?string $state, TeamRole $role, ?string $instance, array $can) {
    $team = Team::factory()->create();
    $server = cloudPresenterServer($state, 'boot', ['team_id' => $team->id, 'provider_server_id' => $instance]);

    expect(app(CloudPresenter::class)->server($server, cloudPresenterMember($team, $role))['can'])->toBe($can);
})->with([
    'a failed creation that never reached the provider, for an owner' => [CloudTask::FAILED, TeamRole::Owner, null, ['retry' => true, 'remove' => true, 'detach' => true, 'destroy' => false, 'resize' => false, 'dismiss' => false]],
    'a failed creation with an instance' => [CloudTask::FAILED, TeamRole::Owner, '4711', ['retry' => true, 'remove' => false, 'detach' => true, 'destroy' => true, 'resize' => false, 'dismiss' => false]],
    'a running creation' => [CloudTask::RUNNING, TeamRole::Owner, '4711', ['retry' => false, 'remove' => false, 'detach' => false, 'destroy' => false, 'resize' => false, 'dismiss' => false]],
    'a finished creation' => [CloudTask::DONE, TeamRole::Owner, '4711', ['retry' => false, 'remove' => false, 'detach' => true, 'destroy' => true, 'resize' => false, 'dismiss' => false]],
    'no task' => [null, TeamRole::Owner, '4711', ['retry' => false, 'remove' => false, 'detach' => true, 'destroy' => true, 'resize' => false, 'dismiss' => false]],
    'a failed creation, for a deployer' => [CloudTask::FAILED, TeamRole::Member, null, ['retry' => false, 'remove' => false, 'detach' => false, 'destroy' => false, 'resize' => false, 'dismiss' => false]],
]);

test('a running destroy offers nothing, and a failed one can be tried again by someone who can delete', function () {
    $team = Team::factory()->create();
    $server = cloudPresenterServer(attributes: ['team_id' => $team->id, 'status' => ServerStatus::Active]);
    CloudTask::start($server, CloudTask::DESTROY, 'delete');

    $running = app(CloudPresenter::class)->server($server->fresh(), cloudPresenterMember($team, TeamRole::Owner));

    CloudTask::fail($server, 'Hetzner Cloud refused the request.');

    $failed = app(CloudPresenter::class)->server($server->fresh(), cloudPresenterMember($team, TeamRole::Owner));
    $deployer = app(CloudPresenter::class)->server($server->fresh(), cloudPresenterMember($team, TeamRole::Member));

    expect($running['can'])->toBe(['retry' => false, 'remove' => false, 'detach' => false, 'destroy' => false, 'resize' => false, 'dismiss' => false])
        ->and($running['task']['step_label'])->toBe('deleting it at Hetzner Cloud')
        ->and($failed['can'])->toBe(['retry' => true, 'remove' => false, 'detach' => true, 'destroy' => true, 'resize' => true, 'dismiss' => false])
        ->and($deployer['can']['retry'])->toBeFalse();
});

test('an active provider server can be resized, and a failed resize dismissed, by someone who can change it', function () {
    $team = Team::factory()->create();
    $server = cloudPresenterServer(attributes: ['team_id' => $team->id, 'status' => ServerStatus::Active]);

    $idle = app(CloudPresenter::class)->server($server, cloudPresenterMember($team, TeamRole::Admin));
    $deployer = app(CloudPresenter::class)->server($server, cloudPresenterMember($team, TeamRole::Member));

    CloudTask::start($server, CloudTask::RESIZE, 'change', ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);
    CloudTask::fail($server, 'Hetzner Cloud refused the request.');

    $failed = app(CloudPresenter::class)->server($server->fresh(), cloudPresenterMember($team, TeamRole::Admin));
    $failedForDeployer = app(CloudPresenter::class)->server($server->fresh(), cloudPresenterMember($team, TeamRole::Member));

    $unknown = app(CloudPresenter::class)->server(cloudPresenterServer(attributes: ['team_id' => $team->id, 'status' => ServerStatus::Active, 'provider_server_id' => null]), cloudPresenterMember($team, TeamRole::Owner));

    expect($unknown['can']['resize'])->toBeFalse()
        ->and($idle['can']['resize'])->toBeTrue()
        ->and($idle['can']['dismiss'])->toBeFalse()
        ->and($deployer['can']['resize'])->toBeFalse()
        ->and($failed['can']['dismiss'])->toBeTrue()
        ->and($failed['can']['resize'])->toBeTrue()
        ->and($failed['task']['to'])->toBe('cx32')
        ->and($failedForDeployer['can']['dismiss'])->toBeFalse();
});

test('a running resize says what it is doing at each step', function (string $step, string $label) {
    $team = Team::factory()->create();
    $server = cloudPresenterServer(attributes: ['team_id' => $team->id, 'status' => ServerStatus::Active]);
    CloudTask::start($server, CloudTask::RESIZE, $step, ['from' => 'cx22', 'to' => 'cx32', 'power_cycle' => true]);

    $block = app(CloudPresenter::class)->server($server->fresh(), cloudPresenterMember($team, TeamRole::Owner));

    expect($block['task']['step_label'])->toBe($label)
        ->and($block['can']['resize'])->toBeFalse()
        ->and($block['can']['dismiss'])->toBeFalse();
})->with([
    ['power_off', 'shutting it down'],
    ['wait_off', 'shutting it down'],
    ['change', 'changing the size'],
    ['wait_change', 'changing the size'],
    ['power_on', 'starting it again'],
    ['wait_on', 'starting it again'],
    ['recover', 'starting it again'],
    ['reconnect', 'signing back in'],
]);

test('a server not from a provider has no cloud block', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    expect(app(CloudPresenter::class)->server($server, cloudPresenterMember($team, TeamRole::Owner)))->toBeNull();
});

test('the provision page picks the account asked for, then the first one not refused, then the first, then a manual server', function (string $case, string $selected) {
    $team = Team::factory()->create();
    $user = cloudPresenterMember($team, TeamRole::Owner);
    $refused = CloudProvider::factory()->for($team)->refused()->create(['name' => 'Refused', 'created_at' => now()->subDays(3)]);
    $working = CloudProvider::factory()->for($team)->create(['name' => 'Working', 'created_at' => now()->subDays(2)]);
    $other = CloudProvider::factory()->create();
    $gone = CloudProvider::factory()->for($team)->create(['name' => 'Gone']);
    $gone->delete();

    if ($case === 'only refused') {
        $working->delete();
    }

    if ($case === 'none') {
        $working->delete();
        $refused->delete();
    }

    $requested = match ($case) {
        'asked for' => $refused->id,
        'another team' => $other->id,
        'deleted' => $gone->id,
        default => null,
    };

    $expected = match ($selected) {
        'refused' => 'account:' . $refused->id,
        'working' => 'account:' . $working->id,
        default => 'manual',
    };

    expect(app(CloudPresenter::class)->choices($team, $user, $requested)['selected'])->toBe($expected);
})->with([
    'the account asked for, even refused' => ['asked for', 'refused'],
    'another team\'s account is ignored' => ['another team', 'working'],
    'a deleted account is ignored' => ['deleted', 'working'],
    'nothing asked for' => ['nothing', 'working'],
    'only a refused account' => ['only refused', 'refused'],
    'no account at all' => ['none', 'manual'],
]);

test('the provision page offers only the providers the team has not connected, and connecting needs the ability', function () {
    $team = Team::factory()->create();
    CloudProvider::factory()->for($team)->create();
    CloudProvider::factory()->for($team)->vultr()->refused()->create();

    $owner = app(CloudPresenter::class)->choices($team, cloudPresenterMember($team, TeamRole::Owner), null);
    $member = app(CloudPresenter::class)->choices($team, cloudPresenterMember($team, TeamRole::Member), null);

    expect(array_column($owner['types'], 'value'))->toBe(['digitalocean', 'linode'])
        ->and($owner['accounts'])->toHaveCount(2)
        ->and($owner['can_connect'])->toBeTrue()
        ->and($member['can_connect'])->toBeFalse();
});

test('a creation Coresmith could not confirm is offered as a destroy, never a remove', function (int $uncertain, array $can, bool $flag) {
    $team = Team::factory()->create();
    $server = cloudPresenterServer(attributes: ['team_id' => $team->id, 'provider_server_id' => null]);
    CloudTask::start($server, CloudTask::CREATE, 'create', ['uncertain' => $uncertain]);
    CloudTask::fail($server, "Coresmith couldn't get an answer from Hetzner Cloud after three tries.");

    $block = app(CloudPresenter::class)->server($server->fresh(), cloudPresenterMember($team, TeamRole::Owner));

    expect($block['can'])->toBe($can)
        ->and($block['uncertain'])->toBe($flag);
})->with([
    'after an unanswered create' => [1, ['retry' => true, 'remove' => false, 'detach' => true, 'destroy' => true, 'resize' => false, 'dismiss' => false], true],
    'after three' => [3, ['retry' => true, 'remove' => false, 'detach' => true, 'destroy' => true, 'resize' => false, 'dismiss' => false], true],
    'when nothing went unanswered' => [0, ['retry' => true, 'remove' => true, 'detach' => true, 'destroy' => false, 'resize' => false, 'dismiss' => false], false],
]);

test('a creation still running is never shown as uncertain', function () {
    $team = Team::factory()->create();
    $server = cloudPresenterServer(attributes: ['team_id' => $team->id, 'provider_server_id' => null]);
    CloudTask::start($server, CloudTask::CREATE, 'create', ['uncertain' => 1]);

    $block = app(CloudPresenter::class)->server($server->fresh(), cloudPresenterMember($team, TeamRole::Owner));

    expect($block['uncertain'])->toBeFalse()
        ->and($block['can']['destroy'])->toBeFalse();
});

test('the server block says whether a resize switches the server off, as the provider client does', function (CloudProviderType $type, bool $cycles) {
    $team = Team::factory()->create();
    $account = CloudProvider::factory()->for($team)->create(['type' => $type]);
    $server = Server::factory()->fromProvider($account)->create(['status' => ServerStatus::Active]);

    $block = app(CloudPresenter::class)->server($server, cloudPresenterMember($team, TeamRole::Owner));

    expect($block['power_cycle'])->toBe($cycles)
        ->and($type->resizeNeedsPowerOff())->toBe($cycles)
        ->and((new HttpCloudApiFactory)->make($type, 'unused-token')->resizeNeedsPowerOff())->toBe($cycles);
})->with([
    [CloudProviderType::Hetzner, true],
    [CloudProviderType::DigitalOcean, true],
    [CloudProviderType::Vultr, false],
    [CloudProviderType::Linode, false],
]);
