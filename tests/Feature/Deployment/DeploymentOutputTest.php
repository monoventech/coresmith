<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentLog;
use App\Services\Deployment\DeploymentOutput;

/**
 * @return array{0: DeploymentOutput, 1: Closure(float): void, 2: Closure(): list<string>}
 */
function deploymentOutputHarness(?Closure $flush = null): array
{
    $clock = 0.0;
    $flushed = [];
    $output = new DeploymentOutput($flush ?? function (string $text) use (&$flushed): void {
        $flushed[] = $text;
    }, function () use (&$clock): float {
        return $clock;
    });

    return [
        $output,
        function (float $milliseconds) use (&$clock): void {
            $clock += $milliseconds;
        },
        function () use (&$flushed): array {
            return $flushed;
        },
    ];
}

test('a partial line waits until it ends', function () {
    [$output, $wait, $flushed] = deploymentOutputHarness();

    $output->write('npm ');
    $wait(300);
    $output->write("ci\n");

    expect($flushed())->toBe(['npm ci']);
});

test('lines arriving close together wait for the quarter second, unless 4 KB are waiting', function () {
    [$output, $wait, $flushed] = deploymentOutputHarness();

    $output->write("one\n");
    $wait(100);
    $output->write("two\n");

    expect($flushed())->toBe([]);

    $wait(200);
    $output->write("three\n");

    expect($flushed())->toBe(["one\ntwo\nthree"]);

    $output->write(str_repeat("x\n", DeploymentOutput::FLUSH_BYTES / 2 + 10));

    expect($flushed())->toHaveCount(2);
});

test('finishing flushes the rest and ends a partial line', function () {
    [$output, , $flushed] = deploymentOutputHarness();

    $output->write("built\ndone");
    $output->finish();

    expect($flushed())->toBe(["built\ndone"]);
});

test('finishing with nothing waiting flushes nothing', function () {
    [$output, , $flushed] = deploymentOutputHarness();

    $output->finish();

    expect($flushed())->toBe([]);
});

test('a line rewritten with carriage returns keeps its last state, and Windows line endings become plain ones', function () {
    [$output, $wait, $flushed] = deploymentOutputHarness();

    $wait(300);
    $output->write("10%\r50%\r100%\nnext\r\n");
    $output->finish();

    expect($flushed())->toBe(["100%\nnext"]);
});

test('a line longer than 16 KB is flushed as it is', function () {
    [$output, , $flushed] = deploymentOutputHarness();

    $output->write(str_repeat('a', DeploymentOutput::LONG_LINE_BYTES + 100));

    expect($flushed())->toHaveCount(1)
        ->and(strlen($flushed()[0]))->toBe(DeploymentOutput::LONG_LINE_BYTES + 100);
});

test('a character split across two chunks arrives whole', function () {
    [$output, $wait, $flushed] = deploymentOutputHarness();

    $wait(300);
    $output->write("ok \xE2\x9C");
    $output->write("\x93\n");

    expect($flushed())->toBe(['ok ✓']);
});

test('a secret split across two chunks is still masked in the log', function () {
    $application = Application::factory()->create(['env_variables' => "APP_SECRET=s3cr3t-value-xyz\n"]);
    $deployment = Deployment::factory()->create(['application_id' => $application->id, 'log' => '']);
    [$output, $wait] = deploymentOutputHarness(fn(string $text) => app(DeploymentLog::class)->append($deployment, $text));

    $wait(300);
    $output->write('connecting with s3cr');
    $output->write("3t-value-xyz to the API\n");
    $output->finish();

    expect((string) $deployment->fresh()->log)->toContain('[REDACTED:APP_SECRET]')
        ->not->toContain('s3cr3t-value-xyz')
        ->not->toContain('s3cr');
});
