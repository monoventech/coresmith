<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Stacks\JavaScript;
use App\Stacks\Language;
use App\Stacks\Php;
use App\Stacks\Ruby;
use App\Stacks\StaticSite;

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
