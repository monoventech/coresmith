<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Server;
use App\Services\Detection\Blocker;
use App\Services\Detection\RepositoryAccess;
use App\Services\Detection\RepositoryReader;
use App\Services\Detection\StackChecks;
use App\Services\Detection\StackDetector;
use App\Services\Runtime\Catalog;
use Illuminate\Http\RedirectResponse;

final class RepositoryCheckController extends Controller
{
    public const NOTHING_TO_USE = 'There is no detected stack to use. Check the repository again.';

    public function store(Server $server, Application $application, RepositoryReader $reader, StackChecks $checks, StackDetector $detector, Catalog $catalog): RedirectResponse
    {
        $this->authorize('deploy', $application);
        abort_unless($application->server_id === $server->id, 404);

        $read = $reader->read($server, RepositoryAccess::forApplication($application), (string) $application->repository_branch, (string) ($application->directory ?: '/'));

        if ($read->facts === null) {
            $application->forceFill(['repository_check' => ['state' => 'failed', 'failure' => $read->failure, 'definitive' => $read->definitive]])->save();

            return back();
        }

        $installed = fn(string $runtime): array => $read->installed($runtime);
        $extensions = fn(string $version): ?array => $read->extensions($version);
        $blockers = $checks->blockers($application, $read->facts, $installed, extensions: $extensions);

        if ($blockers === []) {
            $application->forceFill([
                'repository_check_pending' => false,
                'repository_checked_at' => now(),
                'repository_check' => ['state' => 'passed', 'commit' => $read->commit],
            ])->save();

            return back()->with('success', 'Checked. Ready to deploy.');
        }

        $detected = $detector->detect($read->facts, $installed, $extensions);
        $proposal = $detected->verdict() === 'ready' ? [...$detected->toArray(), 'label' => $detected->label($catalog)] : null;

        $application->forceFill(['repository_check' => [
            'state' => $proposal === null ? 'blocked' : 'proposal',
            'blockers' => array_map(fn(Blocker $blocker): array => $blocker->toArray(), $blockers),
            'proposal' => $proposal,
            'commit' => $read->commit,
        ]])->save();

        return back();
    }

    public function apply(Server $server, Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        $check = (array) $application->repository_check;
        $fields = $check['proposal']['fields'] ?? null;

        if (($check['state'] ?? null) !== 'proposal' || !is_array($fields)) {
            return back()->with('error', self::NOTHING_TO_USE);
        }

        $application->forceFill([
            'type' => $fields['type'],
            'framework' => ($fields['framework'] ?? '') === '' ? null : $fields['framework'],
            'js_runtime' => $fields['js_runtime'] ?? null,
            'web_directory' => $fields['web_directory'] ?? $application->web_directory,
            'repository_check_pending' => false,
            'repository_checked_at' => now(),
            'repository_check' => ['state' => 'passed', 'commit' => $check['commit'] ?? null],
        ])->save();

        return back()->with('success', 'Saved.');
    }
}
