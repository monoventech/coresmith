<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DatabaseType;
use App\Enums\PlanFeature;
use App\Http\Requests\Database\DestroyDatabaseRequest;
use App\Http\Requests\Database\StoreDatabaseRequest;
use App\Jobs\BackupDatabase;
use App\Jobs\CreateDatabase;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\Billing\TeamBilling;
use App\Services\Database\DatabaseManager;
use App\Services\Panel\Formats;
use App\Services\Panel\ServerPresenter;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class DatabaseController extends Controller
{
    public function index(Request $request, Server $server, ServerPresenter $presenter): Response|RedirectResponse
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
            'can' => ['create' => $request->user()->can('create', ManagedDatabase::class)],
        ]);
    }

    public function create(Server $server): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('databases/Create', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => route('servers.databases.index', $server)],
        ]);
    }

    public function store(StoreDatabaseRequest $request, Server $server): RedirectResponse
    {
        $database = ManagedDatabase::create([
            ...$request->safe()->except([]),
            'server_id' => $server->id,
            'team_id' => $server->team_id,
            'port' => $this->defaultPort($request->string('type')->value()),
        ]);

        CreateDatabase::dispatch($database);

        return to_route('servers.databases.index', $server)
            ->with('success', 'Database queued for creation.');
    }

    public function show(Server $server, ManagedDatabase $database): Response
    {
        $this->authorize('view', $database);
        $this->assertBelongsTo($server, $database);

        return Inertia::render('databases/Show', [
            'server' => ['id' => $server->id, 'name' => $server->name, 'href' => route('servers.databases.index', $server)],
            'database' => $this->summarise($database),
            'can' => [
                'viewCredentials' => auth()->user()?->can('viewCredentials', $database) ?? false,
                'backup' => (auth()->user()?->can('backup', $database) ?? false) && $database->takesBackups(),
                'delete' => auth()->user()?->can('delete', $database) ?? false,
            ],
            'offsite' => !$this->goesToS3($database) ? null : [
                'sent' => Formats::when($database->last_offsite_at),
                'error' => $database->last_offsite_error,
            ],
        ]);
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

        return to_route('servers.databases.index', $server)->with('success', $database->onSharedRedis()
            ? "Removed {$database->name}. The shared Redis on {$server->name} keeps what's in it."
            : "Deleted {$database->name} and its user from {$server->name}.");
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

        BackupDatabase::dispatch($database, now()->format('YmdHis'), offsite: $this->goesToS3($database));

        return back()->with('success', 'Backup started.');
    }

    private function goesToS3(ManagedDatabase $database): bool
    {
        $team = $database->team;

        return in_array($database->type, [DatabaseType::Mysql, DatabaseType::Postgresql], true)
            && $team?->backupDestination !== null
            && app(TeamBilling::class)->status($team)->allows(PlanFeature::OffsiteBackups);
    }

    private function assertBelongsTo(Server $server, ManagedDatabase $database): void
    {
        abort_unless($database->server_id === $server->id, 404);
    }

    private function defaultPort(string $type): int
    {
        return match ($type) {
            'postgresql' => 5432,
            'redis' => 6379,
            default => 3306,
        };
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
