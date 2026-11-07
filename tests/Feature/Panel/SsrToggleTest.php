<?php

declare(strict_types=1);

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Jobs\DeployApplication;
use App\Jobs\RollbackApplication;
use App\Jobs\SyncApplicationProcesses;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApplicationToggledNotification;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Panel\RollbackHistory;
use App\Services\Panel\SmartToggles;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProbeOutput;

const SSR_STARTS = 'The SSR server did not start on port 13714. Pass Number(process.env.INERTIA_SSR_PORT) as the port in your SSR entry, then deploy again.';

function ssrStack(bool $inertia = true, bool $ssr = true): array
{
    return [
        'runtimes' => [],
        'framework' => ['name' => 'laravel', 'major' => 13],
        'tooling' => ['manager' => 'bun', 'locked' => true, 'build' => true, 'start' => false, 'manager_version' => null, 'manifest' => true, 'ssr' => $ssr],
        'gems' => [],
        'packages' => ['horizon' => false, 'reverb' => false, 'inertia' => $inertia],
    ];
}

function ssrApp(array $attributes = []): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'host_key_fingerprint' => null]);

    return Application::factory()->for($team)->for($server)->create([
        'name' => 'Pages',
        'slug' => 'pages',
        'domain' => 'pages.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => null,
        'repository_url' => 'https://github.com/monoven/pages.git',
        'run_migrations' => false,
        'base_port' => 8200,
        'stack' => ssrStack(),
        ...$attributes,
    ]);
}

function ssrRow(Application $application): ?array
{
    return collect(app(SmartToggles::class)->describe($application->fresh()))->firstWhere('key', SmartToggles::INERTIA_SSR);
}

function ssrPut(Application $application, bool $on): TestResponse
{
    $user = User::factory()->create();
    $application->team->members()->attach($user, ['role' => TeamRole::Admin->value]);
    $show = route('servers.applications.show', [$application->server_id, $application]);

    return test()->actingAs($user->fresh())
        ->from($show)
        ->put(route('servers.applications.toggles.update', [$application->server_id, $application, SmartToggles::INERTIA_SSR]), ['on' => $on]);
}

function ssrTransport(bool $adapter = true, bool $script = true): FakeSSHTransport
{
    $scripts = $script ? '"build":"vite build","build:ssr":"vite build && vite build --ssr"' : '"build":"vite build"';
    $dependencies = $adapter ? '"@inertiajs/svelte":"^3.0"' : '"svelte":"^5.0"';

    return (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for([
            'composer.json' => '{"require":{"php":"^8.4","laravel/framework":"^13.0","inertiajs/inertia-laravel":"^2.0"}}',
            'package.json' => '{"scripts":{' . $scripts . '},"dependencies":{' . $dependencies . '}}',
        ], ['bun.lock']));
}

function ssrDeploy(Deployment $deployment, FakeSSHTransport $transport): void
{
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, '20261020120000'))->handle(app(SSHConnectionManager::class), app(DeploymentCommands::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

function ssrProcess(Application $application): ?string
{
    return collect((array) $application->fresh()->processes)->firstWhere('name', 'ssr')['command'] ?? null;
}

test('ssr is offered when composer, the frontend adapter and a build:ssr script are all there', function (array $attributes, bool $offered) {
    $keys = app(SmartToggles::class)->available(ssrApp($attributes));

    expect(in_array(SmartToggles::INERTIA_SSR, $keys, true))->toBe($offered);

    if ($offered) {
        expect(array_slice($keys, 0, 3))->toBe([SmartToggles::INERTIA_SSR, SmartToggles::QUEUE, SmartToggles::SCHEDULER]);
    }
})->with([
    'everything' => [[], true],
    'no build:ssr script' => [['stack' => ssrStack(ssr: false)], false],
    'no inertia' => [['stack' => ssrStack(inertia: false)], false],
    'an old stack' => [['stack' => null], false],
    'turned on before the stack changed' => [['stack' => null, 'toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714], true],
]);

test('turning it on takes a port and waits for the next deploy', function () {
    Queue::fake();
    Notification::fake();
    $application = ssrApp();

    ssrPut($application, true)->assertSessionHas('success', 'Saved. Inertia SSR starts with the next deploy.');

    $row = ssrRow($application);

    expect($application->fresh()->ssr_port)->toBe(13714)
        ->and($application->fresh()->toggles['inertia_ssr'])->toBeTrue()
        ->and($row['label'])->toBe('Inertia SSR')
        ->and($row['on'])->toBeTrue()
        ->and($row['description'])->toBe('Starts with the next deploy, which builds the SSR bundle · port 13714')
        ->and($row['busy_on'])->toBe('Saving, it starts with the next deploy…')
        ->and($row['busy_off'])->toBe('Stopping the unit and keeping its port…')
        ->and(ssrProcess($application))->toBeNull();

    Queue::assertNothingPushed();
});

test('turning it on keeps a port the app already holds', function () {
    Queue::fake();
    Notification::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => false], 'ssr_port' => 13714]);

    ssrPut($application, true);

    expect($application->fresh()->ssr_port)->toBe(13714);
});

test('ssr on without a port starts no server', function () {
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => null, 'processes' => [['name' => 'ssr', 'command' => 'INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=bun']]]);

    app(SmartToggles::class)->syncDeployTimeProcesses($application);

    expect(ssrProcess($application))->toBeNull();
});

