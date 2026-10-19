<?php

declare(strict_types=1);

use App\Services\Deployment\RepositoryCache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function repositoryCacheRun(string $command, string $cwd): string
{
    $result = Process::path($cwd)
        ->env(['GIT_AUTHOR_NAME' => 'T', 'GIT_AUTHOR_EMAIL' => 't@example.com', 'GIT_COMMITTER_NAME' => 'T', 'GIT_COMMITTER_EMAIL' => 't@example.com'])
        ->run($command);

    expect($result->successful())->toBeTrue($result->errorOutput());

    return trim($result->output());
}

function repositoryCacheSandbox(): string
{
    $dir = sys_get_temp_dir() . '/coresmith-cache-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir);
    repositoryCacheRun('git init -q -b main origin && cd origin && git commit -q --allow-empty -m one', $dir);
    repositoryCacheRun('git ' . (new RepositoryCache)->cloneArguments($dir . '/origin', 'repo.git'), $dir);

    return $dir;
}

function repositoryCacheRefresh(string $dir): void
{
    $cache = new RepositoryCache;
    repositoryCacheRun($cache->setUrlCommand('repo.git', $dir . '/origin'), $dir);
    repositoryCacheRun('git ' . $cache->fetchArguments('repo.git'), $dir);
}

test('the fetch a deploy runs moves the cached branch to the commit just pushed', function () {
    $dir = repositoryCacheSandbox();
    $pushed = repositoryCacheRun('git commit -q --allow-empty -m two && git rev-parse HEAD', $dir . '/origin');

    repositoryCacheRefresh($dir);

    expect(repositoryCacheRun('git --git-dir=repo.git rev-parse refs/heads/main', $dir))->toBe($pushed);
    File::deleteDirectory($dir);
});

test('a force push replaces the cached branch', function () {
    $dir = repositoryCacheSandbox();
    repositoryCacheRun('git commit -q --allow-empty -m two', $dir . '/origin');
    repositoryCacheRefresh($dir);
    $rewritten = repositoryCacheRun('git reset -q --hard HEAD~1 && git commit -q --allow-empty -m rewritten && git rev-parse HEAD', $dir . '/origin');

    repositoryCacheRefresh($dir);

    expect(repositoryCacheRun('git --git-dir=repo.git rev-parse refs/heads/main', $dir))->toBe($rewritten);
    File::deleteDirectory($dir);
});

test('a repository address that looks like an option is never run as one', function () {
    $dir = repositoryCacheSandbox();
    $marker = $dir . '/ran';
    $cache = new RepositoryCache;

    $clone = Process::path($dir)->run('git ' . $cache->cloneArguments('--upload-pack=touch ' . $marker, 'repo.git'));
    Process::path($dir)->run($cache->setUrlCommand('repo.git', '--upload-pack=touch ' . $marker));
    $fetch = Process::path($dir)->run('git ' . $cache->fetchArguments('repo.git'));

    expect($clone->successful())->toBeFalse()
        ->and($fetch->successful())->toBeFalse()
        ->and(file_exists($marker))->toBeFalse();
    File::deleteDirectory($dir);
});

test('a branch deleted upstream is pruned from the cache', function () {
    $dir = repositoryCacheSandbox();
    repositoryCacheRun('git branch release/2.0', $dir . '/origin');
    repositoryCacheRefresh($dir);
    repositoryCacheRun('git branch -D release/2.0', $dir . '/origin');

    repositoryCacheRefresh($dir);

    expect(repositoryCacheRun('git --git-dir=repo.git branch --list release/2.0', $dir))->toBe('');
    File::deleteDirectory($dir);
});
