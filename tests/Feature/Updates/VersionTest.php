<?php

declare(strict_types=1);

use App\Services\Updates\Version;

test('versions are parsed with or without a leading v', function () {
    $version = Version::parse('v1.2.3');

    expect((string) $version)->toBe('1.2.3')
        ->and($version->tag())->toBe('v1.2.3')
        ->and([$version->major, $version->minor, $version->patch, $version->prerelease])->toBe([1, 2, 3, null])
        ->and(Version::parse('1.2.3')->equals($version))->toBeTrue();
});

test('a pre-release keeps its identifiers', function () {
    $version = Version::parse('1.0.0-beta.2');

    expect($version->prerelease)->toBe('beta.2')
        ->and($version->isPrerelease())->toBeTrue()
        ->and($version->tag())->toBe('v1.0.0-beta.2');
});

test('precedence follows semantic versioning', function () {
    $ordered = ['1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0', '1.0.1', '1.9.9', '1.10.0', '2.0.0'];

    foreach (array_slice($ordered, 1) as $index => $newer) {
        expect(Version::parse($newer)->isNewerThan(Version::parse($ordered[$index])))->toBeTrue("{$newer} should be newer than {$ordered[$index]}")
            ->and(Version::parse($ordered[$index])->compare(Version::parse($newer)))->toBe(-1);
    }
});

test('a version is not newer than itself, and a different one is not equal to it', function () {
    expect(Version::parse('1.2.3')->isNewerThan(Version::parse('v1.2.3')))->toBeFalse()
        ->and(Version::parse('1.2.4')->equals(Version::parse('1.2.3')))->toBeFalse()
        ->and(Version::parse('1.2.3')->equals(Version::parse('1.2.3-rc.1')))->toBeFalse();
});

test('numeric pre-release identifiers compare by value however long they are', function () {
    expect(Version::parse('1.0.0-rc.100000000000000000000')->isNewerThan(Version::parse('1.0.0-rc.99999999999999999999')))->toBeTrue()
        ->and(Version::parse('1.0.0-rc.2')->compare(Version::parse('1.0.0-rc.2')))->toBe(0);
});

test('anything that is not a version is refused', function (string $input) {
    expect(fn() => Version::parse($input))->toThrow(InvalidArgumentException::class)
        ->and(Version::tryParse($input))->toBeNull();
})->with(['', '1', '1.2', '1.2.3.4', '01.2.3', '1.2.3-', '1.2.3+build.5', 'latest', "1.2.3\n", '1.2.3-beta..1', str_repeat('9', 70) . '.0.0']);

test('a value that is not a string is not a version', function (mixed $input) {
    expect(Version::tryParse($input))->toBeNull();
})->with([null, 1, 1.2, [['1.2.3']], true]);

test('the refusal names the value and what a version looks like', function () {
    expect(fn() => Version::parse('latest'))->toThrow(InvalidArgumentException::class, '"latest" is not a version like 1.2.3.');
});

test('a patch update stays on the same minor line and never involves a pre-release', function (string $candidate, string $running, bool $expected) {
    expect(Version::parse($candidate)->isPatchUpdateOf(Version::parse($running)))->toBe($expected);
})->with([
    ['1.0.2', '1.0.1', true],
    ['1.0.9', '1.0.1', true],
    ['1.1.0', '1.0.9', false],
    ['2.0.1', '1.0.1', false],
    ['1.0.1', '1.0.1', false],
    ['1.0.0', '1.0.1', false],
    ['1.0.2-beta.1', '1.0.1', false],
    ['1.0.2', '1.0.2-beta.1', false],
    ['1.0.3', '1.0.2-beta.1', false],
    ['1.1.2', '1.0.1', false],
    ['2.0.2', '1.0.1', false],
]);

test('the running version is the one in the VERSION file and cannot be overridden from the environment', function () {
    $file = trim((string) file_get_contents(base_path('VERSION')));

    expect(config('coresmith.version'))->toBe($file)
        ->and((string) Version::current())->toBe($file)
        ->and((string) file_get_contents(config_path('coresmith.php')))->not->toContain('CORESMITH_VERSION');
});
