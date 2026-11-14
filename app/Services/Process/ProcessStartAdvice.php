<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Models\Application;
use App\Services\Deployment\EnvironmentFile;

final class ProcessStartAdvice
{
    public function for(Application $application, string $output): string
    {
        $port = $application->base_port;
        $slug = $application->slug;
        $railsEnv = $this->railsEnv($application);

        return match (true) {
            str_contains($output, 'Failed to load environment files') => "The app's environment file is missing from the server. Deploy again to write it.",
            str_contains($output, 'Failed to determine user credentials') || str_contains($output, 'status=217/USER') => "The app's account on the server is missing. Deploy again to create it.",
            str_contains($output, 'Failed to set up mount namespacing') || str_contains($output, 'status=226/NAMESPACE') => "The server couldn't set up the app's sandbox. Check the app's folders exist under /var/coresmith/apps/{$slug}/, then deploy again.",
            $railsEnv !== null && str_contains($output, 'cannot load such file') => "This Rails app runs with RAILS_ENV={$railsEnv}, but its gems were installed for production only. Set RAILS_ENV=production in its environment variables, then deploy again.",
            str_contains($output, 'Environment: development') && str_contains($output, 'cannot load such file') => 'This Rails app started in development mode. Set RAILS_ENV=production in its environment variables, then deploy again.',
            str_contains($output, 'Address already in use') || str_contains($output, 'EADDRINUSE') => "Port {$port} is already taken by another process. Find it with ss -tlnp | grep {$port}, stop it, then deploy again.",
            str_contains($output, 'EACCES') => 'The app wasn\'t allowed to listen on its port. Check nothing else on the server uses it, then deploy again.',
            str_contains($output, 'MODULE_NOT_FOUND') || str_contains($output, 'Cannot find module') => 'A Node module is missing. The dependency install may have failed without saying so, so deploy again.',
            str_contains($output, 'Permission denied') => "The process could not read its files. Check ownership with ls -la /var/coresmith/apps/{$slug}/current/, then deploy again.",
            str_contains($output, 'No such file or directory') => 'The entry point was not found. Check the build produced the file the process starts, then deploy again.',
            str_contains($output, 'SyntaxError') || str_contains($output, 'Parse error') => 'The code has a syntax error. The process log names the file and line.',
            default => 'The process log says why. Fix it and deploy again, or roll back to the previous release.',
        };
    }

    private function railsEnv(Application $application): ?string
    {
        $value = (new EnvironmentFile)->parse((string) $application->env_variables)['RAILS_ENV'] ?? '';

        return in_array($value, ['', 'production'], true) ? null : $value;
    }
}
