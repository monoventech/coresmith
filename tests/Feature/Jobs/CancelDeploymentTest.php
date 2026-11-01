<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\CancelOutcome;
use App\Enums\DeploymentStatus;
use App\Enums\PowerState;
use App\Events\DeploymentFailed;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\SSHCommandFailedException;
use App\Exceptions\UnsafeNameException;
use App\Jobs\DeployApplication;
use App\Jobs\InstallRuntimes;
use App\Jobs\StopDeployBuild;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\GitHubApp;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\Team;
use App\Models\User;
use App\Services\AutoDeploy\AutoDeploy;
use App\Services\Deployment\AppShell;
use App\Services\Deployment\DeploymentCancellation;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Runtime\AsdfManager;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProbeOutput;

const CANCEL_RELEASE = '20261020-120000';

const CANCEL_LIVE = '20261019-090000';

function cancelApp(array $attributes = []): Application
{
    return Application::factory()->create([
        'slug' => 'cancel-me',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => null,
        'repository_url' => 'https://github.com/monoven/cancel-me.git',
        'repository_branch' => 'main',
        'run_migrations' => true,
        'base_port' => 8000,
        'current_release' => CANCEL_LIVE,
        ...$attributes,
    ]);
}

function cancelTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for([
            'composer.json' => '{"require":{"php":"^8.4"}}',
            'package.json' => '{"scripts":{"build":"vite build"}}',
        ], ['bun.lock']));
}

function cancelRun(Deployment $deployment, FakeSSHTransport $transport, ?string $owner = null): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, CANCEL_RELEASE, $owner))->handle(
            app(SSHConnectionManager::class),
            new DeploymentCommands(new AsdfManager),
        );
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function cancelAsk(Deployment $deployment, User $user): Closure
{
    return function () use ($deployment, $user): void {
        Deployment::query()
            ->whereKey($deployment->getKey())
            ->where('status', DeploymentStatus::Running->value)
            ->whereNull('cancel_requested_at')
            ->update(['cancel_requested_at' => now(), 'cancelled_by' => $user->getKey()]);
    };
}

function cancelPerson(): User
{
    return User::factory()->create(['name' => 'Maya Lindqvist']);
}

function cancelCommands(FakeSSHTransport $transport, string $needle): array
{
    return array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, $needle)));
}

function cancelLockFree(Deployment $deployment): bool
{
    return Cache::lock('deploy:' . $deployment->application_id, 10)->get();
}

function cancelRunningDeployment(Application $application, array $attributes = []): Deployment
{
    return Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subMinutes(13),
        'stale_after' => now()->addMinutes(2),
        'release' => CANCEL_RELEASE,
        ...$attributes,
    ]);
}

function cancelOver(FakeSSHTransport $transport, Closure $work): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        $work();
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function cancelGitHubApp(array $attributes = []): Application
{
    $team = Team::factory()->create();
    $provider = SourceProvider::factory()->for($team)->githubApp(GitHubApp::factory()->for($team)->create())->create([
        'name' => 'monoventech',
        'installation_id' => '51842',
        'account_type' => 'Organization',
    ]);

    return cancelApp([
        'team_id' => $team->id,
        'server_id' => Server::factory()->create(['team_id' => $team->id, 'name' => 'bench-01'])->id,
        'source_provider_id' => $provider->id,
        'repository_url' => 'https://github.com/monoventech/atlas.git',
        'repository_id' => '70000001',
        ...$attributes,
    ]);
}

function cancelStatusPosts(): array
{
    return Http::recorded(fn(Request $request): bool => str_contains($request->url(), '/statuses/'))
        ->map(fn(array $pair): array => $pair[0]->data())
        ->values()
        ->all();
}

test('build steps run in a unit named for the deploy, migrations in their own', function () {
    $deployment = Deployment::factory()->for(cancelApp())->create();
    $transport = cancelTransport();

    cancelRun($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded);

    foreach (['composer install', 'bun install', 'bun run build', 'artisan config:cache'] as $step) {
        expect(cancelCommands($transport, $step)[0])->toMatch('/ --gid=\S+ --unit=coresmith-build-cancel-me-20261020-120000 -p /');
    }

    expect(cancelCommands($transport, 'artisan migrate')[0])->toMatch('/ --gid=\S+ --unit=coresmith-migrate-cancel-me-20261020-120000 -p /');
});

test('a custom blueprint runs in a unit of its own', function () {
    $deployment = Deployment::factory()->for(cancelApp(['custom_blueprint' => "echo building\n"]))->create();
    $transport = cancelTransport();

    cancelRun($deployment, $transport);

    expect(cancelCommands($transport, "/bin/bash -s < '/etc/coresmith/blueprints/cancel-me.sh'")[0])
        ->toMatch('/ --gid=\S+ --unit=coresmith-blueprint-cancel-me-20261020-120000 -p /');
});

test('each unit is named for the app and the release', function () {
    $application = cancelApp();

    expect(AppShell::buildUnit($application, CANCEL_RELEASE))->toBe('coresmith-build-cancel-me-20261020-120000')
        ->and(AppShell::migrateUnit($application, '20261020120000'))->toBe('coresmith-migrate-cancel-me-20261020120000')
        ->and(AppShell::blueprintUnit($application, CANCEL_RELEASE))->toBe('coresmith-blueprint-cancel-me-20261020-120000')
        ->and(AppShell::assertUnit('coresmith-build-cancel-me-20261020-120000'))->toBe('coresmith-build-cancel-me-20261020-120000');
});

test('a unit name outside the pattern is refused', function (string $unit) {
    AppShell::assertUnit($unit);
})->throws(UnsafeNameException::class)->with([
    'a shell command in the slug' => ['coresmith-build-x; rm -rf /-20261020-120000'],
    'another kind of unit' => ['coresmith-app-cancel-me-20261020-120000'],
    'no release' => ['coresmith-build-cancel-me'],
    'a trailing newline' => ["coresmith-build-cancel-me-20261020-120000\n"],
]);

