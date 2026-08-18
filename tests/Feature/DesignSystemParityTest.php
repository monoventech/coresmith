<?php

declare(strict_types=1);

/**
 * The Monoven design system is the source of truth for Coresmith's visual layer.
 * These assert the app has not drifted from it. The system lives outside the repo,
 * so each test skips rather than fails when it is not mounted.
 */
function designSystemPath(): ?string
{
    $path = getenv('MONOVEN_DS_PATH') ?: getenv('HOME').'/dev/Monoven · Coresmith Design System';

    return is_dir($path) ? $path : null;
}

function appCss(): string
{
    return (string) file_get_contents(base_path('resources/css/app.css'));
}

function designSystemFile(string $relative): string
{
    return (string) file_get_contents(designSystemPath().'/'.$relative);
}

beforeEach(function () {
    if (designSystemPath() === null) {
        $this->markTestSkipped('The Monoven design system is not mounted on this machine.');
    }
});

test('the coresmith product layer values are the ones the app ships', function () {
    $layer = designSystemFile('product-layers/coresmith.css');
    $css = appCss();

    preg_match_all('/--(primary|ring|primary-strong|radius):\s*([^;]+);/', $layer, $matches, PREG_SET_ORDER);

    $missing = [];

    foreach ($matches as [, $token, $value]) {
        $value = trim($value);
        $value = trim(preg_replace('/\s*\/\*.*$/', '', $value));

        if (!str_contains($css, "--{$token}: {$value};")) {
            $missing[] = "--{$token}: {$value}";
        }
    }

    expect($missing)->toBe([], 'These product-layer values differ from the design system: '.implode(' · ', $missing));
});

test('the display face is the one the product layer names', function () {
    expect(appCss())->toContain("--font-display: 'Bespoke Slab'")
        ->and(is_file(base_path('resources/fonts/BespokeSlab-Variable.woff2')))->toBeTrue();
});

test('the locked portfolio faces are unchanged', function () {
    $typography = designSystemFile('tokens/typography.css');
    $css = appCss();

    foreach (['--font-sans', '--font-mono'] as $token) {
        preg_match("/{$token}:\s*([^;]+);/", $typography, $m);
        expect($css)->toContain("{$token}: ".trim($m[1]).';');
    }
});

test('the modular type scale matches the design system exactly', function () {
    preg_match_all('/^(\.type-[a-z0-9-]+)\s*\{([^}]+)\}/m', designSystemFile('tokens/typography.css'), $expected, PREG_SET_ORDER);
    preg_match_all('/^(\.type-[a-z0-9-]+)\s*\{([^}]+)\}/m', appCss(), $actual, PREG_SET_ORDER);

    expect($expected)->not->toBeEmpty();

    // Compare declarations, not whitespace: the file is Prettier-formatted here and minified there.
    $declarations = static function (array $sets): array {
        $out = [];

        foreach ($sets as [, $selector, $body]) {
            $rules = array_filter(array_map(
                static fn (string $rule): string => (string) preg_replace('/\s+/', '', $rule),
                explode(';', $body),
            ));
            sort($rules);
            $out[$selector] = $rules;
        }

        return $out;
    };

    $want = $declarations($expected);
    $have = $declarations($actual);
    $drifted = [];

    foreach ($want as $selector => $rules) {
        if (($have[$selector] ?? null) !== $rules) {
            $drifted[] = $selector;
        }
    }

    expect($drifted)->toBe([], 'These type classes differ from the design system: '.implode(', ', $drifted));
});

test('the radius family is the signature one', function () {
    $shape = designSystemFile('tokens/shape.css');
    $css = appCss();

    foreach (['--radius-sm', '--radius-md', '--radius-lg', '--radius-xl', '--radius-2xl'] as $token) {
        preg_match("/{$token}:\s*([^;]+);/", $shape, $m);
        $value = trim(preg_replace('/\s*\/\*.*$/', '', trim($m[1])));

        expect($css)->toContain("{$token}: {$value};");
    }
});

test('the craft-tell utilities the design system defines are present', function () {
    $css = appCss();

    expect($css)->toContain('.focus-ring:focus-visible')
        ->and($css)->toContain('.grain')
        ->and($css)->toContain('.terminal')
        ->and($css)->toContain('.status-dot-pulse')
        ->and($css)->toContain('.status-dot-working')
        ->and($css)->toContain("font-feature-settings: 'ss01', 'cv01'");
});

test('coresmith opens in dark, which is what the product layer says', function () {
    expect(designSystemFile('product-layers/coresmith.css'))->toContain('defaults to DARK');

    $theme = (string) file_get_contents(base_path('resources/js/lib/theme.svelte.ts'));

    expect($theme)->toContain("{ value: 'dark' }");
});
