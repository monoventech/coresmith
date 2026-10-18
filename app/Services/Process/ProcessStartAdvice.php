<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Models\Application;

final class ProcessStartAdvice
{
    public function for(Application $application, string $journal): string
    {
        $port = $application->base_port;
        $slug = $application->slug;

        return match (true) {
            str_contains($journal, 'Failed to load environment files') => "The app's environment file is missing from the server. Deploy again to write it.",
            str_contains($journal, 'Failed to determine user credentials') || str_contains($journal, 'status=217/USER') => "The app's Linux user is missing from the server. Deploy again to create it.",
            str_contains($journal, 'Failed to set up mount namespacing') || str_contains($journal, 'status=226/NAMESPACE') => "systemd could not set up the app's sandbox. Check the app's folders exist under /var/coresmith/apps/{$slug}/, then deploy again.",
            str_contains($journal, 'Address already in use') || str_contains($journal, 'EADDRINUSE') => "Port {$port} is already taken by another process. Find it with ss -tlnp | grep {$port}, stop it, then deploy again.",
            str_contains($journal, 'EACCES') => 'The process was not allowed to bind its port. Check nothing else owns the port block, then deploy again.',
            str_contains($journal, 'MODULE_NOT_FOUND') || str_contains($journal, 'Cannot find module') => 'A Node module is missing. Deploy again, the dependency install may have failed quietly.',
            str_contains($journal, 'Permission denied') => "The process could not read its files. Check ownership with ls -la /var/coresmith/apps/{$slug}/current/, then deploy again.",
            str_contains($journal, 'No such file or directory') => 'The entry point was not found. Check the build produced the file the process starts, then deploy again.',
            str_contains($journal, 'SyntaxError') || str_contains($journal, 'Parse error') => 'The code has a syntax error. The process log names the file and line.',
            default => 'The process log says why. Fix it and deploy again, or roll back to the previous release.',
        };
    }
}