test('a release that is not a release name never becomes a unit', function () {
    AppShell::buildUnit(cancelApp(), '../../shared');
})->throws(UnsafeNameException::class);

test('a cancelled deploy that reaches a worker does nothing', function (bool $held) {
    $deployment = Deployment::factory()->for(cancelApp())->create([
        'status' => DeploymentStatus::Cancelled,
        'finished_at' => now(),
        'cancel_requested_at' => now(),
        'cancelled_by' => cancelPerson()->id,
        'log' => 'cancel: Maya Lindqvist asked to stop the deploy',
    ]);
    $lock = Cache::lock('deploy:' . $deployment->application_id, 600, 'another-deploy');

    if ($held) {
        $lock->get();
    }

    $before = $deployment->fresh()->getAttributes();
    $transport = cancelTransport();

    cancelRun($deployment, $transport);

    expect($transport->calls())->toBe([])
        ->and($deployment->fresh()->getAttributes())->toBe($before)
        ->and(Cache::lock('deploy:' . $deployment->application_id, 600, 'another-deploy')->isOwnedByCurrentProcess())->toBe($held);
})->with([
    'with the lock free' => [false],
    'with the lock held by another deploy' => [true],
]);

test('a deploy cancelled after its worker picked it up but before it started does nothing and frees the lock', function () {
    $deployment = Deployment::factory()->for(cancelApp())->create();
    $picked = $deployment->fresh();
    Deployment::query()->whereKey($deployment->id)->update(['status' => DeploymentStatus::Cancelled->value, 'cancel_requested_at' => now(), 'cancelled_by' => cancelPerson()->id]);
    $transport = cancelTransport();

    cancelRun($picked, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->started_at)->toBeNull()
        ->and($deployment->application->fresh()->status)->not->toBe(ApplicationStatus::Deploying)
        ->and($transport->commands())->toBe([])
        ->and(cancelLockFree($deployment))->toBeTrue();
});

test('a cancel during the build stops the deploy and nothing goes live', function (?string $live, ApplicationStatus $status) {
    Event::fake([DeploymentFailed::class]);
    $user = cancelPerson();
    $deployment = Deployment::factory()->for(cancelApp(['current_release' => $live]))->create();
    $transport = cancelTransport()->onCommand('run build', cancelAsk($deployment, $user))->respondTo('run build', "Terminated\n", 143);

    cancelRun($deployment, $transport);

    $fresh = $deployment->fresh();
    $commands = implode("\n", $transport->commands());
    $activity = Activity::query()->where('description', 'deployment_cancelled')->sole();

    expect($fresh->status)->toBe(DeploymentStatus::Cancelled)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->failure_reason)->toBeNull()
        ->and($commands)->not->toContain('mv -Tf')
        ->and($commands)->toContain('rm -rf /var/coresmith/apps/cancel-me/releases/' . CANCEL_RELEASE)
        ->and($fresh->log)->toContain("\ncancel: Maya Lindqvist asked to stop the deploy\n")
        ->and(substr_count((string) $fresh->log, 'asked to stop the deploy'))->toBe(1)
        ->and($fresh->log)->toMatch('/\n\[\d{2}:\d{2}:\d{2}\] deploy cancelled\. the current release is untouched\.$/')
        ->and(cancelLockFree($deployment))->toBeTrue()
        ->and($activity->subject_id)->toBe($deployment->application_id)
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->properties->toArray())->toBe(['by' => 'Maya Lindqvist', 'phase' => 'build'])
        ->and($deployment->application->fresh()->status)->toBe($status)
        ->and($deployment->application->fresh()->current_release)->toBe($live);

    Event::assertNotDispatched(DeploymentFailed::class);
})->with([
    'an app with a live release' => [CANCEL_LIVE, ApplicationStatus::Active],
    'a first deploy' => [null, ApplicationStatus::Pending],
]);

test('a cancel seen at a phase boundary stops before the next phase', function (string $when, string $ran, string $never) {
    $deployment = Deployment::factory()->for(cancelApp())->create();
    $transport = cancelTransport()->onCommand($when, cancelAsk($deployment, cancelPerson()));

    cancelRun($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(cancelCommands($transport, $ran))->not->toBe([])
        ->and(cancelCommands($transport, $never))->toBe([])
        ->and(cancelCommands($transport, 'mv -Tf'))->toBe([])
        ->and($deployment->fresh()->log)->toContain('cancel: Maya Lindqvist asked to stop the deploy')
        ->and(substr_count((string) $deployment->fresh()->log, 'asked to stop the deploy'))->toBe(1);
})->with([
    'while dependencies install' => ['composer install', 'bun install', 'run build'],
    'while the disk is checked' => ['df /', 'df /', 'clone --bare'],
    'while the repository is fetched' => ['clone --bare', ' archive ', RepositoryProbe::DIRECTORY_VARIABLE],
    'while the repository is read' => [RepositoryProbe::DIRECTORY_VARIABLE, RepositoryProbe::DIRECTORY_VARIABLE, 'composer install'],
    'while the release builds' => ['bun run build', 'artisan view:cache', 'artisan migrate'],
]);

test('a cancel whose asker has since gone still says it was asked for', function () {
    $user = cancelPerson();
    $deployment = Deployment::factory()->for(cancelApp())->create();
    $transport = cancelTransport()->onCommand('composer install', function () use ($deployment, $user): void {
        cancelAsk($deployment, $user)();
        $user->delete();
    });

    cancelRun($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->log)->toContain("\ncancel: asked to stop the deploy\n")
        ->and(Activity::query()->where('description', 'deployment_cancelled')->sole()->properties->toArray())->toBe(['by' => null, 'phase' => 'deps']);
});

test('a cancel that arrives as migrations start lets the deploy finish', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(cancelApp())->create();
    $transport = cancelTransport()->onCommand('artisan migrate', cancelAsk($deployment, cancelPerson()));

    cancelRun($deployment, $transport);

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Succeeded)
        ->and(cancelCommands($transport, 'mv -Tf'))->toHaveCount(1)
        ->and($fresh->log)->toContain("\ncancel: asked after migrations started, so the deploy carried on\n")
        ->and($fresh->log)->toMatch('/\n\[\d{2}:\d{2}:\d{2}\] deployed\. previous release kept for instant rollback\.$/')
        ->and($deployment->application->fresh()->current_release)->toBe(CANCEL_RELEASE)
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Event::assertNotDispatched(DeploymentFailed::class);
});

