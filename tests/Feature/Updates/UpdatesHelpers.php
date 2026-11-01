<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function updatesRegistry(): stdClass
{
    static $registry = null;

    return $registry ??= (object) ['base' => null, 'answers' => [], 'faked' => []];
}

function updatesUseTemporaryPaths(): array
{
    $base = realpath(sys_get_temp_dir()) . '/csu-' . Str::lower(Str::random(8));
    $paths = ['base' => $base, 'root' => "{$base}/root", 'state' => "{$base}/state", 'keys' => "{$base}/keys"];

    mkdir($paths['root'], 0700, true);
    mkdir($paths['keys'], 0700, true);

    config([
        'coresmith.updates.root' => $paths['root'],
        'coresmith.updates.state_path' => $paths['state'],
        'coresmith.updates.keys_path' => $paths['keys'],
    ]);

    updatesRegistry()->base = $base;

    return $paths;
}

function updatesCleanUp(): void
{
    $registry = updatesRegistry();

    if (is_string($registry->base) && is_dir($registry->base)) {
        File::deleteDirectory($registry->base);
    }

    $registry->base = null;
    $registry->answers = [];
    $registry->faked = [];
}

function updatesAnswer(string $url, array|string|Closure $answer, int $status = 200): void
{
    $registry = updatesRegistry();
    $registry->answers[$url] = [$answer, $status];

    if (in_array($url, $registry->faked, true)) {
        return;
    }

    $registry->faked[] = $url;

    Http::fake([$url => function () use ($url) {
        [$answer, $status] = updatesRegistry()->answers[$url];

        return $answer instanceof Closure ? $answer() : Http::response($answer, $status);
    }]);
}

function updatesFeed(array|string|Closure $answer, int $status = 200): void
{
    updatesAnswer((string) config('coresmith.updates.check_url'), $answer, $status);
}
