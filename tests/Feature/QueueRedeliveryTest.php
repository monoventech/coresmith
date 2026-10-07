<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\File;

function redeliveryLongestQueuedClass(): array
{
    $longest = ['class' => null, 'timeout' => 0];

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

        if ($timeout > $longest['timeout']) {
            $longest = ['class' => $class, 'timeout' => $timeout];
        }
    }

    return $longest;
}

test('no queue hands a job that is still running to a second worker', function (string $connection) {
    $longest = redeliveryLongestQueuedClass();

    expect($longest['timeout'])->toBeGreaterThan(0)
        ->and((int) config("queue.connections.{$connection}.retry_after"))
        ->toBeGreaterThanOrEqual($longest['timeout'] + 60, "{$longest['class']} may run for {$longest['timeout']} seconds");
})->with(['redis', 'database']);
