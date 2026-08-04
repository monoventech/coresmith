<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Database\StoreDatabaseRequest;
use App\Jobs\BackupDatabase;
use App\Jobs\CreateDatabase;
use App\Models\ManagedDatabase;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class DatabaseController extends Controller
{
    public function index(Server $server): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('databases/Index', [
            'server' => ['id' => $server->id, 'name' => $server->name],
            'databases' => $server->databases()->latest()->get()->map(
                fn (ManagedDatabase $database): array => $this->summarise($database),
            ),
        ]);
    }

    public function create(Server $server): Response
    {
        $this->authorize('view', $server);

        return Inertia::render('databases/Create', [
            'server' => ['id' => $server->id, 'name' => $server->name],
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
            'server' => ['id' => $server->id, 'name' => $server->name],
            'database' => $this->summarise($database),
            'can' => [
                'viewCredentials' => auth()->user()?->can('viewCredentials', $database) ?? false,
                'backup' => auth()->user()?->can('backup', $database) ?? false,
                'delete' => auth()->user()?->can('delete', $database) ?? false,
            ],
        ]);
    }

    public function destroy(Server $server, ManagedDatabase $database): RedirectResponse
    {
        $this->authorize('delete', $database);
        $this->assertBelongsTo($server, $database);

        $database->delete();

        return to_route('servers.databases.index', $server)->with('success', 'Database removed.');
    }

    public function backup(Server $server, ManagedDatabase $database): RedirectResponse
    {
        $this->authorize('backup', $database);
        $this->assertBelongsTo($server, $database);

        BackupDatabase::dispatch($database, now()->format('YmdHis'));

        return back()->with('success', 'Backup started.');
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
        ];
    }
}