test('a migration that fails after a cancel was asked is a failure, never a cancel', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(cancelApp())->create();
    $transport = cancelTransport()
        ->onCommand('artisan migrate', cancelAsk($deployment, cancelPerson()))
        ->respondTo('artisan migrate', "SQLSTATE[42S01]: duplicate column\n", 1);

    expect(fn() => cancelRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Event::assertDispatched(DeploymentFailed::class);
});

test('a custom blueprint that fails after a cancel was asked is a failure, because it may have run migrations', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(cancelApp(['custom_blueprint' => "php artisan migrate --force\n"]))->create();
    $transport = cancelTransport()
        ->onCommand('/etc/coresmith/blueprints/cancel-me.sh', cancelAsk($deployment, cancelPerson()))
        ->respondTo("/bin/bash -s < '/etc/coresmith/blueprints/cancel-me.sh'", "Terminated\n", 143);

    expect(fn() => cancelRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
});

test('a cancel asked while the repository is read never hands the deploy to a runtime install', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(cancelApp())->create();
    $transport = cancelTransport()
        ->respondTo("ls -1 '/opt/asdf-data/installs/php'", "\n")
        ->onCommand(RepositoryProbe::DIRECTORY_VARIABLE, cancelAsk($deployment, cancelPerson()));

    cancelRun($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(cancelLockFree($deployment))->toBeTrue();

    Queue::assertNotPushed(InstallRuntimes::class);
});

test('a resumed deploy asked to stop while its runtimes are checked stops before its next phase', function (array $attributes, string $never) {
    $application = cancelApp(['status' => ApplicationStatus::Deploying, ...$attributes]);
    $deployment = cancelRunningDeployment($application, [
        'stack' => ['runtimes' => ['php' => ['runtime' => 'php', 'version' => '8.5.10', 'source' => 'default', 'detail' => null]], 'framework' => null, 'tooling' => null],
    ]);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $transport = cancelTransport()
        ->respondTo("test -d '/opt/asdf-data/installs/php/8.5.10'", "yes\n")
        ->onCommand("test -d '/opt/asdf-data/installs/php/8.5.10'", cancelAsk($deployment, cancelPerson()));

    cancelRun($deployment->fresh(), $transport, $lock->owner());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(cancelCommands($transport, "test -d '/opt/asdf-data/installs/php/8.5.10'"))->toHaveCount(1)
        ->and(cancelCommands($transport, $never))->toBe([])
        ->and(cancelLockFree($deployment))->toBeTrue();
})->with([
    'built in phases' => [[], 'composer install'],
    'a custom blueprint' => [['custom_blueprint' => "php artisan migrate --force\n"], '/etc/coresmith/blueprints/cancel-me.sh'],
]);

test('a deploy that times out after a cancel was asked ends cancelled', function () {
    Event::fake([DeploymentFailed::class]);
    $application = cancelApp(['status' => ApplicationStatus::Deploying]);
    $deployment = cancelRunningDeployment($application, [
        'cancel_requested_at' => now()->subMinute(),
        'cancelled_by' => cancelPerson()->id,
        'log' => "[12:00:00] clone: fetching over https\n[12:00:05] deps: installing dependencies",
    ]);
    Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS)->get();
    $transport = (new FakeSSHTransport)->respondTo("readlink '/var/coresmith/apps/cancel-me/current'", '/var/coresmith/apps/cancel-me/releases/' . CANCEL_LIVE . "\n");

    cancelOver($transport, fn() => (new DeployApplication($deployment, CANCEL_RELEASE))->failed(new TimeoutExceededException('timed out')));

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Cancelled)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->failure_reason)->toBeNull()
        ->and($fresh->log)->toContain('cancel: Maya Lindqvist asked to stop the deploy')
        ->and($fresh->log)->toMatch('/\n\[\d{2}:\d{2}:\d{2}\] deploy cancelled\. the current release is untouched\.$/')
        ->and($fresh->log)->not->toContain('deploy failed')
        ->and($transport->commands())->toContain("rm -rf '/var/coresmith/apps/cancel-me/releases/" . CANCEL_RELEASE . "'")
        ->and(cancelLockFree($deployment))->toBeTrue()
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and(Activity::query()->where('description', 'deployment_cancelled')->sole()->properties->toArray())->toBe(['by' => 'Maya Lindqvist', 'phase' => 'deps']);

    Event::assertNotDispatched(DeploymentFailed::class);
});

