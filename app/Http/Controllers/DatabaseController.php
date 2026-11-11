<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DatabaseType;
use App\Enums\PowerState;
use App\Enums\PrivilegeLevel;
use App\Http\Requests\Database\DestroyDatabaseRequest;
use App\Http\Requests\Database\StoreDatabaseRequest;
use App\Jobs\BackupDatabase;
use App\Jobs\CreateDatabase;
use App\Jobs\ListDatabaseBackups;
use App\Models\Application;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\Database\Backup;
use App\Services\Database\BackupListing;
use App\Services\Database\DatabaseBackups;
use App\Services\Database\DatabaseManager;
use App\Services\Database\DatabaseUsage;
use App\Services\Database\OffsiteBackups;
use App\Services\Database\RedisPorts;
use App\Services\Database\SharedRedis;
use App\Services\Panel\Formats;
use App\Services\Panel\ProjectPresenter;
use App\Services\Panel\ServerPresenter;
use App\Services\SSH\SSHConnectionManager;
use App\Services\Teams\TeamAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class DatabaseController extends Controller
{
    public function index(Request $request, Server $server, ServerPresenter $presenter, SharedRedis $sharedRedis): Response|RedirectResponse
    {
        if ($server->trashed()) {
            return $this->destroyedServer($server);
        }

        $this->authorize('view', $server);

        return Inertia::render('servers/Databases', [
            'server' => $presenter->header($server, $request->user()),
            'databases' => $server->databases()->orderBy('name')->get()->map(
                fn(ManagedDatabase $database): array => [
                    ...$this->summarise($database),
                    'href' => route('servers.databases.show', [$server, $database]),
                ],
            )->values(),
            'can' => [
                'create' => $request->user()->can('create', ManagedDatabase::class),
                'update' => $request->user()->can('update', $server),
            ],
            'shared_redis' => $sharedRedis->state($server),
        ]);
    }

    public function create(Request $request, Server $server, ProjectPresenter $projects): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('databases/Create', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => route('servers.databases.index', $server)],
            'projects' => $projects->choices($request->user(), $server->team),
        ]);
    }

    public function store(StoreDatabaseRequest $request, Server $server, RedisPorts $redisPorts): RedirectResponse
    {
        $attributes = [...$request->safe()->except([]), 'server_id' => $server->id, 'team_id' => $server->team_id];

        try {
            $database = $request->safe()->string('type')->value() === DatabaseType::Redis->value
                ? $redisPorts->lock($server)->block(RedisPorts::LOCK_SECONDS, fn(): ManagedDatabase => ManagedDatabase::create([
                    ...$attributes,
                    'db_user' => null,
                    'privilege_level' => PrivilegeLevel::Standard->value,
                    'port' => $redisPorts->next($server),
                ]))
                : ManagedDatabase::create([...$attributes, 'port' => $this->defaultPort($request->string('type')->value())]);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        CreateDatabase::dispatch($database);

        return to_route('servers.databases.index', $server)
            ->with('success', 'Database queued for creation.');
    }

    public function show(Request $request, Server $server, ManagedDatabase $database, DatabaseBackups $backups, DatabaseUsage $usage, TeamAccess $access): Response
    {
        $this->authorize('view', $database);
        $this->assertBelongsTo($server, $database);

        $user = $request->user();
        $restore = $user->can('restore', $database) && $database->takesBackups();

        return Inertia::render('databases/Show', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => $access->canSeeServer($user, $server) ? route('servers.databases.index', $server) : null],
            'database' => $this->summarise($database),
            'place' => app(ProjectPresenter::class)->place($user, $database),
            'can' => [
                'viewCredentials' => $user->can('viewCredentials', $database),
                'backup' => $user->can('backup', $database) && $database->takesBackups(),
                'delete' => $user->can('delete', $database),
                'restore' => $restore,
            ],
            'offsite' => !$this->goesToS3($database) ? null : [
                'sent' => Formats::when($database->last_offsite_at),
                'error' => $database->last_offsite_error,
            ],
            'backups' => $this->listedBackups($database, $backups),
            'restore' => $this->restoreState($database),
            'apps' => !$restore ? [] : $server->applications()->orderBy('name')->get()
                ->filter(fn(Application $application): bool => $access->canSeeApplication($user, $application))
                ->map(fn(Application $application): array => [
                    'id' => $application->id,
                    'name' => $application->name,
                    'uses' => $usage->mentions($application, $database),
                    'running' => $application->power_state === PowerState::Active,
                ])->values()->all(),
        ]);
    }

    /**
     * @return array{rows: list<array{stamp: string, when: string, size: string, on_server: bool, in_s3: bool}>, server_error: string|null, s3_error: string|null}|null
     */
    private function listedBackups(ManagedDatabase $database, DatabaseBackups $backups): ?array
    {
        if (!$database->takesBackups()) {
            return null;
        }

        $listing = $backups->cached($database);

        if ($listing === null) {
            ListDatabaseBackups::dispatch($database);

            return null;
        }

        return $this->backups($listing);
    }

    /**
     * @return array{rows: list<array{stamp: string, when: string, size: string, on_server: bool, in_s3: bool}>, server_error: string|null, s3_error: string|null}
     */
    private function backups(BackupListing $listing): array
    {
        return [
            'rows' => array_map(fn(Backup $backup): array => [
                'stamp' => $backup->stamp,
                'when' => $backup->at->format('M j, H:i'),
                'size' => Formats::bytes($backup->size),
                'on_server' => $backup->onServer(),
                'in_s3' => $backup->inS3(),
            ], $listing->backups),
            'server_error' => $listing->serverError,
            's3_error' => $listing->s3Error,
        ];
    }

    /**
     * @return array{state: string, when: string, step: string|null, error: string|null}|null
     */
    private function restoreState(ManagedDatabase $database): ?array
    {
        $restore = (array) $database->restore;
        $finished = is_string($restore['finished_at'] ?? null) ? CarbonImmutable::parse($restore['finished_at']) : null;

        if (!is_string($restore['stamp'] ?? null) || ($database->restoring() === false && ($finished === null || $finished->lt(now()->subDay())))) {
            return null;
        }

        return [
            'state' => (string) ($restore['state'] ?? 'failed'),
            'when' => Backup::at($restore['stamp'])->format('M j, H:i'),
            'step' => is_string($restore['step'] ?? null) ? $restore['step'] : null,
            'error' => is_string($restore['error'] ?? null) ? $restore['error'] : null,
        ];
    }

    public function destroy(DestroyDatabaseRequest $request, Server $server, ManagedDatabase $database, SSHConnectionManager $connections, DatabaseManager $databases): RedirectResponse
    {
        $this->assertBelongsTo($server, $database);

        $lock = $database->credentialsLock();

        if (!$lock->get()) {
            return back()->with('error', 'Coresmith is still working on this database. Try again in a minute.');
        }

        try {
            if (!$database->onSharedRedis() && !$this->dropped($connections, $databases, $server, $database)) {
                return back()->with('error', "Coresmith couldn't delete {$database->name} from {$server->name}, so it's still there. Check the server is online and nothing is using the database, then try again.");
            }

            $database->delete();
        } finally {
            $lock->release();
        }

        activity()->performedOn($database)->log('database_deleted');

        $back = app(TeamAccess::class)->canSeeServer($request->user(), $server) ? to_route('servers.databases.index', $server) : to_route('dashboard');

        return $back->with('success', match (true) {
            $database->onSharedRedis() => "Removed {$database->name}. The shared Redis on {$server->name} keeps what's in it.",
            $database->type === DatabaseType::Redis => "Deleted {$database->name} and its Redis instance from {$server->name}.",
            default => "Deleted {$database->name} and its user from {$server->name}.",
        });
    }

    private function dropped(SSHConnectionManager $connections, DatabaseManager $databases, Server $server, ManagedDatabase $database): bool
    {
        try {
            $databases->drop($connections->for($server), $database);
        } catch (RuntimeException $e) {
            report($e);

            return false;
        } finally {
            $connections->disconnect($server);
        }

        return true;
    }

    public function backup(Server $server, ManagedDatabase $database): RedirectResponse
    {
        $this->authorize('backup', $database);
        $this->assertBelongsTo($server, $database);
        abort_unless($database->takesBackups(), 404);

        if ($database->restoring()) {
            return back()->with('error', "Wait for the restore of {$database->name} to finish, then back up.");
        }

        BackupDatabase::dispatch($database, now()->format('YmdHis'), offsite: $this->goesToS3($database));

        return back()->with('success', 'Backup started.');
    }

    private function goesToS3(ManagedDatabase $database): bool
    {
        return app(OffsiteBackups::class)->covers($database);
    }

    private function assertBelongsTo(Server $server, ManagedDatabase $database): void
    {
        abort_unless($database->server_id === $server->id, 404);
    }

    private function defaultPort(string $type): int
    {
        return $type === DatabaseType::Postgresql->value ? 5432 : 3306;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(ManagedDatabase $database): array
    {
        return [
            'id' => $database->id,
            'name' => $database->name,
            'type' => $database->type?->value,
            'db_user' => $database->db_user,
            'host' => $database->host,
            'port' => $database->port,
            'status' => $database->status,
            'privilege_level' => $database->privilege_level,
            'is_external' => (bool) $database->is_external,
            'has_password' => $database->hasPassword(),
            'shared_redis' => $database->onSharedRedis(),
            'password_shown' => $database->credentials_shown_at !== null,
        ];
    }
}
