<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\ServerStatus;
use App\Enums\TeamRole;
use App\Jobs\UpdateServerRuntime;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['coresmith.runtimes.php.versions' => [
        ['line' => '8.4', 'latest' => '8.4.9', 'active_until' => '2030-12-31', 'eol_at' => '2032-12-31'],
    ]]);
    Queue::fake();
});

/**
 * @return array{Team, Server}
 */
function appRuntimePatchFleet(): array
{
    $team = Team::factory()->owned()->create();

    return [$team, Server::factory()->for($team)->create(['name' => 'web-1', 'status' => ServerStatus::Active])];
}

function appRuntimePatchApp(Server $server, string $name, string $php, ?array $resolved = null): Application
{
    return Application::factory()->for($server->team)->for($server)->create([
        'name' => $name,
        'type' => ApplicationType::Php,
        'php_version' => $php,
        'stack' => $resolved === null ? null : ['runtimes' => ['php' => ['runtime' => 'php', 'version' => $php, ...$resolved]]],
    ]);
}

test("updating an app's runtime installs the newest patch beside the old one and deploys only that app", function () {
    [$team, $server] = appRuntimePatchFleet();
    $shop = appRuntimePatchApp($server, 'shop', '8.4.1');
    appRuntimePatchApp($server, 'blog', '8.4.1');

    $this->actingAs($team->owner)
        ->post(route('servers.applications.runtime.patch', [$server, $shop]), ['runtime' => 'php'])
        ->assertRedirect()
        ->assertSessionHas('success', "Installing PHP 8.4.9. shop deploys once it's in.");

    Queue::assertPushed(UpdateServerRuntime::class, fn(UpdateServerRuntime $job): bool => $job->server->is($server)
        && [$job->runtime, $job->from, $job->to, $job->applications] === ['php', '8.4.1', '8.4.9', [$shop->id]]);

    expect(UpdateServerRuntime::state($server))->toMatchArray(['label' => 'PHP 8.4.9', 'state' => 'installing', 'applications' => [$shop->id]]);
});

test('an app update is refused when nothing is newer, the repository pins the patch, or an install is running', function (string $php, ?array $resolved, bool $busy, string $error) {
    [$team, $server] = appRuntimePatchFleet();
    $shop = appRuntimePatchApp($server, 'shop', $php, $resolved);

    if ($busy) {
        (new UpdateServerRuntime($server, 'php', '8.4.1', '8.4.9', [], $team->owner))->remember('installing');
    }

    $this->actingAs($team->owner)
        ->post(route('servers.applications.runtime.patch', [$server, $shop]), ['runtime' => 'php'])
        ->assertRedirect()
        ->assertSessionHas('error', $error);

    Queue::assertNotPushed(UpdateServerRuntime::class);
})->with([
    'nothing newer' => ['8.4.9', null, false, 'PHP 8.4.9 is already the newest patch Coresmith knows of.'],
    'a pinned patch' => ['8.4.1', ['source' => '.tool-versions', 'detail' => '8.4.1'], false, "shop's .tool-versions asks for exactly PHP 8.4.1. Change it there to move to 8.4.9."],
    'an install running' => ['8.4.1', null, true, 'Coresmith is already installing a runtime on this server. Try again once it finishes.'],
]);

test('only people who can change and deploy an app can update its runtime, and only a runtime it uses', function () {
    [$team, $server] = appRuntimePatchFleet();
    $shop = appRuntimePatchApp($server, 'shop', '8.4.1');
    $deployer = User::factory()->create();
    $team->members()->attach($deployer, ['role' => TeamRole::Member->value]);

    $this->actingAs($deployer)
        ->post(route('servers.applications.runtime.patch', [$server, $shop]), ['runtime' => 'php'])
        ->assertForbidden();

    $this->actingAs($team->owner)
        ->post(route('servers.applications.runtime.patch', [$server, $shop]), ['runtime' => 'nodejs'])
        ->assertSessionHasErrors('runtime');

    Queue::assertNotPushed(UpdateServerRuntime::class);
});
