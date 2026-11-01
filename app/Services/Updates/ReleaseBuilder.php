<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use App\Enums\ReleaseSignature;
use App\Services\Deployment\LogSanitizer;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

final class ReleaseBuilder
{
    private const NOTHING_CHANGED = 'Nothing changed.';

    private const PLACEHOLDER = 'coresmith-release:';

    private const COMMIT = '/^[0-9a-f]{40}$/D';

    public function __construct(
        private readonly UpdatePaths $paths,
        private readonly TagSignature $signatures,
        private readonly LogSanitizer $sanitizer,
    ) {}

    /**
     * @return array{commit: string, signature: SignatureResult}
     */
    public function fetch(Version $target, string $directory, UpdateRun $run): array
    {
        $tag = $target->tag();
        $repository = (string) config('coresmith.updates.repository');

        $run->step('fetch');

        $init = $this->exec(['git', 'init', '--quiet', $directory], null, 60, $run);

        if (!$init->successful()) {
            throw $this->failed('fetch', "Coresmith couldn't prepare {$directory}: {$this->excerpt($init)}.");
        }

        [$source, $environment] = $this->source($repository);
        $fetch = $this->exec(['git', '-C', $directory, 'fetch', '--quiet', '--depth', '1', '--no-tags', $source, "refs/tags/{$tag}:refs/tags/{$tag}"], null, 300, $run, $environment);

        if (!$fetch->successful()) {
            throw $this->failed('fetch', str_contains($fetch->errorOutput() . $fetch->output(), "couldn't find remote ref")
                ? "{$tag} isn't in {$repository}. Check CORESMITH_REPOSITORY points at the Coresmith repository."
                : "Coresmith couldn't fetch {$tag} from {$repository}: {$this->excerpt($fetch, 1)}. Check this server can reach it, then try again.");
        }

        $run->step('verify');
        $signature = $this->signatures->verify($directory, $tag);

        if (!$signature->passed()) {
            throw $this->failed('verify', $signature->message);
        }

        $run->signature($signature->status->value);
        $signature->status === ReleaseSignature::Unchecked ? $run->warn($signature->message) : $run->line($signature->message);

        $checkout = $this->exec(['git', '-C', $directory, '-c', 'advice.detachedHead=false', 'checkout', '--quiet', $tag], null, 120, $run);

        if (!$checkout->successful()) {
            throw $this->failed('fetch', "Coresmith couldn't check out {$tag}: {$this->excerpt($checkout)}.");
        }

        $head = $this->exec(['git', '-C', $directory, 'rev-parse', 'HEAD'], null, 30, $run, log: false);
        $commit = trim($head->output());

        if (!$head->successful() || preg_match(self::COMMIT, $commit) !== 1) {
            throw $this->failed('fetch', "Coresmith couldn't read the commit {$tag} points at.");
        }

        $version = is_file("{$directory}/VERSION") ? trim((string) file_get_contents("{$directory}/VERSION")) : '';

        if (Version::tryParse($version)?->equals($target) !== true) {
            $issues = (string) config('coresmith.updates.issues_url');

            throw $this->failed('fetch', ($version === '' ? "The {$tag} tag has no VERSION file." : "The {$tag} tag says it is version {$version}.") . " That release is broken; report it at {$issues}.");
        }

        return ['commit' => $commit, 'signature' => $signature];
    }

