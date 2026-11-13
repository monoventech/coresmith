<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('no code splits lines with a bare \R, which in byte mode also splits inside CJK text at the 0x85 byte', function () {
    $offenders = collect(File::allFiles(app_path()))
        ->filter(fn(SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.php') && preg_match('~preg_split\(\s*[\'"]/\\\\R/[\'"]~', (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn(SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