test('a timed out deploy that leaves running after it was read is not cancelled over its new outcome', function () {
    Event::fake([DeploymentFailed::class]);
    $application = cancelApp(['status' => ApplicationStatus::Deploying]);
    $deployment = cancelRunningDeployment($application, [
        'cancel_requested_at' => now()->subMinute(),
        'cancelled_by' => cancelPerson()->id,
        'log' => '[12:00:00] clone: fetching over https',
    ]);
    Cache::lock('deploy:' . $application->id, 600)->get();
    $transport = new FakeSSHTransport;

    Deployment::retrieved(function (Deployment $retrieved): void {
        Deployment::query()->whereKey($retrieved->getKey())->update(['status' => DeploymentStatus::Failed->value, 'failure_reason' => 'reclaimed']);
    });

    cancelOver($transport, fn() => (new DeployApplication($deployment, CANCEL_RELEASE))->failed(new TimeoutExceededException('timed out')));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->log)->toBe('[12:00:00] clone: fetching over https')
        ->and($transport->commands())->toBe([])
        ->and(Cache::lock('deploy:' . $application->id, 10)->get())->toBeFalse()
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);
});

test('a deploy that times out after its migrations started still fails, even with a cancel asked', function (string $marker) {
    Event::fake([DeploymentFailed::class]);
    $application = cancelApp(['status' => ApplicationStatus::Deploying]);
    $deployment = cancelRunningDeployment($application, [
        'cancel_requested_at' => now()->subMinute(),
        'cancelled_by' => cancelPerson()->id,
        'log' => "[12:00:00] clone: fetching over https\n" . $marker,
    ]);
    $transport = (new FakeSSHTransport)->respondTo("readlink '/var/coresmith/apps/cancel-me/current'", '/var/coresmith/apps/cancel-me/releases/' . CANCEL_LIVE . "\n");

    cancelOver($transport, fn() => (new DeployApplication($deployment, CANCEL_RELEASE))->failed(new TimeoutExceededException('timed out')));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toBe('The deploy ran longer than 13 minutes, so Coresmith stopped it. Deploy again.')
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Event::assertDispatched(DeploymentFailed::class);
})->with([
    'migrations' => ['[12:00:30] migrate: running migrations against release ' . CANCEL_RELEASE],
    'a custom blueprint' => ['[12:00:30] build: running the custom blueprint'],
]);

test('a network error is not retried once a cancel is asked', function () {
    $deployment = Deployment::factory()->for(cancelApp())->create();
    $transport = cancelTransport()->onCommand('bun install', cancelAsk($deployment, cancelPerson()))->respondTo('bun install', 'ETIMEDOUT', 1);

    cancelRun($deployment, $transport);

    expect(cancelCommands($transport, 'bun install'))->toHaveCount(1)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->log)->not->toContain('hit a network error');
});

test('a resumed deploy that was asked to stop while its runtimes installed stops before building', function () {
    $application = cancelApp(['status' => ApplicationStatus::Deploying]);
    $deployment = cancelRunningDeployment($application, [
        'stack' => ['runtimes' => ['php' => ['runtime' => 'php', 'version' => '8.5.10', 'source' => 'default', 'detail' => null]], 'framework' => null, 'tooling' => null],
        'cancel_requested_at' => now()->subMinute(),
        'cancelled_by' => cancelPerson()->id,
    ]);
    $lock = Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS);
    $lock->get();
    $transport = cancelTransport()->respondTo("test -d '/opt/asdf-data/installs/php/8.5.10'", "yes\n");

    cancelRun($deployment->fresh(), $transport, $lock->owner());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(cancelCommands($transport, 'composer install'))->toBe([])
        ->and(cancelCommands($transport, "test -d '/opt/asdf-data/installs/php/8.5.10'"))->toBe([])
        ->and(cancelCommands($transport, 'rm -rf /var/coresmith/apps/cancel-me/releases/' . CANCEL_RELEASE))->toHaveCount(1)
        ->and(cancelLockFree($deployment))->toBeTrue();
});

test('a resumed deploy whose lock was lost meanwhile fails as before, even with a cancel asked', function () {
    $application = cancelApp(['status' => ApplicationStatus::Deploying]);
    $deployment = cancelRunningDeployment($application, [
        'stack' => ['runtimes' => ['php' => ['runtime' => 'php', 'version' => '8.5.10', 'source' => 'default', 'detail' => null]], 'framework' => null, 'tooling' => null],
        'cancel_requested_at' => now()->subMinute(),
        'cancelled_by' => cancelPerson()->id,
    ]);
    Cache::lock('deploy:' . $application->id, 60, 'someone-else')->get();

    expect(fn() => cancelRun($deployment->fresh(), cancelTransport(), 'owner-1'))->toThrow(DeploymentAborted::class);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Deploying)
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0)
        ->and(Cache::lock('deploy:' . $application->id, 60, 'someone-else')->isOwnedByCurrentProcess())->toBeTrue();
});

test('a runtime install finishes, then the deploy stops', function () {
    Queue::fake();
    $application = cancelApp([
        'status' => ApplicationStatus::Deploying,
        'server_id' => Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu'])->id,
    ]);
    $deployment = cancelRunningDeployment($application, ['log' => '[12:00:00] release ' . CANCEL_RELEASE]);
    Cache::lock('deploy:' . $application->id, 3600, 'owner-1')->get();
    $transport = (new FakeSSHTransport)->respondTo('test -d', "no\n")->onCommand('asdf install', cancelAsk($deployment, cancelPerson()));

    cancelOver($transport, fn() => app()->call([
        new InstallRuntimes($deployment, CANCEL_RELEASE, [['runtime' => 'php', 'version' => '8.5.10'], ['runtime' => 'bun', 'version' => '1.4.2']], 'owner-1'),
        'handle',
    ]));

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Cancelled)
        ->and($fresh->log)->toContain("PHP 8.5.10 is installed\n")
        ->and($fresh->log)->toContain("\ncancel: PHP 8.5.10 finishes installing first, the server keeps it for the next deploy\n")
        ->and($fresh->log)->not->toContain('Bun 1.4.2')
        ->and($fresh->log)->toMatch('/\n\[\d{2}:\d{2}:\d{2}\] deploy cancelled\. the current release is untouched\.$/')
        ->and($application->server->fresh()->installed_runtimes)->toBe(['php' => ['8.5.10']])
        ->and(cancelCommands($transport, 'rm -rf /var/coresmith/apps/cancel-me/releases/' . CANCEL_RELEASE))->toHaveCount(1)
        ->and(Cache::lock('deploy:' . $application->id, 10)->get())->toBeTrue()
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Active);

    Queue::assertNotPushed(DeployApplication::class);
});