test('off, it says where it was detected and what turning it on does', function () {
    expect(ssrRow(ssrApp())['description'])->toBe('Detected from composer.json and package.json · turning it on gives it a port from the next deploy');
});

test('a deploy builds the ssr bundle and starts the server on its port', function () {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = ssrTransport();

    ssrDeploy($deployment, $transport);

    $builds = array_values(array_filter($transport->commands(), fn(string $command): bool => str_contains($command, 'bun run build')));

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($builds)->toHaveCount(1)
        ->and($builds[0])->toContain("bun run build:ssr'")
        ->and($transport->uploads()['/etc/coresmith/env/pages.env'] ?? '')->toContain("INERTIA_SSR_ENABLED=\"true\"\n")
        ->and($transport->uploads()['/etc/coresmith/env/pages.env'] ?? '')->toContain("INERTIA_SSR_URL=\"http://127.0.0.1:13714\"\n")
        ->and(ssrProcess($application))->toBe('INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=bun')
        ->and($transport->uploads()['/etc/systemd/system/coresmith-app-pages-ssr.service'] ?? '')->toContain("\nExecStart=/usr/bin/env INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=bun\n")
        ->and($deployment->fresh()->log)->toContain("\nssr: building with build:ssr\n")
        ->and(ssrRow($application)['description'])->toBe('php artisan inertia:start-ssr · port 13714 auto-allocated');
});

test('a deploy with ssr off builds as before, even holding a port', function () {
    Queue::fake();
    $application = ssrApp(['ssr_port' => 13714]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = ssrTransport();

    ssrDeploy($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(implode("\n", $transport->commands()))->not->toContain('build:ssr')
        ->and($transport->uploads()['/etc/coresmith/env/pages.env'] ?? '')->toContain("INERTIA_SSR_ENABLED=\"false\"\n")
        ->and($transport->uploads()['/etc/coresmith/env/pages.env'] ?? '')->not->toContain('INERTIA_SSR_URL')
        ->and(ssrProcess($application))->toBeNull()
        ->and($deployment->fresh()->log)->not->toContain('ssr:');
});

test('a port other than inertia\'s default says the ssr entry must read it', function () {
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13700, 'processes' => [['name' => 'ssr', 'command' => 'INERTIA_SSR_PORT=13700 php artisan inertia:start-ssr --runtime=bun']]]);

    expect(ssrRow($application)['description'])->toBe('php artisan inertia:start-ssr · port 13700 auto-allocated · your SSR entry must read INERTIA_SSR_PORT');
});

test('a node app runs the ssr server on node', function () {
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'stack' => [...ssrStack(), 'tooling' => [...ssrStack()['tooling'], 'manager' => 'npm']]]);

    app(SmartToggles::class)->syncDeployTimeProcesses($application);

    expect(ssrProcess($application))->toBe('INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=node');
});

test("the app's own ssr settings win", function () {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'env_variables' => "INERTIA_SSR_URL=http://127.0.0.1:9999\n"]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = ssrTransport();

    ssrDeploy($deployment, $transport);

    $written = $transport->uploads()['/etc/coresmith/env/pages.env'] ?? '';

    expect(substr_count($written, 'INERTIA_SSR_URL='))->toBe(1)
        ->and($written)->toContain("INERTIA_SSR_URL=\"http://127.0.0.1:9999\"\n")
        ->and($written)->toContain("INERTIA_SSR_ENABLED=\"true\"\n");
});

