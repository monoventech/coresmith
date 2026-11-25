<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DeploymentStatus;
use App\Enums\ServerStatus;
use App\Jobs\RemoveServerRuntime;
use App\Jobs\UpdateServerRuntime;
use App\Models\Application;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\User;
use App\Services\Cloud\Tasks\CloudTask;
use App\Services\Runtime\Catalog;
use App\Services\Server\ServerRuntimes;
use App\Services\Teams\TeamAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ServerRuntimeController extends Controller
{
    public function destroy(Request $request, Server $server, string $runtime, string $version, ServerRuntimes $runtimes, Catalog $catalog, TeamAccess $access): RedirectResponse
    {
        $this->authorize('update', $server);
        abort_unless(in_array($runtime, Catalog::RUNTIMES, true), 404);
        abort_unless(in_array($version, array_map('strval', (array) (((array) $server->installed_runtimes)[$runtime] ?? [])), true), 404);

        $label = $catalog->label($runtime) . " {$version}";
        $use = $runtimes->usage($server)["{$runtime}@{$version}"] ?? ['runs' => [], 'kept' => []];
        $visible = $access->applicationsOn($request->user(), $server)->pluck('id')->map(fn(mixed $id): string => (string) $id)->all();

        $refusal = match (true) {
            $use['runs'] !== [] => $this->who($use['runs'], $visible, opens: true) . (count($use['runs']) === 1 ? ' runs' : ' run') . " on {$label}, so it stays.",
            $use['kept'] !== [] => (count($use['kept']) === 1 ? 'A kept release of ' : 'Kept releases of ') . $this->who($use['kept'], $visible) . (count($use['kept']) === 1 ? ' was' : ' were') . " built on {$label}, so it stays until " . (count($use['kept']) === 1 ? 'that release is' : 'those releases are') . ' gone.',
            $server->status !== ServerStatus::Active => 'This server is not ready yet, so nothing was removed.',
            (UpdateServerRuntime::state($server)['state'] ?? null) === 'installing' => 'Coresmith is installing a runtime on this server. Try again once it finishes.',
            $this->deploying($server) => "A deploy is running on {$server->name}. Try again once it finishes.",
            default => CloudTask::refusal($server, 'Remove'),
        };

        if ($refusal !== null) {
            return back()->with('error', $refusal);
        }

        RemoveServerRuntime::dispatch($server, $runtime, $version, $request->user() instanceof User ? $request->user() : null);

        return back()->with('success', "Removing {$label} from {$server->name}.");
    }

    /**
     * @param  list<Application>  $applications
     * @param  list<string>  $visible
     */
    private function who(array $applications, array $visible, bool $opens = false): string
    {
        $names = array_values(array_map(fn(Application $application): string => $application->name, array_filter($applications, fn(Application $application): bool => in_array((string) $application->getKey(), $visible, true))));
        $hidden = count($applications) - count($names);

        if ($hidden > 0) {
            $names[] = $hidden === 1 ? "an app you can't see" : "{$hidden} apps you can't see";
        }

        $last = (string) array_pop($names);
        $said = $names === [] ? $last : implode(', ', $names) . " and {$last}";

        return $opens && count($applications) === $hidden ? ucfirst($said) : $said;
    }

    private function deploying(Server $server): bool
    {
        return Deployment::query()
            ->whereIn('application_id', $server->applications()->select('id'))
            ->whereIn('status', [DeploymentStatus::Pending, DeploymentStatus::Running])
            ->exists();
    }
}