function cancelInstallingDeployment(array $attributes = [], array $application = []): Deployment
{
    $application = cancelApp([
        'status' => ApplicationStatus::Deploying,
        'server_id' => Server::factory()->create(['host_key_fingerprint' => null, 'os_name' => 'ubuntu'])->id,
        ...$application,
    ]);
    Cache::lock('deploy:' . $application->id, 3600, 'owner-1')->get();

    return cancelRunningDeployment($application, [
        'log' => '[12:00:00] release ' . CANCEL_RELEASE . "\n[12:00:05] deps: installing PHP 8.5.10, compiling it, usually 5–15 minutes the first time",
        ...$attributes,
    ]);
}

function cancelInstall(Deployment $deployment): InstallRuntimes
{
    return new InstallRuntimes($deployment, CANCEL_RELEASE, [['runtime' => 'php', 'version' => '8.5.10']], 'owner-1');
}

function expectInstallCancelled(Deployment $deployment, FakeSSHTransport $transport): void
{
    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Cancelled)
        ->and($fresh->finished_at)->not->toBeNull()
        ->and($fresh->failure_reason)->toBeNull()
        ->and($fresh->log)->toContain("\ncancel: Maya Lindqvist asked to stop the deploy\n")
        ->and($fresh->log)->toMatch('/\n\[\d{2}:\d{2}:\d{2}\] deploy cancelled\. the current release is untouched\.$/')
        ->and($fresh->log)->not->toContain('deploy failed')
        ->and(cancelCommands($transport, 'rm -rf /var/coresmith/apps/cancel-me/releases/' . CANCEL_RELEASE))->toHaveCount(1)
        ->and(cancelLockFree($deployment))->toBeTrue()
        ->and($deployment->application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and(Activity::query()->where('description', 'deployment_cancelled')->sole()->properties->toArray())->toBe(['by' => 'Maya Lindqvist', 'phase' => 'deps']);

    Event::assertNotDispatched(DeploymentFailed::class);
}

test('a runtime install whose worker dies after a cancel was asked ends cancelled', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = cancelInstallingDeployment(['cancel_requested_at' => now()->subMinute(), 'cancelled_by' => cancelPerson()->id]);
    $transport = new FakeSSHTransport;

    cancelOver($transport, fn() => cancelInstall($deployment)->failed(new TimeoutExceededException('timed out')));

    expectInstallCancelled($deployment, $transport);
});

test('a runtime install that fails after a cancel was asked ends cancelled, with its output kept', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = cancelInstallingDeployment();
    $transport = (new FakeSSHTransport)
        ->respondTo('test -d', "no\n")
        ->onCommand('asdf install', cancelAsk($deployment, cancelPerson()))
        ->respondTo('asdf install', "configure: error: Package requirements (libxml-2.0 >= 2.9.0) were not met\n", 1);

    expect(fn() => cancelOver($transport, fn() => app()->call([cancelInstall($deployment), 'handle'])))->toThrow(SSHCommandFailedException::class);

    expectInstallCancelled($deployment, $transport);

    expect($deployment->fresh()->log)->toContain('configure: error: Package requirements (libxml-2.0 >= 2.9.0) were not met');
});

test('a runtime install that dies past a planted marker after a cancel was asked still fails', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = cancelInstallingDeployment([
        'cancel_requested_at' => now()->subMinute(),
        'cancelled_by' => cancelPerson()->id,
        'log' => '[12:00:00] release ' . CANCEL_RELEASE . "\n[12:00:30] migrate: running migrations against release " . CANCEL_RELEASE,
    ]);

    cancelOver(new FakeSSHTransport, fn() => cancelInstall($deployment)->failed(new TimeoutExceededException('timed out')));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Event::assertDispatched(DeploymentFailed::class);
});

test('a runtime install whose deploy was already ended elsewhere is not cancelled over that outcome', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = cancelInstallingDeployment(['cancel_requested_at' => now()->subMinute(), 'cancelled_by' => cancelPerson()->id]);
    $reclaimed = false;

    Deployment::retrieved(function (Deployment $retrieved) use (&$reclaimed): void {
        if (!$reclaimed) {
            $reclaimed = true;
            Deployment::query()->whereKey($retrieved->getKey())->update(['status' => DeploymentStatus::Failed->value, 'failure_reason' => 'reclaimed']);
        }
    });

    cancelOver(new FakeSSHTransport, fn() => cancelInstall($deployment)->failed(new TimeoutExceededException('timed out')));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->fresh()->failure_reason)->toBe('reclaimed')
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Event::assertNotDispatched(DeploymentFailed::class);
});

const CANCEL_HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const CANCEL_NEWER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

function cancelPushedPastApp(array $attributes = []): array
{
    return ['quick_deploy' => true, 'repository_head' => CANCEL_NEWER, 'repository_head_source' => 'push', ...$attributes];
}

function cancelFollowUpSeesLock(): stdClass
{
    $seen = new stdClass;
    $seen->free = null;

    Deployment::created(function (Deployment $created) use ($seen): void {
        $lock = Cache::lock('deploy:' . $created->application_id, 10);
        $seen->free = $lock->get();
        $lock->release();
    });

    return $seen;
}

