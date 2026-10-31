<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\User;
use App\Services\Git\GitHub\GitHubAppRegistrar;
use App\Services\Git\GitHub\ManifestBuilder;
use App\Services\Network\AddressResolver;
use Illuminate\Support\Facades\Http;

function setupOwner(Team $team, TeamRole $role = TeamRole::Owner): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function setupEnvironmentApp(): string
{
    config(['services.github' => [
        'app_id' => '1041207',
        'app_slug' => 'coresmith-cloud',
        'client_id' => 'Iv1.env',
        'client_secret' => 'env-secret',
        'private_key' => "-----BEGIN PRIVATE KEY-----\nx\n-----END PRIVATE KEY-----",
        'webhook_secret' => null,
    ]]);

    return 'env';
}

beforeEach(function () {
    Http::preventStrayRequests();

    config(['inertia.ssr.enabled' => false]);

    config(['services.github' => [
        'app_id' => null,
        'app_slug' => 'coresmith',
        'client_id' => null,
        'client_secret' => null,
        'private_key' => null,
        'webhook_secret' => null,
    ]]);

    app()->instance(AddressResolver::class, new class implements AddressResolver
    {
        public function addresses(string $host): array
        {
            return ['140.82.112.3'];
        }
    });
});

test('a user without a team is sent to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('setup.show'))
        ->assertRedirect(route('dashboard'));
});

test('a brand new team is shown the first step with nothing connected', function () {
    $team = Team::factory()->create();

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->component('setup/Show')
            ->where('steps', [
                ['label' => 'Connect GitHub', 'done' => false],
                ['label' => 'Add a server', 'done' => false],
                ['label' => 'Provision', 'done' => false],
                ['label' => 'First deploy', 'done' => false],
            ])
            ->where('current', 0)
            ->where('step', 0)
            ->where('complete', false)
            ->where('github.connected', false)
            ->where('github.name', null)
            ->where('github.label', null)
            ->where('github.connect_href', null)
            ->has('github.registration.state')
            ->where('github.elsewhere', null)
            ->where('github.can_manage', true)
            ->where('github.settings_href', route('source-providers.index'))
            ->missing('github.configured')
            ->where('team', ['id' => $team->id])
            ->where('server', null)
            ->where('application', null)
            ->where('create_application_href', null));
});

test('a connected github installation is described', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create(['name' => 'monoventech', 'type' => 'github_app']);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page
            ->where('github.connected', true)
            ->where('github.name', 'monoventech')
            ->where('github.label', 'GitHub')
            ->where('steps.0.done', true)
            ->where('current', 1));
});

test('setup offers the app\'s install page when an app exists', function (string $source) {
    $team = Team::factory()->create();
    $key = $source === 'the environment' ? setupEnvironmentApp() : GitHubApp::factory()->for($team)->create()->id;

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page
            ->where('github.connected', false)
            ->where('github.connect_href', route('source-providers.connect', ['type' => 'github_app', 'app' => $key]))
            ->where('github.registration', null)
            ->where('github.elsewhere', null)
            ->where('github.can_manage', true));
})->with(['its own', 'the environment']);

test('setup offers to create the app when the panel has none', function () {
    $team = Team::factory()->create(['name' => 'Acme Labs']);
    $owner = setupOwner($team);

    $response = $this->actingAs($owner)->get(route('setup.show'))->assertOk();
    $registration = $response->inertiaProps('github.registration');

    $response->assertInertia(fn($page) => $page
        ->where('github.connect_href', null)
        ->where('github.registration.manifest', app(ManifestBuilder::class)->base())
        ->where('github.registration.name', 'Coresmith Acme Labs')
        ->where('github.elsewhere', null));

    expect(session(GitHubAppRegistrar::SESSION_KEY)[$registration['state']])
        ->toMatchArray(['team' => (string) $team->id, 'user' => (string) $owner->id, 'key' => $registration['key']]);
});

test('a member is told who connects GitHub', function (bool $withApp) {
    $team = Team::factory()->create();

    if ($withApp) {
        GitHubApp::factory()->for($team)->create();
    }

    $this->actingAs(setupOwner($team, TeamRole::Member))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page
            ->where('github.connected', false)
            ->where('github.can_manage', false)
            ->where('github.registration', null)
            ->where('github.connect_href', null)
            ->where('github.elsewhere', null));

    expect(session(GitHubAppRegistrar::SESSION_KEY, []))->toBe([]);
})->with(['no app yet' => false, 'an app to install' => true]);

