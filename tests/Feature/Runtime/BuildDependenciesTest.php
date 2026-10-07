<?php

declare(strict_types=1);

use App\Services\Runtime\BuildDependencies;

test('php gets the build packages asdf-php builds with', function () {
    expect(BuildDependencies::for('php', 'ubuntu'))->toContain('autoconf', 'bison', 're2c', 'pkg-config', 'libicu-dev', 'libgd-dev', 'libedit-dev', 'gettext', 'zlib1g-dev')
        ->and(BuildDependencies::for('php', 'rocky'))->toContain('autoconf', 'bison', 're2c', 'pkgconf-pkg-config', 'libicu-devel', 'gd-devel', 'libedit-devel', 'gettext', 'zlib-devel');
});

test('ruby gets gmp and patch as well', function () {
    expect(BuildDependencies::for('ruby', 'ubuntu'))->toContain('libgmp-dev', 'patch')
        ->and(BuildDependencies::for('ruby', 'rocky'))->toContain('gmp-devel', 'patch');
});
