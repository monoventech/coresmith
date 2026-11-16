<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\ApplicationDeployScriptController;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Panel\ActivityFeed;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\Stack;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{team: Team, owner: User, server: Server, shop: Application}
 */
function deployScriptFixture(): array
{
    $team = Team::factory()->owned()->create();
    $server = Server::factory()->for($team)->create(['name' => 'web']);

    return [
        'team' => $team,
        'owner' => $team->owner->fresh(),
        'server' => $server,
        'shop' => Application::factory()->for($team)->for($server)->create(['name' => 'shop', 'framework' => 'laravel', 'run_migrations' => true, 'custom_blueprint' => null]),
    ];
}

/**
 * @return array<string, mixed>
 */
function deployScriptStack(): array
{
    return (new Stack(['php' => new ResolvedRuntime('php', '8.5.10', 'composer.json', '^8.4', true)], ['name' => 'laravel', 'major' => 13]))->toArray();
}

function deployScriptDefault(): string
{
    return implode("\n", [
        'composer install --no-dev --optimize-autoloader --no-interaction',
        'php artisan config:cache',
        'php artisan route:cache',
        'php artisan view:cache',
        'php artisan migrate --force',
    ]);
}

test("the app page shows Coresmith's default deploy script, read from what the last deploy ran", function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = deployScriptFixture();
    Deployment::factory()->for($shop)->create(['status' => DeploymentStatus::Succeeded, 'stack' => deployScriptStack()]);

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('deploy_script', [
            'script' => deployScriptDefault(),
            'custom' => false,
            'from_deploy' => true,
            'migrations' => true,
            'update_href' => route('servers.applications.deploy-script.update', [$server, $shop]),
            'reset_href' => route('servers.applications.deploy-script.destroy', [$server, $shop]),
        ]));
});

test('before the first deploy the default is what Coresmith expects to run', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = deployScriptFixture();

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('deploy_script.from_deploy', false)
            ->where('deploy_script.script', fn(string $script): bool => str_contains($script, 'php artisan migrate --force')));
});

test('a script of your own is saved with Unix line endings, recorded, and used from the next deploy', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = deployScriptFixture();

    $this->actingAs($owner)->put(route('servers.applications.deploy-script.update', [$server, $shop]), ['script' => "cd apps/web\r\nbun install --frozen-lockfile\r\nbun run build\r\n\r\n"])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Saved. The next deploy runs it.');

    expect($shop->fresh()->custom_blueprint)->toBe("cd apps/web\nbun install --frozen-lockfile\nbun run build\n")
        ->and(Activity::query()->where('description', ApplicationDeployScriptController::CHANGED)->sole()->properties->all())->toBe(['custom' => true]);

    $this->actingAs($owner)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page
            ->where('deploy_script.custom', true)
            ->where('deploy_script.script', "cd apps/web\nbun install --frozen-lockfile\nbun run build\n"));

    expect(collect(app(ActivityFeed::class)->forServer($server, viewer: $owner))->map(fn(array $entry): string => collect($entry['parts'])->pluck('text')->implode(''))->implode("\n"))
        ->toContain('Deploy script for shop changed');
});

test('saving the default as it is, or nothing at all, keeps the default and records nothing', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = deployScriptFixture();
    $route = route('servers.applications.deploy-script.update', [$server, $shop]);

    $this->actingAs($owner)->put($route, ['script' => deployScriptDefault() . "\n"])->assertSessionHasNoErrors()->assertSessionMissing('success');
    $this->actingAs($owner)->put($route, ['script' => "  \n"])->assertSessionHasNoErrors()->assertSessionMissing('success');

    expect($shop->fresh()->custom_blueprint)->toBeNull()
        ->and(Activity::query()->where('description', ApplicationDeployScriptController::CHANGED)->count())->toBe(0);
});

test("Use the default clears the script so Coresmith's own steps run again", function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = deployScriptFixture();
    $shop->forceFill(['custom_blueprint' => "bun run build\n"])->save();

    $this->actingAs($owner)->delete(route('servers.applications.deploy-script.destroy', [$server, $shop]))
        ->assertSessionHas('success', "Back to Coresmith's default.");

    expect($shop->fresh()->custom_blueprint)->toBeNull()
        ->and(Activity::query()->where('description', ApplicationDeployScriptController::CHANGED)->sole()->properties->all())->toBe(['custom' => false]);
});

test('a script that is too long or holds a null character is refused with what to do', function () {
    ['owner' => $owner, 'server' => $server, 'shop' => $shop] = deployScriptFixture();
    $route = route('servers.applications.deploy-script.update', [$server, $shop]);

    $this->actingAs($owner)->put($route, ['script' => str_repeat('a', 10001)])->assertSessionHasErrors(['script' => 'Keep the deploy script to 10,000 characters or fewer.']);
    $this->actingAs($owner)->put($route, ['script' => "echo\0 hi\n"])->assertSessionHasErrors(['script' => 'The script has a null character in it. Remove it and save again.']);

    expect($shop->fresh()->custom_blueprint)->toBeNull();
});

test('deployers read the script but cannot change it, and another server is not found', function () {
    ['team' => $team, 'owner' => $owner, 'server' => $server, 'shop' => $shop] = deployScriptFixture();
    $deployer = User::factory()->create(['current_team_id' => $team->id]);
    $team->members()->attach($deployer, ['role' => TeamRole::Member->value, 'sees_everything' => true]);
    $deployer = $deployer->fresh();
    $elsewhere = Server::factory()->for($team)->create();

    $this->actingAs($deployer)->get(route('servers.applications.show', [$server, $shop]))
        ->assertInertia(fn(AssertableInertia $page) => $page->where('deploy_script.update_href', null)->where('deploy_script.reset_href', null));
    $this->actingAs($deployer)->put(route('servers.applications.deploy-script.update', [$server, $shop]), ['script' => "echo hi\n"])->assertForbidden();
    $this->actingAs($deployer)->delete(route('servers.applications.deploy-script.destroy', [$server, $shop]))->assertForbidden();
    $this->actingAs($owner)->put(route('servers.applications.deploy-script.update', [$elsewhere, $shop]), ['script' => "echo hi\n"])->assertNotFound();

    expect($shop->fresh()->custom_blueprint)->toBeNull();
});