test('an ssr server that does not start never fails the deploy', function () {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = ssrTransport()->respondTo("systemctl is-active 'coresmith-app-pages-ssr'", "failed\n", 3);

    ssrDeploy($deployment, $transport);

    $failure = Activity::query()->where('description', SmartToggles::START_FAILED)->sole();
    $commands = implode("\n", $transport->commands());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($failure->properties['toggle'])->toBe(SmartToggles::INERTIA_SSR)
        ->and($failure->properties['error'])->toBe(SSR_STARTS)
        ->and($commands)->toContain("systemctl disable --now 'coresmith-app-pages-ssr' || true")
        ->and($commands)->toContain("rm -f '/etc/systemd/system/coresmith-app-pages-ssr.service'")
        ->and(ssrProcess($application))->toBeNull()
        ->and($deployment->fresh()->log)->toContain("\nssr: inertia:start-ssr did not start, pages render in the browser until it does\n")
        ->and(ssrRow($application)['on'])->toBeTrue()
        ->and(ssrRow($application)['failure'])->toBe(SSR_STARTS);
});

test('a crash loop in the ssr server alone does not fail the deploy, one in web still does', function (string $unit, DeploymentStatus $status, bool $ssrStopped) {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'current_release' => '20260101-000000']);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = ssrTransport()->respondToSequence("systemctl is-active '{$unit}'", [["active\n", 0], ["failed\n", 3]]);

    rescue(fn() => ssrDeploy($deployment, $transport), report: false);

    expect($deployment->fresh()->status)->toBe($status)
        ->and(str_contains((string) $deployment->fresh()->log, "\nssr: inertia:start-ssr did not start, pages render in the browser until it does\n"))->toBe($ssrStopped)
        ->and(ssrProcess($application) === null)->toBe($ssrStopped);
})->with([
    'the ssr server' => ['coresmith-app-pages-ssr', DeploymentStatus::Succeeded, true],
    'php-fpm' => ['coresmith-app-pages-fpm', DeploymentStatus::Failed, false],
]);

test('turning it off stops the server and keeps the port', function () {
    Queue::fake();
    Notification::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'processes' => [['name' => 'ssr', 'command' => 'INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=bun']]]);

    ssrPut($application, false)->assertSessionHas('success', 'Saved. Inertia SSR stopped. Nothing else was touched.');

    expect($application->fresh()->ssr_port)->toBe(13714)
        ->and($application->fresh()->toggles['inertia_ssr'])->toBeFalse()
        ->and(ssrProcess($application))->toBeNull();

    Queue::assertPushed(SyncApplicationProcesses::class, fn(SyncApplicationProcesses $job): bool => (fn(): array => $this->removed)->call($job) === ['ssr']);
});

test('a second app is never given a port the first still holds, on or off', function () {
    Queue::fake();
    Notification::fake();
    $first = ssrApp();

    ssrPut($first, true);
    ssrPut($first, false);

    $second = Application::factory()->for($first->team)->for($first->server)->create(['slug' => 'pages-staging', 'framework' => 'laravel', 'base_port' => 8210, 'stack' => ssrStack()]);

    ssrPut($second, true);

    expect($first->fresh()->ssr_port)->toBe(13714)
        ->and($second->fresh()->ssr_port)->toBe(13700);
});

test('turning it back on reuses the port it kept', function () {
    Queue::fake();
    Notification::fake();
    $application = ssrApp(['ssr_port' => 13700]);

    ssrPut($application, true);

    expect($application->fresh()->ssr_port)->toBe(13700);
});

test('a release without what ssr needs leaves it out, says why and stops the old server', function (bool $adapter, bool $script, string $why) {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'processes' => [['name' => 'ssr', 'command' => 'INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=bun']]]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = ssrTransport($adapter, $script);

    ssrDeploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());
    $row = ssrRow($application);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(ssrProcess($application))->toBeNull()
        ->and($commands)->toContain("systemctl disable --now 'coresmith-app-pages-ssr' || true")
        ->and($commands)->toContain("rm -f '/etc/systemd/system/coresmith-app-pages-ssr.service'")
        ->and($commands)->not->toContain('build:ssr')
        ->and($commands)->not->toContain('/health')
        ->and($transport->uploads()['/etc/coresmith/env/pages.env'] ?? '')->not->toContain('INERTIA_SSR_ENABLED="true"')
        ->and($transport->uploads())->not->toHaveKey('/etc/systemd/system/coresmith-app-pages-ssr.service')
        ->and(Activity::query()->where('description', SmartToggles::START_FAILED)->count())->toBe(0)
        ->and($deployment->fresh()->log)->toContain("\nssr: {$why}\n")
        ->and($row['on'])->toBeTrue()
        ->and($row['description'])->toBe($why)
        ->and($row['failure'])->toBeNull();
})->with([
    'the build:ssr script is gone' => [true, false, 'package.json no longer has a build:ssr script, so it does not start'],
    'the inertia adapter is gone' => [false, true, 'composer.json and package.json no longer include Inertia, so it does not start'],
]);

