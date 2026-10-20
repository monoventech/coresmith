<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Deployment;

use App\Models\Application;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;

final class AppAccount
{
    public const WEB_GROUP = 'caddy';

    public const UMASK = '0027';

    public function ensure(SSHConnection $connection, Application $application): void
    {
        $connection->execute($this->command($application), sudo: true)->throw();
    }

    public function command(Application $application): string
    {
        $user = SafeName::assertSystemUser($application->system_user);
        $paths = new ReleasePaths($application);
        $home = self::home($application);
        $base = $paths->base();
        $releases = $paths->releases();
        $shared = $paths->shared();
        $group = self::WEB_GROUP;

        return implode(' && ', [
            "{ id -u {$user} >/dev/null 2>&1 || useradd --system --create-home --home-dir {$home} --shell /usr/sbin/nologin $(getent group {$user} >/dev/null && echo \"-g {$user}\" || echo --user-group) {$user}; }",
            'mkdir -p ' . ReleasePaths::ROOT,
            'chmod 751 ' . ReleasePaths::ROOT,
            "mkdir -p {$releases} {$shared}",
            "chown root:root {$base} {$releases}",
            "chmod 711 {$base} {$releases}",
            "chown -h {$user}:{$group} {$shared}",
            "chmod 2750 {$shared}",
            "find {$releases} -mindepth 1 -maxdepth 1 -type d -regextype posix-extended -regex '.*/[0-9]{8}-?[0-9]{6}' ! -user {$user} -exec chown -hR {$user}:{$group} {} + -exec chmod 750 {} +",
        ]);
    }

    public function claimRelease(Application $application, string $release): string
    {
        $user = SafeName::assertSystemUser($application->system_user);
        $path = escapeshellarg($release);

        return implode(' && ', [
            'chmod -R u+rwX,g+rX,g-w,o-rwx ' . $path,
            'chmod 750 ' . $path,
            'find ' . $path . ' -type d -exec chmod g+s {} +',
            'chown -hR ' . $user . ':' . self::WEB_GROUP . ' ' . $path,
        ]);
    }

    public function shareStorage(Application $application): string
    {
        $shared = (new ReleasePaths($application))->shared();
        $storage = escapeshellarg($shared . '/storage');
        $group = self::WEB_GROUP;
        $folders = 'systemd-run --quiet --wait --pipe --collect --service-type=exec'
            . ' -p ProtectSystem=strict -p ' . escapeshellarg('ReadWritePaths=' . $shared)
            . ' -p PrivateDevices=yes -p PrivateTmp=yes -p ProtectKernelTunables=yes -p ProtectControlGroups=yes -p NoNewPrivileges=yes'
            . " -- /usr/bin/find {$storage} -type d";

        return "if [ -d {$storage} ]; then {$folders} '!' -group {$group} -exec chgrp {$group} '{}' + && {$folders} '!' -perm -2000 -exec chmod g+s '{}' +; fi";
    }

    public static function home(Application $application): string
    {
        return '/home/' . SafeName::assertSystemUser($application->system_user);
    }
}
