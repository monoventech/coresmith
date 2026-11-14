<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Stacks\JavaScript;
use App\Stacks\Language;
use App\Stacks\Php;
use App\Stacks\Route;
use App\Stacks\Ruby;
use App\Stacks\StaticSite;
use App\Stacks\StorageSeed;

test('each application type speaks through its own language provider', function () {
    $providers = array_map(fn(ApplicationType $type): Language => $type->language(), ApplicationType::cases());

    expect(array_map(fn(Language $language): string => $language::class, $providers))
        ->toBe([Php::class, JavaScript::class, Ruby::class, StaticSite::class]);
});

test('only PHP apps run under PHP-FPM, and only JavaScript and Ruby apps run a web process of their own', function () {
    $application = fn(ApplicationType $type): Application => new Application(['type' => $type, 'slug' => 'demo', 'base_port' => 4100, 'ruby_version' => '3.4.1', 'bun_version' => '1.2.20']);

    expect(array_map(fn(ApplicationType $type): bool => $type->language()->usesFpm(), ApplicationType::cases()))->toBe([true, false, false, false])
        ->and(array_map(fn(ApplicationType $type): ?string => $type->language()->webCommand($application($type)), ApplicationType::cases()))->toBe([
            null,
            '/opt/asdf-data/installs/bun/1.2.20/bin/bun server.js',
            '/opt/asdf-data/installs/ruby/3.4.1/bin/bundle exec puma -b tcp://127.0.0.1:4100',
            null,
        ]);
});

test('PHP apps are handed to PHP-FPM, static sites to the file server and the rest to their own port', function () {
    expect(array_map(fn(ApplicationType $type): Route => $type->language()->route(new Application(['type' => $type])), ApplicationType::cases()))
        ->toBe([Route::PhpFastcgi, Route::ReverseProxy, Route::ReverseProxy, Route::FileServer])
        ->and(array_map(fn(Route $route): bool => $route->servesFiles(), Route::cases()))->toBe([true, false, true]);
});

test('PHP seeds shared storage from the release, Ruby from the live release, and the others share none', function () {
    expect(array_map(fn(ApplicationType $type): StorageSeed => $type->language()->storageSeed(), ApplicationType::cases()))
        ->toBe([StorageSeed::FromRelease, StorageSeed::None, StorageSeed::FromLiveRelease, StorageSeed::None]);
});

test('each language names the environment its app starts with, runs with and listens on', function () {
    $application = fn(ApplicationType $type): Application => new Application(['type' => $type, 'framework' => null, 'base_port' => 4100]);

    expect(array_map(fn(ApplicationType $type): array => array_keys($type->language()->initialEnvironment(match ($type) {
        ApplicationType::Php => 'laravel',
        ApplicationType::Ruby => 'rails',
        default => null,
    })), ApplicationType::cases()))->toBe([['APP_KEY'], [], ['SECRET_KEY_BASE'], []])
        ->and(array_map(fn(ApplicationType $type): array => $type->language()->processEnvironment($application($type)), ApplicationType::cases()))->toBe([
            [],
            ['NODE_ENV' => 'production'],
            ['RAILS_ENV' => 'production', 'RACK_ENV' => 'production', 'RAILS_SERVE_STATIC_FILES' => '1', 'RAILS_LOG_TO_STDOUT' => '1'],
            [],
        ])
        ->and(array_map(fn(ApplicationType $type): array => $type->language()->listenEnvironment($application($type)), ApplicationType::cases()))->toBe([
            [],
            ['PORT' => '4100', 'HOST' => '127.0.0.1', 'HOSTNAME' => '127.0.0.1'],
            ['PORT' => '4100', 'HOST' => '127.0.0.1'],
            [],
        ]);
});
