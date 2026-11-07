<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('the probe tells loopback which release is serving', function () {
    $this->getJson(route('release.probe'))
        ->assertOk()
        ->assertExactJson(['release' => basename(base_path()), 'version' => config('coresmith.version')]);
});

test('the probe is invisible to anyone else', function (string $address) {
    $this->withServerVariables(['REMOTE_ADDR' => $address])->getJson(route('release.probe'))->assertNotFound();
})->with(['203.0.113.9', '10.0.0.5', '127.0.0.2']);

test('a forwarded header cannot make a remote caller look like loopback, even behind trusted proxies', function () {
    TrustProxies::at('*');

    try {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-For' => '127.0.0.1'])
            ->getJson(route('release.probe'))
            ->assertNotFound();
    } finally {
        TrustProxies::flushState();
    }
});

test('the probe answers on ipv6 loopback', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '::1'])->getJson(route('release.probe'))->assertOk();
});

test('the probe answers while the panel is in maintenance', function () {
    $storage = realpath(sys_get_temp_dir()) . '/csu-storage-' . Str::lower(Str::random(8));
    mkdir("{$storage}/framework", 0700, true);
    $this->app->useStoragePath($storage);

    try {
        Artisan::call('down');

        $down = json_decode((string) file_get_contents("{$storage}/framework/down"), true);

        expect($down['except'] ?? [])->toContain('api/up/release');

        $this->getJson(route('release.probe'))->assertOk();
        $this->get('/')->assertStatus(503);
    } finally {
        Artisan::call('up');
        File::deleteDirectory($storage);
    }
});

test('the probe answers before anyone has set the instance up', function () {
    config(['fortify.features' => []]);

    $this->getJson(route('release.probe'))->assertOk();
});

test('the probe is rate limited', function () {
    expect(Route::getRoutes()->getByName('release.probe')->gatherMiddleware())->toContain('throttle:60,1,release-probe');
});
