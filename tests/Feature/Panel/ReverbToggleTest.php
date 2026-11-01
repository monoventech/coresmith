<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Jobs\DeployApplication;
use App\Jobs\SyncApplicationProcesses;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Panel\Reverb;
use App\Services\Panel\SmartToggles;
use App\Services\Runtime\RepositoryProbe;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSSHTransport;
use Tests\Support\ProbeOutput;

const REVERB_SETTINGS = "REVERB_APP_ID=chat-app\nREVERB_APP_KEY=chat-key\nREVERB_APP_SECRET=s3cret\n";

function reverbApp(array $attributes = [], ?string $env = null): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web-1', 'host_key_fingerprint' => null]);

    return Application::factory()->for($team)->for($server)->create([
        'name' => 'Chat',
        'slug' => 'chat',
        'domain' => 'chat.example.com',
        'type' => ApplicationType::Php,
        'framework' => 'laravel',
        'php_version' => null,
        'repository_url' => 'https://github.com/monoven/chat.git',
        'run_migrations' => false,
        'base_port' => 8200,
        'env_variables' => $env ?? REVERB_SETTINGS,
        'stack' => ['runtimes' => [], 'framework' => ['name' => 'laravel', 'major' => 13], 'tooling' => null, 'gems' => [], 'packages' => ['horizon' => false, 'reverb' => true, 'inertia' => false]],
        ...$attributes,
    ]);
}

function reverbAdmin(Application $application): User
{
    $user = User::factory()->create();
    $application->team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    return $user->fresh();
}

function reverbRow(Application $application): ?array
{
    return collect(app(SmartToggles::class)->describe($application->fresh()))->firstWhere('key', SmartToggles::REVERB);
}

function reverbPut(Application $application, bool $on): TestResponse
{
    $show = route('servers.applications.show', [$application->server_id, $application]);

    return test()->actingAs(reverbAdmin($application))
        ->from($show)
        ->put(route('servers.applications.toggles.update', [$application->server_id, $application, SmartToggles::REVERB]), ['on' => $on]);
}

test('reverb is offered only to laravel apps that install it', function (array $attributes, bool $offered) {
    $keys = app(SmartToggles::class)->available(reverbApp($attributes));

    expect(in_array(SmartToggles::REVERB, $keys, true))->toBe($offered);

    if ($offered) {
        expect(array_search(SmartToggles::REVERB, $keys, true))->toBe(array_search(SmartToggles::SCHEDULER, $keys, true) + 1);
    }
})->with([
    'installs reverb' => [[], true],
    'does not install it' => [['stack' => ['packages' => ['horizon' => false, 'reverb' => false, 'inertia' => false]]], false],
    'an old stack' => [['stack' => null], false],
    'turned on before it was removed' => [['stack' => null, 'toggles' => ['reverb' => true]], true],
    'not laravel' => [['framework' => 'symfony'], false],
]);

test('it explains what is missing before it can turn on', function (array $attributes, ?string $env, string $problem) {
    Queue::fake();
    $application = reverbApp($attributes, $env);
    $row = reverbRow($application);

    expect($row['description'])->toBe($problem)
        ->and($row['disabled'])->toBeTrue()
        ->and($row['on'])->toBeFalse();

    reverbPut($application, true)->assertSessionHas('error', $problem);

    expect(((array) $application->fresh()->toggles)['reverb'] ?? false)->toBeFalse();
})->with([
    'no settings' => [[], "APP_ENV=production\n", 'Set REVERB_APP_ID, REVERB_APP_KEY and REVERB_APP_SECRET in the environment to turn it on'],
    'no secret' => [[], "REVERB_APP_ID=chat-app\nREVERB_APP_KEY=chat-key\n", 'Set REVERB_APP_ID, REVERB_APP_KEY and REVERB_APP_SECRET in the environment to turn it on'],
    'a key with a slash' => [[], "REVERB_APP_ID=chat-app\nREVERB_APP_KEY=chat/key\nREVERB_APP_SECRET=s3cret\n", 'REVERB_APP_ID and REVERB_APP_KEY can only use letters, numbers, dots, dashes and underscores'],
    'no domain' => [['domain' => null], null, 'Add a domain first, browsers reach Reverb through it'],
]);

