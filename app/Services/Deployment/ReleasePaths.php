<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;
use App\Services\SafeName;

final class ReleasePaths
{
    public const ROOT = '/var/coresmith/apps';

    public const KEEP_RELEASES = 5;

    public function __construct(private readonly Application $application) {}

    public function base(): string
    {
        return self::ROOT.'/'.SafeName::assertSlug($this->application->slug);
    }

    public function releases(): string
    {
        return $this->base().'/releases';
    }

    public function release(string $timestamp): string
    {
        return $this->releases().'/'.$timestamp;
    }

    public function current(): string
    {
        return $this->base().'/current';
    }

    public function shared(): string
    {
        return $this->base().'/shared';
    }

    public function repo(): string
    {
        return $this->base().'/repo';
    }

    public function envFile(): string
    {
        return '/etc/coresmith/env/'.SafeName::assertSlug($this->application->slug).'.env';
    }

    public function webRoot(string $releasePath): string
    {
        $directory = trim((string) $this->application->directory, '/');
        $web = trim((string) $this->application->web_directory, '/');

        $path = $releasePath;

        if ($directory !== '') {
            $path .= '/'.$directory;
        }

        if ($web !== '') {
            $path .= '/'.$web;
        }

        return $path;
    }
}