test('a release that brings ssr back builds and starts it, reading the release not the last one', function () {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'stack' => ssrStack(ssr: false)]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = ssrTransport();

    ssrDeploy($deployment, $transport);

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and(implode("\n", $transport->commands()))->toContain("bun run build:ssr'")
        ->and($transport->uploads()['/etc/coresmith/env/pages.env'] ?? '')->toContain("INERTIA_SSR_ENABLED=\"true\"\n")
        ->and(ssrProcess($application))->not->toBeNull();
});

test('an app without inertia gets no ssr settings', function () {
    Queue::fake();
    $without = ssrApp(['stack' => null]);
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['composer.json' => '{"require":{"php":"^8.4","laravel/framework":"^13.0"}}'], []));

    ssrDeploy(Deployment::factory()->for($without)->create(), $transport);

    expect($transport->uploads()['/etc/coresmith/env/pages.env'] ?? '')->not->toContain('INERTIA_SSR');
});

test('a server that is active but does not answer on its port is stopped and reported', function () {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = ssrTransport()->respondTo('http://127.0.0.1:13714/health', "curl: (7) Failed to connect\n", 7);

    ssrDeploy($deployment, $transport);

    $commands = implode("\n", $transport->commands());

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($commands)->toContain("systemctl disable --now 'coresmith-app-pages-ssr' || true")
        ->and($commands)->toContain("rm -f '/etc/systemd/system/coresmith-app-pages-ssr.service'\nsystemctl daemon-reload")
        ->and(ssrProcess($application))->toBeNull()
        ->and(Activity::query()->where('description', SmartToggles::STARTED)->count())->toBe(0)
        ->and($deployment->fresh()->log)->toContain("\nssr: inertia:start-ssr did not start, pages render in the browser until it does\n")
        ->and(ssrRow($application)['failure'])->toBe(SSR_STARTS)
        ->and(ssrRow($application)['on'])->toBeTrue();
});

test('the health check asks the port this app holds, with a short timeout', function () {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13700]);
    $transport = ssrTransport();

    ssrDeploy(Deployment::factory()->for($application)->create(), $transport);

    $health = collect($transport->commands())->first(fn(string $command): bool => str_contains($command, '/health'));

    expect($health)->toContain('curl -fsS --max-time 2')
        ->and($health)->toContain("'http://127.0.0.1:13700/health'")
        ->and(ssrProcess($application))->not->toBeNull()
        ->and(Activity::query()->where('description', SmartToggles::STARTED)->sole()->properties['toggle'])->toBe(SmartToggles::INERTIA_SSR);
});

test('a server that answers clears an earlier failure', function () {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714]);

    ssrDeploy(Deployment::factory()->for($application)->create(), ssrTransport()->respondTo("systemctl is-active 'coresmith-app-pages-ssr'", "failed\n", 3));

    expect(ssrRow($application)['failure'])->toBe(SSR_STARTS);

    ssrDeploy(Deployment::factory()->for($application)->create(), ssrTransport());

    expect(ssrRow($application)['failure'])->toBeNull()
        ->and(ssrRow($application)['description'])->toBe('php artisan inertia:start-ssr · port 13714 auto-allocated');
});

function ssrRollback(Application $application, array $stack, FakeSSHTransport $transport): void
{
    Deployment::factory()->for($application)->create(['release' => '20260912-090000', 'status' => DeploymentStatus::Succeeded, 'stack' => $stack]);
    $transport->respondTo('ls -1dt', "/var/coresmith/apps/pages/releases/20261001-120000/\n/var/coresmith/apps/pages/releases/20260912-090000/\n");
    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new RollbackApplication($application, '20260912-090000'))->handle(app(SSHConnectionManager::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }
}

