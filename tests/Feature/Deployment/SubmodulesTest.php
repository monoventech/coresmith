<?php

declare(strict_types=1);

use App\Exceptions\SubmoduleRefused;
use App\Services\Deployment\Submodules;

test('entries come from the release\'s .gitmodules as git lists it, keyed by name, with any other settings left out', function () {
    $listed = implode("\n", [
        'submodule.realworld.path=realworld',
        'submodule.realworld.url=https://github.com/realworld-apps/realworld.git',
        'submodule.realworld.ignore=dirty',
        'submodule.theme.v2.path=resources/theme files',
        'submodule.theme.v2.url=../theme.git',
        'submodule.theme.v2.branch=main',
    ]);

    expect(Submodules::entries($listed))->toBe([
        ['name' => 'realworld', 'path' => 'realworld', 'url' => 'https://github.com/realworld-apps/realworld.git'],
        ['name' => 'theme.v2', 'path' => 'resources/theme files', 'url' => '../theme.git'],
    ]);
});

test('an entry missing its path or its url is left out', function () {
    expect(Submodules::entries("submodule.a.path=a\nsubmodule.b.url=https://github.com/o/b.git\n"))->toBe([]);
});

test('the commit each submodule is pinned to comes from the parent\'s tree', function () {
    $tree = implode("\n", [
        "100644 blob 4b825dc642cb6eb9a060e54bf8d69288fbee4904\tREADME.md",
        "160000 commit 25588846c314b15a5e427ffe816e6b4af7be68c7\trealworld",
        "160000 commit 0123456789abcdef0123456789abcdef01234567\tresources/theme files",
        "040000 tree 9b2a1a8e1b2c3d4e5f60718293a4b5c6d7e8f901\tsrc",
    ]);

    expect(Submodules::pins($tree))->toBe([
        'realworld' => '25588846c314b15a5e427ffe816e6b4af7be68c7',
        'resources/theme files' => '0123456789abcdef0123456789abcdef01234567',
    ]);
});

test('an https url is fetched as written, and relative and SSH urls become https', function (string $url, string $parent, string $resolved) {
    expect(Submodules::resolve($url, $parent))->toBe($resolved);
})->with([
    'https' => ['https://github.com/realworld-apps/realworld.git', 'https://github.com/gothinkster/app.git', 'https://github.com/realworld-apps/realworld.git'],
    'a sibling' => ['../theme.git', 'https://github.com/acme/shop.git', 'https://github.com/acme/theme.git'],
    'another owner' => ['../../design/theme', 'https://github.com/acme/shop.git', 'https://github.com/design/theme'],
    'from a parent without .git' => ['../theme.git', 'https://gitlab.com/acme/apps/shop', 'https://gitlab.com/acme/apps/theme.git'],
    'scp-style ssh' => ['git@github.com:acme/theme.git', 'https://github.com/acme/shop.git', 'https://github.com/acme/theme.git'],
    'ssh url' => ['ssh://git@gitlab.com/acme/apps/theme.git', 'https://gitlab.com/acme/apps/shop.git', 'https://gitlab.com/acme/apps/theme.git'],
    'bitbucket ssh' => ['git@bitbucket.org:acme/theme.git', 'https://bitbucket.org/acme/shop.git', 'https://bitbucket.org/acme/theme.git'],
    'a sibling of a parent cloned over ssh' => ['../theme.git', 'git@github.com:acme/shop.git', 'https://github.com/acme/theme.git'],
    'a sibling of a parent with an ssh url' => ['../theme.git', 'ssh://git@gitlab.com/acme/apps/shop.git', 'https://gitlab.com/acme/apps/theme.git'],
]);

test('a url that could read the server\'s own files or run a command is refused, naming the submodule', function (string $url) {
    expect(fn() => Submodules::resolve($url, 'https://github.com/acme/shop.git', 'theme'))
        ->toThrow(SubmoduleRefused::class, "The submodule at theme points at {$url}");
})->with([
    'file' => ['file:///var/coresmith/apps/other/repo.git'],
    'a local path' => ['/var/coresmith/apps/other/repo.git'],
    'ext' => ['ext::sh -c touch% /tmp/pwned'],
    'plain http' => ['http://example.com/theme.git'],
    'git protocol' => ['git://example.com/theme.git'],
    'ssh to another host' => ['git@example.com:acme/theme.git'],
    'a relative url above the host' => ['../../../theme.git'],
]);

test('a submodule path that leaves the release is refused', function (string $path) {
    expect(fn() => Submodules::safePath($path))->toThrow(SubmoduleRefused::class);
})->with(['../escape', 'lib/../../escape', '/etc', '', '.']);

test('a nested path inside the release is kept', function () {
    expect(Submodules::safePath('resources/theme files'))->toBe('resources/theme files');
});

test('each submodule url gets its own cache folder beside the app\'s repository', function () {
    $cache = Submodules::cache('/var/coresmith/apps/shop/repo', 'https://github.com/realworld-apps/realworld.git');

    expect($cache)->toStartWith('/var/coresmith/apps/shop/repo.modules/')
        ->and($cache)->toEndWith('.git')
        ->and($cache)->toBe(Submodules::cache('/var/coresmith/apps/shop/repo', 'https://github.com/realworld-apps/realworld.git'))
        ->and($cache)->not->toBe(Submodules::cache('/var/coresmith/apps/shop/repo', 'https://github.com/acme/theme.git'));
});
