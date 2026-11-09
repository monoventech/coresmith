<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ManagedDatabase;
use App\Models\Server;
use App\Services\Database\DatabaseManager;
use App\Services\SSH\SSHConnectionManager;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

final class DatabasePasswordController extends Controller
{
    public function show(Server $server, ManagedDatabase $database): RedirectResponse
    {
        $this->authorize('viewCredentials', $database);
        $this->assertHasPassword($server, $database);

        if ($database->db_password === null) {
            return back()->with('error', 'Coresmith is still creating this database. Try again in a minute.');
        }

        $claimed = ManagedDatabase::query()->whereKey($database->getKey())->whereNull('credentials_shown_at')->update(['credentials_shown_at' => now()]);

        if ($claimed !== 1) {
            return back()->with('error', 'The password was shown once already. Rotate it to get a new one.');
        }

        activity()->performedOn($database)->log('database_password_shown');

        return back()->with('databasePassword', (string) $database->db_password);
    }

    public function update(Server $server, ManagedDatabase $database, SSHConnectionManager $connections, DatabaseManager $databases): RedirectResponse
    {
        $this->authorize('viewCredentials', $database);
        $this->assertHasPassword($server, $database);

        $lock = $database->credentialsLock();

        if (!$lock->get()) {
            return back()->with('error', 'Coresmith is still working on this database. Try again in a minute.');
        }

        $password = DatabaseManager::generatePassword();

        try {
            $rotated = $this->rotate($connections, $databases, $server, $database, $password);
        } finally {
            $lock->release();
        }

        if (!$rotated) {
            return back()->with('error', "Coresmith couldn't change the password on {$server->name}, so the old one still works. Check the server is online, then try again.");
        }

        return back()->with([
            'success' => 'Password rotated. Give the apps that use it the new one, then deploy.',
            'databasePassword' => $password,
        ]);
    }

    private function rotate(SSHConnectionManager $connections, DatabaseManager $databases, Server $server, ManagedDatabase $database, #[\SensitiveParameter] string $password): bool
    {
        try {
            $databases->changePassword($connections->for($server), $database, $password);
        } catch (RuntimeException $e) {
            report($e);

            return false;
        } finally {
            $connections->disconnect($server);
        }

        $database->forceFill(['db_password' => $password, 'credentials_shown_at' => now()])->save();

        activity()->performedOn($database)->log('database_password_rotated');

        return true;
    }

    private function assertHasPassword(Server $server, ManagedDatabase $database): void
    {
        abort_unless($database->server_id === $server->id && $database->hasPassword(), 404);
    }
}
