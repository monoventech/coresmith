<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DatabaseType;
use App\Http\Requests\Application\UpdateApplicationSetupRequest;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Services\Database\AppDatabaseChoice;
use App\Services\Database\AppDatabaseState;
use App\Services\Database\DatabaseConnections;
use App\Services\Panel\AddAppForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ApplicationSetupController extends Controller
{
    public const CHANGED = 'setup_changed';

    private const ENGINES = ['postgresql' => 'PostgreSQL', 'mysql' => 'MySQL', 'redis' => 'Redis'];

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

    public function edit(Request $request, Server $server, Application $application, AddAppForm $form, AppDatabaseState $state): Response
    {
        $this->authorize('update', $application);
        abort_unless($application->server_id === $server->id, 404);

        $runtime = $application->type->runtime($application->js_runtime);

        return Inertia::render('applications/Create', [
            ...$form->props($request->user(), $server),
            'databases' => $this->databases($form, $request, $server, $application),
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
                    'database' => $state->database($application),
                    'redis' => $state->redis($application),
                    'horizon' => $state->horizon($application),
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

        $connected = $this->connect($application, $request->databaseChoice(), $request->redisChoice());

        if ($changed === [] && $connected === null) {
            return to_route('servers.applications.show', [$server, $application]);
        }

        if ($changed !== []) {
            activity()->performedOn($application)->causedBy($request->user())->withProperties(['changed' => $changed])->log(self::CHANGED);
        }

        $saved = $recheck ? 'Saved. Check the repository before the next deploy.' : 'Saved. The next deploy uses it.';

        return to_route('servers.applications.show', [$server, $application])
            ->with('success', $connected === null ? $saved : 'Saved. ' . $connected . ($recheck ? ' Check the repository before the next deploy.' : ''));
    }

    /**
     * @return array{choices: list<array{value: string, name: string, type: string}>, can_create: bool}
     */
    private function databases(AddAppForm $form, Request $request, Server $server, Application $application): array
    {
        $databases = $form->databases($request->user(), $server);
        $offered = array_column($databases['choices'], 'value');

        foreach ($application->databases()->orderBy('name')->get() as $connected) {
            if (!in_array('existing:' . $connected->getKey(), $offered, true)) {
                $databases['choices'][] = ['value' => 'existing:' . $connected->getKey(), 'name' => (string) $connected->name, 'type' => (string) $connected->type?->value];
            }
        }

        return $databases;
    }

    private function connect(Application $application, ?string $database, ?string $redis): ?string
    {
        if ($database === null && $redis === null) {
            return null;
        }

        $state = app(AppDatabaseState::class);
        $connections = app(DatabaseConnections::class);
        $previous = $database === null ? null : $state->connected($application, false);
        $previousRedis = $redis === null ? null : $state->connected($application, true);

        foreach (array_filter([$previous, $previousRedis]) as $old) {
            $connections->disconnect($application, $old);
        }

        $chosen = app(AppDatabaseChoice::class)->apply($application, $database, $redis, $state->horizon($application));
        $sentences = array_values(array_filter([
            $this->sentence($application, $database, $previous, $this->pick($chosen, false)),
            $this->sentence($application, $redis, $previousRedis, $this->pick($chosen, true)),
        ]));
        $creating = str_starts_with((string) $database, 'new') || str_starts_with((string) $redis, 'new');
        $connecting = $chosen !== [] || $database === 'sqlite';

        $message = implode(' ', $sentences) . match (true) {
            $creating => " Its settings are in the variables, so deploy once it's ready.",
            $connecting => ' Deploy to switch over.',
            $previous !== null || $previousRedis !== null => ' Its variables stay as they are until you change them.',
            default => '',
        };

        return trim($message) === '' ? null : trim($message);
    }

    /**
     * @param  list<ManagedDatabase>  $chosen
     */
    private function pick(array $chosen, bool $redis): ?ManagedDatabase
    {
        foreach ($chosen as $database) {
            if (($database->type === DatabaseType::Redis) === $redis) {
                return $database;
            }
        }

        return null;
    }

    private function sentence(Application $application, ?string $choice, ?ManagedDatabase $previous, ?ManagedDatabase $chosen): ?string
    {
        if ($choice === null) {
            return null;
        }

        $name = $application->name;
        $kept = $previous === null ? '' : ", and {$previous->name} keeps its data";

        return match (true) {
            str_starts_with($choice, 'new') && $chosen !== null => 'Coresmith is creating a ' . self::ENGINES[$chosen->type->value] . " database for {$name}{$kept}.",
            $chosen !== null => "{$name} now uses {$chosen->name}{$kept}.",
            $choice === 'sqlite' => "{$name} now uses SQLite, kept across deploys{$kept}.",
            $choice === 'none' && $previous !== null => "{$previous->name} is no longer connected to {$name}.",
            $choice === 'none' => "{$name} keeps using SQLite until you change DB_CONNECTION in its variables.",
            default => null,
        };
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
