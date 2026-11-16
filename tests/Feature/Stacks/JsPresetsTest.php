<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Detection\DetectedStack;
use App\Services\Panel\ApplicationPresenter;
use App\Services\Runtime\Catalog;
use App\Services\Runtime\RepositoryFacts;
use App\Stacks\JavaScriptDetection;
use App\Stacks\JsPresets\JsPreset;
use App\Stacks\JsPresets\JsPresets;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

test('the JavaScript presets are tried in a fixed order', function () {
    expect(array_map(fn(JsPreset $preset): string => $preset->key(), app(JsPresets::class)->all()))
        ->toBe(['sveltekit', 'nextjs', 'nuxt', 'astro', 'adonisjs', 'angular', 'react', 'vue', 'svelte', 'vite']);
});

test('a server preset is found by the framework an app stores, in any case, and next finds Next.js, while static-only presets are never an app\'s framework', function () {
    $presets = app(JsPresets::class);

    expect($presets->find('next')?->key())->toBe('nextjs')
        ->and($presets->find('SvelteKit')?->key())->toBe('sveltekit')
        ->and($presets->find(''))->toBeNull()
        ->and($presets->find('express'))->toBeNull()
        ->and($presets->find('React')?->key())->toBe('react')
        ->and($presets->find('vue')?->key())->toBe('vue')
        ->and($presets->find('svelte')?->key())->toBe('svelte')
        ->and($presets->find('vite'))->toBeNull()
        ->and($presets->find('react-scripts'))->toBeNull();
});

test('only server presets name the file they start from, and only SvelteKit adds to the environment', function () {
    $presets = app(JsPresets::class)->all();

    expect(array_map(fn(JsPreset $preset): ?string => $preset->entry(), $presets))->toBe(['build/index.js', null, '.output/server/index.mjs', 'dist/server/entry.mjs', 'build/bin/server.js', null, null, null, null, null])
        ->and(array_map(fn(JsPreset $preset): array => array_keys($preset->environment()), $presets))->toBe([['PROTOCOL_HEADER', 'HOST_HEADER', 'ADDRESS_HEADER'], [], [], [], ['LOG_LEVEL', 'SESSION_DRIVER'], [], [], [], [], []]);
});

test('Add an app offers the presets as JavaScript frameworks, by name, saying which ones are served as files', function () {
    expect(app(JsPresets::class)->choices())->toBe([
        ['value' => 'sveltekit', 'label' => 'SvelteKit', 'files' => false],
        ['value' => 'nextjs', 'label' => 'Next.js', 'files' => false],
        ['value' => 'nuxt', 'label' => 'Nuxt', 'files' => false],
        ['value' => 'astro', 'label' => 'Astro', 'files' => false],
        ['value' => 'adonisjs', 'label' => 'AdonisJS', 'files' => false],
        ['value' => 'angular', 'label' => 'Angular', 'files' => false],
        ['value' => 'react', 'label' => 'React', 'files' => true],
        ['value' => 'vue', 'label' => 'Vue', 'files' => true],
        ['value' => 'svelte', 'label' => 'Svelte', 'files' => true],
    ]);
});

test('React, Vue and Svelte build to files Coresmith serves, so they run no process of their own', function () {
    expect(array_map(fn(JsPreset $preset): bool => $preset->servesFiles(), app(JsPresets::class)->all()))
        ->toBe([false, false, false, false, false, false, true, true, true, false]);
});

test('a JavaScript framework is called by its preset name in detection and on the app page', function () {
    expect((new DetectedStack('js', 'nuxt', 'node', null))->label(app(Catalog::class)))->toBe('Nuxt')
        ->and((new DetectedStack('js', '', 'node', null))->label(app(Catalog::class)))->toBe('Other server')
        ->and(ApplicationPresenter::kind(new Application(['type' => ApplicationType::Js, 'framework' => 'next'])))->toBe('Next.js')
        ->and(ApplicationPresenter::kind(new Application(['type' => ApplicationType::Js, 'framework' => 'astro'])))->toBe('Astro')
        ->and(ApplicationPresenter::kind(new Application(['type' => ApplicationType::Js, 'framework' => 'react'])))->toBe('React')
        ->and((new DetectedStack('js', 'vue', 'bun', '/dist'))->label(app(Catalog::class)))->toBe('Vue');
});

test('a framework whose build script does not build it is named with the right article', function () {
    $facts = new RepositoryFacts(['package.json' => (string) json_encode(['scripts' => ['build' => 'tsc'], 'dependencies' => ['astro' => '^5.0.0']])], complete: true);

    expect(ApplicationType::Js->language()->detect($facts)['unsettled']['framework'])->toBe("astro is in package.json, but the build script doesn't run astro build, so Coresmith can't tell this is an Astro app.");
});

test('a start-script server is called by the server package it uses, and an unlisted package by its own name', function () {
    expect((new DetectedStack('js', '', 'node', null, serverPackage: 'express'))->label(app(Catalog::class)))->toBe('Express')
        ->and((new DetectedStack('js', '', 'node', null, serverPackage: '@nestjs/core'))->label(app(Catalog::class)))->toBe('NestJS')
        ->and((new DetectedStack('js', '', 'bun', null, serverPackage: 'elysia'))->label(app(Catalog::class)))->toBe('Elysia')
        ->and((new DetectedStack('js', '', 'node', null, serverPackage: 'some-server'))->label(app(Catalog::class)))->toBe('some-server');
});

test('detection names the generic JavaScript entry Other server, as Add an app does', function () {
    $code = collect(File::allFiles(app_path('Stacks')))->merge(File::allFiles(app_path('Services/Detection')))
        ->map(fn(SplFileInfo $file): string => $file->getContents())
        ->implode("\n");

    expect($code)->not->toContain('API or other')
        ->and(JavaScriptDetection::NO_SERVER)->toContain('Pick Other server');
});
