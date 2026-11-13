<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Deployment\EnvironmentFile;
use App\Services\Detection\DetectionStore;
use App\Services\Detection\RepositoryAccess;
use App\Services\Detection\RepositoryRead;
use App\Services\Runtime\RepositoryFacts;
use Illuminate\Testing\TestResponse;
use Tests\Support\RepositoryFixture;

/**
 * @return array{User, Server}
 */
function storeDetectionServer(): array
{
    $team = Team::factory()->owned()->create();

    return [$team->owner->fresh(), Server::factory()->for($team)->create(['name' => 'web-01'])];
}

function storeDetectionRead(User $user, Server $server, RepositoryRead $read, string $url = 'https://github.com/acme/shop.git', string $branch = 'main'): string
{
    return app(DetectionStore::class)->put($user, $server, RepositoryAccess::public($url), $branch, '/', $read);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function storeDetectionPost(User $user, Server $server, array $attributes): TestResponse
{
    return test()->actingAs($user)->post(route('servers.applications.store', $server), [
        'name' => 'Shop',
        'type' => ApplicationType::Php->value,
        'framework' => 'laravel',
        'repository_url' => 'https://github.com/acme/shop.git',
        'repository_branch' => 'main',
        'directory' => '/',
        'web_directory' => '/public',
        'app_size' => 'small',
        ...$attributes,
    ]);
}

function storeDetectionApp(): ?Application
{
    return Application::query()->where('name', 'Shop')->first();
}

test('the store refuses a PHP extension the server\'s PHP lacks', function () {
    [$owner, $server] = storeDetectionServer();
    $facts = RepositoryFixture::facts('laravel-13');
    $manifest = $facts->json('composer.json');
    $manifest['require']['ext-amqp'] = '*';
    $needs = new RepositoryFacts(['composer.json' => (string) json_encode($manifest), ...array_diff_key($facts->files, ['composer.json' => true])], $facts->lockfiles, $facts->locked, $facts->markers, $facts->envKeys, $facts->complete);
    $id = storeDetectionRead($owner, $server, new RepositoryRead($needs, installed: ['php' => ['8.5.10']], extensions: ['8.5.10' => ['ctype', 'mbstring', 'pdo_sqlite']]));

    storeDetectionPost($owner, $server, ['detection' => $id])
        ->assertSessionHasErrors(['repository_url' => "composer.json needs the amqp PHP extension, which PHP 8.5.10 on this server doesn't have, so composer install fails. Remove ext-amqp from composer.json if the app runs without it."]);
});

test('a server with no free port block says so on the form instead of failing', function () {
    [$owner, $server] = storeDetectionServer();
    Application::factory()->count(200)->sequence(fn($sequence): array => ['base_port' => 8000 + $sequence->index * 10, 'slug' => 'taken-' . $sequence->index])->create(['server_id' => $server->id]);

    storeDetectionPost($owner, $server, [])
        ->assertRedirect()
        ->assertSessionHas('error', 'No port block is free on web-01. All 200 blocks in 8000–9999 are taken by apps or by other processes. Remove apps you no longer need, or stop processes using ports in that range.');

    expect(storeDetectionApp())->toBeNull();
});

test('the store refuses a choice the checks know will fail and names the fix', function () {
    [$owner, $server] = storeDetectionServer();
    $id = storeDetectionRead($owner, $server, new RepositoryRead(RepositoryFixture::facts('slim-skeleton')));

    storeDetectionPost($owner, $server, ['detection' => $id])
        ->assertSessionHasErrors(['framework' => "There is no artisan file at the repository's root, so this isn't a Laravel app. Pick Plain PHP, or Symfony if it uses Symfony."]);

    expect(storeDetectionApp())->toBeNull();
});

test('a version the lock rules out is refused next to the version field', function () {
    [$owner, $server] = storeDetectionServer();
    $id = storeDetectionRead($owner, $server, new RepositoryRead(RepositoryFixture::facts('laravel-locked-84')));

    storeDetectionPost($owner, $server, ['detection' => $id, 'runtime_version' => '8.5'])->assertSessionHasErrors('runtime_version');
});

test('a choice that matches what Coresmith read is saved as checked', function () {
    [$owner, $server] = storeDetectionServer();
    $id = storeDetectionRead($owner, $server, new RepositoryRead(RepositoryFixture::facts('laravel-13')));

    storeDetectionPost($owner, $server, ['detection' => $id])->assertSessionHasNoErrors();

    expect(storeDetectionApp()?->repository_checked_at)->not->toBeNull()
        ->and(storeDetectionApp()?->repository_check_pending)->toBeFalse();
});

test('an app saved without a read waits for its repository check', function () {
    [$owner, $server] = storeDetectionServer();

    storeDetectionPost($owner, $server, ['repository_url' => 'git@github.com:acme/shop.git'])->assertSessionHasNoErrors();

    expect(storeDetectionApp()?->repository_check_pending)->toBeTrue()
        ->and(storeDetectionApp()?->repository_checked_at)->toBeNull();
});

test('an app with no repository waits for nothing', function () {
    [$owner, $server] = storeDetectionServer();

    storeDetectionPost($owner, $server, ['repository_url' => null])->assertSessionHasNoErrors();

    expect(storeDetectionApp()?->repository_check_pending)->toBeFalse();
});

test('a read for someone else, another branch or another repository is ignored', function (string $case) {
    [$owner, $server] = storeDetectionServer();
    $other = User::factory()->create();
    $read = new RepositoryRead(RepositoryFixture::facts('slim-skeleton'));
    $id = match ($case) {
        'person' => storeDetectionRead($other, $server, $read),
        'branch' => storeDetectionRead($owner, $server, $read, branch: 'release'),
        'repository' => storeDetectionRead($owner, $server, $read, 'https://github.com/acme/other.git'),
        'expired' => 'k7vq2mz9xw4hpbn6tq8rjf3ylc5ds0ga',
    };

    storeDetectionPost($owner, $server, ['detection' => $id])->assertSessionHasNoErrors();

    expect(storeDetectionApp()?->repository_check_pending)->toBeTrue();
})->with(['person', 'branch', 'repository', 'expired']);

test('a repository Coresmith was refused is refused again on save, with the reason', function () {
    [$owner, $server] = storeDetectionServer();
    $id = storeDetectionRead($owner, $server, RepositoryRead::failed("github.com wouldn't let Coresmith read https://github.com/acme/shop.git without credentials.", true));

    storeDetectionPost($owner, $server, ['detection' => $id])
        ->assertSessionHasErrors(['repository_url' => "github.com wouldn't let Coresmith read https://github.com/acme/shop.git without credentials."]);
});

test('a read that only failed for now still lets the app be saved, waiting for its check', function () {
    [$owner, $server] = storeDetectionServer();
    $id = storeDetectionRead($owner, $server, RepositoryRead::failed("web-01 couldn't reach github.com.", false));

    storeDetectionPost($owner, $server, ['detection' => $id])->assertSessionHasNoErrors();

    expect(storeDetectionApp()?->repository_check_pending)->toBeTrue();
});

test('a new app starts with the variables its framework needs', function (string $type, string $framework, array $names) {
    [$owner, $server] = storeDetectionServer();

    storeDetectionPost($owner, $server, ['type' => $type, 'framework' => $framework, 'repository_url' => null])->assertSessionHasNoErrors();

    expect(array_keys(app(EnvironmentFile::class)->parse((string) storeDetectionApp()?->env_variables)))->toBe($names);
})->with([
    'laravel' => ['php', 'laravel', ['APP_KEY']],
    'symfony' => ['php', 'symfony', ['APP_ENV', 'APP_SECRET']],
    'rails' => ['ruby', 'rails', ['SECRET_KEY_BASE']],
    'sinatra' => ['ruby', 'sinatra', []],
]);
