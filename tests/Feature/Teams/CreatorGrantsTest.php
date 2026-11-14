<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\CloudProvider;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Cloud\Contracts\CloudApiFactory;
use App\Services\Teams\TeamAccess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\CloudFixtures;
use Tests\Support\FakeCloudApiFactory;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $this->creatorGrantsFolder = sys_get_temp_dir() . '/coresmith-creator-grants-' . bin2hex(random_bytes(4));
});

afterEach(function () {
    File::deleteDirectory($this->creatorGrantsFolder);
});

/**
 * @return array{Team, User}
 */
function creatorGrantsAdmin(): array
{
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $team->members()->attach($admin, ['role' => TeamRole::Admin->value, 'sees_everything' => false]);

    return [$team, $admin->fresh()];
}

function creatorGrantsAddServer(string $how, Team $team, User $admin): TestResponse
{
    if ($how === 'at a cloud provider') {
        $fake = new FakeCloudApiFactory;
        CloudFixtures::catalogue($fake->api);
        app()->instance(CloudApiFactory::class, $fake);
        $account = CloudProvider::factory()->for($team)->create(['name' => 'Hetzner Cloud', 'api_token' => 'tok-1']);

        return test()->actingAs($admin)->post(route('servers.cloud.store'), [
            'cloud_provider_id' => $account->id,
            'name' => 'outpost-02',
            'region' => 'fsn1',
            'size' => 'cx22',
            'image' => 'ubuntu-24.04',
            'environment' => 'Production',
        ]);
    }

    if ($how === 'this machine') {
        File::ensureDirectoryExists(test()->creatorGrantsFolder);
        config(['coresmith.this_machine.state_path' => test()->creatorGrantsFolder]);
        Team::factory()->create()->members()->attach($admin, ['role' => TeamRole::Owner->value]);

        return test()->actingAs($admin)->post(route('servers.this-machine.store'), ['team_id' => $team->id, 'name' => 'coresmith-box']);
    }

    return test()->actingAs($admin)->post(route('servers.store'), [
        'team_id' => $team->id,
        'name' => 'web-1',
        'ip_address' => '203.0.113.10',
        'ssh_port' => 22,
        'ssh_user' => 'root',
    ]);
}

test('a narrowed admin who adds a server sees it straight away', function (string $how) {
    [$team, $admin] = creatorGrantsAdmin();

    creatorGrantsAddServer($how, $team, $admin)->assertSessionHasNoErrors()->assertRedirect();

    $server = Server::query()->sole();

    $this->actingAs($admin)->get(route('servers.show', $server))->assertOk();

    expect(app(TeamAccess::class)->summary($team, $admin))->toBe(['everything' => false, 'servers' => [$server->id], 'applications' => [], 'projects' => [], 'environments' => [], 'withheld_applications' => [], 'withheld_databases' => []]);
})->with(['by its address', 'at a cloud provider', 'this machine']);

test('a narrowed admin who adds an app keeps it shared on its own, apart from its server', function () {
    [$team, $admin] = creatorGrantsAdmin();
    $web = Server::factory()->for($team)->create();
    app(TeamAccess::class)->grant($team, $admin, $web);

    $this->actingAs($admin)->post(route('servers.applications.store', $web), [
        'name' => 'Shop',
        'type' => ApplicationType::Php->value,
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(app(TeamAccess::class)->summary($team, $admin))->toBe(['everything' => false, 'servers' => [$web->id], 'applications' => [Application::query()->sole()->id], 'projects' => [], 'environments' => [], 'withheld_applications' => [], 'withheld_databases' => []]);
});
