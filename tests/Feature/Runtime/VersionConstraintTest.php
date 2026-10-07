<?php

declare(strict_types=1);

use App\Services\Runtime\VersionConstraint;

test('composer constraints', function (string $constraint, string $version, bool $expected) {
    expect(VersionConstraint::parse($constraint, 'composer')?->satisfiedBy($version))->toBe($expected);
})->with([
    ['^8.2', '8.2.0', true],
    ['^8.2', '8.5.10', true],
    ['^8.2', '9.0.0', false],
    ['^8.2', '8.1.34', false],
    ['~8.2', '8.5.10', true],
    ['~8.2.3', '8.2.9', true],
    ['~8.2.3', '8.3.0', false],
    ['>=8.2 <8.5', '8.4.25', true],
    ['>=8.2 <8.5', '8.5.0', false],
    ['>=8.2,<8.5', '8.3.1', true],
    ['^8.3 || ^8.4', '8.4.25', true],
    ['^8.3|^8.4', '8.2.33', false],
    ['8.4.*', '8.4.25', true],
    ['8.4.*', '8.5.10', false],
    ['>8.3', '8.4.0', true],
    ['>8.3', '8.3.33', true],
    ['*', '8.1.0', true],
    ['8.4', '8.4.25', false],
    ['8.4', '8.4.0', true],
    ['=8.4', '8.4.25', false],
    ['<=8.3', '8.3.33', false],
    ['<=8.3', '8.3.0', true],
    ['!=8.2', '8.2.5', true],
    ['!=8.2', '8.2.0', false],
    ['8.*', '8.5.10', true],
    ['8.2 - 8.4', '8.4.25', true],
    ['8.2 - 8.4', '8.5.0', false],
]);

test('npm ranges', function (string $constraint, string $version, bool $expected) {
    expect(VersionConstraint::parse($constraint)?->satisfiedBy($version))->toBe($expected);
})->with([
    ['>=20', '24.21.0', true],
    ['>=20 <23', '24.21.0', false],
    ['22.x', '22.23.2', true],
    ['^22', '24.0.0', false],
    ['~22.16', '22.16.9', true],
    ['~22.16', '22.17.0', false],
    ['20 || 22', '22.23.2', true],
    ['>= 20.0.0', '20.20.2', true],
    ['20 - 22', '22.23.2', true],
    ['20 - 22.1', '22.2.0', false],
    ['v22', '22.1.0', true],
    ['22', '24.21.0', false],
    ['>8.3', '8.3.33', false],
    ['8.4', '8.4.25', true],
    ['<=8.3', '8.3.33', true],
]);

test('rubygems requirements', function (string $constraint, string $version, bool $expected) {
    expect(VersionConstraint::parse($constraint, 'gem')?->satisfiedBy($version))->toBe($expected);
})->with([
    ['~> 3.3', '3.4.10', true],
    ['~> 3.3', '4.0.0', false],
    ['~> 3.3.0', '3.3.12', true],
    ['~> 3.3.0', '3.4.0', false],
    ['>= 3.2', '4.0.7', true],
    ['3.3', '3.3.12', false],
    ['3.3', '3.3.0', true],
    ['> 3.3', '3.3.12', true],
    ['<= 3.3', '3.3.12', false],
    ['!= 3.3', '3.3.12', true],
]);

test('an exact version is recognised as one', function () {
    expect(VersionConstraint::parse('8.4.12')?->exact)->toBe('8.4.12')
        ->and(VersionConstraint::parse('v22.16.0')?->exact)->toBe('22.16.0')
        ->and(VersionConstraint::parse('=3.3.6')?->exact)->toBe('3.3.6')
        ->and(VersionConstraint::parse('^8.4')?->exact)->toBeNull()
        ->and(VersionConstraint::parse('8.4')?->exact)->toBeNull()
        ->and(VersionConstraint::parse('8.4', 'composer')?->exact)->toBe('8.4.0')
        ->and(VersionConstraint::parse('= 3.3', 'gem')?->exact)->toBe('3.3.0')
        ->and(VersionConstraint::parse('8.4.*', 'composer')?->exact)->toBeNull();
});

test('the newest matching version wins', function () {
    expect(VersionConstraint::parse('^8.2', 'composer')?->best(['8.2.33', '8.5.10', '8.4.25', '9.0.0']))->toBe('8.5.10')
        ->and(VersionConstraint::parse('^22')?->best(['24.21.0', '20.20.2']))->toBeNull();
});

test('anything that is not a version constraint is refused rather than guessed', function (string $constraint) {
    expect(VersionConstraint::parse($constraint))->toBeNull();
})->with(['lts/iron', 'latest', '8.4; rm -rf /', 'dev-main', '!=8.2', '', '   ']);
