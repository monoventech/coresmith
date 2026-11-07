<?php

declare(strict_types=1);

use App\Exceptions\RuntimeUnavailable;
use App\Services\Runtime\Requirement;
use App\Services\Runtime\ResolvedRuntime;
use App\Services\Runtime\RuntimeResolver;

function resolverComposer(string $constraint): Requirement
{
    return new Requirement('php', $constraint, $constraint, 'composer.json', 'require.php in composer.json', 'composer');
}

test('an exact version is used as written, installed or not', function () {
    $resolved = app(RuntimeResolver::class)->resolve('php', resolverComposer('8.4.12'), null, ['8.5.10']);

    expect($resolved->version)->toBe('8.4.12')
        ->and($resolved->installed)->toBeFalse()
        ->and($resolved->source)->toBe('composer.json')
        ->and($resolved->detail)->toBe('8.4.12');
});

test('a composer requirement with a missing segment resolves to the zero-padded version', function () {
    $resolved = app(RuntimeResolver::class)->resolve('php', resolverComposer('8.4'), null, []);

    expect($resolved->version)->toBe('8.4.0')->and($resolved->source)->toBe('composer.json');
});

test('a range takes the newest installed version that fits, so nothing compiles', function () {
    $resolved = app(RuntimeResolver::class)->resolve('php', resolverComposer('^8.2'), null, ['8.3.1', '8.4.20']);

    expect($resolved->version)->toBe('8.4.20')->and($resolved->installed)->toBeTrue();
});

test('a range with nothing installed takes the newest supported version', function () {
    expect(app(RuntimeResolver::class)->resolve('php', resolverComposer('^8.3'), null, [])->version)->toBe('8.5.10');
});

test('a range nothing can satisfy is refused with what to change', function () {
    app(RuntimeResolver::class)->resolve('php', resolverComposer('>=9'), null, []);
})->throws(RuntimeUnavailable::class, 'require.php in composer.json asks for PHP >=9, which matches no version Coresmith can install (8.1–8.5). Change it, then deploy.');

test('a huge unsatisfiable requirement is quoted only up to sixty characters', function () {
    $raw = str_repeat('9', 80);
    $requirement = new Requirement('php', '>=9', $raw, 'composer.json', 'require.php in composer.json', 'composer');

    expect(fn() => app(RuntimeResolver::class)->resolve('php', $requirement, null, []))
        ->toThrow(RuntimeUnavailable::class, 'require.php in composer.json asks for PHP ' . str_repeat('9', 60) . '..., which matches no version Coresmith can install (8.1–8.5). Change it, then deploy.');
});

test('a version older than anything Coresmith builds is refused before compiling', function () {
    app(RuntimeResolver::class)->resolve('php', resolverComposer('7.4.33'), null, []);
})->throws(RuntimeUnavailable::class, 'require.php in composer.json asks for PHP 7.4.33, older than the oldest version Coresmith builds (8.1). Set it to 8.1 or newer, then deploy.');

test('with nothing in the repository the version chosen when the app was added applies', function () {
    $installed = app(RuntimeResolver::class)->resolve('php', null, '8.4', ['8.4.20']);
    $missing = app(RuntimeResolver::class)->resolve('php', null, '8.4', []);

    expect($installed->version)->toBe('8.4.20')
        ->and($installed->source)->toBe(ResolvedRuntime::FROM_PIN)
        ->and($missing->version)->toBe('8.4.25')
        ->and($missing->installed)->toBeFalse();
});

test('with nothing in the repository and nothing chosen the recommended version applies', function () {
    $resolved = app(RuntimeResolver::class)->resolve('nodejs', null, null, []);

    expect($resolved->version)->toBe('24.21.0')->and($resolved->source)->toBe(ResolvedRuntime::FROM_DEFAULT);
});

test('a requirement Coresmith cannot read is set aside with a note, not guessed', function () {
    $resolved = app(RuntimeResolver::class)->resolve('php', resolverComposer('soon'), null, []);

    expect($resolved->version)->toBe('8.5.10')
        ->and($resolved->source)->toBe(ResolvedRuntime::FROM_DEFAULT)
        ->and($resolved->note)->toBe('require.php in composer.json says soon, which is not a version Coresmith understands, so it was ignored.');
});

test('a requirement Coresmith cannot read still falls back to the pin, keeping the note', function () {
    $resolved = app(RuntimeResolver::class)->resolve('php', resolverComposer('soon'), '8.4', ['8.4.20']);

    expect($resolved->version)->toBe('8.4.20')
        ->and($resolved->source)->toBe(ResolvedRuntime::FROM_PIN)
        ->and($resolved->note)->toBe('require.php in composer.json says soon, which is not a version Coresmith understands, so it was ignored.');
});

test('an unreadable requirement quotes its raw text only up to sixty characters', function () {
    $raw = str_repeat('x', 80);
    $requirement = new Requirement('php', $raw, $raw, 'composer.json', 'require.php in composer.json', 'composer');

    $resolved = app(RuntimeResolver::class)->resolve('php', $requirement, null, []);

    expect($resolved->note)->toBe('require.php in composer.json says ' . str_repeat('x', 60) . '..., which is not a version Coresmith understands, so it was ignored.');
});

test('a line that is no longer in the catalog is refused with what to do', function () {
    app(RuntimeResolver::class)->resolve('php', null, '7.4', []);
})->throws(RuntimeUnavailable::class, 'This app was added with PHP 7.4, which Coresmith no longer builds. Add a version file to the repository, then deploy.');

test('a resolved runtime describes where its version came from', function () {
    expect((new ResolvedRuntime('php', '8.4.25', 'composer.json', '^8.4', true))->describe('PHP'))->toBe('PHP 8.4.25 from composer.json (^8.4)')
        ->and((new ResolvedRuntime('nodejs', '22.16.0', '.nvmrc', '22.16.0', true))->describe('Node.js'))->toBe('Node.js 22.16.0 from .nvmrc')
        ->and((new ResolvedRuntime('php', '8.4.25', ResolvedRuntime::FROM_PIN, null, true))->describe('PHP'))->toBe('PHP 8.4.25, the version chosen when the app was added')
        ->and((new ResolvedRuntime('php', '8.5.10', ResolvedRuntime::FROM_DEFAULT, null, true))->describe('PHP'))->toBe('PHP 8.5.10, the recommended version, because the repository names none');
});

test('a resolved runtime survives being stored and read back', function () {
    $resolved = new ResolvedRuntime('ruby', '3.4.10', '.ruby-version', 'ruby-3.4.10', false);

    expect(ResolvedRuntime::fromArray($resolved->toArray())->toArray())->toBe(['runtime' => 'ruby', 'version' => '3.4.10', 'source' => '.ruby-version', 'detail' => 'ruby-3.4.10']);
});
