<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Events\DeploymentFailed;
use App\Events\DeploymentUpdated;
use App\Exceptions\DeploymentAborted;
use App\Exceptions\SSHCommandFailedException;
use App\Jobs\DeployApplication;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\User;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Deployment\DeploymentPhases;
use App\Services\Runtime\AsdfManager;
use App\Services\SSH\SSHConnectionManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeSSHTransport;

function releaseApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'my-app',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => '8.5.5',
        'repository_url' => 'https://github.com/monoven/example.git',
        'repository_branch' => 'production',
        'directory' => '/',
        'web_directory' => '/public',
        'run_migrations' => true,
        'base_port' => 8000,
    ], $attributes));
}

function releaseTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)->respondTo('df /', "40\n");
}

function releaseRun(Deployment $deployment, FakeSSHTransport $transport, string $timestamp = '20260913-140509'): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, $timestamp))->handle(
            app(SSHConnectionManager::class),
            new DeploymentCommands(new AsdfManager),
        );
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function releaseLogLines(Deployment $deployment): array
{
    return preg_split('/\R/', (string) $deployment->fresh()->log) ?: [];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-13 14:05:09'));
});

test('a deployment records the release it builds and the branch it deploys', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create();

    releaseRun($deployment, releaseTransport());

    $fresh = $deployment->fresh();

    expect($fresh->release)->toBe('20260913-140509')
        ->and($fresh->branch)->toBe('production')
        ->and($fresh->status)->toBe(DeploymentStatus::Succeeded)
        ->and($fresh->started_at?->toDateTimeString())->toBe('2026-09-13 14:05:09');
});

test('the log opens with the release, branch and who triggered it', function () {
    $user = User::factory()->create(['name' => 'Grace Hopper']);
    $deployment = Deployment::factory()->for(releaseApp())->create(['triggered_by' => $user->id]);

    releaseRun($deployment, releaseTransport());

    expect(releaseLogLines($deployment)[0])->toBe('[14:05:09] release 20260913-140509 · branch production · triggered by Grace Hopper');
});

test('a deployment nobody triggered is attributed to a push', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create(['triggered_by' => null]);

    releaseRun($deployment, releaseTransport());

    expect(releaseLogLines($deployment)[0])->toEndWith('triggered by push');
});

test('every phase writes a timestamped marker in deploy order', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create();

    releaseRun($deployment, releaseTransport());

    $log = (string) $deployment->fresh()->log;
    $markers = [
        '[14:05:09] clone: fetching over https',
        '[14:05:09] deps: installing dependencies',
        '[14:05:09] build: building the release',
        '[14:05:09] migrate: running migrations against release 20260913-140509',
        '[14:05:09] health: checking the release before it goes live',
        '[14:05:09] swap: current → releases/20260913-140509',
        '[14:05:09] deployed. previous release kept for instant rollback.',
    ];

    $positions = array_map(fn(string $marker): int|false => strpos($log, $marker), $markers);

    expect($positions)->not->toContain(false);

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);
});

test('a successful log reads as every phase done', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create();

    releaseRun($deployment, releaseTransport());

    expect(collect((new DeploymentPhases)->states($deployment->fresh()))->pluck('state')->unique()->all())->toBe(['done']);
});

test('each command run in the release is echoed before its output', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create();
    $transport = releaseTransport()->respondTo('composer install', "Installing dependencies from lock file\n");

    releaseRun($deployment, $transport);

    $lines = releaseLogLines($deployment);
    $command = array_search('$ composer install --no-dev --optimize-autoloader --no-interaction', $lines, true);

    expect($command)->not->toBeFalse()
        ->and($lines[$command + 1])->toBe('Installing dependencies from lock file')
        ->and($lines)->toContain('$ php artisan config:cache')
        ->and($lines)->toContain('$ php artisan route:cache')
        ->and($lines)->toContain('$ php artisan view:cache')
        ->and($lines)->toContain('$ php artisan migrate --force');
});

test('a custom blueprint is logged as the build phase with no dependency install or migration marker', function () {
    $deployment = Deployment::factory()->for(releaseApp(['custom_blueprint' => "echo building\n"]))->create();

    releaseRun($deployment, releaseTransport());

    $log = (string) $deployment->fresh()->log;

    expect($log)->toContain('[14:05:09] build: running the custom blueprint')
        ->and($log)->not->toContain('] deps: installing dependencies')
        ->and($log)->not->toContain('] migrate:');
});

test('a private repository announces that it fetches with the deploy key', function () {
    $deployment = Deployment::factory()->for(releaseApp(['repository_url' => 'git@github.com:monoven/example.git']))->create();

    releaseRun($deployment, releaseTransport());

    expect((string) $deployment->fresh()->log)->toContain('[14:05:09] clone: fetching via deploy key (ed25519, read-only)');
});

