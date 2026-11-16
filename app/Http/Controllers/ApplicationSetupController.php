<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Application\UpdateApplicationSetupRequest;
use App\Models\Application;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Services\Panel\AddAppForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ApplicationSetupController extends Controller
{
    public const CHANGED = 'setup_changed';

    public const LABELS = [
        'type' => 'language',
        'framework' => 'framework',
        'js_runtime' => 'runtime',
        'repository_url' => 'repository',
        'repository_branch' => 'branch',
        'directory' => 'folder',
        'web_directory' => 'web root',
        'main_package' => 'main package',
        'app_size' => 'size',
        'runtime_pins' => 'runtime version',
    ];

    private const CHECKED_FIELDS = ['type', 'framework', 'js_runtime', 'repository_url', 'repository_branch', 'directory', 'web_directory', 'main_package', 'runtime_pins'];

    public function edit(Request $request, Server $server, Application $application, AddAppForm $form): Response
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        $runtime = $application->type->runtime($application->js_runtime);

        return Inertia::render('applications/Create', [
            ...$form->props($request->user(), $server),
            'setup' => [
                'action' => route('servers.applications.setup.update', [$server, $application]),
                'back' => route('servers.applications.show', [$server, $application]),
                'application' => [
                    'name' => $application->name,
                    'source' => $this->source($application),
                    'branch' => $application->repository_branch,
                    'directory' => $application->directory ?? '/',
                    'type' => $application->type->value,
                    'framework' => $application->framework,
                    'js_runtime' => $application->js_runtime,
                    'web_directory' => $application->web_directory,
                    'main_package' => $application->main_package,
                    'app_size' => $application->app_size,
                    'runtime_version' => $runtime === null ? null : ((array) $application->runtime_pins)[$runtime] ?? null,
                ],
            ],
        ]);
    }

    public function update(UpdateApplicationSetupRequest $request, Server $server, Application $application): RedirectResponse
    {
        abort_unless($application->server_id === $server->id, 404);

        $validated = $request->validated();
        $line = $request->runtimeLine();
        $checked = $request->checkedRead();

        $application->forceFill([
            'type' => $validated['type'],
            'framework' => $validated['framework'] ?? null,
            'js_runtime' => $validated['js_runtime'] ?? null,
            ...($request->linked() ? [] : ['repository_url' => $validated['repository_url']]),
            'repository_branch' => $validated['repository_branch'],
            'directory' => $validated['directory'],
            'web_directory' => $validated['web_directory'] ?? null,
            'main_package' => $validated['main_package'] ?? null,
            'app_size' => $validated['app_size'],
            'runtime_pins' => $line === null ? null : [$request->runtime() => $line],
        ]);

        $changed = array_values(array_filter(array_keys(self::LABELS), fn(string $field): bool => $application->isDirty($field)));
        $recheck = $checked === null && array_intersect($changed, self::CHECKED_FIELDS) !== [];

        if ($checked !== null) {
            $application->forceFill(['repository_checked_at' => now(), 'repository_check_pending' => false, 'repository_check' => ['state' => 'passed', 'commit' => $checked->commit]]);
        } elseif ($recheck) {
            $application->forceFill(['repository_check_pending' => true, 'repository_check' => ['state' => 'changed']]);
        }

        $application->save();

        if ($changed === []) {
            return to_route('servers.applications.show', [$server, $application]);
        }

        activity()->performedOn($application)->causedBy($request->user())->withProperties(['changed' => $changed])->log(self::CHANGED);

        return to_route('servers.applications.show', [$server, $application])
            ->with('success', $recheck ? 'Saved. Check the repository before the next deploy.' : 'Saved. The next deploy uses it.');
    }

    /**
     * @return array<string, string>
     */
    private function source(Application $application): array
    {
        if ($application->source_provider_id === null) {
            return ['kind' => 'url', 'url' => (string) $application->repository_url];
        }

        return [
            'kind' => 'github',
            'full_name' => (string) $application->repositoryFullName(),
            'account' => (string) SourceProvider::query()->whereKey($application->source_provider_id)->value('name'),
            'connection_id' => (string) $application->source_provider_id,
            'repository_id' => (string) $application->repository_id,
        ];
    }
}
