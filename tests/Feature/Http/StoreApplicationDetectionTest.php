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
        ->assertSessionHas('error', "There's no room for another app on web-01. Its apps, apps removed in the last 7 days or other processes hold every port Coresmith gives apps, 8000–9999. Remove apps you no longer need or stop processes using ports in that range. A removed app's ports free up after 7 days.");

    expect(storeDetectionApp())->toBeNull();
});

test('the store refuses a Gemfile.lock that leaves out the server\'s platform', function () {
    [$owner, $server] = storeDetectionServer();
    $facts = RepositoryFixture::facts('sinatra-puma');
    $lock = (string) preg_replace('/^PLATFORMS\n(?:  [^\n]*\n)+/m', "PLATFORMS\n  arm64-darwin-23\n", (string) $facts->file('Gemfile.lock'));
    $mac = new RepositoryFacts(['Gemfile.lock' => $lock, ...array_diff_key($facts->files, ['Gemfile.lock' => true])], $facts->lockfiles, $facts->locked, $facts->markers, $facts->envKeys, $facts->complete);
    $id = storeDetectionRead($owner, $server, new RepositoryRead($mac, arch: 'x86_64'));

    storeDetectionPost($owner, $server, ['detection' => $id, 'type' => ApplicationType::Ruby->value, 'framework' => 'sinatra', 'web_directory' => '/'])
        ->assertSessionHasErrors(['repository_url' => 'Gemfile.lock lists only the arm64-darwin-23 platform, and this server is x86_64-linux, so bundle install refuses it. Run bundle lock --add-platform x86_64-linux, then commit Gemfile.lock.']);
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

test('the store refuses a Django app whose fixed ALLOWED_HOSTS leaves out the domain it is added with', function () {
    [$owner, $server] = storeDetectionServer();
    $facts = new RepositoryFacts([
        'requirements.txt' => "Django==6.1.1\ngunicorn\n",
        'manage.py' => "os.environ.setdefault('DJANGO_SETTINGS_MODULE', 'locallibrary.settings')\n",
        'locallibrary/settings.py' => "ALLOWED_HOSTS = ['.railway.app', '.pythonanywhere.com', '127.0.0.1']\nWSGI_APPLICATION = 'locallibrary.wsgi.application'\n",
    ], complete: true);
    $id = storeDetectionRead($owner, $server, new RepositoryRead($facts, installed: ['python' => ['3.14.10']]));

    storeDetectionPost($owner, $server, ['detection' => $id, 'type' => ApplicationType::Python->value, 'framework' => 'django', 'web_directory' => null, 'domain' => 'library.example.com'])
        ->assertSessionHasErrors(['repository_url' => 'locallibrary/settings.py sets ALLOWED_HOSTS to .railway.app, .pythonanywhere.com and 127.0.0.1, so Django answers 400 Bad Request to library.example.com. Add library.example.com to ALLOWED_HOSTS, or read the list from the environment, for example ALLOWED_HOSTS = os.environ.get("ALLOWED_HOSTS", "").split(","), then add ALLOWED_HOSTS with your domain to the app\'s environment.']);

    expect(storeDetectionApp())->toBeNull();
});

test('a Go app is added with the main package that answers web requests, and one that writes its port into the code is refused', function () {
    [$owner, $server] = storeDetectionServer();
    $mod = "module github.com/acme/shop\n\ngo 1.26\n";
    $reads = new RepositoryFacts(['go.mod' => $mod], complete: true, goLines: ['main.go' => [[1, 'package main'], [9, 'http.ListenAndServe(":"+os.Getenv("PORT"), nil)']]]);
    $fixed = new RepositoryFacts(['go.mod' => $mod], complete: true, goLines: ['main.go' => [[1, 'package main'], [9, 'http.ListenAndServe(":8080", nil)']]]);
    $go = ['type' => ApplicationType::Go->value, 'framework' => 'net-http', 'web_directory' => null, 'main_package' => '.'];

    storeDetectionPost($owner, $server, ['detection' => storeDetectionRead($owner, $server, new RepositoryRead($fixed, installed: ['go' => ['1.27.4']])), ...$go])
        ->assertSessionHasErrors(['repository_url' => 'main.go line 9 listens on port 8080, and Coresmith gives each app its own port in the PORT environment variable. Listen on ":"+os.Getenv("PORT") there instead of port 8080.']);

    expect(storeDetectionApp())->toBeNull();

    storeDetectionPost($owner, $server, ['detection' => storeDetectionRead($owner, $server, new RepositoryRead($reads, installed: ['go' => ['1.27.4']])), ...$go])
        ->assertSessionHasNoErrors();

    expect(storeDetectionApp()?->only(['type', 'framework', 'main_package']))->toBe(['type' => ApplicationType::Go, 'framework' => 'net-http', 'main_package' => '.']);
});

test('a Go app needs a main package, named as a folder inside the module', function (?string $main, string $error) {
    [$owner, $server] = storeDetectionServer();

    storeDetectionPost($owner, $server, ['type' => ApplicationType::Go->value, 'framework' => 'net-http', 'web_directory' => null, 'main_package' => $main])
        ->assertSessionHasErrors(['main_package' => $error]);
})->with([
    'none' => [null, 'Pick the package that answers web requests.'],
    'outside the module' => ['../secrets', 'Use the folder of a package in the module, like cmd/server, or a dot for the module root.'],
]);
