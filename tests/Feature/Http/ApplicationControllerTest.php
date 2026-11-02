<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\DeployApplication;
use App\Jobs\ReservePortBlock;
use App\Jobs\RollbackApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\SafeName;
use App\Services\Server\PortAllocator;
use App\Services\SlugGenerator;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

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
        'type' => ApplicationType::Php->value,
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

test('creating an app on an active server checks its port block in the background, a pending server does not', function (ServerStatus $status, bool $queued) {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['status' => $status]);

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->post(route('servers.applications.store', $server), payload())
        ->assertRedirect();

    $queued ? Queue::assertPushed(ReservePortBlock::class) : Queue::assertNotPushed(ReservePortBlock::class);
})->with([
    'active' => [ServerStatus::Active, true],
    'pending' => [ServerStatus::Pending, false],
]);

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
    [['runtime_version' => '8.5.5; rm -rf /'], 'runtime_version'],
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

test('the app page knows when the last failed deploy had already gone live', function (string $log, bool $live) {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create();
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'log' => $log]);

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page->where('application.last_failed', true)->where('application.last_failed_live', $live));
})->with([
    'after the swap' => ["[14:03:11] swap: current → releases/20260914-140211\n[14:03:40] deploy failed after the symlink swap.", true],
    'before the swap' => ["[14:02:12] clone: fetching over https\n[14:02:15] deploy failed. the current release is untouched.", false],
]);

test('the app page gives the reason a release that went live is not serving, while that release is still live', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['current_release' => '20260914-140211']);
    $reason = 'coresmith-app-shop-web did not start, systemd reports it as activating. The process log says why. Fix it and deploy again, or roll back to the previous release.';
    Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Failed,
        'release' => '20260914-140211',
        'failure_reason' => $reason,
        'log' => "[14:03:11] swap: current → releases/20260914-140211\n[14:03:40] deploy failed after the symlink swap.",
    ]);
    $member = appTeamMember($team, TeamRole::Member);
    $show = route('servers.applications.show', [$application->server_id, $application]);

    $this->actingAs($member)->get($show)
        ->assertInertia(fn($page) => $page->where('application.last_failed_live', true)->where('application.last_failed_reason', $reason));

    $application->forceFill(['current_release' => '20260913-120000'])->save();

    $this->actingAs($member)->get($show)
        ->assertInertia(fn($page) => $page->where('application.last_failed_live', true)->where('application.last_failed_reason', null));
});

test('a worker that did not start after going live leaves the app live, with the worker named and why', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['current_release' => '20260914-140211', 'status' => ApplicationStatus::Active]);
    $reason = 'coresmith-app-shop-queue did not start, systemd reports it as activating. The entry point was not found. Check the build produced the file the process starts, then deploy again.';
    Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Failed,
        'release' => '20260914-140211',
        'failure_reason' => $reason,
        'log' => "[14:03:11] swap: current → releases/20260914-140211\n[14:03:12] watch: starting coresmith-app-shop-fpm, coresmith-app-shop-queue\n[14:03:16] deploy failed after the symlink swap.",
    ]);

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page
            ->where('application.state.label', 'Live')
            ->where('application.last_failed_live', true)
            ->where('application.last_failed_reason', $reason));
});

test('a deploy that failed before going live gives no serving reason', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['current_release' => '20260913-120000']);
    Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Failed,
        'release' => '20260914-140211',
        'failure_reason' => 'Branch release/2.0 is not in the repository.',
        'log' => "[14:02:12] clone: fetching over https\n[14:02:15] deploy failed. the current release is untouched.",
    ]);

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page->where('application.last_failed', true)->where('application.last_failed_reason', null));
});

function appRollback(Application $application, string $description, int $minutesAgo, array $properties = []): void
{
    $activity = activity()->performedOn($application)->withProperties($properties)->log($description);
    $activity->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
}

function appWithFailedRollback(Team $team): Application
{
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['current_release' => '20260912-090000', 'status' => ApplicationStatus::Failed]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913-120000', 'finished_at' => now()->subHours(2)]);
    appRollback($application, 'rollback_processes_failed', 30, [
        'from' => '20260913-120000',
        'to' => '20260912-090000',
        'error' => 'coresmith-app-shop-web did not start, systemd reports it as activating. Port 8000 is already taken by another process. Find it with ss -tlnp | grep 8000, stop it, then deploy again.',
    ]);

    return $application;
}

test('the app page says a rollback did not start the app, with the reason it stored', function () {
    $team = Team::factory()->create();
    $application = appWithFailedRollback($team);

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page
            ->where('application.state.label', 'Failed')
            ->where('application.rollback_failed', [
                'release' => '20260912-090000',
                'error' => 'coresmith-app-shop-web did not start, systemd reports it as activating. Port 8000 is already taken by another process. Find it with ss -tlnp | grep 8000, stop it, then deploy again.',
                'worker' => null,
            ]));
});

