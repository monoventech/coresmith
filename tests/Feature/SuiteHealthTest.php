<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

test('no two test files declare the same global helper', function () {
    $declarations = [];

    foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $file) {
        preg_match_all('/^function ([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', (string) file_get_contents($file->getPathname()), $matches);

        foreach ($matches[1] as $name) {
            $declarations[$name][] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    $clashes = array_filter($declarations, fn (array $files): bool => count($files) > 1);

    expect($clashes)->toBe(
        [],
        'A helper declared in two test files is a fatal redeclaration: Pest exits 255 with no output, which reads as "no tests ran". Rename one of them. Clashes: '
        .json_encode($clashes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
    );
});