test('the commit hash, author and subject are resolved from git when the push did not carry them', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create(['commit_hash' => null, 'commit_message' => null]);
    $transport = releaseTransport()->respondTo('log -1 --format', "4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b\nAda Lovelace\nShip the release screen\n");

    releaseRun($deployment, $transport);

    $fresh = $deployment->fresh();

    expect($fresh->commit_hash)->toBe('4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b')
        ->and($fresh->commit_author)->toBe('Ada Lovelace')
        ->and($fresh->commit_message)->toBe('Ship the release screen')
        ->and($fresh->tag)->toBeNull()
        ->and($fresh->tag_signed)->toBeNull()
        ->and(releaseLogLines($deployment))->toContain('commit 4f3c2b1 by Ada Lovelace');
});

test('the commit is read from the branch being deployed', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create();
    $transport = releaseTransport();

    releaseRun($deployment, $transport);

    expect(implode("\n", $transport->commands()))
        ->toContain("git --git-dir=/var/coresmith/apps/my-app/repo log -1 --format='%H%n%an%n%s' 'production'");
});

test('the commit git archived is recorded even when the push named another', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create(['commit_hash' => 'c0ffee1234', 'commit_message' => 'From the push']);
    $transport = releaseTransport()->respondTo('log -1 --format', "4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b\nAda Lovelace\nShip the release screen\n");

    releaseRun($deployment, $transport);

    expect($deployment->fresh())
        ->commit_hash->toBe('4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b')
        ->commit_message->toBe('Ship the release screen')
        ->commit_author->toBe('Ada Lovelace');
});

test('a commit with no subject still records the hash and author', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create(['commit_hash' => null, 'commit_message' => null]);
    $transport = releaseTransport()->respondTo('log -1 --format', "4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b\nAda Lovelace\n");

    releaseRun($deployment, $transport);

    expect($deployment->fresh()->commit_author)->toBe('Ada Lovelace')
        ->and($deployment->fresh()->commit_message)->toBeNull();
});

test('git output that is not a commit leaves the commit details alone and looks for no tag', function (string $output) {
    $deployment = Deployment::factory()->for(releaseApp())->create(['commit_hash' => null, 'commit_message' => null]);
    $transport = releaseTransport()->respondTo('log -1 --format', $output);

    releaseRun($deployment, $transport);

    expect($deployment->fresh()->commit_author)->toBeNull()
        ->and($deployment->fresh()->commit_hash)->toBeNull()
        ->and(implode("\n", $transport->commands()))->not->toContain('describe --tags');
})->with([
    'nothing' => '',
    'an error' => "fatal: ambiguous argument 'production'\n",
    'a short hash' => "4f3c2b1\nAda Lovelace\nShip it\n",
]);

test('a tagged commit records the tag and whether its signature is present', function (string $object, bool $signed) {
    $deployment = Deployment::factory()->for(releaseApp())->create(['commit_hash' => null]);
    $transport = releaseTransport()
        ->respondTo('log -1 --format', "4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b\nAda Lovelace\nRelease 2.4\n")
        ->respondTo('describe --tags', "v2.4.0\n")
        ->respondTo('cat-file -p', $object);

    releaseRun($deployment, $transport);

    expect($deployment->fresh()->tag)->toBe('v2.4.0')
        ->and($deployment->fresh()->tag_signed)->toBe($signed)
        ->and(implode("\n", $transport->commands()))->toContain("cat-file -p 'refs/tags/v2.4.0'")
        ->and(releaseLogLines($deployment))->toContain('commit 4f3c2b1 by Ada Lovelace · tag v2.4.0');
})->with([
    'pgp signed' => ["object 4f3c2b1a\ntype commit\ntag v2.4.0\n\n-----BEGIN PGP SIGNATURE-----\nabc\n-----END PGP SIGNATURE-----\n", true],
    'ssh signed' => ["object 4f3c2b1a\ntype commit\ntag v2.4.0\n\n-----BEGIN SSH SIGNATURE-----\nabc\n-----END SSH SIGNATURE-----\n", true],
    'unsigned' => ["object 4f3c2b1a\ntype commit\ntag v2.4.0\n\nRelease 2.4\n", false],
]);

test('a describe result that is not a plain tag name is ignored', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create();
    $transport = releaseTransport()
        ->respondTo('log -1 --format', "4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a9f8e7d6c5b\nAda Lovelace\nShip it\n")
        ->respondTo('describe --tags', "v1; rm -rf /\n");

    releaseRun($deployment, $transport);

    expect($deployment->fresh()->tag)->toBeNull()
        ->and($deployment->fresh()->tag_signed)->toBeNull()
        ->and(implode("\n", $transport->commands()))->not->toContain('cat-file');
});

test('the application is marked deploying while the release builds and active on the new release afterwards', function () {
    $application = releaseApp(['status' => ApplicationStatus::Pending]);
    $deployment = Deployment::factory()->for($application)->create();
    $seen = [];

    Event::listen(DeploymentUpdated::class, function () use ($application, &$seen): void {
        $seen[] = Application::query()->whereKey($application->id)->value('status');
    });

    releaseRun($deployment, releaseTransport());

    expect($seen[0])->toBe(ApplicationStatus::Deploying)
        ->and(end($seen))->toBe(ApplicationStatus::Active)
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and($application->fresh()->current_release)->toBe('20260913-140509');
});

