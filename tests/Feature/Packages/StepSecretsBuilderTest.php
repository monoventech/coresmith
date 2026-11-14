<?php

declare(strict_types=1);

use App\Models\PackageCredential;
use App\Services\Packages\StepSecretsBuilder;

function stepSecretsFor(array $credentials, array $environment = [], array $projectFiles = [])
{
    return app(StepSecretsBuilder::class)->build(collect($credentials), $environment, $projectFiles);
}

test('nothing to hand over builds empty step secrets', function () {
    $secrets = stepSecretsFor([]);

    expect($secrets->isEmpty())->toBeTrue()
        ->and($secrets->environment)->toBe([])
        ->and($secrets->files)->toBe([]);
});

test('composer credentials become COMPOSER_AUTH with http-basic for a username and bearer without one', function () {
    $secrets = stepSecretsFor([
        PackageCredential::factory()->make(['target' => 'nova.laravel.com', 'username' => 'ops@example.com', 'secret' => 'nova-key-1234']),
        PackageCredential::factory()->make(['target' => 'repo.packagist.com', 'username' => null, 'secret' => 'packagist-bearer-99']),
    ]);

    expect(json_decode($secrets->environment['COMPOSER_AUTH'], true))->toEqual([
        'http-basic' => ['nova.laravel.com' => ['username' => 'ops@example.com', 'password' => 'nova-key-1234']],
        'bearer' => ['repo.packagist.com' => 'packagist-bearer-99'],
    ])->and($secrets->files)->toBe([]);
});

test('git tokens reach composer as github-oauth, gitlab-token or http-basic by host', function () {
    $secrets = stepSecretsFor([
        PackageCredential::factory()->git('github.com/acme', null, 'ghp_acmetoken0001')->make(),
        PackageCredential::factory()->git('gitlab.com', null, 'glpat-acmetoken0002')->make(),
        PackageCredential::factory()->git('git.acme.dev/platform', 'deploy', 'gitea-token-0003')->make(),
    ]);

    expect(json_decode($secrets->environment['COMPOSER_AUTH'], true))->toEqual([
        'github-oauth' => ['github.com' => 'ghp_acmetoken0001'],
        'gitlab-token' => ['gitlab.com' => 'glpat-acmetoken0002'],
        'http-basic' => ['git.acme.dev' => ['username' => 'deploy', 'password' => 'gitea-token-0003']],
    ]);
});

test('the app environment keeps its own COMPOSER_AUTH hosts and gains the team ones', function () {
    $secrets = stepSecretsFor(
        [
            PackageCredential::factory()->make(['target' => 'nova.laravel.com', 'username' => 'team@example.com', 'secret' => 'team-key-0000']),
            PackageCredential::factory()->make(['target' => 'spark.laravel.com', 'username' => 'team@example.com', 'secret' => 'spark-key-0000']),
        ],
        ['COMPOSER_AUTH' => '{"http-basic":{"nova.laravel.com":{"username":"app@example.com","password":"app-key-1111"}},"github-oauth":{"github.com":"ghp_appowntoken"}}'],
    );

    expect(json_decode($secrets->environment['COMPOSER_AUTH'], true))->toEqual([
        'http-basic' => [
            'nova.laravel.com' => ['username' => 'app@example.com', 'password' => 'app-key-1111'],
            'spark.laravel.com' => ['username' => 'team@example.com', 'password' => 'spark-key-0000'],
        ],
        'github-oauth' => ['github.com' => 'ghp_appowntoken'],
    ]);
});

test('npm registries become a user npmrc and a Yarn home config', function () {
    $secrets = stepSecretsFor([
        PackageCredential::factory()->npm('https://npm.pkg.github.com', '@acme', 'ghp_npmtoken0001')->make(),
        PackageCredential::factory()->npm('https://gitlab.com/api/v4/projects/42/packages/npm/', null, 'glpat-npm0002')->make(),
    ]);

    expect($secrets->files['.npmrc'])->toBe(implode("\n", [
        '@acme:registry=https://npm.pkg.github.com/',
        '//npm.pkg.github.com/:_authToken=ghp_npmtoken0001',
        '//gitlab.com/api/v4/projects/42/packages/npm/:_authToken=glpat-npm0002',
        '',
    ]))->and($secrets->files['.yarnrc.yml'])->toBe(implode("\n", [
        'npmRegistries:',
        '  "//npm.pkg.github.com":',
        '    npmAlwaysAuth: true',
        '    npmAuthToken: "ghp_npmtoken0001"',
        '  "//gitlab.com/api/v4/projects/42/packages/npm":',
        '    npmAlwaysAuth: true',
        '    npmAuthToken: "glpat-npm0002"',
        'npmScopes:',
        '  "acme":',
        '    npmRegistryServer: "https://npm.pkg.github.com"',
        '',
    ]));
});

test('a token the repository npmrc reads from a variable is set for a matching registry only', function () {
    $npmrc = implode("\n", [
        '@acme:registry=https://npm.pkg.github.com',
        '//npm.pkg.github.com/:_authToken=${NODE_AUTH_TOKEN}',
        '//registry.other.dev/:_authToken=${OTHER_TOKEN}',
    ]);

    $secrets = stepSecretsFor([PackageCredential::factory()->npm('https://npm.pkg.github.com/', '@acme', 'ghp_npmtoken0001')->make()], [], ['.npmrc' => $npmrc]);

    expect($secrets->environment)->toHaveKey('NODE_AUTH_TOKEN', 'ghp_npmtoken0001')
        ->and($secrets->environment)->not->toHaveKey('OTHER_TOKEN');
});