test('ready and off, it says what turning it on does', function () {
    $row = reverbRow(reverbApp());

    expect($row['label'])->toBe('Reverb websockets')
        ->and($row['description'])->toBe('Available, turning it on gives it port 8202 from the next deploy')
        ->and($row['disabled'])->toBeFalse()
        ->and($row['busy_on'])->toBe('Saving, it starts with the next deploy…')
        ->and($row['busy_off'])->toBe('Stopping the unit and removing the route…');
});

test('turning it on waits for the next deploy', function () {
    Queue::fake();
    Notification::fake();
    $application = reverbApp();

    reverbPut($application, true)->assertSessionHas('success', 'Saved. Reverb websockets starts with the next deploy.');

    expect($application->fresh()->toggles['reverb'])->toBeTrue()
        ->and(reverbRow($application)['description'])->toBe('Starts with the next deploy, on port 8202 behind wss://chat.example.com/app')
        ->and(reverbRow($application)['on'])->toBeTrue()
        ->and(collect($application->fresh()->processes)->pluck('name')->all())->toBe([]);

    Queue::assertNothingPushed();
});

test('a deploy starts it and routes it', function () {
    Queue::fake();
    $application = reverbApp(['toggles' => ['reverb' => true]]);
    $deployment = Deployment::factory()->for($application)->create();
    $transport = (new FakeSSHTransport)
        ->respondTo('df /', "40\n")
        ->respondTo(RepositoryProbe::DIRECTORY_VARIABLE, ProbeOutput::for(['composer.json' => '{"require":{"php":"^8.4","laravel/framework":"^13.0","laravel/reverb":"^1.5"}}']));

    SSHConnectionManager::createTransportUsing(fn(): FakeSSHTransport => $transport);

    try {
        (new DeployApplication($deployment, '20261020120000'))->handle(app(SSHConnectionManager::class), app(DeploymentCommands::class));
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    $reverb = collect($application->fresh()->processes)->firstWhere('name', 'reverb');

    expect($deployment->fresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($reverb['command'])->toBe('php artisan reverb:start --host=127.0.0.1 --port=8202')
        ->and($transport->uploads()['/etc/coresmith/env/chat.env'] ?? '')->toContain("REVERB_SERVER_PORT=\"8202\"\n")
        ->and($transport->uploads())->toHaveKey('/etc/systemd/system/coresmith-app-chat-reverb.service')
        ->and($deployment->fresh()->log)->toContain("\nreverb: routing wss://chat.example.com/app to port 8202\n")
        ->and(app(Reverb::class)->live($application->fresh()))->toBeTrue()
        ->and(reverbRow($application)['description'])->toBe('php artisan reverb:start · port 8202 · wss://chat.example.com/app');
});

test('a deploy leaves reverb out while it is not ready', function () {
    Queue::fake();
    $application = reverbApp(['toggles' => ['reverb' => true], 'processes' => [['name' => 'reverb', 'command' => Reverb::COMMAND . '8202']]], "APP_ENV=production\n");

    app(SmartToggles::class)->syncDeployTimeProcesses($application);

    expect(collect($application->fresh()->processes)->pluck('name')->all())->toBe([])
        ->and(reverbRow($application)['description'])->toBe('Set REVERB_APP_ID, REVERB_APP_KEY and REVERB_APP_SECRET in the environment to turn it on')
        ->and(reverbRow($application)['disabled'])->toBeFalse();
});

test('turning it off stops it and its route at once', function () {
    Queue::fake();
    Notification::fake();
    $application = reverbApp(['toggles' => ['reverb' => true], 'processes' => [['name' => 'reverb', 'command' => Reverb::COMMAND . '8202']]]);

    reverbPut($application, false)->assertSessionHas('success', 'Saved. Reverb websockets stopped. Nothing else was touched.');

    expect($application->fresh()->toggles['reverb'])->toBeFalse()
        ->and(collect($application->fresh()->processes)->pluck('name')->all())->toBe([]);

    Queue::assertPushed(SyncApplicationProcesses::class, fn(SyncApplicationProcesses $job): bool => (fn(): array => $this->removed)->call($job) === ['reverb']);
    Queue::assertPushed(SyncApplicationRoute::class, 1);
});
