<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Database\RestoreDatabaseRequest;
use App\Jobs\RestoreDatabase;
use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\Database\DatabaseBackups;
use Illuminate\Http\RedirectResponse;

final class DatabaseRestoreController extends Controller
{
    public function store(RestoreDatabaseRequest $request, Server $server, ManagedDatabase $database, DatabaseBackups $backups): RedirectResponse
    {
        abort_unless($database->server_id === $server->id && $database->takesBackups(), 404);

        if ($database->restoring()) {
            return back()->with('error', "{$database->name} is already being restored.");
        }

        $backup = $backups->find($database, $request->string('stamp')->value());

        if ($backup === null) {
            return back()->with('error', "That backup isn't there anymore. Pick another one.");
        }

        $by = (string) $request->user()->getKey();
        $claimed = ManagedDatabase::query()
            ->whereKey($database->getKey())
            ->where(fn($query) => $query->whereNull('restore')->orWhere('restore->state', '!=', 'running'))
            ->update(['restore' => json_encode([
                'state' => 'running',
                'stamp' => $backup->stamp,
                'step' => 'queued',
                'safety' => null,
                'error' => null,
                'by' => $by,
                'started_at' => now()->toIso8601String(),
                'finished_at' => null,
            ])]);

        if ($claimed === 0) {
            return back()->with('error', "{$database->name} is already being restored.");
        }

        RestoreDatabase::dispatch($database->fresh() ?? $database, $backup->stamp, $backup->onServer() ? null : $backup->key, $backup->size, array_values(array_map(strval(...), (array) $request->validated('stop', []))), $by);

        return back()->with('success', "Restoring {$database->name} from {$backup->at->format('M j, H:i')}.");
    }
}
