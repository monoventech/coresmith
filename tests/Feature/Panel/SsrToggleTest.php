<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Jobs\DeployApplication;
use App\Jobs\SyncApplicationProcesses;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\DeploymentCommands;
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

function ssrTransport(): FakeSSHTransport
{
    return (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for([
            'composer.json' => '{"require":{"php":"^8.4","laravel/framework":"^13.0","inertiajs/inertia-laravel":"^2.0"}}',
            'package.json' => '{"scripts":{"build":"vite build","build:ssr":"vite build && vite build --ssr"},"dependencies":{"@inertiajs/svelte":"^3.0"}}',
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
        ->and($row['busy_off'])->toBe('Stopping the unit and freeing the port…')
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
        ->and($transport->uploads()['/etc/coresmith/env/pages.env'] ?? '')->not->toContain('INERTIA_SSR')
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

test('turning it off frees the port at once', function () {
    Queue::fake();
    Notification::fake();
    $application = ssrApp(['toggles' => ['inertia_ssr' => true], 'ssr_port' => 13714, 'processes' => [['name' => 'ssr', 'command' => 'INERTIA_SSR_PORT=13714 php artisan inertia:start-ssr --runtime=bun']]]);

    ssrPut($application, false)->assertSessionHas('success', 'Saved. Inertia SSR stopped. Nothing else was touched.');

    expect($application->fresh()->ssr_port)->toBeNull()
        ->and($application->fresh()->toggles['inertia_ssr'])->toBeFalse()
        ->and(ssrProcess($application))->toBeNull();

    Queue::assertPushed(SyncApplicationProcesses::class, fn(SyncApplicationProcesses $job): bool => (fn(): array => $this->removed)->call($job) === ['ssr']);
});
