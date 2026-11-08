<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Updates;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ReleaseLayout
{
    public const NAME = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-[0-9A-Za-z.-]+)?-[0-9a-f]{7}$/D';

    private const COMMIT = '/^[0-9a-f]{7,40}$/D';

    private const INCOMING = '.incoming-';

    public function __construct(private readonly UpdatePaths $paths) {}

    public static function name(Version $version, string $commit): string
    {
        if (preg_match(self::COMMIT, $commit) !== 1) {
            throw new InvalidArgumentException("\"{$commit}\" is not a commit hash.");
        }

        return "{$version}-" . substr($commit, 0, 7);
    }

    public function isManaged(): bool
    {
        return $this->currentPath() !== null
            && is_file($this->paths->shared() . '/.env')
            && is_dir($this->paths->shared() . '/storage');
    }

    public function current(): ?Release
    {
        $path = $this->currentPath();

        return $path === null ? null : Release::fromDirectory($path);
    }

    public function find(string $name): ?Release
    {
        if (preg_match(self::NAME, $name) !== 1) {
            return null;
        }

        $path = $this->child($this->paths->releases() . "/{$name}");

        return $path === null ? null : Release::fromDirectory($path);
    }

    public function incoming(): string
    {
        $releases = $this->paths->releases();

        if (!is_dir($releases) && !@mkdir($releases, 0755, true) && !is_dir($releases)) {
            throw new RuntimeException("Coresmith couldn't create {$releases}.");
        }

        $path = realpath($releases) . '/' . self::INCOMING . Str::lower((string) Str::ulid());

        if (!@mkdir($path, 0755)) {
            throw new RuntimeException("Coresmith couldn't create {$path}.");
        }

        @chmod($path, 0755);

        return $path;
    }

    public function promote(string $incoming, string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException("\"{$name}\" is not a release name.");
        }

        $source = $this->child($incoming);

        if ($source === null || !str_starts_with(basename($source), self::INCOMING)) {
            throw new RuntimeException("{$incoming} is not a release being built.");
        }

        $target = realpath($this->paths->releases()) . "/{$name}";

        if (file_exists($target) || is_link($target)) {
            if ($this->child($target) !== null && $this->child($target) === $this->currentPath()) {
                throw new RuntimeException("{$name} is the running release.");
            }

            is_dir($target) && !is_link($target) ? File::deleteDirectory($target) : @unlink($target);
        }

        if (!@rename($source, $target)) {
            throw new RuntimeException("Coresmith couldn't move the new release into place as {$name}.");
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function seal(string $path, array $meta): Release
    {
        $path = rtrim($path, '/');
        $sentinel = [
            'schema' => Release::SCHEMA,
            'version' => $meta['version'] ?? null,
            'commit' => $meta['commit'] ?? null,
            'tag' => $meta['tag'] ?? null,
            'signature' => $meta['signature'] ?? 'unchecked',
            'fingerprint' => $meta['fingerprint'] ?? null,
            'built_at' => CarbonImmutable::now()->toIso8601String(),
            'built_by' => $meta['built_by'] ?? '',
        ];

        $temporary = "{$path}/" . Release::SENTINEL . '.' . Str::random(8);

        if (@file_put_contents($temporary, json_encode($sentinel, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false
            || !@chmod($temporary, 0644)
            || !@rename($temporary, "{$path}/" . Release::SENTINEL)) {
            @unlink($temporary);

            throw new RuntimeException("Coresmith couldn't seal the release in {$path}.");
        }

        return Release::fromDirectory($path) ?? throw new RuntimeException("Coresmith couldn't read back the release in {$path}.");
    }

    public function activate(Release $release): void
    {
        $path = $this->child($release->path);

        if ($path === null || Release::fromDirectory($path) === null) {
            throw new RuntimeException("{$release->name} is not a sealed release in " . $this->paths->releases() . '.');
        }

        $temporary = $this->paths->root() . '/.app-' . Str::lower(Str::random(12));

        if (!@symlink($path, $temporary)) {
            throw new RuntimeException("Coresmith couldn't create the link to {$release->name}.");
        }

        if (!@rename($temporary, $this->paths->current())) {
            @unlink($temporary);

            throw new RuntimeException("Coresmith couldn't switch to {$release->name}.");
        }

        if (@readlink($this->paths->current()) !== $path) {
            throw new RuntimeException("Coresmith does not point at {$release->name} after switching.");
        }
    }

    /**
     * @return list<string>
     */
    public function prune(Release $current, ?Release $previous): array
    {
        $running = $this->currentPath();
        $keep = array_filter([$current->name, $previous?->name, $running === null ? null : basename($running)]);
        $deleted = [];

        foreach ($this->entries(includeHidden: true) as $name) {
            $path = $this->child($this->paths->releases() . "/{$name}");

            if ($path === null || in_array($name, $keep, true) || $path === $running) {
                continue;
            }

            File::deleteDirectory($path);
            $deleted[] = $name;
        }

        return $deleted;
    }

    public function discard(string $path): void
    {
        $resolved = $this->child($path);

        if ($resolved === null || is_link(rtrim($path, '/'))) {
            throw new RuntimeException("{$path} is not a release directory Coresmith can remove.");
        }

        if ($resolved === $this->currentPath()) {
            throw new RuntimeException(basename($resolved) . ' is the running release.');
        }

        File::deleteDirectory($resolved);
    }

    private function currentPath(): ?string
    {
        $app = $this->paths->current();

        if (!is_link($app)) {
            return null;
        }

        $target = realpath($app);
        $releases = realpath($this->paths->releases());

        if ($target === false || $releases === false || dirname($target) !== $releases) {
            return null;
        }

        return is_file("{$target}/" . Release::SENTINEL) ? $target : null;
    }

    private function child(string $path): ?string
    {
        $resolved = realpath($path);
        $releases = realpath($this->paths->releases());

        if ($resolved === false || $releases === false || !is_dir($resolved) || dirname($resolved) !== $releases) {
            return null;
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function entries(bool $includeHidden = false): array
    {
        $directory = $this->paths->releases();

        if (!is_dir($directory)) {
            return [];
        }

        return array_values(array_filter(
            scandir($directory) ?: [],
            fn(string $name): bool => $name !== '.' && $name !== '..' && ($includeHidden || !str_starts_with($name, '.')),
        ));
    }
}