test('health checks run before the symlink swap and are logged', function () {
    $deployment = Deployment::factory()->for(releaseApp())->create();
    $transport = releaseTransport();

    releaseRun($deployment, $transport);

    $commands = $transport->commands();
    $check = array_search("test -f '/var/coresmith/apps/my-app/releases/20260913-140509/public/index.php'", $commands, true);
    $swap = array_search('ln -sfn /var/coresmith/apps/my-app/releases/20260913-140509 /var/coresmith/apps/my-app/current.tmp', $commands, true);

    expect($check)->not->toBeFalse()
        ->and($swap)->not->toBeFalse()
        ->and($check)->toBeLessThan($swap)
        ->and(releaseLogLines($deployment))->toContain('health: public/index.php present');
});

test('a missing health check target aborts before the swap and removes the half built release', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(releaseApp())->create();
    $transport = releaseTransport()->respondTo("public/index.php'", '', 1);

    expect(fn() => releaseRun($deployment, $transport))->toThrow(DeploymentAborted::class);

    $commands = implode("\n", $transport->commands());

    expect($commands)->not->toContain('mv -Tf')
        ->and($commands)->not->toContain('current.tmp')
        ->and($commands)->toContain('rm -rf /var/coresmith/apps/my-app/releases/20260913-140509')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and((string) $deployment->fresh()->log)->toContain('Health check failed: public/index.php is missing, so the release was not put live.');

    Event::assertDispatched(DeploymentFailed::class);
});

test('a failure before the swap leaves a live application active on its current release', function () {
    Event::fake([DeploymentFailed::class]);
    $application = releaseApp(['status' => ApplicationStatus::Active, 'current_release' => '20260801-090000']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = releaseTransport()->respondTo('artisan migrate', "SQLSTATE[42S01]: duplicate column\n", 1);

    expect(fn() => releaseRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and($application->fresh()->current_release)->toBe('20260801-090000')
        ->and(releaseLogLines($deployment))->toContain('[14:05:09] deploy failed. the current release is untouched.')
        ->and(implode("\n", $transport->commands()))->toContain('rm -rf /var/coresmith/apps/my-app/releases/20260913-140509');
});

test('a failure on an application that was never deployed marks it failed', function () {
    Event::fake([DeploymentFailed::class]);
    $application = releaseApp(['status' => ApplicationStatus::Pending, 'current_release' => null]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = releaseTransport()->respondTo('composer install', 'Your lock file is out of date', 1);

    expect(fn() => releaseRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Failed)
        ->and($application->fresh()->current_release)->toBeNull();
});

test('a failure before the swap reads as failed in the phase it happened in', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(releaseApp())->create();
    $transport = releaseTransport()->respondTo('artisan migrate', 'boom', 1);

    expect(fn() => releaseRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    expect(collect((new DeploymentPhases)->states($deployment->fresh()))->pluck('state', 'key')->all())->toBe([
        'clone' => 'done',
        'deps' => 'done',
        'build' => 'done',
        'migrate' => 'failed',
        'health' => 'skipped',
        'swap' => 'skipped',
    ]);
});

test('a failure after the swap never deletes the release that is now live and says so', function () {
    Event::fake([DeploymentFailed::class]);
    $application = releaseApp(['status' => ApplicationStatus::Active, 'current_release' => '20260801-090000']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = releaseTransport()->respondTo('systemctl daemon-reload', 'Failed to reload daemon', 1);

    expect(fn() => releaseRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    $commands = implode("\n", $transport->commands());

    expect($commands)->toContain('mv -Tf /var/coresmith/apps/my-app/current.tmp /var/coresmith/apps/my-app/current')
        ->and($commands)->not->toContain('rm -rf /var/coresmith/apps/my-app/releases/20260913-140509')
        ->and(releaseLogLines($deployment))->toContain('[14:05:09] deploy failed after the symlink swap. releases/20260913-140509 is live, check its processes.')
        ->and((string) $deployment->fresh()->log)->not->toContain('the current release is untouched')
        ->and($deployment->fresh()->status)->toBe(DeploymentStatus::Failed)
        ->and($application->fresh()->current_release)->toBe('20260913-140509')
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Active);

    Event::assertDispatched(DeploymentFailed::class);
});

test('a failure after the swap reads as a failed swap phase with its duration', function () {
    Event::fake([DeploymentFailed::class]);
    $deployment = Deployment::factory()->for(releaseApp())->create();
    $transport = releaseTransport()->respondTo('systemctl daemon-reload', 'Failed to reload daemon', 1);

    expect(fn() => releaseRun($deployment, $transport))->toThrow(SSHCommandFailedException::class);

    $swap = collect((new DeploymentPhases)->states($deployment->fresh()))->firstWhere('key', 'swap');

    expect($swap)->toMatchArray(['state' => 'failed', 'duration' => '0s']);
});
