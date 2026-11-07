<?php

declare(strict_types=1);

use App\Enums\ApplicationType;
use App\Exceptions\InvalidRuntimeException;
use App\Models\Application;
use App\Services\Deployment\CrontabBuilder;
use App\Services\Deployment\DeploymentCommands;
use App\Services\Process\SystemdUnitBuilder;
use Illuminate\Support\Facades\DB;

test('an app lists the runtimes its live release runs, in a fixed order', function () {
    $application = new Application(['ruby_version' => '3.4.10', 'php_version' => '8.4.20', 'bun_version' => '1.4.2', 'node_version' => null]);

    expect($application->runtimeVersions())->toBe(['php' => '8.4.20', 'bun' => '1.4.2', 'ruby' => '3.4.10']);
});

test('commands run with every runtime of the release on PATH', function () {
    expect(app(DeploymentCommands::class)->pathPrefix(['php' => '8.4.20', 'bun' => '1.4.2']))
        ->toBe('PATH=/opt/asdf-data/installs/php/8.4.20/bin:/opt/asdf-data/installs/bun/1.4.2/bin:$PATH ')
        ->and(app(DeploymentCommands::class)->pathPrefix([]))->toBe('');
});

test('a version that could reach the shell is refused before it is used', function () {
    app(DeploymentCommands::class)->pathPrefix(['php' => '8.4; rm -rf /']);
})->throws(InvalidRuntimeException::class);

test('the crontab and every unit get the same PATH as the deploy', function () {
    $application = Application::factory()->create([
        'slug' => 'shop',
        'php_version' => '8.4.20',
        'bun_version' => '1.4.2',
        'cron_jobs' => [['schedule' => '* * * * *', 'command' => 'php artisan schedule:run']],
    ]);

    expect(app(CrontabBuilder::class)->build($application))->toContain('PATH=/opt/asdf-data/installs/php/8.4.20/bin:/opt/asdf-data/installs/bun/1.4.2/bin:/usr/local/bin:/usr/bin:/bin')
        ->and(app(SystemdUnitBuilder::class)->build($application, 'queue', 'php artisan queue:work'))
        ->toContain('Environment="PATH=/opt/asdf-data/installs/php/8.4.20/bin:/opt/asdf-data/installs/bun/1.4.2/bin:/usr/local/bin:/usr/bin:/bin"')
        ->toContain("\nExecStart=/usr/bin/env php artisan queue:work\n");
});

test('a bun or deno app starts from its own runtime, not from node', function (string $runtime, array $attributes, string $command) {
    $application = Application::factory()->create(['slug' => 'shop', 'type' => ApplicationType::Js, 'framework' => null, 'js_runtime' => $runtime, 'php_version' => null, ...$attributes]);

    expect(app(SystemdUnitBuilder::class)->webCommand($application))->toBe($command);
})->with([
    'bun' => ['bun', ['bun_version' => '1.4.2', 'node_version' => '24.21.0'], '/opt/asdf-data/installs/bun/1.4.2/bin/bun server.js'],
    'deno' => ['deno', ['deno_version' => '2.9.7'], '/opt/asdf-data/installs/deno/2.9.7/bin/deno run --allow-net --allow-read --allow-env main.ts'],
    'node' => ['node', ['node_version' => '24.21.0'], '/opt/asdf-data/installs/nodejs/24.21.0/bin/node server.js'],
]);

test('a version chosen before this release is kept as the app\'s chosen version', function () {
    $migration = require database_path('migrations/2026_09_25_100000_add_runtime_stack.php');
    $migration->down();

    $id = Application::factory()->create(['php_version' => '8.4.20', 'node_version' => '22.16.0'])->id;

    $migration->up();

    expect(json_decode((string) DB::table('applications')->where('id', $id)->value('runtime_pins'), true))->toBe(['php' => '8.4.20', 'nodejs' => '22.16.0']);
});
