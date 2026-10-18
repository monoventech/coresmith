<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * @return array<string, string>
 */
function svelteSources(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(base_path('resources/js'))->name('*.svelte')->notPath('components/ui') as $file) {
        $files[str_replace(base_path() . '/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
    }

    return $files;
}

/**
 * @return array<string, string>
 */
function fullBleedPages(): array
{
    $app = (string) file_get_contents(base_path('resources/js/app.ts'));

    preg_match('/layout: \(name\) => \{(.*?)\n    \},/s', $app, $matches);
    preg_match_all("/case name === '([^']+)':/", $matches[1] ?? '', $cases, PREG_OFFSET_CAPTURE);

    $returnNull = strpos($matches[1] ?? '', 'return null');
    $pages = [];

    foreach ($cases[1] as $case) {
        if ($returnNull !== false && $case[1] < $returnNull) {
            $pages[] = $case[0];
        }
    }

    $sources = [];

    foreach ($pages as $page) {
        $path = 'resources/js/pages/' . $page . '.svelte';
        $sources[$path] = (string) file_get_contents(base_path($path));
    }

    return $sources;
}

test('no page reaches for a raw control that the design system already provides', function () {
    $offenders = [];

    foreach (svelteSources() as $path => $source) {
        if (preg_match('/type="(checkbox|radio)"/', $source) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe(
        [],
        'A native checkbox draws the browser focus outline, not the Monoven focus ring, and ignores the radius family. '
            . 'Use components/ui/checkbox, ui/switch or ui/radio-group. Offenders: ' . implode(', ', $offenders),
    );
});

test('a view carries at most one hand tell', function () {
    $offenders = [];

    foreach (svelteSources() as $path => $source) {
        $marks = substr_count($source, '<MarkerUnderline');

        if ($marks > 1) {
            $offenders[$path] = $marks;
        }
    }

    expect($offenders)->toBe([], 'MONOVEN_DESIGN §7: the marker stroke is used once per view. Offenders: ' . json_encode($offenders));
});

test('every full bleed page carries the hand tell and the Monoven mark', function () {
    $missing = [];

    foreach (fullBleedPages() as $path => $source) {
        $tells = [];

        str_contains($source, 'MarkerUnderline') || $tells[] = 'no hand tell';
        str_contains($source, 'MonovenMark') || $tells[] = 'no Monoven mark';

        if ($tells !== []) {
            $missing[$path] = implode(' and ', $tells);
        }
    }

    expect($missing)->toBe(
        [],
        'A page with no layout inherits no branding, so it has to carry its own. ' . json_encode($missing),
    );
});

test('the interface avoids the anti-patterns the design language names', function (string $pattern, string $why) {
    $offenders = [];

    foreach (svelteSources() as $path => $source) {
        // A file may opt out of one rule by naming the reason, so exceptions stay visible.
        if (preg_match($pattern, $source) === 1 && !str_contains($source, 'monoven-allow')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], "MONOVEN_DESIGN §12: {$why}. Offenders: " . implode(', ', $offenders));
})->with([
    ['/\bbg-gradient-to-|\bfrom-\[#|\bbg-\[linear-gradient/', 'no gradient meshes as the personality'],
    ['/\bbackdrop-blur/', 'no glassmorphism as decoration'],
    ['/\bshadow-2xl|\bdrop-shadow-2xl/', 'no heavy drop shadows, no cards floating for no reason'],
    ['/\b(bg|text|border)-(black|white)\b/', 'colours come from the tokens, never hardcoded black or white'],
]);

test('icons come from one set only', function () {
    $offenders = [];

    foreach (svelteSources() as $path => $source) {
        preg_match_all('~from \'(@?[a-z0-9@/.-]*icons?[a-z0-9@/.-]*)\'~i', $source, $matches);

        foreach ($matches[1] as $import) {
            if (!str_starts_with($import, '@/') && !str_contains($import, 'lucide')) {
                $offenders[] = $path . ' → ' . $import;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'MONOVEN_DESIGN §7: Lucide only. Mixing icon sets breaks the line signature. Offenders: ' . implode(', ', $offenders),
    );
});

test('the design language is a standing instruction, not a document nobody opens', function () {
    if (!is_file(base_path('MONOVEN_DESIGN.md')) || !is_file(base_path('brand.md'))) {
        $this->markTestSkipped('The private Monoven design documents are not in this checkout.');
    }

    $agreements = (string) file_get_contents(base_path('CONTRIBUTING.md'));

    expect($agreements)->toContain('MONOVEN_DESIGN.md')
        ->and($agreements)->toContain('brand.md');
});