function expectNewerHeadFollowedUp(Deployment $cancelled, stdClass $seen): void
{
    expect($cancelled->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(Deployment::query()->where('requested_commit', CANCEL_NEWER)->count())->toBe(1)
        ->and(Deployment::query()->where('requested_commit', CANCEL_HEAD)->count())->toBe(1)
        ->and($seen->free)->toBeTrue();

    Queue::assertPushed(DeployApplication::class, 1);
}

test('a deploy that times out after a cancel was asked hands a newer push to auto deploy once its lock is free', function () {
    Queue::fake();
    $application = cancelApp(cancelPushedPastApp(['status' => ApplicationStatus::Deploying]));
    $deployment = cancelRunningDeployment($application, [
        'requested_commit' => CANCEL_HEAD,
        'cancel_requested_at' => now()->subMinute(),
        'cancelled_by' => cancelPerson()->id,
        'log' => "[12:00:00] clone: fetching over https\n[12:00:05] deps: installing dependencies",
    ]);
    Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS)->get();
    $transport = (new FakeSSHTransport)->respondTo("readlink '/var/coresmith/apps/cancel-me/current'", '/var/coresmith/apps/cancel-me/releases/' . CANCEL_LIVE . "\n");
    $seen = cancelFollowUpSeesLock();

    cancelOver($transport, fn() => (new DeployApplication($deployment, CANCEL_RELEASE))->failed(new TimeoutExceededException('timed out')));

    expectNewerHeadFollowedUp($deployment, $seen);
});

test('a runtime install that ends cancelled hands a newer push to auto deploy once its lock is free', function () {
    Queue::fake();
    $deployment = cancelInstallingDeployment(
        ['requested_commit' => CANCEL_HEAD, 'cancel_requested_at' => now()->subMinute(), 'cancelled_by' => cancelPerson()->id],
        cancelPushedPastApp(),
    );
    $seen = cancelFollowUpSeesLock();

    cancelOver(new FakeSSHTransport, fn() => cancelInstall($deployment)->failed(new TimeoutExceededException('timed out')));

    expectNewerHeadFollowedUp($deployment, $seen);
});

test('a runtime install stopped on request hands a newer push to auto deploy once its lock is free', function () {
    Queue::fake();
    $deployment = cancelInstallingDeployment(['requested_commit' => CANCEL_HEAD], cancelPushedPastApp());
    $transport = (new FakeSSHTransport)->respondTo('test -d', "no\n")->onCommand('asdf install', cancelAsk($deployment, cancelPerson()));
    $seen = cancelFollowUpSeesLock();

    cancelOver($transport, fn() => app()->call([cancelInstall($deployment), 'handle']));

    expectNewerHeadFollowedUp($deployment, $seen);
});

test('a queued deploy cancelled after a newer push hands that push to auto deploy', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(cancelApp(cancelPushedPastApp()))->create(['trigger' => 'push', 'requested_commit' => CANCEL_HEAD, 'commit_hash' => null]);
    $seen = cancelFollowUpSeesLock();

    expect(app(DeploymentCancellation::class)->request($deployment, cancelPerson()))->toBe(CancelOutcome::Cancelled);

    expectNewerHeadFollowedUp($deployment, $seen);
});

test('a deploy whose cancel throws once it has won still removes its release, frees its lock and follows up', function () {
    Queue::fake();
    $application = cancelApp(cancelPushedPastApp(['status' => ApplicationStatus::Deploying]));
    $deployment = cancelRunningDeployment($application, [
        'requested_commit' => CANCEL_HEAD,
        'cancel_requested_at' => now()->subMinute(),
        'cancelled_by' => cancelPerson()->id,
        'log' => "[12:00:00] clone: fetching over https\n[12:00:05] deps: installing dependencies",
    ]);
    Cache::lock('deploy:' . $application->id, DeployApplication::LOCK_SECONDS)->get();
    $transport = (new FakeSSHTransport)->respondTo("readlink '/var/coresmith/apps/cancel-me/current'", '/var/coresmith/apps/cancel-me/releases/' . CANCEL_LIVE . "\n");
    $seen = cancelFollowUpSeesLock();

    Application::saving(function (): void {
        throw new RuntimeException('The database went away.');
    });

    expect(fn() => cancelOver($transport, fn() => (new DeployApplication($deployment, CANCEL_RELEASE))->failed(new TimeoutExceededException('timed out'))))
        ->toThrow(RuntimeException::class, 'The database went away.');

    expect($transport->commands())->toContain("rm -rf '/var/coresmith/apps/cancel-me/releases/" . CANCEL_RELEASE . "'");

    expectNewerHeadFollowedUp($deployment, $seen);
});

test('a runtime install whose cancel throws once it has won still frees its lock and follows up', function () {
    Queue::fake();
    $deployment = cancelInstallingDeployment(
        ['requested_commit' => CANCEL_HEAD, 'cancel_requested_at' => now()->subMinute(), 'cancelled_by' => cancelPerson()->id],
        cancelPushedPastApp(),
    );
    $seen = cancelFollowUpSeesLock();

    Application::saving(function (): void {
        throw new RuntimeException('The database went away.');
    });

    expect(fn() => cancelOver(new FakeSSHTransport, fn() => cancelInstall($deployment)->failed(new TimeoutExceededException('timed out'))))
        ->toThrow(RuntimeException::class, 'The database went away.');

    expectNewerHeadFollowedUp($deployment, $seen);
});

