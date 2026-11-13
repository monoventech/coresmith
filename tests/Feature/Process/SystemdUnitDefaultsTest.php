<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Deployment\AppShell;
use App\Services\Process\SystemdUnitBuilder;

function unitDefaultsApp(array $attributes): Application
{
    return Application::factory()->create(array_merge([
        'slug' => 'shop',
        'system_user' => 'coresmith-app-shop',
        'php_version' => null,
        'bun_version' => '1.4.2',
        'ruby_version' => '3.3.6',
        'base_port' => 8120,
    ], $attributes));
}

test('process defaults sit before EnvironmentFile so the app\'s environment overrides them', function (array $attributes, array $defaults, array $absent) {
    $unit = app(SystemdUnitBuilder::class)->build(unitDefaultsApp($attributes), 'web', '/bin/true');
    $file = strpos($unit, "\nEnvironmentFile=");

    foreach ($defaults as $name => $value) {
        expect($unit)->toContain("Environment=\"{$name}={$value}\"\n")
            ->and(strpos($unit, "Environment=\"{$name}={$value}\""))->toBeLessThan($file);
    }

    foreach ($absent as $name) {
        expect($unit)->not->toContain($name . '=');
    }
})->with([
    'sveltekit' => [['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'bun'], ['NODE_ENV' => 'production', 'PROTOCOL_HEADER' => 'x-forwarded-proto', 'HOST_HEADER' => 'x-forwarded-host', 'ADDRESS_HEADER' => 'x-forwarded-for'], ['RAILS_ENV']],
    'next.js' => [['type' => ApplicationType::Js, 'framework' => 'nextjs', 'js_runtime' => 'bun'], ['NODE_ENV' => 'production'], ['PROTOCOL_HEADER', 'RAILS_ENV']],
    'rails' => [['type' => ApplicationType::Ruby, 'framework' => 'rails'], ['RAILS_ENV' => 'production', 'RACK_ENV' => 'production', 'RAILS_SERVE_STATIC_FILES' => '1', 'RAILS_LOG_TO_STDOUT' => '1'], ['NODE_ENV']],
    'sinatra' => [['type' => ApplicationType::Ruby, 'framework' => 'sinatra'], ['RACK_ENV' => 'production'], ['RAILS_SERVE_STATIC_FILES', 'NODE_ENV']],
    'php' => [['type' => ApplicationType::Php, 'framework' => 'laravel', 'php_version' => '8.4.13'], [], ['NODE_ENV', 'RAILS_ENV', 'RACK_ENV']],
]);

test('NODE_ENV stays off the build, so dev dependencies still install', function () {
    $application = unitDefaultsApp(['type' => ApplicationType::Js, 'framework' => 'sveltekit', 'js_runtime' => 'bun']);

    expect(app(SystemdUnitBuilder::class)->build($application, 'web', '/bin/true'))->toContain('Environment="NODE_ENV=production"')
        ->and(app(AppShell::class)->run($application, 'bun run build', '/tmp'))->not->toContain('NODE_ENV');
});
