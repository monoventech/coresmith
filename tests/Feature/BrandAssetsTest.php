<?php

declare(strict_types=1);

use App\Models\Team;

beforeEach(fn() => config(['inertia.ssr.enabled' => false]));

test('every page names the official icons, the web manifest and the share card', function () {
    $html = (string) $this->get(route('login'))->assertOk()->getContent();

    expect($html)->toContain('<link rel="icon" href="/favicon.ico" sizes="32x32">')
        ->toContain('<link rel="icon" href="/favicon.svg" type="image/svg+xml">')
        ->toContain('<link rel="apple-touch-icon" href="/apple-touch-icon.png">')
        ->toContain('<link rel="manifest" href="/site.webmanifest">')
        ->toContain('<meta property="og:image" content="http://localhost/og-image.png">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">');
});

test('every icon the head and the web manifest name is in public', function () {
    $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    $icons = array_map(fn(array $icon): string => ltrim((string) $icon['src'], '/'), $manifest['icons']);

    expect($manifest['name'])->toBe('Coresmith')
        ->and($icons)->toBe(['icon-192.png', 'icon-512.png', 'icon-maskable-512.png']);

    foreach (['favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'og-image.png', ...$icons] as $file) {
        expect(is_file(public_path($file)))->toBeTrue($file . ' is missing from public');
    }
});

test('a page under a team address keeps the share card at the root of the host', function () {
    $team = Team::factory()->owned()->create(['slug' => 'forgeworks']);

    $html = (string) $this->actingAs($team->owner)->get('/forgeworks/dashboard')->assertOk()->getContent();

    expect($html)->toContain('<meta property="og:image" content="http://localhost/og-image.png">')
        ->not->toContain('/forgeworks/og-image.png');
});
