<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Php\PhpLimits;

function phpLimitsApp(array $attributes = []): Application
{
    return Application::factory()->make([...['slug' => 'shop', 'type' => ApplicationType::Php, 'framework' => 'laravel', 'app_size' => 'small', 'php_limits' => null], ...$attributes]);
}

test('a new app gets 64 MB uploads, half its size in memory and a minute per request', function (string $size, int $memory, int $cap) {
    $limits = PhpLimits::for(phpLimitsApp(['app_size' => $size]));

    expect($limits->uploads)->toBe(64)
        ->and($limits->memory)->toBe($memory)
        ->and($limits->memoryCap)->toBe($cap)
        ->and($limits->seconds)->toBe(60);
})->with([
    'small' => ['small', 128, 256],
    'medium' => ['medium', 256, 512],
    'large' => ['large', 512, 1024],
    'custom' => ['custom', 128, 256],
]);

test('framework apps load new code at each deploy, and plain PHP apps check their files', function (?string $framework, string $reload) {
    expect(PhpLimits::for(phpLimitsApp(['framework' => $framework]))->reload)->toBe($reload);
})->with([
    'laravel' => ['laravel', 'deploy'],
    'symfony' => ['symfony', 'deploy'],
    'plain php' => [null, 'timestamps'],
]);

test('stored limits win over the defaults', function () {
    $limits = PhpLimits::for(phpLimitsApp(['app_size' => 'medium', 'php_limits' => ['uploads' => 256, 'memory' => 512, 'seconds' => 300, 'reload' => PhpLimits::RELOAD_TIMESTAMPS]]));

    expect([$limits->uploads, $limits->memory, $limits->seconds, $limits->reload])->toBe([256, 512, 300, PhpLimits::RELOAD_TIMESTAMPS]);
});

test('memory stored above what the app size allows is held to the size, and the card says so', function () {
    $limits = PhpLimits::for(phpLimitsApp(['app_size' => 'small', 'php_limits' => ['uploads' => 64, 'memory' => 1024, 'seconds' => 60, 'reload' => PhpLimits::RELOAD_DEPLOY]]));

    expect($limits->memory)->toBe(256)
        ->and($limits->held)->toBeTrue();
});

test('a stored value that is not one of the choices falls back to its default', function () {
    $limits = PhpLimits::for(phpLimitsApp(['php_limits' => ['uploads' => 7, 'memory' => 'lots', 'seconds' => 45, 'reload' => 'never']]));

    expect([$limits->uploads, $limits->memory, $limits->seconds, $limits->reload])->toBe([64, 128, 60, PhpLimits::RELOAD_DEPLOY]);
});

test('memory choices stop at what the app size allows', function (string $size, array $choices) {
    expect(PhpLimits::for(phpLimitsApp(['app_size' => $size]))->memoryChoices())->toBe($choices);
})->with([
    'small' => ['small', [64, 128, 256]],
    'medium' => ['medium', [64, 128, 256, 512]],
    'large' => ['large', [64, 128, 256, 512, 1024]],
]);

test('the ini file sets uploads, requests, memory, time and how code reloads', function () {
    expect(PhpLimits::for(phpLimitsApp())->ini())->toBe(implode("\n", [
        '; Managed by Coresmith for shop. Change these on the app page.',
        'upload_max_filesize = 64M',
        'post_max_size = 64M',
        'memory_limit = 128M',
        'max_execution_time = 60',
        'max_input_time = 60',
        'opcache.validate_timestamps = 0',
        'opcache.revalidate_freq = 2',
    ]) . "\n");
});

test('checking files every 2 seconds turns timestamp checks back on', function () {
    $ini = PhpLimits::for(phpLimitsApp(['framework' => null]))->ini();

    expect($ini)->toContain("opcache.validate_timestamps = 1\n");
});

test('each app has its own folder for the file', function () {
    expect(PhpLimits::directory(phpLimitsApp(['slug' => 'shop-2'])))->toBe('/etc/coresmith/php/shop-2')
        ->and(PhpLimits::scanDir(phpLimitsApp(['slug' => 'shop-2'])))->toBe(':/etc/coresmith/php/shop-2');
});
