<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * @return array{User, Application}
 */
function runtimeChoiceApp(TeamRole $role = TeamRole::Owner): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => $role->value]);
    $application = Application::factory()->for($team)->for(Server::factory()->for($team))->create(['type' => ApplicationType::Php, 'framework' => 'laravel']);

    return [$user->fresh(), $application];
}

function runtimeChoiceRoute(Application $application): string
{
    return route('servers.applications.runtime', [$application->server_id, $application]);
}

test('an owner sets the app to a php version and the next deploy uses it', function () {
    [$user, $application] = runtimeChoiceApp();

    $this->actingAs($user)
        ->put(runtimeChoiceRoute($application), ['runtime_version' => '8.4'])
        ->assertRedirect()
        ->assertSessionHas('success', 'Saved. The next deploy uses PHP 8.4.');

    expect($application->fresh()->runtime_pins)->toBe(['php' => '8.4']);
});

test('from the repository clears the version chosen for the app', function () {
    [$user, $application] = runtimeChoiceApp();
    $application->forceFill(['runtime_pins' => ['php' => '8.4']])->save();

    $this->actingAs($user)
        ->put(runtimeChoiceRoute($application), ['runtime_version' => ''])
        ->assertSessionHas('success', 'Saved. The next deploy uses the version the repository asks for.');

    expect($application->fresh()->runtime_pins)->toBeNull();
});

test('a version past its security fixes needs confirming', function () {
    [$user, $application] = runtimeChoiceApp();

    $this->actingAs($user)->put(runtimeChoiceRoute($application), ['runtime_version' => '8.1'])->assertSessionHasErrors(['runtime_version']);
    expect($application->fresh()->runtime_pins)->toBeNull();

    $this->actingAs($user)->put(runtimeChoiceRoute($application), ['runtime_version' => '8.1', 'acknowledge_eol' => true])->assertSessionHasNoErrors();
    expect($application->fresh()->runtime_pins)->toBe(['php' => '8.1']);
});

test('a version Coresmith does not build is refused', function () {
    [$user, $application] = runtimeChoiceApp();

    $this->actingAs($user)->put(runtimeChoiceRoute($application), ['runtime_version' => '7.4'])->assertSessionHasErrors(['runtime_version']);

    expect($application->fresh()->runtime_pins)->toBeNull();
});

test('a deployer cannot change the php version', function () {
    [$user, $application] = runtimeChoiceApp(TeamRole::Member);

    $this->actingAs($user)->put(runtimeChoiceRoute($application), ['runtime_version' => '8.4'])->assertForbidden();

    expect($application->fresh()->runtime_pins)->toBeNull();
});

test('the app page offers the version choice and says when the lock holds php back', function () {
    [$user, $application] = runtimeChoiceApp();
    $application->forceFill(['runtime_pins' => ['php' => '8.4'], 'current_release' => '20261112-091400'])->save();
    Deployment::factory()->for($application)->create([
        'release' => '20261112-091400',
        'status' => DeploymentStatus::Succeeded,
        'stack' => ['runtimes' => ['php' => ['runtime' => 'php', 'version' => '8.4.20', 'source' => 'composer.json', 'detail' => '^8.2', 'held_by' => ['nette/schema 1.3.2', 'nette/utils 4.0.5'], 'newest' => '8.5.10']]],
    ]);

    $this->actingAs($user)
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('runtime_choice.label', 'PHP')
            ->where('runtime_choice.pin', '8.4')
            ->where('runtime_choice.href', runtimeChoiceRoute($application))
            ->has('runtime_choice.options')
            ->where('held.current', '8.4')
            ->where('held.newest', '8.5')
            ->where('held.packages', ['nette/schema 1.3.2', 'nette/utils 4.0.5'])
            ->where('held.command', 'composer update nette/schema nette/utils'));
});

test('a deployer sees the held note but no version choice', function () {
    [$user, $application] = runtimeChoiceApp(TeamRole::Member);

    $this->actingAs($user)
        ->get(route('servers.applications.show', [$application->server_id, $application]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('runtime_choice', null)->where('held', null));
});
