<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Enums\ApplicationType;
use App\Enums\AppSize;
use App\Models\Application;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\AsdfManager;

final class SystemdUnitBuilder
{
    public const UNIT_DIR = '/etc/systemd/system';

    public function __construct(private readonly AsdfManager $asdf) {}

    public function unitName(Application $application, string $process): string
    {
        return 'coresmith-app-'.$application->slug.'-'.$process;
    }

    public function unitPath(Application $application, string $process): string
    {
        return self::UNIT_DIR.'/'.$this->unitName($application, $process).'.service';
    }

    public function build(Application $application, string $process, string $command): string
    {
        $slug = $application->slug;
        $name = $application->name;
        $user = $application->system_user;
        $size = AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small;
        $workingDirectory = ReleasePaths::ROOT.'/'.$slug.'/current';
        $envFile = '/etc/coresmith/env/'.$slug.'.env';
        $logFile = '/var/coresmith/logs/'.$slug.'/'.$process.'.log';
        $path = $this->pathFor($application);
        $memoryMax = $size->memoryMaxMb();
        $memoryHigh = $size->memoryHighMb();
        $appRoot = ReleasePaths::ROOT.'/'.$slug;
        $installs = AsdfManager::DATA_DIR;

        return <<<UNIT
        [Unit]
        Description=Coresmith: {$name} - {$process}
        After=network.target

        [Service]
        Type=simple
        User={$user}
        Group={$user}
        WorkingDirectory={$workingDirectory}
        Environment="HOME=/home/{$user}"
        Environment="PATH={$path}"
        EnvironmentFile={$envFile}

        ExecStart={$command}

        Restart=always
        RestartSec=5
        StandardOutput=append:{$logFile}
        StandardError=append:{$logFile}

        MemoryMax={$memoryMax}M
        MemoryHigh={$memoryHigh}M

        NoNewPrivileges=true
        ProtectSystem=strict
        ProtectHome=tmpfs
        ReadWritePaths={$appRoot}
        ReadOnlyPaths={$installs}
        PrivateTmp=true
        ProtectKernelTunables=true
        ProtectKernelModules=true
        ProtectControlGroups=true

        [Install]
        WantedBy=multi-user.target

        UNIT;
    }

    public function webCommand(Application $application): ?string
    {
        return match ($application->type) {
            ApplicationType::Js => $this->jsCommand($application),
            ApplicationType::Ruby => $this->asdf->binaryPath('ruby', (string) $application->ruby_version, 'bundle')
                .' exec puma -p '.$application->base_port,
            default => null,
        };
    }

    private function jsCommand(Application $application): string
    {
        $runtime = $application->js_runtime === 'node' ? 'nodejs' : (string) $application->js_runtime;
        $version = $application->node_version;

        $binary = $runtime === 'nodejs'
            ? $this->asdf->binaryPath('nodejs', (string) $version)
            : $this->asdf->binaryPath($runtime, (string) $version);

        return $binary.' server.js';
    }

    private function pathFor(Application $application): string
    {
        $directories = [];

        foreach ([['php', $application->php_version], ['nodejs', $application->node_version], ['ruby', $application->ruby_version]] as [$runtime, $version]) {
            if ($version !== null) {
                $directories[] = dirname($this->asdf->binaryPath($runtime, (string) $version));
            }
        }

        $directories[] = '/usr/local/bin';
        $directories[] = '/usr/bin';
        $directories[] = '/bin';

        return implode(':', $directories);
    }
}
