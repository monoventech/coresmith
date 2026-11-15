<?php

declare(strict_types=1);

use App\Enums\PackageSource;
use App\Models\PackageCredential;
use App\Models\Team;
use App\Services\Packages\PrivateSources;
use App\Services\Runtime\RepositoryFacts;

function privateSourcesIn(array $files): array
{
    return array_map(fn(array $found): array => [$found['source']->value, $found['host'], $found['id']], app(PrivateSources::class)->in(new RepositoryFacts(files: $files, complete: true)));
}

test('composer repositories other than packagist and public forges are private sources', function () {
    expect(privateSourcesIn(['composer.json' => json_encode(['repositories' => [
        ['type' => 'composer', 'url' => 'https://nova.laravel.com'],
        ['type' => 'vcs', 'url' => 'https://github.com/acme/fork'],
        ['type' => 'vcs', 'url' => 'https://git.acme.dev/platform/sdk.git'],
        ['type' => 'composer', 'url' => 'https://repo.packagist.org'],
        ['type' => 'path', 'url' => '../packages/*'],
    ]])]))->toBe([['composer', 'nova.laravel.com', null], ['composer', 'git.acme.dev', null]]);
});

test('npm, Yarn and Bun configs name their registries once each', function () {
    expect(privateSourcesIn([
        '.npmrc' => "@acme:registry=https://npm.pkg.github.com\n//npm.pkg.github.com/:_authToken=\${NODE_AUTH_TOKEN}\nregistry=https://registry.npmjs.org/\n",
        '.yarnrc.yml' => "npmScopes:\n  ui:\n    npmRegistryServer: \"https://npm.acme.dev\"\n",
        'bunfig.toml' => "[install.scopes]\nkit = { token = \"\$KIT_TOKEN\", url = \"https://kit.acme.dev/npm/\" }\n",
    ]))->toBe([['npm', 'npm.pkg.github.com', null], ['npm', 'npm.acme.dev', null], ['npm', 'kit.acme.dev', null]]);
});

test('python indexes come from pyproject, Pipfile and requirements files, without their credentials', function () {
    expect(privateSourcesIn([
        'pyproject.toml' => "[project]\nname = \"shop\"\n\n[[tool.uv.index]]\nname = \"acme\"\nurl = \"https://pypi.acme.dev/simple\"\n",
        'requirements.txt' => "--extra-index-url https://deploy:s3cret@wheels.acme.dev/simple\n--index-url https://pypi.org/simple\ndjango==5.2\n",
        'Pipfile' => "[[source]]\nurl = \"https://pypi.org/simple\"\nname = \"pypi\"\n",
    ]))->toBe([['python', 'pypi.acme.dev', null], ['python', 'wheels.acme.dev', null]]);
});

test('maven repositories keep their id, while central and distribution targets are left out', function () {
    $pom = <<<'XML'
        <project>
          <repositories>
            <repository><url>https://maven.acme.dev/releases</url><id>acme-releases</id></repository>
            <repository><id>central</id><url>https://repo.maven.apache.org/maven2</url></repository>
          </repositories>
          <distributionManagement><repository><id>deploy-only</id><url>https://deploy.acme.dev</url></repository></distributionManagement>
        </project>
        XML;

    expect(privateSourcesIn(['pom.xml' => $pom, 'build.gradle.kts' => "repositories {\n  maven {\n    url = uri(\"https://gradle.acme.dev/libs\")\n    credentials(PasswordCredentials::class)\n  }\n}\n"]))
        ->toBe([['maven', 'maven.acme.dev', 'acme-releases'], ['maven', 'gradle.acme.dev', null]]);
});

test('gem sources other than rubygems.org are private sources', function () {
    expect(privateSourcesIn(['Gemfile' => "source \"https://rubygems.org\"\nsource \"https://gems.acme.dev\" do\n  gem \"acme-ui\"\nend\n"]))
        ->toBe([['ruby', 'gems.acme.dev', null]]);
});

test('only sources the team has no credentials for are reported, labelled for people', function () {
    $team = Team::factory()->create();
    PackageCredential::factory()->for($team)->create(['target' => 'nova.laravel.com']);
    PackageCredential::factory()->for($team)->git('git.acme.dev/platform')->create();
    PackageCredential::factory()->for($team)->maven('acme-releases')->create();
    $facts = new RepositoryFacts(files: [
        'composer.json' => json_encode(['repositories' => [['type' => 'composer', 'url' => 'https://nova.laravel.com'], ['type' => 'vcs', 'url' => 'https://git.acme.dev/platform/sdk.git'], ['type' => 'composer', 'url' => 'https://satis.acme.dev']]]),
        'pom.xml' => '<project><repositories><repository><id>acme-releases</id><url>https://maven.acme.dev</url></repository></repositories></project>',
        '.npmrc' => "@acme:registry=https://npm.pkg.github.com\n",
    ], complete: true);

    expect(app(PrivateSources::class)->missing($facts, $team))->toBe([
        ['host' => 'satis.acme.dev', 'source' => PackageSource::Composer->label()],
        ['host' => 'npm.pkg.github.com', 'source' => PackageSource::Npm->label()],
    ]);
});
