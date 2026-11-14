<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Services\Panel\CronJobs;
use App\Services\Panel\SmartToggles;

function stackSettingsApp(array $attributes): Application
{
    return new Application(['name' => 'demo', 'slug' => 'demo', 'repository_url' => 'https://github.com/acme/demo', 'framework' => null, 'stack' => [], ...$attributes]);
}

test('each stack is offered only the smart toggles that apply to it', function (array $attributes, array $toggles) {
    expect(app(SmartToggles::class)->available(stackSettingsApp($attributes)))->toBe($toggles);
})->with([
    'plain PHP' => [['type' => ApplicationType::Php], ['maintenance_mode', 'auto_deploy']],
    'Laravel without Inertia' => [['type' => ApplicationType::Php, 'framework' => 'laravel', 'stack' => ['packages' => ['inertia' => false]]], ['queue_worker', 'scheduler', 'maintenance_mode', 'auto_deploy']],
    'Laravel with Inertia but no SSR build' => [['type' => ApplicationType::Php, 'framework' => 'laravel', 'stack' => ['packages' => ['inertia' => true], 'tooling' => ['ssr' => false]]], ['queue_worker', 'scheduler', 'maintenance_mode', 'auto_deploy']],
    'Laravel with Inertia SSR' => [['type' => ApplicationType::Php, 'framework' => 'laravel', 'stack' => ['packages' => ['inertia' => true], 'tooling' => ['ssr' => true]]], ['inertia_ssr', 'queue_worker', 'scheduler', 'maintenance_mode', 'auto_deploy']],
    'Laravel that turned SSR on, then dropped Inertia' => [['type' => ApplicationType::Php, 'framework' => 'laravel', 'stack' => ['packages' => ['inertia' => false]], 'toggles' => ['inertia_ssr' => true]], ['queue_worker', 'scheduler', 'maintenance_mode', 'auto_deploy']],
    'Laravel with Reverb' => [['type' => ApplicationType::Php, 'framework' => 'laravel', 'stack' => ['packages' => ['reverb' => true]]], ['queue_worker', 'scheduler', 'reverb', 'maintenance_mode', 'auto_deploy']],
    'Laravel that turned Reverb on, then removed it' => [['type' => ApplicationType::Php, 'framework' => 'laravel', 'stack' => ['packages' => ['reverb' => false]], 'toggles' => ['reverb' => true]], ['queue_worker', 'scheduler', 'maintenance_mode', 'auto_deploy']],
    'Symfony' => [['type' => ApplicationType::Php, 'framework' => 'symfony'], ['maintenance_mode', 'auto_deploy']],
    'Rails' => [['type' => ApplicationType::Ruby, 'framework' => 'rails', 'stack' => ['gems' => ['sidekiq' => false, 'solid_queue' => false]]], ['maintenance_mode', 'auto_deploy']],
    'Rails with Sidekiq' => [['type' => ApplicationType::Ruby, 'framework' => 'rails', 'stack' => ['gems' => ['sidekiq' => true]]], ['sidekiq', 'maintenance_mode', 'auto_deploy']],
    'Rails with Solid Queue' => [['type' => ApplicationType::Ruby, 'framework' => 'rails', 'stack' => ['gems' => ['solid_queue' => true]]], ['solid_queue', 'maintenance_mode', 'auto_deploy']],
    'Sinatra with a sidekiq gem' => [['type' => ApplicationType::Ruby, 'framework' => 'sinatra', 'stack' => ['gems' => ['sidekiq' => true]]], ['maintenance_mode', 'auto_deploy']],
    'Rack' => [['type' => ApplicationType::Ruby, 'framework' => 'rack'], ['maintenance_mode', 'auto_deploy']],
    'Next.js' => [['type' => ApplicationType::Js, 'framework' => 'nextjs', 'js_runtime' => 'node'], ['maintenance_mode', 'auto_deploy']],
    'a Node API' => [['type' => ApplicationType::Js, 'js_runtime' => 'node'], ['maintenance_mode', 'auto_deploy']],
    'a static site' => [['type' => ApplicationType::Static], ['maintenance_mode', 'auto_deploy']],
    'an app with no repository' => [['type' => ApplicationType::Php, 'repository_url' => null], ['maintenance_mode']],
]);

test('the cron dialog suggests a command in the app\'s own stack', function (array $attributes, string $example) {
    expect(app(CronJobs::class)->describe(stackSettingsApp($attributes))['example'])->toBe($example);
})->with([
    'Laravel' => [['type' => ApplicationType::Php, 'framework' => 'laravel'], 'php artisan backup:run'],
    'Symfony' => [['type' => ApplicationType::Php, 'framework' => 'symfony'], 'php bin/console app:send-reports'],
    'plain PHP' => [['type' => ApplicationType::Php], 'php cron/cleanup.php'],
    'Rails' => [['type' => ApplicationType::Ruby, 'framework' => 'rails'], 'bin/rails reports:send'],
    'Sinatra' => [['type' => ApplicationType::Ruby, 'framework' => 'sinatra'], 'bundle exec rake reports:send'],
    'Rack' => [['type' => ApplicationType::Ruby, 'framework' => 'rack'], 'bundle exec rake reports:send'],
    'Node' => [['type' => ApplicationType::Js, 'js_runtime' => 'node'], 'npm run cleanup'],
    'Bun' => [['type' => ApplicationType::Js, 'js_runtime' => 'bun'], 'bun run cleanup'],
    'Deno' => [['type' => ApplicationType::Js, 'js_runtime' => 'deno'], 'deno task cleanup'],
    'a static site' => [['type' => ApplicationType::Static], 'sh scripts/nightly.sh'],
]);
