<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Detection;

use App\Models\Server;
use App\Models\User;
use App\Services\Runtime\RepositoryFacts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class DetectionStore
{
    public const MINUTES = 30;

    private const PREFIX = 'repository-stack:';

    public function put(User $user, Server $server, RepositoryAccess $access, string $branch, string $directory, RepositoryRead $read): string
    {
        $id = Str::lower(Str::random(32));

        Cache::put(self::PREFIX . $id, [
            'user' => (string) $user->getKey(),
            'server' => (string) $server->getKey(),
            'repository' => $access->key(),
            'branch' => $branch,
            'directory' => $this->folder($directory),
            'facts' => $read->facts === null ? null : [
                'files' => $read->facts->files,
                'lockfiles' => $read->facts->lockfiles,
                'locked' => $read->facts->locked,
                'markers' => $read->facts->markers,
                'env_keys' => $read->facts->envKeys,
                'complete' => $read->facts->complete,
                'go_lines' => $read->facts->goLines,
            ],
            'commit' => $read->commit,
            'failure' => $read->failure,
            'definitive' => $read->definitive,
            'installed' => $read->installed,
            'extensions' => $read->extensions,
            'arch' => $read->arch,
        ], now()->addMinutes(self::MINUTES));

        return $id;
    }

    public function find(?string $id, User $user, Server $server, string $repository, string $branch, string $directory): ?RepositoryRead
    {
        if ($id === null || preg_match('/^[a-z0-9]{32}$/D', $id) !== 1) {
            return null;
        }

        $stored = Cache::get(self::PREFIX . $id);

        if (!is_array($stored)
            || ($stored['user'] ?? null) !== (string) $user->getKey()
            || ($stored['server'] ?? null) !== (string) $server->getKey()
            || ($stored['repository'] ?? null) !== $repository
            || ($stored['branch'] ?? null) !== $branch
            || ($stored['directory'] ?? null) !== $this->folder($directory)) {
            return null;
        }

        $facts = is_array($stored['facts'] ?? null) ? new RepositoryFacts(
            (array) $stored['facts']['files'],
            (array) $stored['facts']['lockfiles'],
            (array) $stored['facts']['locked'],
            (array) $stored['facts']['markers'],
            (array) $stored['facts']['env_keys'],
            (bool) $stored['facts']['complete'],
            trim((string) $stored['directory'], '/'),
            (array) ($stored['facts']['go_lines'] ?? []),
        ) : null;

        return new RepositoryRead($facts, $stored['commit'] ?? null, $stored['failure'] ?? null, (bool) ($stored['definitive'] ?? false), (array) ($stored['installed'] ?? []), (array) ($stored['extensions'] ?? []), is_string($stored['arch'] ?? null) ? $stored['arch'] : null);
    }

    private function folder(string $directory): string
    {
        return '/' . trim($directory, '/');
    }
}
