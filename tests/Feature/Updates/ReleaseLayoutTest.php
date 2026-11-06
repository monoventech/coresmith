<?php

declare(strict_types=1);

use App\Services\Updates\Release;
use App\Services\Updates\ReleaseLayout;
use App\Services\Updates\UpdatePaths;
use App\Services\Updates\Version;
use Illuminate\Support\Facades\File;

require_once __DIR__ . '/UpdatesHelpers.php';

beforeEach(function () {
    $this->paths = updatesUseTemporaryPaths();
    $this->root = $this->paths['root'];
});

afterEach(function () {
    updatesCleanUp();
});

function updatesLayout(): ReleaseLayout
{
    return app(ReleaseLayout::class);
}

test('a release is named after its version and commit', function () {
    expect(ReleaseLayout::name(Version::parse('1.0.2'), 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0'))->toBe('1.0.2-a1b2c3d')
        ->and(ReleaseLayout::name(Version::parse('1.0.2-beta.1'), 'a1b2c3d'))->toBe('1.0.2-beta.1-a1b2c3d')
        ->and(preg_match(ReleaseLayout::NAME, '1.0.2-beta.1-a1b2c3d'))->toBe(1)
        ->and(preg_match(ReleaseLayout::NAME, "1.0.2-a1b2c3d\n"))->toBe(0);
});

test('a commit that is not a hash cannot name a release', function (string $commit) {
    expect(fn() => ReleaseLayout::name(Version::parse('1.0.2'), $commit))->toThrow(InvalidArgumentException::class);
})->with(['ABCDEF0', 'a1b2c3', '../../x', str_repeat('a', 41), "a1b2c3d\n"]);

test('a layout is managed only when the symlink, the release and the shared files are all there', function (?Closure $break, bool $managed) {
    $release = updatesInstall($this->root);

    if ($break !== null) {
        $break($this->root, $release);
    }

    expect(updatesLayout()->isManaged())->toBe($managed);
})->with([
    'intact' => [null, true],
    'app is a directory' => [function (string $root) {
        unlink("{$root}/app");
        mkdir("{$root}/app");
    }, false],
    'app points outside releases' => [function (string $root) {
        unlink("{$root}/app");
        symlink(sys_get_temp_dir(), "{$root}/app");
    }, false],
    'app points at a release inside another release' => [function (string $root, Release $release) {
        mkdir("{$release->path}/nested");
        file_put_contents("{$release->path}/nested/" . Release::SENTINEL, '{}');
        unlink("{$root}/app");
        symlink("{$release->path}/nested", "{$root}/app");
    }, false],
    'app missing' => [fn(string $root) => unlink("{$root}/app"), false],
    'the sentinel removed' => [fn(string $root, Release $release) => unlink("{$release->path}/" . Release::SENTINEL), false],
    'shared env removed' => [fn(string $root) => unlink("{$root}/shared/.env"), false],
    'shared storage removed' => [fn(string $root) => File::deleteDirectory("{$root}/shared/storage"), false],
]);

test('the current release is the one the symlink names', function () {
    $release = updatesInstall($this->root, '1.0.1');

    expect(updatesLayout()->current()->name)->toBe($release->name)
        ->and(updatesLayout()->current()->path)->toBe(realpath($release->path));

    unlink("{$this->root}/app");

    expect(updatesLayout()->current())->toBeNull();
});

test('a sealed release reads back what it was sealed with', function () {
    $this->travelTo('2026-11-01 09:30:00');
    $release = updatesRelease($this->root, '1.0.2', 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', ['signature' => 'verified', 'fingerprint' => 'ABCD1234', 'built_by' => 'updater']);

    expect(Release::fromDirectory($release->path)->toArray())->toBe($release->toArray())
        ->and($release->toArray())->toBe([
            'schema' => 1, 'version' => '1.0.2', 'commit' => 'a1b2c3d4e5f6a7b8c9d0a1b2c3d4e5f6a7b8c9d0', 'tag' => 'v1.0.2',
            'signature' => 'verified', 'fingerprint' => 'ABCD1234', 'built_at' => '2026-11-01T09:30:00+00:00', 'built_by' => 'updater',
        ])
        ->and(fileperms("{$release->path}/" . Release::SENTINEL) & 0777)->toBe(0644);

    mkdir("{$this->root}/releases/unsealed");

    expect(Release::fromDirectory("{$this->root}/releases/unsealed"))->toBeNull();
});

test('a sentinel that does not say how the release was checked reads as unchecked', function () {
    mkdir("{$this->root}/releases/1.0.2-a1b2c3d", 0755, true);
    file_put_contents("{$this->root}/releases/1.0.2-a1b2c3d/" . Release::SENTINEL, json_encode(['schema' => 1, 'version' => '1.0.2']));

    expect(Release::fromDirectory("{$this->root}/releases/1.0.2-a1b2c3d")->signature)->toBe('unchecked');
});

test('an unreadable sentinel is not a sealed release', function () {
    mkdir("{$this->root}/releases/1.0.2-a1b2c3d", 0755, true);
    file_put_contents("{$this->root}/releases/1.0.2-a1b2c3d/" . Release::SENTINEL, 'not json');

    expect(Release::fromDirectory("{$this->root}/releases/1.0.2-a1b2c3d"))->toBeNull();
});

test('a release is found by its name and half-built ones are ignored', function () {
    $a = updatesRelease($this->root, '1.0.0', 'aaaaaaa1111111');
    mkdir("{$this->root}/releases/1.0.3-ddddddd");
    mkdir("{$this->root}/releases/.incoming-01jbxyz");

    expect(updatesLayout()->find($a->name)->name)->toBe($a->name)
        ->and(updatesLayout()->find('1.0.3-ddddddd'))->toBeNull()
        ->and(updatesLayout()->find('.incoming-01jbxyz'))->toBeNull()
        ->and(updatesLayout()->find('../shared'))->toBeNull();
});

test('a new release is built in an incoming directory of its own', function () {
    updatesInstall($this->root);

    $incoming = updatesLayout()->incoming();

    expect(dirname($incoming))->toBe(realpath("{$this->root}/releases"))
        ->and(basename($incoming))->toMatch('/^\.incoming-[0-9a-z]{26}$/')
        ->and(is_dir($incoming))->toBeTrue()
        ->and(fileperms($incoming) & 0777)->toBe(0755)
        ->and(updatesLayout()->incoming())->not->toBe($incoming);
});

test('activating a release swaps the symlink and leaves the old release in place', function () {
    $old = updatesInstall($this->root, '1.0.1');
    $new = updatesRelease($this->root, '1.0.2', 'a1b2c3d4e5f6');

    updatesLayout()->activate($new);

    expect(readlink(app(UpdatePaths::class)->current()))->toBe(realpath($new->path))
        ->and(is_dir($old->path))->toBeTrue()
        ->and(glob("{$this->root}/.app-*"))->toBe([])
        ->and(updatesLayout()->current()->name)->toBe($new->name);
});

test('only a sealed release inside releases can be activated', function (Closure $candidate) {
    $old = updatesInstall($this->root, '1.0.1');
    $release = $candidate($this->root);

    expect(fn() => updatesLayout()->activate($release))->toThrow(RuntimeException::class)
        ->and(readlink("{$this->root}/app"))->toBe($old->path)
        ->and(glob("{$this->root}/.app-*"))->toBe([]);
})->with([
    'unsealed' => [function (string $root): Release {
        $release = updatesRelease($root, '1.0.2', 'a1b2c3d4e5f6');
        unlink("{$release->path}/" . Release::SENTINEL);

        return $release;
    }],
    'sealed somewhere else' => [function (string $root): Release {
        mkdir("{$root}/elsewhere/1.0.2-a1b2c3d", 0755, true);
        app(ReleaseLayout::class)->seal("{$root}/elsewhere/1.0.2-a1b2c3d", ['version' => '1.0.2', 'commit' => 'a1b2c3d', 'tag' => 'v1.0.2', 'signature' => 'unchecked', 'fingerprint' => null, 'built_by' => 'test']);

        return Release::fromDirectory("{$root}/elsewhere/1.0.2-a1b2c3d");
    }],
]);

test('promoting replaces a leftover of the same name but never the running release', function () {
    $current = updatesInstall($this->root, '1.0.1');
    $layout = updatesLayout();

    mkdir("{$this->root}/releases/1.0.2-a1b2c3d");
    file_put_contents("{$this->root}/releases/1.0.2-a1b2c3d/leftover", 'old');
    $incoming = $layout->incoming();
    file_put_contents("{$incoming}/fresh", 'new');

    $promoted = $layout->promote($incoming, '1.0.2-a1b2c3d');

    expect($promoted)->toBe(realpath("{$this->root}/releases") . '/1.0.2-a1b2c3d')
        ->and(is_file("{$promoted}/leftover"))->toBeFalse()
        ->and(file_get_contents("{$promoted}/fresh"))->toBe('new')
        ->and(is_dir($incoming))->toBeFalse();

    $sealed = updatesRelease($this->root, '1.0.3', 'b1b2c3d4e5f6');
    file_put_contents("{$sealed->path}/marker", 'old');
    $incoming = $layout->incoming();
    file_put_contents("{$incoming}/fresh", 'newer');

    $promoted = $layout->promote($incoming, $sealed->name);

    expect(is_file("{$promoted}/marker"))->toBeFalse()
        ->and(file_get_contents("{$promoted}/fresh"))->toBe('newer');

    $incoming = $layout->incoming();
    file_put_contents("{$current->path}/marker", 'running');

    expect(fn() => $layout->promote($incoming, $current->name))->toThrow(RuntimeException::class, "{$current->name} is the running release.")
        ->and(file_get_contents("{$current->path}/marker"))->toBe('running')
        ->and(fn() => $layout->promote($incoming, '../shared'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $layout->promote("{$this->root}/shared", '1.0.4-c1b2c3d'))->toThrow(RuntimeException::class)
        ->and(is_dir("{$this->root}/shared/storage"))->toBeTrue();
});

test('pruning keeps the running release and the one before it', function () {
    $this->travelTo('2026-10-01 00:00:00');
    $a = updatesRelease($this->root, '1.0.0', 'aaaaaaa1111111');
    $this->travelTo('2026-10-02 00:00:00');
    $b = updatesRelease($this->root, '1.0.1', 'bbbbbbb2222222');
    $this->travelTo('2026-10-03 00:00:00');
    $c = updatesInstall($this->root, '1.0.2', 'ccccccc3333333');
    mkdir("{$this->root}/releases/1.0.3-ddddddd");
    mkdir("{$this->root}/releases/.incoming-x");

    $deleted = updatesLayout()->prune($c, $b);

    expect($deleted)->toEqualCanonicalizing([$a->name, '1.0.3-ddddddd', '.incoming-x'])
        ->and(is_dir($b->path))->toBeTrue()
        ->and(is_dir($c->path))->toBeTrue()
        ->and(is_dir($a->path))->toBeFalse();

    expect(updatesLayout()->prune($b, null))->toBe([])
        ->and(is_dir($c->path))->toBeTrue()
        ->and(is_dir($b->path))->toBeTrue();
});

test('discarding refuses the running release and anything outside releases', function () {
    $current = updatesInstall($this->root, '1.0.1');
    $other = updatesRelease($this->root, '1.0.2', 'a1b2c3d4e5f6');
    $outside = realpath(sys_get_temp_dir()) . '/csu-outside-' . bin2hex(random_bytes(4));
    mkdir($outside);

    try {
        foreach ([$current->path, $outside, "{$this->root}/releases/../shared", "{$this->root}/app"] as $path) {
            expect(fn() => updatesLayout()->discard($path))->toThrow(RuntimeException::class);
        }

        expect(is_dir($current->path))->toBeTrue()
            ->and(is_dir($outside))->toBeTrue()
            ->and(is_dir("{$this->root}/shared/storage"))->toBeTrue();

        updatesLayout()->discard($other->path);

        expect(is_dir($other->path))->toBeFalse();
    } finally {
        @rmdir($outside);
    }
});
