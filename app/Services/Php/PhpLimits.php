<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Php;

use App\Enums\AppSize;
use App\Models\Application;
use App\Services\SafeName;

final class PhpLimits
{
    public const UPLOADS = [8, 16, 32, 64, 128, 256, 512, 1024];

    public const MEMORY = [64, 128, 256, 512, 1024];

    public const SECONDS = [30, 60, 120, 300];

    public const RELOAD_DEPLOY = 'deploy';

    public const RELOAD_TIMESTAMPS = 'timestamps';

    public const DIRECTORY = '/etc/coresmith-php';

    public const FILE = 'coresmith.ini';

    private const FRAMEWORKS = ['laravel', 'symfony'];

    private const DEFAULT_UPLOADS = 64;

    private const DEFAULT_SECONDS = 60;

    public function __construct(
        public readonly string $slug,
        public readonly int $uploads,
        public readonly int $memory,
        public readonly int $seconds,
        public readonly string $reload,
        public readonly int $memoryCap,
        public readonly bool $held,
    ) {}

    public static function for(Application $application): self
    {
        $cap = (AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small)->memoryMaxMb();
        $stored = (array) $application->php_limits;
        $memory = in_array($stored['memory'] ?? null, self::MEMORY, true) ? $stored['memory'] : intdiv($cap, 2);

        return new self(
            slug: SafeName::assertSlug($application->slug),
            uploads: in_array($stored['uploads'] ?? null, self::UPLOADS, true) ? $stored['uploads'] : self::DEFAULT_UPLOADS,
            memory: min($memory, $cap),
            seconds: in_array($stored['seconds'] ?? null, self::SECONDS, true) ? $stored['seconds'] : self::DEFAULT_SECONDS,
            reload: in_array($stored['reload'] ?? null, [self::RELOAD_DEPLOY, self::RELOAD_TIMESTAMPS], true) ? $stored['reload'] : self::defaultReload($application),
            memoryCap: $cap,
            held: $memory > $cap,
        );
    }

    public static function directory(Application $application): string
    {
        return self::DIRECTORY . '/' . SafeName::assertSlug($application->slug);
    }

    public static function scanDir(Application $application): string
    {
        return ':' . self::directory($application);
    }

    /**
     * @return list<int>
     */
    public function memoryChoices(): array
    {
        return array_values(array_filter(self::MEMORY, fn(int $megabytes): bool => $megabytes <= $this->memoryCap));
    }

    public function ini(): string
    {
        return implode("\n", [
            "; Managed by Coresmith for {$this->slug}. Change these on the app page.",
            "upload_max_filesize = {$this->uploads}M",
            "post_max_size = {$this->uploads}M",
            "memory_limit = {$this->memory}M",
            "max_execution_time = {$this->seconds}",
            "max_input_time = {$this->seconds}",
            'opcache.validate_timestamps = ' . ($this->reload === self::RELOAD_DEPLOY ? '0' : '1'),
            'opcache.revalidate_freq = 2',
        ]) . "\n";
    }

    /**
     * @return array{uploads: int, memory: int, seconds: int, reload: string}
     */
    public function toArray(): array
    {
        return ['uploads' => $this->uploads, 'memory' => $this->memory, 'seconds' => $this->seconds, 'reload' => $this->reload];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function sentence(array $values): string
    {
        $seconds = (int) ($values['seconds'] ?? self::DEFAULT_SECONDS);

        return implode(', ', [
            'uploads up to ' . self::size((int) ($values['uploads'] ?? self::DEFAULT_UPLOADS)),
            self::size((int) ($values['memory'] ?? 128)) . ' of memory',
            'web requests stop after ' . match (true) {
                $seconds === 60 => 'a minute',
                $seconds > 60 => intdiv($seconds, 60) . ' minutes',
                default => "{$seconds} seconds",
            },
            'new code loads ' . (($values['reload'] ?? null) === self::RELOAD_TIMESTAMPS ? 'every 2 seconds' : 'at each deploy'),
        ]);
    }

    public static function size(int $megabytes): string
    {
        return $megabytes >= 1024 && $megabytes % 1024 === 0 ? intdiv($megabytes, 1024) . ' GB' : "{$megabytes} MB";
    }

    private static function defaultReload(Application $application): string
    {
        return in_array($application->framework, self::FRAMEWORKS, true) ? self::RELOAD_DEPLOY : self::RELOAD_TIMESTAMPS;
    }
}