    public function build(string $path, UpdateRun $run): void
    {
        $tag = 'v' . ($run->status()->toVersion ?? basename($path));

        $run->step('dependencies');

        $platform = $this->exec(['composer', 'check-platform-reqs', '--lock', '--no-dev', '--no-interaction'], $path, 120, $run);

        if (!$platform->successful()) {
            throw $this->failed('dependencies', "{$tag} needs something this server doesn't have:\n" . implode("\n", $this->lines($platform, 20)) . "\nInstall it, then update again.");
        }

        $this->link($path);

        foreach ([
            ['composer', 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-scripts', '--no-progress'],
            [PHP_BINARY, 'artisan', 'package:discover', '--ansi'],
            [PHP_BINARY, 'artisan', 'vendor:publish', '--tag=laravel-assets', '--ansi', '--force'],
        ] as $command) {
            $this->must($command, $path, $command[0] === 'composer' ? 900 : 120, $run, 'dependencies', 'Installing PHP dependencies failed');
        }

        $run->step('assets');
        $this->must(['bun', 'install', '--frozen-lockfile'], $path, 600, $run, 'assets', 'Building the interface failed');
        $this->must(['bun', 'run', 'build'], $path, 900, $run, 'assets', 'Building the interface failed');
        File::deleteDirectory("{$path}/node_modules");

        $run->step('cache');

        foreach (['config:cache', 'route:cache', 'view:cache'] as $cache) {
            $this->must([PHP_BINARY, 'artisan', $cache], $path, 120, $run, 'cache', 'Warming caches failed');
        }

        $user = (string) config('coresmith.updates.web_user');

        if (!function_exists('posix_getpwnam') || posix_getpwnam($user) === false) {
            $run->line("Skipping web server permissions: there is no {$user} user on this machine.");

            return;
        }

        $this->must(['setfacl', '-m', "u:{$user}:x", $path], $path, 120, $run, 'cache', "Coresmith couldn't let the web server read the new release");
        $this->must(['setfacl', '-R', '-m', "u:{$user}:rX", "{$path}/public"], $path, 120, $run, 'cache', "Coresmith couldn't let the web server read the new release");
    }

    public function migrate(string $path, UpdateRun $run): void
    {
        $result = $this->exec([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction'], $path, 1800, $run);

        if (!$result->successful()) {
            throw new UpdateFailed('migrate', $this->sanitizer->sanitize("Running migrations failed: {$this->excerpt($result)}."));
        }
    }

    private function link(string $path): void
    {
        foreach (['storage', '.env'] as $shared) {
            $link = "{$path}/{$shared}";

            if (is_dir($link) && !is_link($link)) {
                File::deleteDirectory($link);
            } elseif (file_exists($link) || is_link($link)) {
                @unlink($link);
            }

            if (!@symlink($this->paths->shared() . "/{$shared}", $link)) {
                throw $this->failed('dependencies', "Coresmith couldn't link the shared {$shared} into the new release.");
            }
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function must(array $command, string $path, int $timeout, UpdateRun $run, string $step, string $failure): void
    {
        $result = $this->exec($command, $path, $timeout, $run);

        if (!$result->successful()) {
            throw $this->failed($step, "{$failure}: {$this->excerpt($result)}.");
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    private function exec(array $command, ?string $path, int $timeout, UpdateRun $run, array $environment = [], bool $log = true): ProcessResult
    {
        $pending = Process::timeout($timeout)->env(['GIT_TERMINAL_PROMPT' => '0', ...$environment]);

        if ($path !== null) {
            $pending = $pending->path($path);
        }

        $streamed = false;
        $result = $pending->run($command, function (string $type, string $output) use ($run, $log, &$streamed): void {
            $streamed = true;

            if ($log) {
                $run->output($output);
            }
        });

        if (!$streamed && $log) {
            $run->output($result->output());
            $run->output($result->errorOutput());
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function source(string $repository): array
    {
        $parts = parse_url($repository);

        if (!is_array($parts) || (!isset($parts['user']) && !isset($parts['pass']))) {
            return [$repository, []];
        }

        return [self::PLACEHOLDER, [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => "url.{$repository}.insteadOf",
            'GIT_CONFIG_VALUE_0' => self::PLACEHOLDER,
        ]];
    }

    private function excerpt(ProcessResult $result, int $count = 3): string
    {
        return rtrim(implode("\n", array_slice($this->lines($result), -$count)), '.');
    }

    /**
     * @return list<string>
     */
    private function lines(ProcessResult $result, int $limit = 0): array
    {
        $plain = (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', trim($result->errorOutput()) !== '' ? $result->errorOutput() : $result->output());
        $lines = array_values(array_filter(array_map('rtrim', preg_split('/\r\n|\r|\n/', $plain) ?: []), fn(string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            $lines = ["the command exited with code {$result->exitCode()}"];
        }

        return $limit > 0 ? array_slice($lines, 0, $limit) : $lines;
    }

    private function failed(string $step, string $message): UpdateFailed
    {
        return new UpdateFailed($step, $this->sanitizer->sanitize($message . ' ' . self::NOTHING_CHANGED));
    }
}