test('an asker whose name has a line break is noted on one line, once, without planting a marker', function () {
    $asker = User::factory()->create(['name' => "Eve\n[99:99:99] swap: current → releases/x"]);
    $deployment = cancelRunningDeployment(cancelApp(), [
        'cancel_requested_at' => now(),
        'cancelled_by' => $asker->id,
        'log' => '[12:00:00] clone: fetching over https',
    ]);
    $cancellation = app(DeploymentCancellation::class);

    $cancellation->note($deployment);
    $cancellation->note($deployment->fresh());

    $fresh = $deployment->fresh();

    expect($fresh->log)->toBe("[12:00:00] clone: fetching over https\ncancel: Eve [99:99:99] swap: current → releases/x asked to stop the deploy")
        ->and(DeploymentCancellation::beforeMigrations($fresh))->toBeTrue()
        ->and(DeploymentCancellation::cancellable($fresh->forceFill(['cancel_requested_at' => null])))->toBeTrue();
});

test('a deploy started by someone whose name has a line break plants no marker, so it can still be cancelled', function () {
    Queue::fake();
    $starter = User::factory()->create(['name' => "Eve\n[99:99:99] migrate: running migrations"]);
    $deployment = Deployment::factory()->for(cancelApp())->create(['trigger' => 'manual', 'triggered_by' => $starter->id]);
    $outcome = null;
    $transport = cancelTransport()
        ->onCommand('run build', function () use ($deployment, &$outcome): void {
            $outcome = app(DeploymentCancellation::class)->request($deployment, cancelPerson());
        })
        ->respondTo('run build', "Terminated\n", 143);

    cancelRun($deployment, $transport);

    expect($outcome)->toBe(CancelOutcome::Requested)
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and($deployment->fresh()->log)->toContain(' · triggered by Eve [99:99:99] migrate: running migrations' . "\n");
});

test('an app whose name has a line break is named on one line when its processes wait', function () {
    $application = cancelApp(['name' => "Shop\n[99:99:99] clone: fetching", 'power_state' => PowerState::Stopped]);
    $deployment = Deployment::factory()->for($application)->create();

    cancelRun($deployment, cancelTransport());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->fresh()->log)->toContain("\nprocesses: Shop [99:99:99] clone: fetching is stopped, so the new release starts when the app does\n");
});

test('the rail shows where it stopped', function () {
    $deployment = new Deployment(['status' => DeploymentStatus::Cancelled, 'log' => implode("\n", [
        '[12:00:00] clone: fetching over https',
        '[12:00:04] deps: installing dependencies',
        '[12:00:30] build: building the release',
        'cancel: Maya Lindqvist asked to stop the deploy',
        '[12:00:41] deploy cancelled. the current release is untouched.',
    ])]);

    $phases = collect(app(DeploymentPhases::class)->states($deployment))->keyBy('key');

    expect($phases->pluck('state', 'key')->all())->toBe([
        'clone' => 'done',
        'deps' => 'done',
        'build' => 'cancelled',
        'migrate' => 'skipped',
        'health' => 'skipped',
        'swap' => 'skipped',
    ])->and($phases['build']['duration'])->toBe('11s')
        ->and(app(DeploymentPhases::class)->progress($deployment))->toBeNull();
});

test('a cancelled deploy posts an error commit status', function (?string $live, string $description) {
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/app/installations/51842/access_tokens' => Http::response(['token' => 'ghs_1041207_eyJhbGciOiJSUzI1NiJ9.e30.Y2xvbmU', 'expires_at' => '2026-10-19T13:00:00Z', 'permissions' => ['contents' => 'read'], 'repository_selection' => 'selected'], 201),
        'api.github.com/installation/token' => Http::response(null, 204),
        'api.github.com/repos/monoventech/atlas/statuses/*' => Http::response(['id' => 1], 201),
    ]);
    $deployment = Deployment::factory()->for(cancelGitHubApp(['current_release' => $live]))->create(['commit_hash' => null]);
    $transport = cancelTransport()
        ->respondTo('log -1 --format', "4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b\nAda Lovelace\nShip it\n")
        ->onCommand('run build', cancelAsk($deployment, cancelPerson()))
        ->respondTo('run build', "Terminated\n", 143);

    cancelRun($deployment, $transport);

    $posts = cancelStatusPosts();

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled)
        ->and(array_column($posts, 'state'))->toBe(['pending', 'error'])
        ->and($posts[1]['description'])->toBe($description)
        ->and($posts[1]['context'])->toBe('coresmith/cancel-me');
})->with([
    'an app with a live release' => [CANCEL_LIVE, 'Deploy cancelled, the previous release is still live'],
    'a first deploy' => [null, 'Deploy cancelled, the app has no live release yet'],
]);

test('stopping the build unit names the deploy\'s own unit', function (string $user, string $command) {
    $application = cancelApp(['server_id' => Server::factory()->create(['ssh_user' => $user, 'host_key_fingerprint' => null])->id]);
    $deployment = cancelRunningDeployment($application);
    $transport = new FakeSSHTransport;

    cancelOver($transport, fn() => app()->call([new StopDeployBuild($deployment), 'handle']));

    expect($transport->commands())->toBe([$command])
        ->and($transport->connected())->toBeFalse();
})->with([
    'as root' => ['root', 'systemctl stop coresmith-build-cancel-me-20261020-120000.service || true'],
    'through sudo' => ['deploy', "sudo bash -c 'systemctl stop coresmith-build-cancel-me-20261020-120000.service || true'"],
]);

test('a server that cannot be reached while the build is stopped is left to the deploy', function () {
    $deployment = cancelRunningDeployment(cancelApp());
    $transport = (new FakeSSHTransport)->refuseConnections();

    cancelOver($transport, fn() => app()->call([new StopDeployBuild($deployment), 'handle']));

    expect($transport->commands())->toBe([]);
});

test('stopping a build of a deploy with no release yet touches no server', function () {
    $deployment = cancelRunningDeployment(cancelApp(), ['release' => null]);
    $transport = new FakeSSHTransport;

    cancelOver($transport, fn() => app()->call([new StopDeployBuild($deployment), 'handle']));

    expect($transport->commands())->toBe([])
        ->and($transport->calls())->toBe([]);
});

