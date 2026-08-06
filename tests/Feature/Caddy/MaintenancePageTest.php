<?php

declare(strict_types=1);

use App\Enums\PowerState;
use App\Enums\TeamRole;
use App\Jobs\StartApplication;
use App\Jobs\StopApplication;
use App\Jobs\SyncApplicationRoute;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSSHTransport;

function maintenanceApp(array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'shop',
        'domain' => 'shop.example.com',
        'base_port' => 8000,
    ], $attributes));
}

function routeFor(Application $application): array
{
    return (new CaddyRouteBuilder)->build($application);
}

function handlers(array $route): array
{
    return $route['handle'][0]['routes'];
}

test('a running application is routed to its own handlers', function () {
    $route = routeFor(maintenanceApp(['power_state' => PowerState::Active]));
    $encoded = json_encode($route, JSON_THROW_ON_ERROR);

    expect(handlers($route))->toHaveCount(2)
        ->and($encoded)->toContain('php_fastcgi')
        ->and($encoded)->not->toContain('static_response":{"status_code":503')
        ->and($encoded)->not->toContain('is unavailable');
});

test('a stopped application is served a maintenance page instead of the site', function () {
    $route = routeFor(maintenanceApp(['power_state' => PowerState::Stopped, 'name' => 'Shop']));
    $encoded = json_encode($route, JSON_THROW_ON_ERROR);

    expect(handlers($route))->toHaveCount(1)
        ->and($encoded)->not->toContain('php_fastcgi')
        ->and($encoded)->not->toContain('reverse_proxy')
        ->and($encoded)->not->toContain('file_server');

    $response = handlers($route)[0]['handle'];
    $static = collect($response)->firstWhere('handler', 'static_response');

    expect($static['status_code'])->toBe(503)
        ->and($static['body'])->toContain('Shop is unavailable')
        ->and($static['body'])->toContain('stopped by its owner');
});

test('maintenance mode serves the page while the processes keep running', function () {
    $application = maintenanceApp(['power_state' => PowerState::Active, 'toggles' => ['maintenance_mode' => true]]);
    $static = collect(handlers(routeFor($application))[0]['handle'])->firstWhere('handler', 'static_response');

    expect($static['status_code'])->toBe(503)
        ->and($static['body'])->toContain('down for maintenance');
});

test('a sleeping application still serves the site so a request can wake it', function () {
    $route = routeFor(maintenanceApp(['power_state' => PowerState::Sleeping]));

    expect(json_encode($route, JSON_THROW_ON_ERROR))->toContain('php_fastcgi');
});

test('the maintenance page tells crawlers not to index it and browsers not to cache it', function () {
    $handlers = collect(handlers(routeFor(maintenanceApp(['power_state' => PowerState::Stopped])))[0]['handle']);
    $static = $handlers->firstWhere('handler', 'static_response');
    $headers = $handlers->where('handler', 'headers')->pluck('response.set')->collapse();

    expect($static['body'])->toContain('name="robots" content="noindex"')
        ->and($headers['Cache-Control'])->toBe(['no-store'])
        ->and($headers['Retry-After'])->toBe(['300']);
});

test('an application name cannot inject markup into the maintenance page', function () {
    $application = maintenanceApp([
        'power_state' => PowerState::Stopped,
        'name' => '<script>alert("xss")</script>',
    ]);

    $static = collect(handlers(routeFor($application))[0]['handle'])->firstWhere('handler', 'static_response');

    expect($static['body'])->not->toContain('<script>alert')
        ->and($static['body'])->toContain('&lt;script&gt;');
});

test('the maintenance page keeps the security headers the site would have had', function () {
    $handlers = collect(handlers(routeFor(maintenanceApp(['power_state' => PowerState::Stopped])))[0]['handle']);
    $headers = $handlers->where('handler', 'headers')->pluck('response.set')->collapse();

    expect($headers)->toHaveKey('X-Frame-Options')
        ->and($headers)->toHaveKey('Strict-Transport-Security')
        ->and($headers)->toHaveKey('X-Content-Type-Options');
});

test('stopping an application queues the work and repoints the route', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['power_state' => PowerState::Active]);

    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $this->actingAs($user->fresh())
        ->post(route('servers.applications.stop', [$server, $application]))
        ->assertRedirect();

    expect($application->fresh()->power_state)->toBe(PowerState::Stopped);

    Queue::assertPushed(StopApplication::class);
    Queue::assertPushed(SyncApplicationRoute::class);
});

test('starting an application queues the work and repoints the route', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['power_state' => PowerState::Stopped]);

    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $this->actingAs($user->fresh())
        ->post(route('servers.applications.start', [$server, $application]))
        ->assertRedirect();

    expect($application->fresh()->power_state)->toBe(PowerState::Active);

    Queue::assertPushed(StartApplication::class);
    Queue::assertPushed(SyncApplicationRoute::class);
});

test('a member can neither stop nor start nor set maintenance mode', function (string $route, string $method) {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create(['power_state' => PowerState::Active]);

    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Member->value]);

    $this->actingAs($user->fresh())
        ->{$method}(route($route, [$server, $application]), ['enabled' => true])
        ->assertForbidden();

    expect($application->fresh()->power_state)->toBe(PowerState::Active);
    Queue::assertNothingPushed();
})->with([
    ['servers.applications.stop', 'post'],
    ['servers.applications.start', 'post'],
    ['servers.applications.maintenance', 'put'],
]);

test('turning maintenance mode on stores the toggle and repoints the route', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $this->actingAs($user->fresh())
        ->put(route('servers.applications.maintenance', [$server, $application]), ['enabled' => true])
        ->assertRedirect();

    expect((array) $application->fresh()->toggles)->toHaveKey('maintenance_mode', true);

    Queue::assertPushed(SyncApplicationRoute::class);
});

test('turning maintenance mode off leaves other toggles alone', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create([
        'toggles' => ['maintenance_mode' => true, 'force_https' => true],
    ]);

    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $this->actingAs($user->fresh())
        ->put(route('servers.applications.maintenance', [$server, $application]), ['enabled' => false]);

    expect((array) $application->fresh()->toggles)
        ->toHaveKey('maintenance_mode', false)
        ->toHaveKey('force_https', true);
});

test('the maintenance toggle refuses a value that is not a boolean', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $this->actingAs($user->fresh())
        ->put(route('servers.applications.maintenance', [$server, $application]), ['enabled' => 'maybe'])
        ->assertSessionHasErrors('enabled');
});

test('an application on another server is a not found', function () {
    $team = Team::factory()->create();
    $server = Server::factory()->for($team)->create();
    $other = Server::factory()->for($team)->create();
    $application = Application::factory()->for($team)->for($server)->create();

    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Admin->value]);

    $this->actingAs($user->fresh())
        ->post(route('servers.applications.stop', [$other, $application]))
        ->assertNotFound();
});

test('the route sync job leaves the server alone for an application with no domain', function () {
    $application = Application::factory()->create(['domain' => null, 'additional_domains' => []]);
    $transport = new FakeSSHTransport;

    SSHConnectionManager::createTransportUsing(fn (): FakeSSHTransport => $transport);

    try {
        (new SyncApplicationRoute($application))->handle(
            app(SSHConnectionManager::class),
            app(CaddyApiClient::class),
        );
    } finally {
        SSHConnectionManager::createTransportUsing(null);
    }

    expect($application->allDomains())->toBe([])
        ->and($transport->commands())->toBe([])
        ->and($transport->connected())->toBeFalse()
        ->and($transport->loginAttempted())->toBeFalse();
});