test('on a team\'s own domain setup sends the owner to the panel address to create the app', function () {
    $team = Team::factory()->create(['slug' => 'acme']);
    TeamDomain::factory()->for($team)->verified()->create(['hostname' => 'panel.acme.test']);

    $this->actingAs(setupOwner($team))
        ->get('http://panel.acme.test/setup')
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('github.registration', null)
            ->where('github.connect_href', null)
            ->where('github.elsewhere', 'http://localhost/acme/settings/git')
            ->where('github.can_manage', true));

    expect(session(GitHubAppRegistrar::SESSION_KEY, []))->toBe([]);
});

test('only a github installation connects github, though any git provider finishes the step', function (array $attributes) {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create($attributes);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show', ['step' => 0]))
        ->assertInertia(fn($page) => $page
            ->where('github.connected', false)
            ->where('github.name', null)
            ->where('github.label', null)
            ->where('steps.0.done', true));
})->with([
    'gitlab' => [['type' => 'gitlab', 'name' => 'gitlab-maya', 'installation_id' => null]],
    'bitbucket' => [['type' => 'bitbucket', 'name' => 'bitbucket-maya', 'installation_id' => null]],
]);

test('a disconnected github installation no longer counts', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create(['name' => 'monoventech', 'type' => 'github_app'])->disconnect();

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page
            ->where('github.connected', false)
            ->where('github.name', null)
            ->where('steps.0.done', false));
});

test('a requested step is kept between the first step and the current one', function (string $requested, int $expected) {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Pending]);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show', ['step' => $requested]))
        ->assertInertia(fn($page) => $page->where('current', 2)->where('step', $expected));
})->with([
    'an earlier step' => ['1', 1],
    'the current step' => ['2', 2],
    'a step not reached yet' => ['3', 2],
    'far beyond the last' => ['99', 2],
    'negative' => ['-4', 0],
    'not a number' => ['next', 0],
]);

test('the server still being set up is shown in preference to an active one', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create(['name' => 'old-active', 'status' => ServerStatus::Active, 'created_at' => now()->subDay()]);
    Server::factory()->for($team)->create(['name' => 'newer-active', 'status' => ServerStatus::Active, 'created_at' => now()->subHour()]);
    $pending = Server::factory()->for($team)->create(['name' => 'in-progress', 'status' => ServerStatus::Provisioning, 'created_at' => now()->subDays(2)]);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page
            ->where('server.id', $pending->id)
            ->where('server.name', 'in-progress')
            ->where('server.href', route('servers.show', $pending))
            ->where('server.test_href', route('servers.test-connection', $pending))
            ->where('server.provision_href', route('servers.provision', $pending))
            ->has('server.provisioning.phases', 8)
            ->where('create_application_href', null));
});

test('once every server is active the newest one is shown and an application can be created on it', function () {
    $team = Team::factory()->create();
    Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'created_at' => now()->subDay()]);
    $newest = Server::factory()->for($team)->create(['status' => ServerStatus::Active, 'created_at' => now()->subHour()]);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page
            ->where('server.id', $newest->id)
            ->where('server.active', true)
            ->where('create_application_href', route('servers.applications.create', $newest)));
});

test('the latest application is offered for its first deploy', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active]);
    Application::factory()->for($team)->for($server)->create(['name' => 'Older', 'created_at' => now()->subDay()]);
    $application = Application::factory()->for($team)->for($server)->create([
        'name' => 'Shop',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'repository_url' => 'git@github.com:monoven/shop.git',
        'created_at' => now(),
    ]);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page->where('application', [
            'id' => $application->id,
            'name' => 'Shop',
            'repository' => 'monoven/shop',
            'kind' => 'Laravel',
            'runtime' => 'PHP 8.5',
            'server' => 'web-1',
            'deploy_href' => route('servers.applications.deploy', [$server, $application]),
        ]));
});

test('a repository that cannot be shortened is shown as given', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    Application::factory()->for($team)->for($server)->create(['repository_url' => 'https://git.example.com/solo']);

    $this->actingAs(setupOwner($team))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page->where('application.repository', 'https://git.example.com/solo'));
});

test('a finished setup says so and stays on the last step', function () {
    $team = Team::factory()->create();
    SourceProvider::factory()->for($team)->create();
    $server = Server::factory()->for($team)->create(['status' => ServerStatus::Active]);
    Deployment::factory()->for(Application::factory()->for($team)->for($server))->create(['status' => DeploymentStatus::Succeeded]);

    $this->actingAs(setupOwner($team, TeamRole::Member))
        ->get(route('setup.show'))
        ->assertInertia(fn($page) => $page->where('complete', true)->where('current', 3)->where('step', 3));
});

test('setup requires authentication', function () {
    $this->get(route('setup.show'))->assertRedirect(route('login'));
});
