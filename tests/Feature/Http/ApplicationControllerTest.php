<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\TeamRole;
use App\Jobs\DeployApplication;
use App\Jobs\RollbackApplication;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\SafeName;
use App\Services\Server\PortAllocator;
use App\Services\SlugGenerator;
use Illuminate\Support\Facades\Queue;

function appTeamMember(Team $team, TeamRole $role): User
{
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

function payload(array $overrides = []): array
{
    return array_merge([
        'name' => 'My Svelte App',
        'type' => ApplicationType::Js->value,
        'js_runtime' => 'bun',
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
    ], $overrides);
}

test('a member cannot create an application', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->post(route('servers.applications.store', $server), payload())
        ->assertForbidden();

    expect(Application::count())->toBe(0);
});

test('an admin creating an app gets a slug, a system user and a port block', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->post(route('servers.applications.store', $server), payload())
        ->assertRedirect();

    $application = Application::firstWhere('name', 'My Svelte App');

    expect($application->slug)->toBe('my-svelte-app')
        ->and($application->system_user)->toBe('coresmith-app-my-svelte-app')
        ->and($application->base_port)->toBe(PortAllocator::RANGE_START)
        ->and($application->team_id)->toBe($team->id);
});

test('a second app on the same server gets the next port block', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $admin = appTeamMember($team, TeamRole::Admin);

    $this->actingAs($admin)->post(route('servers.applications.store', $server), payload(['name' => 'first']));
    $this->actingAs($admin)->post(route('servers.applications.store', $server), payload(['name' => 'second']));

    expect(Application::firstWhere('name', 'second')->base_port)
        ->toBe(PortAllocator::RANGE_START + PortAllocator::BLOCK_SIZE);
});

test('a duplicate name gets a suffixed slug rather than colliding', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $admin = appTeamMember($team, TeamRole::Admin);

    $this->actingAs($admin)->post(route('servers.applications.store', $server), payload(['name' => 'shop']));
    $this->actingAs($admin)->post(route('servers.applications.store', $server), payload(['name' => 'shop']));

    $slugs = Application::pluck('slug')->all();

    expect($slugs)->toHaveCount(2)
        ->and(count(array_unique($slugs)))->toBe(2)
        ->and($slugs[1])->toStartWith('shop-');
});

test('the system user always fits a linux username', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->post(route('servers.applications.store', $server), payload([
            'name' => 'an extremely long application name that would never fit in a unix user',
        ]));

    expect(strlen((string) Application::first()->system_user))->toBeLessThanOrEqual(32);
});

test('an application cannot be created on another teams server', function () {
    $outsider = appTeamMember(Team::factory()->create(), TeamRole::Owner);
    $server = Server::factory()->for(Team::factory()->create())->create();

    $this->actingAs($outsider)
        ->post(route('servers.applications.store', $server), payload())
        ->assertForbidden();

    expect(Application::count())->toBe(0);
});

test('application input is validated', function (array $override, string $field) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->post(route('servers.applications.store', $server), payload($override))
        ->assertSessionHasErrors($field);
})->with([
    [['domain' => 'not a domain'], 'domain'],
    [['domain' => 'evil.com, other.com'], 'domain'],
    [['type' => 'wordpress'], 'type'],
    [['php_version' => '8.5.5; rm -rf /'], 'php_version'],
    [['repository_url' => 'not-a-url'], 'repository_url'],
    [['app_size' => 'enormous'], 'app_size'],
    [['name' => ''], 'name'],
]);

test('an application from another server returns 404 even inside the same team', function () {
    $team = Team::factory()->create();
    $serverA = Server::factory()->for($team)->create();
    $serverB = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($serverB)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->get(route('servers.applications.show', [$serverA, $application]))
        ->assertNotFound();
});

test('another teams application cannot be viewed', function () {
    $outsider = appTeamMember(Team::factory()->create(), TeamRole::Owner);
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs($outsider)
        ->get(route('servers.applications.show', [$server, $application]))
        ->assertForbidden();
});

test('a member can deploy', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->post(route('servers.applications.deploy', [$server, $application]))
        ->assertRedirect();

    Queue::assertPushed(DeployApplication::class);
});

test('an outsider cannot deploy and nothing is queued', function () {
    Queue::fake();
    $outsider = appTeamMember(Team::factory()->create(), TeamRole::Owner);
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs($outsider)
        ->post(route('servers.applications.deploy', [$server, $application]))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('a member can roll back but an outsider cannot', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->post(route('servers.applications.rollback', [$server, $application]))
        ->assertRedirect();

    Queue::assertPushed(RollbackApplication::class);
});

test('a member cannot read or write environment variables', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();
    $member = appTeamMember($team, TeamRole::Member);

    $this->actingAs($member)
        ->get(route('servers.applications.env.show', [$server, $application]))
        ->assertForbidden();

    $this->actingAs($member)
        ->put(route('servers.applications.env.update', [$server, $application]), ['env' => 'APP_KEY=leak'])
        ->assertForbidden();
});

test('an admin can save environment variables and they are encrypted at rest', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.env.update', [$server, $application]), ['env' => "APP_KEY=secret-value\n"])
        ->assertRedirect();

    $raw = DB::table('applications')->where('id', $application->id)->value('env_variables');

    expect($raw)->not->toContain('secret-value')
        ->and($application->fresh()->env_variables)->toContain('secret-value');
});

test('the slug generator never produces an invalid system user', function () {
    $server = Server::factory()->create();
    $slugs = new SlugGenerator;

    foreach (['My App!!!', '   ', '...', 'ÜNICODE náme', str_repeat('x', 200)] as $name) {
        $slug = $slugs->fromName($name, $server);

        expect(SafeName::isSlug($slug))->toBeTrue()
            ->and(SafeName::isSystemUser($slugs->systemUser($slug)))->toBeTrue();
    }
});
