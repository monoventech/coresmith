<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\PackageCredential;
use App\Models\Server;
use App\Services\Packages\RegistryAuthFailure;

function registryFailureApp(): Application
{
    $server = Server::factory()->create();

    return Application::factory()->for($server)->for($server->team)->create();
}

test('a refusal from a host with no credentials says to add them', function (string $output, string $host) {
    expect(app(RegistryAuthFailure::class)->reason($output, registryFailureApp()))
        ->toBe("The install needs credentials for {$host}, and Coresmith has none. Add them to Private packages in Settings, Git, then deploy again.");
})->with([
    'composer 401' => ['    The "https://nova.laravel.com/packages.json" file could not be downloaded (HTTP/2 401 )', 'nova.laravel.com'],
    'composer prompt' => ["The 'https://repo.packagist.com/acme/packages.json' URL required authentication (HTTP 401).\nYou must be using the interactive console to authenticate", 'repo.packagist.com'],
    'npm' => ["npm error code E401\nnpm error 401 Unauthorized - GET https://npm.pkg.github.com/@acme%2fui - authentication token not provided", 'npm.pkg.github.com'],
    'pnpm' => [' ERR_PNPM_FETCH_401  GET https://npm.pkg.github.com/@acme%2Fui: Unauthorized - 401', 'npm.pkg.github.com'],
    'yarn 1' => ['error An unexpected error occurred: "https://npm.pkg.github.com/@acme%2fui: Request failed \"401 Unauthorized\"".', 'npm.pkg.github.com'],
    'yarn berry' => ["➤ YN0041: │ @acme/ui@npm:^1.0.0: Invalid authentication (as an anonymous user)\n➤ YN0041: │   Request URL: https://npm.pkg.github.com/@acme%2fui", 'npm.pkg.github.com'],
    'bun' => ['error: GET https://npm.pkg.github.com/@acme%2fui - 401', 'npm.pkg.github.com'],
    'pip' => ['ERROR: HTTP error 401 while getting https://pypi.acme.dev/simple/acme-lib/', 'pypi.acme.dev'],
    'poetry' => ['  HTTPError  401 Client Error: Unauthorized for url: https://pypi.acme.dev/simple/acme-lib/', 'pypi.acme.dev'],
    'uv' => ['  Caused by: HTTP status client error (401 Unauthorized) for url (https://pypi.acme.dev/simple/acme-lib/)', 'pypi.acme.dev'],
    'gradle' => ["> Could not GET 'https://maven.acme.dev/releases/com/acme/lib/1.0/lib-1.0.pom'. Received status code 401 from server: Unauthorized", 'maven.acme.dev'],
    'git over https' => ["fatal: could not read Username for 'https://github.com': terminal prompts disabled", 'github.com'],
    'bundler' => ['Authentication is required for gems.acme.dev.', 'gems.acme.dev'],
]);

test('a maven refusal names the repository id from pom.xml', function () {
    $output = '[ERROR] Failed to execute goal on project shop: Could not resolve dependencies for project com.acme:shop:jar:1.0: Could not transfer artifact com.acme:lib:pom:1.0 from/to acme-releases (https://maven.acme.dev/releases): status code: 401, reason phrase: Unauthorized (401)';

    expect(app(RegistryAuthFailure::class)->reason($output, registryFailureApp()))
        ->toBe('The install needs credentials for the Maven repository acme-releases (maven.acme.dev), and Coresmith has none. Add them to Private packages in Settings, Git, then deploy again.');
});

test('credentials Coresmith has that were turned down are named as such', function () {
    $application = registryFailureApp();
    PackageCredential::factory()->for($application->team)->create(['target' => 'nova.laravel.com']);

    expect(app(RegistryAuthFailure::class)->reason('The "https://nova.laravel.com/packages.json" file could not be downloaded (HTTP/2 401 )', $application))
        ->toBe("nova.laravel.com didn't accept the credentials Coresmith has for it. Check them in Private packages in Settings, Git, then deploy again.");
});

test('credentials that are accepted but cannot read the package are told apart', function () {
    $application = registryFailureApp();
    PackageCredential::factory()->for($application->team)->npm()->create();

    expect(app(RegistryAuthFailure::class)->reason('npm error 403 403 Forbidden - GET https://npm.pkg.github.com/@acme%2fui - Permission permission_denied: read_package', $application))
        ->toBe("npm.pkg.github.com accepted Coresmith's credentials, but they can't read this package. Give them access to it, then deploy again.");
});

test('a token variable the repository npmrc reads but nothing sets is named', function () {
    expect(app(RegistryAuthFailure::class)->reason('npm error Failed to replace env in config: ${NODE_AUTH_TOKEN}', registryFailureApp()))
        ->toBe("The repository's .npmrc reads NODE_AUTH_TOKEN, which isn't set. Add the registry's token to Private packages in Settings, Git, then deploy again.");
});

test('output without a registry refusal has no reason', function (string $output) {
    expect(app(RegistryAuthFailure::class)->reason($output, registryFailureApp()))->toBeNull();
})->with([
    'a build error' => ['error during build: Rollup failed to resolve import "lodash/each"'],
    'a test hitting an api' => ['GET /api/user 401 in 12ms'],
    'a 404' => ['npm error 404 Not Found - GET https://registry.npmjs.org/left-padd - Not found'],
]);

test('a gradle repository without credentials names the id to add them under', function () {
    $output = "* What went wrong:\nExecution failed for task ':compileJava'.\n> The following Gradle properties are missing for 'acmeReleases' credentials:\n    - acmeReleasesUsername\n    - acmeReleasesPassword";

    expect(app(RegistryAuthFailure::class)->reason($output, registryFailureApp()))
        ->toBe('The build needs credentials for the Gradle repository acmeReleases, and Coresmith has none. Add them to Private packages in Settings, Git as Maven or Gradle with acmeReleases as the repository ID, then deploy again.');
});
