<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamDomain;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

function inUseServer(?Team $team = null): Server
{
    return Server::factory()->for($team ?? Team::factory()->create())->create();
}

function inUseApp(Server $server, array $attributes = []): Application
{
    return Application::factory()->for($server->team)->for($server)->create(['domain' => null, 'additional_domains' => [], ...$attributes]);
}

function inUseOwner(Team $team): User
{
    $user = User::factory()->create(['current_team_id' => $team->getKey()]);
    $team->members()->attach($user, ['role' => TeamRole::Owner->value, 'sees_everything' => true]);

    return $user->fresh();
}

test('a domain another app on the same server uses is refused, as its main domain or an extra one', function (array $payload, string $field) {
    $server = inUseServer();
    inUseApp($server, ['domain' => 'shop.example.com']);
    $application = inUseApp($server, ['domain' => 'blog.example.com']);

    $this->actingAs(inUseOwner($server->team))
        ->put(route('servers.applications.update', [$server, $application]), $payload)
        ->assertSessionHasErrors([$field => 'Another app already uses shop.example.com. Remove it from that app first, or pick another domain.']);

    expect($application->fresh()->allDomains())->toBe(['blog.example.com']);
})->with([
    'main domain' => [['domain' => 'shop.example.com'], 'domain'],
    'extra domain' => [['additional_domains' => ['shop.example.com']], 'additional_domains.0'],
]);

test("a domain another of the team's apps uses on another server is refused", function () {
    $server = inUseServer();
    inUseApp(inUseServer($server->team), ['domain' => 'shop.example.com']);
    $application = inUseApp($server);

    $this->actingAs(inUseOwner($server->team))
        ->put(route('servers.applications.update', [$server, $application]), ['domain' => 'shop.example.com'])
        ->assertSessionHasErrors('domain');
});

test("another team's app on another server can't stop you using a domain", function () {
    inUseApp(inUseServer(), ['domain' => 'shop.example.com']);
    $server = inUseServer();
    $application = inUseApp($server);

    $this->actingAs(inUseOwner($server->team))
        ->put(route('servers.applications.update', [$server, $application]), ['domain' => 'shop.example.com'])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->domain)->toBe('shop.example.com');
});

test("Coresmith's own address and a team's custom domain are refused", function (string $domain) {
    config(['app.url' => 'https://coresmith.example.com']);
    TeamDomain::query()->create(['team_id' => Team::factory()->create()->getKey(), 'hostname' => 'deploy.example.org', 'verification_token' => 'token']);
    $server = inUseServer();
    $application = inUseApp($server);

    $this->actingAs(inUseOwner($server->team))
        ->put(route('servers.applications.update', [$server, $application]), ['domain' => $domain])
        ->assertSessionHasErrors(['domain' => "Coresmith uses {$domain} for its own address. Pick another domain."]);
})->with(['coresmith.example.com', 'deploy.example.org']);

test('on Cloud a team domain saved before holds no name, so an app can use it', function () {
    config(['coresmith.edition' => 'cloud']);
    TeamDomain::query()->create(['team_id' => Team::factory()->create()->getKey(), 'hostname' => 'deploy.example.org', 'verification_token' => 'token']);
    $server = inUseServer();
    $server->team->forceFill(['trial_ends_at' => now()->addDays(14)])->save();
    $application = inUseApp($server);

    $this->actingAs(inUseOwner($server->team))
        ->put(route('servers.applications.update', [$server, $application]), ['domain' => 'deploy.example.org'])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->domain)->toBe('deploy.example.org');
});

test('an app that already shares a domain can still save its other settings', function () {
    $server = inUseServer();
    inUseApp($server, ['domain' => 'shared.example.com']);
    $application = inUseApp($server, ['domain' => 'shared.example.com']);

    $this->actingAs(inUseOwner($server->team))
        ->put(route('servers.applications.update', [$server, $application]), ['domain' => 'shared.example.com', 'app_size' => 'large'])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->app_size)->toBe('large');
});

test("a removed app's domain is free again", function () {
    $server = inUseServer();
    inUseApp($server, ['domain' => 'shop.example.com'])->delete();
    $application = inUseApp($server);

    $this->actingAs(inUseOwner($server->team))
        ->put(route('servers.applications.update', [$server, $application]), ['domain' => 'shop.example.com'])
        ->assertSessionHasNoErrors();
});

test('domains are saved in lower case, without a trailing dot', function () {
    $server = inUseServer();
    $application = inUseApp($server);

    $this->actingAs(inUseOwner($server->team))
        ->put(route('servers.applications.update', [$server, $application]), ['domain' => 'Shop.Example.COM.', 'additional_domains' => ['WWW.Example.com']])
        ->assertSessionHasNoErrors();

    expect($application->fresh()->allDomains())->toBe(['shop.example.com', 'www.example.com']);
});

test('Add an app refuses a domain another app on the server uses', function () {
    $server = inUseServer();
    inUseApp($server, ['domain' => 'shop.example.com']);

    $this->actingAs(inUseOwner($server->team))
        ->post(route('servers.applications.store', $server), [
            'name' => 'Second shop',
            'type' => ApplicationType::Php->value,
            'repository_branch' => 'main',
            'directory' => '/',
            'web_directory' => '/public',
            'app_size' => 'small',
            'domain' => 'Shop.example.com',
        ])
        ->assertSessionHasErrors(['domain' => 'Another app already uses shop.example.com. Remove it from that app first, or pick another domain.']);

    expect(Application::query()->count())->toBe(1);
});
