<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Server;
use App\Services\Deployment\DeployScript;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ApplicationDeployScriptController extends Controller
{
    public const CHANGED = 'deploy_script_changed';

    public function update(Request $request, Server $server, Application $application, DeployScript $scripts): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        $validated = $request->validate([
            'script' => ['present', 'nullable', 'string', 'max:' . DeployScript::LIMIT, 'not_regex:/\x00/'],
        ], [
            'script.max' => 'Keep the deploy script to 10,000 characters or fewer.',
            'script.not_regex' => 'The script has a null character in it. Remove it and save again.',
        ]);

        $script = DeployScript::normalize((string) ($validated['script'] ?? ''));

        if ($script !== null && trim($script) === trim($scripts->default($application))) {
            $script = null;
        }

        return $this->store($request, $application, $script);
    }

    public function destroy(Request $request, Server $server, Application $application): RedirectResponse
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        return $this->store($request, $application, null);
    }

    private function store(Request $request, Application $application, ?string $script): RedirectResponse
    {
        if ($script === (DeployScript::custom($application) ? $application->custom_blueprint : null)) {
            return back();
        }

        $application->forceFill(['custom_blueprint' => $script])->save();

        activity()->performedOn($application)->causedBy($request->user())->withProperties(['custom' => $script !== null])->log(self::CHANGED);

        return back()->with('success', $script === null ? "Back to Coresmith's default." : 'Saved. The next deploy runs it.');
    }
}
