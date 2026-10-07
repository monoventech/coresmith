<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;

function supervisorTimeoutQueuedJobs(): array
{
    $jobs = [];

    foreach (File::allFiles(app_path()) as $file) {
        $relativePath = $file->getRelativePathname();
        if (!str_ends_with($relativePath, '.php')) {
            continue;
        }

        $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);

        if (!class_exists($class) || !in_array(ShouldQueue::class, class_implements($class) ?: [], true)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->hasProperty('timeout')) {
            continue;
        }

        $timeout = (int) $reflection->getProperty('timeout')->getDefaultValue();
        $source = $file->getContents();

        $queue = match (true) {
            preg_match('/onQueue\(\s*\'([^\']+)\'\s*\)/', $source, $matches) === 1 => $matches[1],
            preg_match('/\$queue\s*=\s*\'([^\']+)\'/', $source, $matches) === 1 => $matches[1],
            default => 'default',
        };

        $jobs[] = ['class' => $class, 'queue' => $queue, 'timeout' => $timeout];
    }

    return $jobs;
}

function supervisorTimeoutFor(string $queue): int
{
    foreach (config('horizon.defaults') as $supervisor) {
        if (in_array($queue, $supervisor['queue'], true)) {
            return (int) $supervisor['timeout'];
        }
    }

    throw new RuntimeException("No Horizon supervisor covers the \"{$queue}\" queue.");
}

test('every queued job finishes within its supervisor timeout', function () {
    $jobs = supervisorTimeoutQueuedJobs();

    expect($jobs)->not->toBeEmpty();

    foreach ($jobs as $job) {
        $limit = supervisorTimeoutFor($job['queue']);

        expect($job['timeout'])->toBeLessThanOrEqual($limit, "{$job['class']} times out after {$job['timeout']}s on the \"{$job['queue']}\" queue, past its supervisor's {$limit}s timeout");
    }
});
