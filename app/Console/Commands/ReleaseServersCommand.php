<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\Address\AtomicFile;
use App\Services\Server\CoresmithKey;
use Illuminate\Console\Command;

final class ReleaseServersCommand extends Command
{
    protected $signature = 'coresmith:release-servers {--report= : Write what happened to this file, as JSON}';

    protected $description = "Take Coresmith's key off every server it manages";

    public function handle(CoresmithKey $keys): int
    {
        $servers = Server::query()->where('runs_coresmith', false)->orderBy('name')->orderBy('id')->get();
        $removed = [];
        $failed = [];

        foreach ($servers as $server) {
            if ($keys->takeOff($server) === CoresmithKey::REMOVED) {
                $removed[] = (string) $server->name;
                $this->line("Took Coresmith's key off {$server->name}.");

                continue;
            }

            $failed[] = ['name' => (string) $server->name, 'file' => CoresmithKey::file($server), 'comment' => CoresmithKey::comment($server)];
            $this->line("Couldn't reach {$server->name}. Remove the line ending in " . CoresmithKey::comment($server) . ' from ' . CoresmithKey::file($server) . ' there.');
        }

        $this->line("Took Coresmith's key off " . count($removed) . ' of ' . $servers->count() . ' servers.');

        $report = $this->option('report');

        if (is_string($report) && $report !== '') {
            AtomicFile::write($report, (string) json_encode(['removed' => $removed, 'failed' => $failed]), 0640, "Coresmith couldn't write what it released to {$report}");
        }

        return self::SUCCESS;
    }
}