test('the app page names the worker that did not start after a rollback, and the app stays live', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['current_release' => '20260912-090000', 'status' => ApplicationStatus::Active]);
    Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913-120000', 'finished_at' => now()->subHours(2)]);
    appRollback($application, 'rollback_worker_failed', 30, [
        'from' => '20260913-120000',
        'to' => '20260912-090000',
        'worker' => 'Sidekiq',
        'error' => 'coresmith-app-shop-sidekiq did not start, systemd reports it as activating. The process log says why. Fix it and deploy again, or roll back to the previous release.',
    ]);

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page
            ->where('application.state.label', 'Live')
            ->where('application.rollback_failed', [
                'release' => '20260912-090000',
                'error' => 'coresmith-app-shop-sidekiq did not start, systemd reports it as activating. The process log says why. Fix it and deploy again, or roll back to the previous release.',
                'worker' => 'Sidekiq',
            ]));
});

test('the failed rollback stays on the app page until a deploy or rollback starts the app', function (Closure $after, bool $shown) {
    $team = Team::factory()->create();
    $application = appWithFailedRollback($team);
    $after($application);

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $shown ? $page->whereNot('application.rollback_failed', null) : $page->where('application.rollback_failed', null));
})->with([
    'a deploy that went live' => [fn(Application $application) => Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Succeeded, 'release' => '20260913-150000', 'finished_at' => now()->subMinutes(10)]), false],
    'a rollback that started' => [fn(Application $application) => appRollback($application, 'application_rolled_back', 10, ['from' => '20260912-090000', 'to' => '20260913-120000']), false],
    'a deploy that failed before going live' => [fn(Application $application) => Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Failed, 'release' => '20260913-150000', 'finished_at' => now()->subMinutes(10), 'log' => "[14:02:12] clone: fetching over https\n[14:02:15] deploy failed. the current release is untouched."]), true],
    'a rollback that started before the one that did not' => [fn(Application $application) => appRollback($application, 'application_rolled_back', 60), true],
]);

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
    $application = Application::factory()->for($team)->for($server)->create([
        'env_variables' => "APP_KEY=base64:app-key-value\nDATABASE_URL=postgres://shop:hunter2@db.internal/shop\nDB_PASS=db-pass-value",
    ]);
    $member = appTeamMember($team, TeamRole::Member);

    $response = $this->actingAs($member)
        ->get(route('servers.applications.show', [$server, $application]))
        ->assertOk()
        ->assertInertia(fn($page) => $page
            ->where('application.env', null)
            ->has('application.environment', 3)
            ->where('can.manageEnvironment', false));

    expect($response->getContent())->not->toContain('app-key-value')
        ->and($response->getContent())->not->toContain('hunter2')
        ->and($response->getContent())->not->toContain('db-pass-value');

    $this->actingAs($member)
        ->put(route('servers.applications.env.update', [$server, $application]), ['env' => 'APP_KEY=leak'])
        ->assertForbidden();

    expect($application->fresh()->env_variables)->not->toContain('leak');
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

test('an environment as long as the form accepts is saved and read back whole', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();
    $lines = [];

    for ($number = 1; strlen(implode("\n", $lines)) < 65000; $number++) {
        $lines[] = sprintf('SETTING_%04d=%s', $number, str_repeat('a', 48));
    }

    $env = implode("\n", $lines);
    $env .= "\nLAST=" . str_repeat('b', 65535 - strlen($env) - 6);

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.env.update', [$server, $application]), ['env' => $env])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(strlen($env))->toBe(65535)
        ->and($application->fresh()->env_variables)->toBe($env);
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

test('an update may send only the fields that changed', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create([
        'domain' => null,
        'app_size' => 'large',
        'web_directory' => '/dist',
    ]);

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.update', [$server, $application]), ['domain' => 'shop.example.com'])
        ->assertRedirect();

    $fresh = $application->fresh();

    expect($fresh->domain)->toBe('shop.example.com')
        ->and($fresh->app_size)->toBe('large')
        ->and($fresh->web_directory)->toBe('/dist');
});

test('a name sent to the update endpoint is ignored, so the app keeps its name, slug and linux user', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['name' => 'Before']);

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.update', [$server, $application]), ['name' => 'After', 'repository_branch' => 'release'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = $application->fresh();

    expect($fresh->name)->toBe('Before')
        ->and($fresh->slug)->toBe($application->slug)
        ->and($fresh->system_user)->toBe($application->system_user)
        ->and($fresh->repository_branch)->toBe('release')
        ->and(Activity::query()->where('description', 'application_renamed')->exists())->toBeFalse();
});

test('a partial update still validates the fields it does send', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.update', [$server, $application]), ['domain' => 'evil.com, other.com'])
        ->assertSessionHasErrors('domain');
});

function appLinkedToGitHub(Team $team, Server $server): Application
{
    return Application::factory()->for($team)->for($server)->create([
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_provider' => 'github',
        'source_provider_id' => SourceProvider::factory()->for($team)->githubApp()->create()->id,
        'repository_id' => '70000001',
    ]);
}

