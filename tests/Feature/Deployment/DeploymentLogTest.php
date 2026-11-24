<?php

declare(strict_types=1);

use App\Enums\DeploymentStatus;
use App\Events\DeploymentLogAppended;
use App\Events\DeploymentUpdated;
use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * @return Collection<int, DeploymentLogAppended>
 */
function deploymentLogPieces(): Collection
{
    return Event::dispatched(DeploymentLogAppended::class)->map(fn(array $arguments): DeploymentLogAppended => $arguments[0])->values();
}

function deploymentLogRequestBytes(DeploymentLogAppended $event): int
{
    return strlen((string) json_encode([
        'name' => $event->broadcastAs(),
        'data' => (string) json_encode($event->broadcastWith()),
        'channels' => ['private-deployments.' . $event->deploymentId],
    ]));
}

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

test('an append broadcasts only the new text with the byte offset it starts at', function () {
    Event::fake([DeploymentLogAppended::class, DeploymentUpdated::class]);
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::Running, 'log' => '[12:00:00] build: building assets']);

    app(DeploymentLog::class)->append($deployment, "vite v7.1.0 building for production...\n");

    expect(deploymentLogPieces())->toHaveCount(1)
        ->and(deploymentLogPieces()[0]->deploymentId)->toBe($deployment->id)
        ->and(deploymentLogPieces()[0]->offset)->toBe(strlen('[12:00:00] build: building assets'))
        ->and(deploymentLogPieces()[0]->text)->toBe("\nvite v7.1.0 building for production...")
        ->and((string) $deployment->fresh()->log)->toBe("[12:00:00] build: building assets\nvite v7.1.0 building for production...");

    Event::assertDispatched(DeploymentUpdated::class);
});

test('the first text of a log starts at offset zero', function () {
    Event::fake([DeploymentLogAppended::class]);
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::Running, 'log' => '']);

    app(DeploymentLog::class)->append($deployment, "\n[12:00:00] release 20260913-140509\n");

    expect(deploymentLogPieces())->toHaveCount(1)
        ->and(deploymentLogPieces()[0]->offset)->toBe(0)
        ->and(deploymentLogPieces()[0]->text)->toBe('[12:00:00] release 20260913-140509')
        ->and((string) $deployment->fresh()->log)->toBe('[12:00:00] release 20260913-140509');
});

test('a long append is sent in pieces that each fit the socket and are cut between characters', function (string $line) {
    Event::fake([DeploymentLogAppended::class]);
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::Running, 'log' => '[12:00:00] build: building assets']);

    app(DeploymentLog::class)->append($deployment, implode("\n", array_fill(0, 400, $line)));

    $offset = strlen('[12:00:00] build: building assets');
    $text = '';

    expect(deploymentLogPieces()->count())->toBeGreaterThan(1);

    foreach (deploymentLogPieces() as $piece) {
        expect($piece->offset)->toBe($offset)
            ->and(mb_check_encoding($piece->text, 'UTF-8'))->toBeTrue()
            ->and(deploymentLogRequestBytes($piece) + 1_000)->toBeLessThanOrEqual((int) config('reverb.servers.reverb.max_request_size'));

        $offset += strlen($piece->text);
        $text .= $piece->text;
    }

    expect('[12:00:00] build: building assets' . $text)->toBe((string) $deployment->fresh()->log);
})->with([
    'plain text' => ['dist/assets/chunk-1.js   12.34 kB │ gzip: 4.56 kB'],
    'quotes and backslashes' => ['npm warn "C:\\\\path\\\\to" said "\\" and "\\" again'],
    'characters outside ASCII' => ['✓ 编译成功 🚀 ✓ 编译成功 🚀 ✓ 编译成功 🚀'],
]);

test('output that is not valid UTF-8 is kept with the broken bytes replaced, so the log can be saved and sent', function () {
    Event::fake([DeploymentLogAppended::class]);
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::Running, 'log' => '']);

    app(DeploymentLog::class)->append($deployment, "caf\xE9 au lait\n");

    expect((string) $deployment->fresh()->log)->toBe('caf? au lait')
        ->and(deploymentLogPieces()[0]->text)->toBe('caf? au lait');
});

test('streamed output sends the new text without a status update', function () {
    Event::fake([DeploymentLogAppended::class, DeploymentUpdated::class]);
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::Running, 'log' => '$ npm ci']);

    app(DeploymentLog::class)->stream($deployment, 'added 1204 packages in 41s');

    expect(deploymentLogPieces())->toHaveCount(1)
        ->and(deploymentLogPieces()[0]->offset)->toBe(8)
        ->and(deploymentLogPieces()[0]->text)->toBe("\nadded 1204 packages in 41s");

    Event::assertNotDispatched(DeploymentUpdated::class);
});

test('output that is only blank lines changes nothing and sends nothing', function () {
    Event::fake([DeploymentLogAppended::class, DeploymentUpdated::class]);
    $deployment = Deployment::factory()->create(['status' => DeploymentStatus::Running, 'log' => '$ npm ci']);

    app(DeploymentLog::class)->append($deployment, "\n  \n");

    expect((string) $deployment->fresh()->log)->toBe('$ npm ci');

    Event::assertNothingDispatched();
});
