<?php

declare(strict_types=1);

use App\Services\Runtime\RepositoryFacts;

test('gem versions come from the specs of every section in Gemfile.lock', function () {
    $facts = new RepositoryFacts(['Gemfile.lock' => implode("\n", [
        'GIT',
        '  remote: https://github.com/rails/rails.git',
        '  revision: 0a1b2c3d',
        '  branch: main',
        '  specs:',
        '    rails (8.1.0.alpha)',
        '      actioncable (= 8.1.0.alpha)',
        '',
        'GEM',
        '  remote: https://rubygems.org/',
        '  specs:',
        '    nokogiri (1.18.3-arm64-darwin)',
        '      racc (~> 1.4)',
        '    propshaft (1.1.0)',
        '    puma (6.6.0)',
        '      nio4r (~> 2.0)',
        '',
        'DEPENDENCIES',
        '  bootsnap',
        '  puma (>= 5.0)',
        '',
    ])]);

    expect($facts->gem('rails'))->toBe('8.1.0.alpha')
        ->and($facts->gem('puma'))->toBe('6.6.0')
        ->and($facts->gem('propshaft'))->toBe('1.1.0')
        ->and($facts->gem('nokogiri'))->toBe('1.18.3-arm64-darwin')
        ->and($facts->gem('nio4r'))->toBeNull()
        ->and($facts->gem('bootsnap'))->toBeNull()
        ->and((new RepositoryFacts)->gem('puma'))->toBeNull();
});

test('a dependency is found in dependencies or devDependencies', function () {
    $facts = new RepositoryFacts(['package.json' => json_encode([
        'dependencies' => ['@sveltejs/kit' => '^2.20.0'],
        'devDependencies' => ['@sveltejs/adapter-node' => '^5.2.0'],
    ])]);

    expect($facts->dependency('@sveltejs/kit'))->toBe('^2.20.0')
        ->and($facts->dependency('@sveltejs/adapter-node'))->toBe('^5.2.0')
        ->and($facts->dependency('react'))->toBeNull();
});

test('a workspace root lists other packages in package.json workspaces or pnpm-workspace.yaml, not only itself', function () {
    expect((new RepositoryFacts(['package.json' => '{"workspaces":["apps/*"]}']))->workspace())->toBeTrue()
        ->and((new RepositoryFacts(['package.json' => '{"workspaces":{"packages":["apps/*"]}}']))->workspace())->toBeTrue()
        ->and((new RepositoryFacts(['package.json' => '{}', 'pnpm-workspace.yaml' => "packages:\n  - 'apps/*'\n  - 'packages/*'\n"]))->workspace())->toBeTrue()
        ->and((new RepositoryFacts(['package.json' => '{}', 'pnpm-workspace.yaml' => "packages: [\".\"]\nallowBuilds:\n  esbuild: true\n"]))->workspace())->toBeFalse()
        ->and((new RepositoryFacts(['package.json' => '{}', 'pnpm-workspace.yaml' => "packages:\n    - '.'\n\npublicHoistPattern:\n    - '@inertiajs/core'\n"]))->workspace())->toBeFalse()
        ->and((new RepositoryFacts(['package.json' => '{}', 'pnpm-workspace.yaml' => "onlyBuiltDependencies:\n  - esbuild\n"]))->workspace())->toBeFalse()
        ->and((new RepositoryFacts(['package.json' => '{"name":"app"}']))->workspace())->toBeFalse();
});
