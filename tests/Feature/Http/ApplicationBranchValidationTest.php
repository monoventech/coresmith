<?php

declare(strict_types=1);

use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{0: User, 1: Server}
 */
function branchValidationOwner(): array
{
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    return [$owner->fresh(), Server::factory()->for($team)->create(['status' => ServerStatus::Active])];
}

function branchValidationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Shop',
        'type' => 'static',
        'repository_url' => 'https://github.com/monoven/shop.git',
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
    ], $overrides);
}

test('a branch git would read as an option is refused when an app is added', function () {
    [$owner, $server] = branchValidationOwner();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), branchValidationPayload(['repository_branch' => '--upload-pack=x']))
        ->assertSessionHasErrors(['repository_branch' => 'That is not a branch name Git accepts. Use something like main or release/2.0.']);

    expect(Application::query()->count())->toBe(0);
});

test('a branch git would read as an option is refused when an app is changed', function () {
    [$owner, $server] = branchValidationOwner();
    $application = Application::factory()->for($server->team)->for($server)->create();

    $this->actingAs($owner)
        ->put(route('servers.applications.update', [$server, $application]), ['repository_branch' => '--upload-pack=x'])
        ->assertSessionHasErrors(['repository_branch' => 'That is not a branch name Git accepts. Use something like main or release/2.0.']);

    expect($application->fresh()->repository_branch)->toBe('main');
});

test('a repository address that ends in a newline is refused when an app is added', function () {
    Queue::fake();
    [$owner, $server] = branchValidationOwner();
    $this->withoutMiddleware(TrimStrings::class);

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), branchValidationPayload(['repository_url' => "https://github.com/monoven/shop.git\n"]))
        ->assertSessionHasErrors(['repository_url' => 'Use an https:// URL for public repositories, or git@host:owner/repo.git for private ones.']);

    expect(Application::query()->count())->toBe(0);
});

test('a repository address that ends in a newline is refused when an app is changed', function () {
    Queue::fake();
    [$owner, $server] = branchValidationOwner();
    $application = Application::factory()->for($server->team)->for($server)->create(['repository_url' => 'git@github.com:monoven/shop.git']);
    $this->withoutMiddleware(TrimStrings::class);

    $this->actingAs($owner)
        ->put(route('servers.applications.update', [$server, $application]), ['repository_url' => "git@github.com:monoven/other.git\n"])
        ->assertSessionHasErrors('repository_url');

    expect($application->fresh()->repository_url)->toBe('git@github.com:monoven/shop.git');
});

test('an app folder outside the repository is refused when an app is added', function (string $field, string $path, string $message) {
    [$owner, $server] = branchValidationOwner();

    $this->actingAs($owner)
        ->post(route('servers.applications.store', $server), branchValidationPayload([$field => $path]))
        ->assertSessionHasErrors([$field => $message]);

    expect(Application::query()->count())->toBe(0);
})->with([
    ['directory', '../other-app', 'Use a folder inside the repository, like / or apps/web.'],
    ['web_directory', '../../../../etc', 'Use a folder inside the app, like /public or dist.'],
]);

test('a web root that would break the server config is refused when an app is changed', function () {
    [$owner, $server] = branchValidationOwner();
    $application = Application::factory()->for($server->team)->for($server)->create(['web_directory' => '/public']);

    $this->actingAs($owner)
        ->put(route('servers.applications.update', [$server, $application]), ['web_directory' => "public\nphp_admin_value[open_basedir] = /"])
        ->assertSessionHasErrors(['web_directory' => 'Use a folder inside the app, like /public or dist.']);

    expect($application->fresh()->web_directory)->toBe('/public');
});
