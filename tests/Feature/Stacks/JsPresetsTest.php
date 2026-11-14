<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Detection\DetectedStack;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RepositoryFacts;
use App\Stacks\JsPresets\JsPreset;
use App\Stacks\JsPresets\JsPresets;

test('the JavaScript presets are tried in a fixed order', function () {
    expect(array_map(fn(JsPreset $preset): string => $preset->key(), app(JsPresets::class)->all()))
        ->toBe(['sveltekit', 'nextjs', 'nuxt', 'astro', 'adonisjs', 'angular', 'react-scripts', 'vite']);
});

test('a server preset is found by the framework an app stores, in any case, and next finds Next.js, while static-only presets are never an app\'s framework', function () {
    $presets = app(JsPresets::class);

    expect($presets->find('next')?->key())->toBe('nextjs')
        ->and($presets->find('SvelteKit')?->key())->toBe('sveltekit')
        ->and($presets->find(''))->toBeNull()
        ->and($presets->find('express'))->toBeNull()
        ->and($presets->find('vite'))->toBeNull()
        ->and($presets->find('react-scripts'))->toBeNull();
});

test('only server presets name the file they start from, and only SvelteKit adds to the environment', function () {
    $presets = app(JsPresets::class)->all();

    expect(array_map(fn(JsPreset $preset): ?string => $preset->entry(), $presets))->toBe(['build/index.js', null, '.output/server/index.mjs', 'dist/server/entry.mjs', 'build/bin/server.js', null, null, null])
        ->and(array_map(fn(JsPreset $preset): array => array_keys($preset->environment()), $presets))->toBe([['PROTOCOL_HEADER', 'HOST_HEADER', 'ADDRESS_HEADER'], [], [], [], ['LOG_LEVEL', 'SESSION_DRIVER'], [], [], []]);
});

test('Add an app offers the server presets as JavaScript frameworks, by name', function () {
    expect(app(JsPresets::class)->choices())->toBe([
        ['value' => 'sveltekit', 'label' => 'SvelteKit'],
        ['value' => 'nextjs', 'label' => 'Next.js'],
        ['value' => 'nuxt', 'label' => 'Nuxt'],
        ['value' => 'astro', 'label' => 'Astro'],
        ['value' => 'adonisjs', 'label' => 'AdonisJS'],
        ['value' => 'angular', 'label' => 'Angular'],
    ]);
});

test('a JavaScript framework is called by its preset name in detection and on the app page', function () {
    expect((new DetectedStack('js', 'nuxt', 'node', null))->label(app(Catalog::class)))->toBe('Nuxt')
        ->and((new DetectedStack('js', '', 'node', null))->label(app(Catalog::class)))->toBe('API or other')
        ->and(ApplicationPresenter::kind(new Application(['type' => ApplicationType::Js, 'framework' => 'next'])))->toBe('Next.js')
        ->and(ApplicationPresenter::kind(new Application(['type' => ApplicationType::Js, 'framework' => 'astro'])))->toBe('Astro');
});

test('a framework whose build script does not build it is named with the right article', function () {
    $facts = new RepositoryFacts(['package.json' => (string) json_encode(['scripts' => ['build' => 'tsc'], 'dependencies' => ['astro' => '^5.0.0']])], complete: true);

    expect(ApplicationType::Js->language()->detect($facts)['unsettled']['framework'])->toBe("astro is in package.json, but the build script doesn't run astro build, so Coresmith can't tell this is an Astro app.");
});