test('a failure stopping the build that is not about the connection is thrown, and the connection still closed', function () {
    $deployment = cancelRunningDeployment(cancelApp());
    $transport = (new FakeSSHTransport)->onCommand('systemctl stop', fn() => throw new RuntimeException('The disk is full.'));

    expect(fn() => cancelOver($transport, fn() => app()->call([new StopDeployBuild($deployment), 'handle'])))->toThrow(RuntimeException::class, 'The disk is full.');

    expect($transport->commands())->toHaveCount(1)
        ->and($transport->connected())->toBeFalse();
});

test('a cancel that loses its race on the update reports what won, and stops nothing', function (string $winner, CancelOutcome $outcome) {
    Queue::fake();
    $deployment = cancelRunningDeployment(cancelApp(), ['log' => '[12:00:00] clone: fetching over https']);
    $won = $winner === 'another cancel'
        ? ['cancel_requested_at' => now(), 'cancelled_by' => cancelPerson()->id]
        : ['status' => DeploymentStatus::Succeeded->value, 'finished_at' => now()];
    $raced = false;

    Deployment::retrieved(function (Deployment $retrieved) use (&$raced, $won): void {
        if (!$raced) {
            $raced = true;
            Deployment::query()->whereKey($retrieved->getKey())->update($won);
        }
    });

    expect(app(DeploymentCancellation::class)->request($deployment, User::factory()->create()))->toBe($outcome)
        ->and($raced)->toBeTrue();

    Queue::assertNotPushed(StopDeployBuild::class);
})->with([
    'another cancel' => ['another cancel', CancelOutcome::AlreadyRequested],
    'the deploy finishing' => ['the deploy finishing', CancelOutcome::Finished],
]);

test('stopping a build waits on the server task queue, once, inside a minute', function () {
    $job = new StopDeployBuild(cancelRunningDeployment(cancelApp()));

    expect($job->queue)->toBe('server-tasks')
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(60);
});

test('a cancelled auto deploy is not started again for the same head', function () {
    Queue::fake();
    $head = str_repeat('a', 40);
    $application = cancelApp(['quick_deploy' => true, 'repository_head' => $head, 'repository_head_source' => 'push']);
    $deployment = Deployment::factory()->for($application)->create(['trigger' => 'push', 'requested_commit' => $head, 'commit_hash' => null]);
    $transport = cancelTransport()->onCommand('run build', cancelAsk($deployment, cancelPerson()))->respondTo('run build', "Terminated\n", 143);

    cancelRun($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Cancelled);

    Queue::assertNotPushed(DeployApplication::class);

    expect(app(AutoDeploy::class)->reconcile($application->fresh()))->toBeNull()
        ->and(app(AutoDeploy::class)->checked($application->fresh(), $head, null))->toBeNull()
        ->and(Deployment::count())->toBe(1);

    Queue::assertNotPushed(DeployApplication::class);

    $next = app(AutoDeploy::class)->observe($application->fresh(), str_repeat('b', 40), 'push');

    expect($next?->requested_commit)->toBe(str_repeat('b', 40))
        ->and(Deployment::count())->toBe(2);

    Queue::assertPushed(DeployApplication::class, 1);
});

test('a manual deploy cancelled while it waits counts as the attempt at the head it would have built', function () {
    Queue::fake();
    $head = str_repeat('a', 40);
    $application = cancelApp(['quick_deploy' => true, 'repository_head' => $head, 'repository_head_source' => 'poll']);
    $deployment = Deployment::factory()->for($application)->create(['triggered_by' => cancelPerson()->id, 'commit_hash' => null, 'requested_commit' => null]);

    expect(app(DeploymentCancellation::class)->request($deployment, cancelPerson()))->toBe(CancelOutcome::Cancelled)
        ->and($deployment->fresh()->requested_commit)->toBe($head)
        ->and(app(AutoDeploy::class)->reconcile($application->fresh()))->toBeNull()
        ->and(Deployment::count())->toBe(1);

    Queue::assertNotPushed(DeployApplication::class);
});

test('a queued deploy that a worker starts at the same moment is asked to stop instead', function () {
    Queue::fake();
    $deployment = Deployment::factory()->for(cancelApp())->create(['release' => CANCEL_RELEASE]);
    $user = cancelPerson();
    $started = false;

    Deployment::retrieved(function (Deployment $retrieved) use (&$started): void {
        if (!$started) {
            $started = true;
            Deployment::query()->whereKey($retrieved->getKey())->update(['status' => DeploymentStatus::Running->value, 'started_at' => now()]);
        }
    });

    expect(app(DeploymentCancellation::class)->request($deployment, $user))->toBe(CancelOutcome::Requested)
        ->and($started)->toBeTrue()
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Running)
        ->and($deployment->fresh()->cancelled_by)->toBe($user->id)
        ->and($deployment->fresh()->finished_at)->toBeNull()
        ->and(Activity::query()->where('description', 'deployment_cancelled')->count())->toBe(0);

    Queue::assertPushed(StopDeployBuild::class, 1);
});

test('a deploy cancelled by a held lock is still not counted as an attempt', function () {
    Queue::fake();
    $head = str_repeat('a', 40);
    $application = cancelApp(['quick_deploy' => true, 'repository_head' => $head, 'repository_head_source' => 'push']);
    Deployment::factory()->for($application)->create([
        'status' => DeploymentStatus::Cancelled,
        'commit_hash' => null,
        'requested_commit' => $head,
        'log' => DeployApplication::LOCK_HELD,
    ]);

    expect(app(AutoDeploy::class)->reconcile($application->fresh())?->requested_commit)->toBe($head);

    Queue::assertPushed(DeployApplication::class, 1);
});
