<?php

declare(strict_types=1);

use App\Services\Runtime\RepositoryFacts;
use App\Services\Runtime\Toml;

test('a table gives its keys with strings decoded, from either kind of quote', function () {
    $document = <<<'TOML'
    name = "top"

    [project]
    name = "shop"
    requires-python = '>=3.11'
    description = "A \"quoted\" shop\tand a tab"
    version = 1

    [tool.ruff]
    line-length = 100
    TOML;

    expect(Toml::table($document, 'project'))->toBe([
        'name' => 'shop',
        'requires-python' => '>=3.11',
        'description' => "A \"quoted\" shop\tand a tab",
        'version' => '1',
    ])->and(Toml::table($document, ''))->toBe(['name' => 'top']);
});

test('arrays of strings span lines, keep their order and ignore comments and trailing commas', function () {
    $document = <<<'TOML'
    [project]
    dependencies = [
        "django>=5.1", # the framework
        'gunicorn',
        "psycopg[binary]>=3.2",
    ]
    TOML;

    expect(Toml::table($document, 'project')['dependencies'])->toBe(['django>=5.1', 'gunicorn', 'psycopg[binary]>=3.2']);
});

test('dotted and quoted table names match their plain spelling', function () {
    $document = <<<'TOML'
    [ tool . "poetry" . dependencies ]
    python = "^3.12"
    "django" = { version = "^5.1", extras = ["argon2"] }
    celery = {version="^5.4"}  # workers

    [[tool.poetry.source]]
    name = "private"
    TOML;

    expect(Toml::table($document, 'tool.poetry.dependencies'))->toBe([
        'python' => '^3.12',
        'django' => '{ version = "^5.1", extras = ["argon2"] }',
        'celery' => '{version="^5.4"}',
    ]);
});

test('a sub-table is a table of its own', function () {
    $document = "[project]\nname = \"shop\"\n[tool.uv]\ndev-dependencies = []\n[project.scripts]\nshop = \"shop:main\"\n";

    expect(Toml::table($document, 'project'))->toBe(['name' => 'shop'])
        ->and(Toml::table($document, 'project.scripts'))->toBe(['shop' => 'shop:main']);
});

test('a broken document gives what was read before it broke, and never throws', function () {
    expect(Toml::table("[project]\nname = \"shop\"\nrequires-python = \">=3.11", 'project'))->toBe(['name' => 'shop'])
        ->and(Toml::table("[project\nname = 'x'", 'project'))->toBe([])
        ->and(Toml::table('', 'project'))->toBe([]);
});

test('repository facts read a table from a TOML file they hold', function () {
    $facts = new RepositoryFacts(['pyproject.toml' => "[project]\nrequires-python = \">=3.12\"\n"], complete: true);

    expect($facts->toml('pyproject.toml', 'project'))->toBe(['requires-python' => '>=3.12'])
        ->and($facts->toml('missing.toml', 'project'))->toBe([]);
});