test('an app linked to github cannot have its repository address changed', function (array $sent) {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = appLinkedToGitHub($team, $server);

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.update', [$server, $application]), $sent)
        ->assertSessionHasErrors(['repository_url' => "This app's repository comes from its GitHub connection, so it can't be changed here."]);

    $fresh = $application->fresh();

    expect($fresh->repository_url)->toBe('https://github.com/monoventech/atlas.git')
        ->and($fresh->repository_id)->toBe('70000001')
        ->and($fresh->source_provider_id)->toBe($application->source_provider_id);
})->with([
    'another repository' => [['repository_url' => 'https://github.com/someone/else.git']],
    'an ssh address' => [['repository_url' => 'git@github.com:someone/else.git']],
    'the same address' => [['repository_url' => 'https://github.com/monoventech/atlas.git']],
    'a blank address' => [['repository_url' => '']],
    'a null address' => [['repository_url' => null]],
    'beside a change that is allowed' => [['repository_url' => 'https://github.com/someone/else.git', 'repository_branch' => 'release']],
]);

test('a linked app can still change everything but its repository address', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = appLinkedToGitHub($team, $server);

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.update', [$server, $application]), ['repository_branch' => 'release', 'domain' => 'shop.example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $fresh = $application->fresh();

    expect($fresh->repository_branch)->toBe('release')
        ->and($fresh->domain)->toBe('shop.example.com')
        ->and($fresh->repository_url)->toBe('https://github.com/monoventech/atlas.git')
        ->and($fresh->repository_id)->toBe('70000001');
});

test('an app that is not linked can still change its repository address', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['repository_url' => 'https://github.com/monoven/old.git']);

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.update', [$server, $application]), ['repository_url' => 'https://github.com/monoven/new.git'])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->repository_url)->toBe('https://github.com/monoven/new.git');
});

test('additional domains are validated one by one', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.update', [$server, $application]), [
            'additional_domains' => ['ok.example.com', 'not a domain'],
        ])
        ->assertSessionHasErrors('additional_domains.1');
});

test('the app page explains a recent out of memory kill', function () {
    $team = Team::factory()->create();
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['app_size' => 'small']);

    activity()->performedOn($application)->withProperties(['process' => 'queue', 'at' => now()->toIso8601String(), 'limit_mb' => 256])->log('app_oom_killed');

    $this->actingAs(appTeamMember($team, TeamRole::Member))
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn($page) => $page
            ->where('application.oom.process', 'queue')
            ->where('application.oom.limit_mb', 256)
            ->where('application.oom.size', 'Small')
            ->where('application.oom.when', 'at ' . now()->format('H:i')));
});

test('the add-an-app page offers every runtime\'s versions with their status', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->get(route('servers.applications.create', $server))
        ->assertInertia(fn($page) => $page
            ->where('runtimes.php.label', 'PHP')
            ->where('runtimes.php.recommended', '8.5')
            ->where('runtimes.php.options.0.line', '8.5')
            ->where('runtimes.nodejs.sources', '.tool-versions, .nvmrc, .node-version or engines.node in package.json')
            ->has('runtimes.bun.options', 1)
            ->has('runtimes.deno.options', 1)
            ->has('runtimes.ruby.options', 4));
});

test('a chosen version is kept as a fallback and nothing claims to be live yet', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->post(route('servers.applications.store', $server), payload(['runtime_version' => '8.4']))
        ->assertSessionHasNoErrors();

    $application = Application::query()->latest('created_at')->first();

    expect($application->runtime_pins)->toBe(['php' => '8.4'])
        ->and($application->php_version)->toBeNull();
});

test('an end-of-life version is refused until the choice is confirmed, and the confirmed choice is recorded', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $admin = appTeamMember($team, TeamRole::Admin);

    $this->actingAs($admin)
        ->post(route('servers.applications.store', $server), payload(['runtime_version' => '8.1']))
        ->assertSessionHasErrors(['runtime_version' => 'PHP 8.1 no longer gets security fixes. Confirm you want it, or pick another version.']);

    $this->actingAs($admin)
        ->post(route('servers.applications.store', $server), payload(['runtime_version' => '8.1', 'acknowledge_eol' => true]))
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('description', 'runtime_eol_chosen')->sole()->properties->toArray())->toBeInAnyKeyOrder(['runtime' => 'php', 'line' => '8.1']);
});

test('a version the runtime does not have is refused', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->post(route('servers.applications.store', $server), payload(['runtime_version' => '24']))
        ->assertSessionHasErrors('runtime_version');
});

test('an app\'s versions cannot be edited after it is added, because the repository decides them', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['php_version' => '8.5.10']);

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.update', [$server, $application]), ['php_version' => '7.4.33']);

    expect($application->fresh()->php_version)->toBe('8.5.10');
});

test('environment text the server could not read is refused with the line and what to do', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['env_variables' => "APP_ENV=production\n"]);

    $this->actingAs(appTeamMember($team, TeamRole::Admin))
        ->put(route('servers.applications.env.update', [$server, $application]), ['env' => "APP_ENV=staging\nAPP_NAME=\"Shop"])
        ->assertSessionHasErrors(['env' => 'Line 2 opens a quote that never closes. Close it or remove it.']);

    expect($application->fresh()->env_variables)->toBe("APP_ENV=production\n");
});