test('rolling back to a release from before ssr leaves it out, stops the server and succeeds', function () {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'current_release' => '20261001-120000', 'processes' => [['name' => 'ssr', 'command' => 'INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=bun']]]);
    $transport = new FakeSSHTransport;

    ssrRollback($application, ssrStack(ssr: false), $transport);

    $commands = implode("\n", $transport->commands());

    expect($application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and(ssrProcess($application))->toBeNull()
        ->and($commands)->toContain("systemctl disable --now 'coresmith-app-pages-ssr' || true")
        ->and($commands)->toContain("rm -f '/etc/systemd/system/coresmith-app-pages-ssr.service'")
        ->and($transport->uploads()['/etc/coresmith/env/pages.env'] ?? '')->toContain("INERTIA_SSR_ENABLED=\"false\"\n")
        ->and(Activity::query()->where('description', 'application_rolled_back')->count())->toBe(1)
        ->and(Activity::query()->whereIn('description', [RollbackHistory::DID_NOT_START, RollbackHistory::WORKER_DID_NOT_START, SmartToggles::START_FAILED])->count())->toBe(0)
        ->and(ssrRow($application)['description'])->toBe('package.json no longer has a build:ssr script, so it does not start');
});

test('a rollback whose ssr server does not come back records it and still succeeds', function (string $needle, string $output, int $exit) {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'current_release' => '20261001-120000', 'processes' => [['name' => 'ssr', 'command' => 'INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=bun']]]);
    $transport = (new FakeSSHTransport)->respondTo($needle, $output, $exit);

    ssrRollback($application, ssrStack(), $transport);

    expect($application->fresh()->status)->toBe(ApplicationStatus::Active)
        ->and(ssrProcess($application))->toBeNull()
        ->and(implode("\n", $transport->commands()))->toContain("systemctl disable --now 'coresmith-app-pages-ssr' || true")
        ->and(Activity::query()->where('description', 'application_rolled_back')->count())->toBe(1)
        ->and(Activity::query()->where('description', RollbackHistory::WORKER_DID_NOT_START)->count())->toBe(0)
        ->and(ssrRow($application)['failure'])->toBe(SSR_STARTS);
})->with([
    'the unit does not start' => ["systemctl is-active 'coresmith-app-pages-ssr'", "failed\n", 3],
    'the port does not answer' => ['http://127.0.0.1:13714/health', "curl: (7) Failed to connect\n", 7],
]);

test('a rollback whose ssr server answers clears an earlier failure', function () {
    Queue::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'current_release' => '20261001-120000', 'processes' => [['name' => 'ssr', 'command' => 'INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=bun']]]);
    activity()->performedOn($application)->withProperties(['toggle' => SmartToggles::INERTIA_SSR, 'label' => 'Inertia SSR', 'error' => SSR_STARTS])->log(SmartToggles::START_FAILED);

    ssrRollback($application, ssrStack(), new FakeSSHTransport);

    expect(ssrRow($application)['failure'])->toBeNull()
        ->and(ssrProcess($application))->not->toBeNull();
});

test('the ssr unit is named by its label when it is the one that failed', function () {
    $application = ssrApp();

    expect(app(SmartToggles::class)->failedWorker($application, 'coresmith-app-pages-ssr'))->toBe('Inertia SSR')
        ->and(app(SmartToggles::class)->failedWorker($application, 'coresmith-app-pages-reverb'))->toBe('Reverb websockets')
        ->and(app(SmartToggles::class)->failedWorker($application, 'coresmith-app-pages-queue'))->toBe('Queue worker')
        ->and(app(SmartToggles::class)->failedWorker($application, 'coresmith-app-pages-custom'))->toBe('Custom');
});

test('turning it on does not say no deployment was needed, turning it off still does', function (bool $on, string $sentence) {
    Queue::fake();
    Notification::fake();
    $application = ssrApp($on ? [] : ['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714]);
    $teammate = User::factory()->create();
    $application->team->members()->attach($teammate, ['role' => TeamRole::Member->value]);

    ssrPut($application, $on)->assertSessionHas('success');

    Notification::assertSentTo($teammate->fresh(), ApplicationToggledNotification::class, fn(ApplicationToggledNotification $notification): bool => str_ends_with($notification->toMail($teammate)->introLines[0], $sentence));
})->with([
    'on' => [true, 'made the change from the panel.'],
    'off' => [false, 'made the change from the panel. No deployment was needed.'],
]);
