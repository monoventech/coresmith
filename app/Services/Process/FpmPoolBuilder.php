<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Process;

use App\Enums\AppSize;
use App\Models\Application;
use App\Services\Caddy\CaddyRouteBuilder;
use App\Services\Deployment\AppAccount;
use App\Services\Deployment\ReleasePaths;
use App\Services\Runtime\AsdfManager;
use App\Services\SafeName;

final class FpmPoolBuilder
{
    public const DISABLED_FUNCTIONS = 'exec,passthru,shell_exec,system,proc_open,popen,pcntl_exec,dl,putenv';

    public function __construct(private readonly AsdfManager $asdf) {}

    public function poolPath(Application $application): string
    {
        return '/etc/php-fpm.d/coresmith-app-' . SafeName::assertSlug($application->slug) . '.conf';
    }

    public function unitName(Application $application): string
    {
        return 'coresmith-app-' . SafeName::assertSlug($application->slug) . '-fpm';
    }

    public function pool(Application $application): string
    {
        $slug = SafeName::assertSlug($application->slug);
        $user = SafeName::assertSystemUser($application->system_user);
        $socket = (new CaddyRouteBuilder)->fpmSocket($application);
        $size = $this->size($application);
        $maxChildren = $size->maxChildren();
        $base = ReleasePaths::ROOT . '/' . $slug;
        $chdir = (new CaddyRouteBuilder)->documentRoot($application);
        $installs = AsdfManager::INSTALL_DIR;
        $disabled = self::DISABLED_FUNCTIONS;

        return <<<CONFIG
        [global]
        error_log = syslog
        syslog.ident = coresmith-app-{$slug}-fpm

        [coresmith-app-{$slug}]
        user = {$user}
        group = {$user}
        listen = {$socket}
        listen.owner = caddy
        listen.group = caddy
        listen.mode = 0660

        pm = ondemand
        pm.max_children = {$maxChildren}
        pm.process_idle_timeout = 10s
        pm.max_requests = 500

        chdir = {$chdir}
        clear_env = no

        php_admin_value[open_basedir] = {$base}:/tmp:{$installs}
        php_admin_value[disable_functions] = {$disabled}

        CONFIG;
    }

    public function unit(Application $application): string
    {
        $slug = $application->slug;
        $name = $application->name;
        $binary = $this->asdf->fpmBinaryPath((string) $application->php_version);
        $pool = $this->poolPath($application);
        $base = ReleasePaths::ROOT . '/' . $slug;
        $installs = AsdfManager::DATA_DIR;
        $envFile = (new ReleasePaths($application))->envFile();
        $umask = AppAccount::UMASK;
        $sandbox = implode('', array_map(fn(string $property): string => $property . "\n", AppSandbox::properties($application->server)));

        return <<<UNIT
        [Unit]
        Description=Coresmith: {$name} - PHP-FPM
        After=network.target

        [Service]
        Type=simple
        User=root
        UMask={$umask}
        EnvironmentFile={$envFile}
        ExecStart={$binary} --nodaemonize --fpm-config {$pool}
        ExecReload=/bin/kill -USR2 \$MAINPID
        Restart=always
        RestartSec=5

        ProtectSystem=strict
        ReadWritePaths={$base} /var/run/php /tmp
        ReadOnlyPaths={$installs}
        PrivateTmp=true
        {$sandbox}
        [Install]
        WantedBy=multi-user.target

        UNIT;
    }

    private function size(Application $application): AppSize
    {
        return AppSize::tryFrom((string) $application->app_size) ?? AppSize::Small;
    }
}
