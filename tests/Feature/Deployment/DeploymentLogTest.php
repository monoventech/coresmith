<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentLog;

test('a failure is still written to the deployment when the application cannot be saved', function () {
    $application = Application::factory()->create(['current_release' => null]);
    $deployment = Deployment::factory()->for($application)->create(['status' => DeploymentStatus::Running, 'log' => '[12:00:00] deps: installing dependencies']);

    Application::saving(function (Application $saving) use ($application): void {
        if ($saving->is($application)) {
            throw new RuntimeException('The database went away.');
        }
    });

    expect(fn() => app(DeploymentLog::class)->fail($deployment, 'composer install exited with 1', 'Composer could not install the dependencies. Fix composer.json, then deploy again.'))
        ->toThrow(RuntimeException::class, 'The database went away.');

    $fresh = $deployment->fresh();

    expect($fresh->status)->toBe(DeploymentStatus::Failed)
        ->and($fresh->failure_reason)->toBe('Composer could not install the dependencies. Fix composer.json, then deploy again.')
        ->and($fresh->log)->toContain('composer install exited with 1')
        ->and($fresh->log)->toEndWith(' deploy failed. the current release is untouched.');
});

test('a deploy log longer than 64 KB is kept whole', function () {
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::Running, 'log' => '[12:00:00] build: building assets']);
    $output = implode("\n", array_map(fn(int $line): string => "dist/assets/chunk-{$line}.js   12.34 kB │ gzip: 4.56 kB", range(1, 2000)));

    app(DeploymentLog::class)->append($deployment, $output);

    $log = (string) $deployment->fresh()->log;

    expect(strlen($log))->toBeGreaterThan(65535)
        ->and($log)->toStartWith('[12:00:00] build: building assets')
        ->and($log)->toEndWith('dist/assets/chunk-2000.js   12.34 kB │ gzip: 4.56 kB');
});
