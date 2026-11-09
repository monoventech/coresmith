<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\Database\DatabaseManager;
use App\Services\Database\RedisInstance;
use App\Services\Database\RedisPorts;
use App\Services\Database\SharedRedis;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

final class SharedRedisController extends Controller
{
    public function move(Server $server, ManagedDatabase $database, SSHConnectionManager $connections, RedisInstance $instances, RedisPorts $ports): RedirectResponse
    {
        $this->authorize('viewCredentials', $database);
        abort_unless($database->server_id === $server->id && $database->onSharedRedis(), 404);

        $lock = $database->credentialsLock();

        if (!$lock->get()) {
            return back()->with('error', 'Coresmith is still working on this database. Try again in a minute.');
        }

        $password = DatabaseManager::generatePassword();

        try {
            $connection = $connections->for($server);
            $port = $ports->lock($server)->block(RedisPorts::LOCK_SECONDS, function () use ($connection, $instances, $ports, $server, $database, $password): int {
                $port = $ports->next($server, $ports->listening($connection));
                $instances->create($connection, $port, $password);
                $database->forceFill(['port' => $port, 'db_password' => $password, 'credentials_shown_at' => now()])->save();

                return $port;
            });
        } catch (RuntimeException $e) {
            report($e);

            return back()->with('error', "Coresmith couldn't start a Redis of its own for {$database->name} on {$server->name}, so it's still on the shared Redis. Check the server is online, then try again.");
        } finally {
            $connections->disconnect($server);
            $lock->release();
        }

        activity()->performedOn($database)->withProperties(['port' => $port])->log('database_moved_off_shared_redis');

        return back()->with([
            'success' => "Moved {$database->name} to a Redis of its own on port {$port}. Give the apps that use it the new port and password, then deploy.",
            'databasePassword' => $password,
        ]);
    }

    public function turnOff(Server $server, SSHConnectionManager $connections, SharedRedis $shared): RedirectResponse
    {
        $this->authorize('update', $server);
        abort_unless($shared->state($server)['can_turn_off'] ?? false, 404);

        try {
            $connection = $connections->for($server);
            $open = $shared->connections($connection);

            if ($open > 0) {
                return back()->with('error', ($open === 1 ? '1 connection is' : "{$open} connections are") . ' still open to the shared Redis. Point the apps that use it at a Redis of their own and deploy, then try again.');
            }

            $shared->turnOff($connection);
        } catch (RuntimeException $e) {
            report($e);

            return back()->with('error', "Coresmith couldn't turn off the shared Redis on {$server->name}. Check the server is online, then try again.");
        } finally {
            $connections->disconnect($server);
        }

        activity()->performedOn($server)->log('shared_redis_turned_off');

        return back()->with('success', "Shared Redis turned off. Nothing on {$server->name} answers without a password now.");
    }
}
