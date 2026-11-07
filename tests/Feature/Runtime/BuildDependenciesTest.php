<?php

declare(strict_types=1);

use App\Services\Runtime\BuildDependencies;

test('php gets the build packages asdf-php builds with', function () {
    expect(BuildDependencies::for('php', 'ubuntu'))->toContain('autoconf', 'bison', 're2c', 'pkg-config', 'libicu-dev', 'libgd-dev', 'libedit-dev', 'gettext', 'zlib1g-dev')
        ->and(BuildDependencies::for('php', 'rocky'))->toContain('autoconf', 'bison', 're2c', 'pkgconf-pkg-config', 'libicu-devel', 'gd-devel', 'libedit-devel', 'gettext', 'zlib-devel');
});

test('php gets the headers for every library php-build configures, and sodium and gmp so neither is left out', function () {
    expect(BuildDependencies::for('php', 'ubuntu'))->toContain('libbz2-dev', 'libtidy-dev', 'libxslt1-dev', 'libsodium-dev', 'libgmp-dev')
        ->and(BuildDependencies::for('php', 'debian'))->toContain('libbz2-dev', 'libtidy-dev', 'libxslt1-dev', 'libsodium-dev', 'libgmp-dev')
        ->and(BuildDependencies::for('php', 'rocky'))->toContain('bzip2-devel', 'libtidy-devel', 'libxslt-devel', 'libsodium-devel', 'gmp-devel')
        ->and(BuildDependencies::for('php', 'almalinux'))->toContain('bzip2-devel', 'libtidy-devel', 'libxslt-devel', 'libsodium-devel', 'gmp-devel');
});

test('ruby gets a rust compiler, so it is built with yjit', function () {
    expect(BuildDependencies::for('ruby', 'ubuntu'))->toContain('rustc')
        ->and(BuildDependencies::for('ruby', 'debian'))->toContain('rustc')
        ->and(BuildDependencies::for('ruby', 'rocky'))->toContain('rust')
        ->and(BuildDependencies::for('ruby', 'fedora'))->toContain('rust')
        ->and(BuildDependencies::for('php', 'ubuntu'))->not->toContain('rustc')
        ->and(BuildDependencies::for('nodejs', 'rocky'))->not->toContain('rust');
});

test('ruby gets gmp and patch as well', function () {
    expect(BuildDependencies::for('ruby', 'ubuntu'))->toContain('libgmp-dev', 'patch')
        ->and(BuildDependencies::for('ruby', 'rocky'))->toContain('gmp-devel', 'patch');
});