test('a variable the app environment already sets is left to the app', function () {
    $secrets = stepSecretsFor(
        [PackageCredential::factory()->npm('https://npm.pkg.github.com/', '@acme', 'ghp_npmtoken0001')->make()],
        ['NPM_TOKEN' => 'the-apps-own-token'],
        ['.npmrc' => '//npm.pkg.github.com/:_authToken=${NPM_TOKEN}'],
    );

    expect($secrets->environment)->not->toHaveKey('NPM_TOKEN');
});

test('a token a Yarn config reads from a variable is matched through its scope or registry', function () {
    $yarnrc = implode("\n", [
        'nodeLinker: node-modules',
        'npmScopes:',
        '  acme:',
        '    npmRegistryServer: "https://npm.pkg.github.com"',
        '    npmAuthToken: "${GH_PACKAGES_TOKEN}"',
        'npmRegistries:',
        '  "https://registry.other.dev":',
        '    npmAuthToken: ${OTHER_TOKEN:-none}',
    ]);

    $secrets = stepSecretsFor([PackageCredential::factory()->npm('https://npm.pkg.github.com/', '@acme', 'ghp_npmtoken0001')->make()], [], ['.yarnrc.yml' => $yarnrc]);

    expect($secrets->environment)->toHaveKey('GH_PACKAGES_TOKEN', 'ghp_npmtoken0001')
        ->and($secrets->environment)->not->toHaveKey('OTHER_TOKEN');
});

test('python indexes and git hosts become netrc machines', function () {
    $secrets = stepSecretsFor([
        PackageCredential::factory()->python('pypi.acme.dev', '__token__', 'pypi-secret-0001')->make(),
        PackageCredential::factory()->git('github.com/acme', null, 'ghp_gittoken0002')->make(),
        PackageCredential::factory()->git('gitlab.com/acme', null, 'glpat-gittoken0003')->make(),
    ]);

    expect($secrets->files['.netrc'])->toBe(implode("\n", [
        'machine pypi.acme.dev login __token__ password pypi-secret-0001',
        'machine github.com login x-access-token password ghp_gittoken0002',
        'machine gitlab.com login oauth2 password glpat-gittoken0003',
        '',
    ]));
});

test('git hosts mark their modules private to Go and reach Bundler', function () {
    $secrets = stepSecretsFor([
        PackageCredential::factory()->git('github.com/acme', null, 'ghp_gittoken0002')->make(),
        PackageCredential::factory()->git('git.acme-corp.dev', 'deploy', 'gitea:token/0003')->make(),
    ]);

    expect($secrets->environment['GOPRIVATE'])->toBe('github.com/acme,git.acme-corp.dev')
        ->and($secrets->environment['BUNDLE_GITHUB__COM'])->toBe('x-access-token:ghp_gittoken0002')
        ->and($secrets->environment['BUNDLE_GIT__ACME___CORP__DEV'])->toBe('deploy:gitea%3Atoken%2F0003');
});

test('maven repositories become settings servers and Gradle properties', function () {
    $secrets = stepSecretsFor([PackageCredential::factory()->maven('acme-releases', 'deploy<ci>', 'p&ss:w=rd')->make()]);

    expect($secrets->files['.m2/settings.xml'])->toBe(implode("\n", [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<settings>',
        '  <servers>',
        '    <server>',
        '      <id>acme-releases</id>',
        '      <username>deploy&lt;ci&gt;</username>',
        '      <password>p&amp;ss:w=rd</password>',
        '    </server>',
        '  </servers>',
        '</settings>',
        '',
    ]))->and($secrets->files['.gradle/gradle.properties'])->toBe(implode("\n", [
        'acme-releasesUsername=deploy<ci>',
        'acme-releasesPassword=p&ss\:w\=rd',
        '',
    ]));
});

test('gem sources become Bundler credentials with escaped parts', function () {
    $secrets = stepSecretsFor([PackageCredential::factory()->ruby('gems.acme-corp.dev', 'deploy@acme', 'pa:ss word')->make()]);

    expect($secrets->environment)->toBe(['BUNDLE_GEMS__ACME___CORP__DEV' => 'deploy%40acme:pa%3Ass%20word']);
});

test('every secret is listed for redaction with its escaped form', function () {
    $secrets = stepSecretsFor([
        PackageCredential::factory()->ruby('gems.acme.dev', 'deploy', 'pa:ss word')->make(),
        PackageCredential::factory()->npm()->make(),
    ]);

    expect($secrets->secrets)->toContain('pa:ss word', 'pa%3Ass%20word', 'ghp_npmtoken0000');
});

test('one npm entry can serve several scopes from its registry', function () {
    $secrets = stepSecretsFor([PackageCredential::factory()->npm('https://npm.pkg.github.com/', '@acme, @Acme-UI acme', 'ghp_npmtoken0001')->make()]);

    expect($secrets->files['.npmrc'])->toBe(implode("\n", [
        '@acme:registry=https://npm.pkg.github.com/',
        '@acme-ui:registry=https://npm.pkg.github.com/',
        '//npm.pkg.github.com/:_authToken=ghp_npmtoken0001',
        '',
    ]))->and($secrets->files['.yarnrc.yml'])->toContain("npmScopes:\n  \"acme\":\n    npmRegistryServer: \"https://npm.pkg.github.com\"\n  \"acme-ui\":\n");
});
