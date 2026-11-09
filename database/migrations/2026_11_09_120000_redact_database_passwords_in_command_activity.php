<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

use App\Services\Deployment\LogSanitizer;
use App\Services\SSH\SSHConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sanitizer = new LogSanitizer;

        DB::table('activity_log')
            ->where('description', SSHConnection::COMMAND_ACTIVITY)
            ->select(['id', 'properties'])
            ->chunkById(500, function (Collection $rows) use ($sanitizer): void {
                foreach ($rows as $row) {
                    $properties = json_decode((string) $row->properties, true);
                    $command = is_array($properties) ? ($properties['command'] ?? null) : null;

                    if (!is_string($command) || preg_match('/IDENTIFIED|PASSWORD/i', $command) !== 1) {
                        continue;
                    }

                    $redacted = $sanitizer->sanitize($command);

                    if ($redacted !== $command) {
                        DB::table('activity_log')->where('id', $row->id)->update(['properties' => json_encode([...$properties, 'command' => $redacted])]);
                    }
                }
            });
    }
};
