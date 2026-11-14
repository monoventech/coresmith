<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Enums\ApplicationType;
use App\Enums\AppSize;
use App\Exceptions\UnsafeNameException;
use App\Models\Application;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\ReleasePaths;
use App\Services\Deployment\StackDefaults;
use App\Services\Runtime\AsdfManager;
use App\Services\SafeName;

final class SystemdUnitBuilder
{
    public const UNIT_DIR = '/etc/systemd/system';

    public function __construct(private readonly AsdfManager $asdf) {}

    public function unitName(Application $application, string $process): string
    {
        return 'coresmith-app-' . SafeName::assertSlug($application->slug) . '-' . SafeName::assertProcess($process);
    }

    public function unitPath(Application $application, string $process): string
    {
        return self::UNIT_DIR . '/' . $this->unitName($application, $process) . '.service';
    }

    public function build(Application $application, string $process, string $command): string
    {
        $slug = $application->slug;
        $name = $application->name;
        $user = $application->system_user;
        $size = AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small;
        $paths = new ReleasePaths($application);
        $workingDirectory = $paths->appDirectory($paths->current());
        $envFile = '/etc/coresmith/env/' . $slug . '.env';
        $logFile = $paths->processLog($process);
        $path = $this->pathFor($application);
        $memoryMax = $size->memoryMaxMb();
        $appRoot = ReleasePaths::ROOT . '/' . $slug;
        $installs = AsdfManager::DATA_DIR;
        $listen = $process === 'web'
            ? "Environment=\"PORT={$application->base_port}\"\nEnvironment=\"HOST=127.0.0.1\"\n" . ($application->type === ApplicationType::Js ? "Environment=\"HOSTNAME=127.0.0.1\"\n" : '')
            : '';
        $defaults = app(StackDefaults::class)->process($application);
        $production = implode('', array_map(fn(string $name, string $value): string => "Environment=\"{$name}={$value}\"\n", array_keys($defaults), $defaults));
        $command = $process === 'web' ? $command : $this->workerCommand($command);
        $umask = AppAccount::UMASK;
        $sandbox = implode('', array_map(fn(string $property): string => $property . "\n", AppSandbox::properties($application->server)));

        return <<<UNIT
        [Unit]
        Description=Coresmith: {$name} - {$process}
        After=network.target

        [Service]
        Type=simple
        User={$user}
        Group={$user}
        UMask={$umask}
        WorkingDirectory={$workingDirectory}
        Environment="HOME=/home/{$user}"
        Environment="PATH={$path}"
        {$listen}{$production}EnvironmentFile={$envFile}
        BindPaths=-/home/{$user}

        ExecStart={$command}

        Restart=always
        RestartSec=5
        StandardOutput=append:{$logFile}
        StandardError=append:{$logFile}

        MemoryMax={$memoryMax}M

        NoNewPrivileges=true
        ProtectSystem=strict
        ProtectHome=tmpfs
        ReadWritePaths={$appRoot}
        ReadOnlyPaths={$installs}
        PrivateTmp=true
        ProtectKernelTunables=true
        ProtectKernelModules=true
        ProtectControlGroups=true
        {$sandbox}
        [Install]
        WantedBy=multi-user.target

        UNIT;
    }

    public function webCommand(Application $application): ?string
    {
        return $application->type->language()->webCommand($application);
    }

    private function workerCommand(string $command): string
    {
        $pinned = '~^(?:/usr/bin/(?=php(?:\s|\z))|' . preg_quote(AsdfManager::INSTALL_DIR, '~') . '/[a-z]+/[A-Za-z0-9._-]+/bin/(?=[^/\s]+(?:\s|\z)))~';
        $command = (string) preg_replace($pinned, '', trim($command));

        if ($command === '') {
            throw UnsafeNameException::workerCommand();
        }

        return str_starts_with($command, '/') ? $command : '/usr/bin/env ' . $command;
    }

    private function pathFor(Application $application): string
    {
        $directories = [];

        foreach ($application->runtimeVersions() as $runtime => $version) {
            AsdfManager::assertValid($runtime, $version);
            $directories[] = $this->asdf->versionPath($runtime, $version) . '/bin';
        }

        return implode(':', [...$directories, '/usr/local/bin', '/usr/bin', '/bin']);
    }
}
