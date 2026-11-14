<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Runtime\NativeGemTools;
use App\Services\Runtime\NativeGoTools;
use App\Services\Runtime\NativePythonTools;
use App\Services\Runtime\RepositoryFacts;
use App\Stacks\Go;
use App\Stacks\Java;
use App\Stacks\JavaScript;
use App\Stacks\Language;
use App\Stacks\Php;
use App\Stacks\Python;
use App\Stacks\Route;
use App\Stacks\Ruby;
use App\Stacks\StaticSite;
use App\Stacks\StorageSeed;

test('each application type speaks through its own language provider', function () {
    $providers = array_map(fn(ApplicationType $type): Language => $type->language(), ApplicationType::cases());

    expect(array_map(fn(Language $language): string => $language::class, $providers))
        ->toBe([Php::class, JavaScript::class, Ruby::class, StaticSite::class, Python::class, Go::class, Java::class]);
});

test('only PHP apps run under PHP-FPM, and only JavaScript and Ruby apps run a web process of their own', function () {
    $application = fn(ApplicationType $type): Application => new Application(['type' => $type, 'slug' => 'demo', 'base_port' => 4100, 'ruby_version' => '3.4.1', 'bun_version' => '1.2.20']);

    expect(array_map(fn(ApplicationType $type): bool => $type->language()->usesFpm(), ApplicationType::cases()))->toBe([true, false, false, false, false, false, false])
        ->and(array_map(fn(ApplicationType $type): ?string => $type->language()->webCommand($application($type)), ApplicationType::cases()))->toBe([
            null,
            '/opt/asdf-data/installs/bun/1.2.20/bin/bun server.js',
            '/opt/asdf-data/installs/ruby/3.4.1/bin/bundle exec puma -b tcp://127.0.0.1:4100',
            null,
            null,
            null,
            null,
        ]);
});

test('PHP apps are handed to PHP-FPM, static sites to the file server and the rest to their own port', function () {
    expect(array_map(fn(ApplicationType $type): Route => $type->language()->route(new Application(['type' => $type])), ApplicationType::cases()))
        ->toBe([Route::PhpFastcgi, Route::ReverseProxy, Route::ReverseProxy, Route::FileServer, Route::ReverseProxy, Route::ReverseProxy, Route::ReverseProxy])
        ->and(array_map(fn(Route $route): bool => $route->servesFiles(), Route::cases()))->toBe([true, false, false, true]);
});

test('Ruby and Python name the system packages their dependencies build against, and the others need none', function () {
    expect(array_map(fn(ApplicationType $type): ?string => $type->language()->nativeTools() === null ? null : $type->language()->nativeTools()::class, ApplicationType::cases()))
        ->toBe([null, null, NativeGemTools::class, null, NativePythonTools::class, NativeGoTools::class, null]);
});

test('PHP seeds shared storage from the release, Ruby from the live release, and the others share none', function () {
    expect(array_map(fn(ApplicationType $type): StorageSeed => $type->language()->storageSeed(), ApplicationType::cases()))
        ->toBe([StorageSeed::FromRelease, StorageSeed::None, StorageSeed::FromLiveRelease, StorageSeed::None, StorageSeed::None, StorageSeed::None, StorageSeed::None]);
});

test('each language names the environment its app starts with, runs with and listens on', function () {
    $application = fn(ApplicationType $type): Application => new Application(['type' => $type, 'framework' => null, 'base_port' => 4100]);

    expect(array_map(fn(ApplicationType $type): array => array_keys($type->language()->initialEnvironment(match ($type) {
        ApplicationType::Php => 'laravel',
        ApplicationType::Ruby => 'rails',
        default => null,
    })), ApplicationType::cases()))->toBe([['APP_KEY'], [], ['SECRET_KEY_BASE'], [], [], [], []])
        ->and(array_map(fn(ApplicationType $type): array => $type->language()->processEnvironment($application($type)), ApplicationType::cases()))->toBe([
            [],
            ['NODE_ENV' => 'production'],
            ['RAILS_ENV' => 'production', 'RACK_ENV' => 'production', 'RAILS_SERVE_STATIC_FILES' => '1', 'RAILS_LOG_TO_STDOUT' => '1'],
            [],
            ['PYTHONUNBUFFERED' => '1', 'WEB_CONCURRENCY' => '2'],
            [],
            ['JAVA_OPTS' => '-XX:MaxRAMPercentage=75'],
        ])
        ->and(array_map(fn(ApplicationType $type): array => $type->language()->listenEnvironment($application($type)), ApplicationType::cases()))->toBe([
            [],
            ['PORT' => '4100', 'HOST' => '127.0.0.1', 'HOSTNAME' => '127.0.0.1'],
            ['PORT' => '4100', 'HOST' => '127.0.0.1'],
            [],
            ['PORT' => '4100', 'HOST' => '127.0.0.1'],
            ['PORT' => '4100', 'HOST' => '127.0.0.1'],
            ['PORT' => '4100', 'HOST' => '127.0.0.1'],
        ]);
});

test('each language detects only the repositories written in it', function (Closure $facts, array $types) {
    expect(array_map(fn(ApplicationType $type): ?string => $type->language()->detect($facts())['type'] ?? null, ApplicationType::cases()))->toBe($types);
})->with([
    'composer.json and public/index.php' => [fn(): RepositoryFacts => new RepositoryFacts(['composer.json' => '{"require":{"slim/slim":"^4.12"}}'], markers: ['public/index.php'], complete: true), ['php', null, null, null, null, null, null]],
    'a package.json server' => [fn(): RepositoryFacts => new RepositoryFacts(['package.json' => '{"scripts":{"start":"node server.js"},"dependencies":{"express":"^5.0.0"}}'], complete: true), [null, 'js', null, null, null, null, null]],
    'a Sinatra Gemfile' => [fn(): RepositoryFacts => new RepositoryFacts(['Gemfile' => "gem 'sinatra'\n"], markers: ['config.ru'], complete: true), [null, null, 'ruby', null, null, null, null]],
    'index.html alone' => [fn(): RepositoryFacts => new RepositoryFacts([], markers: ['index.html'], complete: true), [null, null, null, 'static', null, null, null]],
    'a go.mod' => [fn(): RepositoryFacts => new RepositoryFacts(['go.mod' => "module example.com/shop\n\ngo 1.26\n"], complete: true), [null, null, null, null, null, 'go', null]],
    'a pom.xml' => [fn(): RepositoryFacts => new RepositoryFacts(['pom.xml' => '<project><artifactId>shop</artifactId></project>'], complete: true), [null, null, null, null, null, null, 'java']],
]);
