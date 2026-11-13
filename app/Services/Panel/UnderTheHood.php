<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Server;
use App\Models\User;
use App\Services\Caddy\CaddyApiClient;
use App\Services\Database\SharedRedis;
use App\Services\Deployment\ReleasePaths;
use App\Services\Process\ProcessManager;
use App\Services\Runtime\AsdfManager;
use App\Services\Server\FirewallManagerFactory;
use App\Services\Server\ProtectionCheck;
use App\Services\Server\SecurityHardening;
use App\Services\Teams\TeamAccess;

final class UnderTheHood
{
    public const ISOLATION = [
        'Each app runs as a Linux user of its own',
        'systemd sandboxing on every process: NoNewPrivileges, ProtectSystem, PrivateTmp',
        'App folders and files closed to the other apps',
        'Environment files owned by root, handed to the app by systemd at start',
        self::CREDENTIALS,
        'A memory limit per app (MemoryMax)',
        'A block of ten ports per app, never shared',
        'A Caddy route per app, matched on its own domains',
        'Log files per app',
    ];

    public const CREDENTIALS = 'Database credentials per app';

    public const CREDENTIALS_EXCEPT_SHARED_REDIS = 'Database credentials per app, except the shared Redis on port 6379';

    private const DATABASES = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'postgresql' => 'PostgreSQL', 'redis' => 'Redis'];

    public function __construct(
        private readonly ProcessManager $processes,
        private readonly TeamAccess $access,
        private readonly SharedRedis $sharedRedis,
    ) {}

    /**
     * @return array{apps: list<array<string, mixed>>, services: list<array{name: string, detail: string}>, isolation: list<string>, files: list<array{path: string, what: string}>}
     */
    public function for(Server $server, User $user): array
    {
        return [
            'apps' => $this->access->applicationsOn($user, $server)->orderBy('name')->get()->map(fn(Application $application): array => [
                'id' => $application->id,
                'name' => $application->name,
                'href' => route('servers.applications.show', [$server, $application]),
                'user' => $application->system_user,
                'ports' => ApplicationPresenter::portBlock($application) . ($application->ssr_port === null ? '' : ", {$application->ssr_port}"),
                'folder' => ReleasePaths::ROOT . '/' . $application->slug,
                'processes' => $this->processes->unitNames($application),
            ])->values()->all(),
            'services' => $this->services($server, $user),
            'isolation' => $this->sharedRedis->state($server) === null
                ? self::ISOLATION
                : array_map(fn(string $layer): string => $layer === self::CREDENTIALS ? self::CREDENTIALS_EXCEPT_SHARED_REDIS : $layer, self::ISOLATION),
            'files' => $this->files($server),
        ];
    }

    /**
     * @return list<array{name: string, detail: string}>
     */
    private function services(Server $server, User $user): array
    {
        $installed = array_values(array_intersect_key(self::DATABASES, array_flip((array) (((array) $server->meta)['installed_databases'] ?? []))));

        return array_values(array_filter([
            ['name' => 'Caddy', 'detail' => 'Serves every app over HTTPS and renews its certificates. Coresmith changes its routes through ' . ($server->caddyAdminOnSocket() ? 'the admin socket at ' . CaddyApiClient::ADMIN_SOCKET . ', which only root and caddy can open.' : 'its admin API on localhost:' . CaddyApiClient::ADMIN_PORT . '.')],
            ['name' => 'systemd', 'detail' => 'Runs each app process as a unit named coresmith-app-{app}-{process}, and starts it again if it stops.'],
            $this->access->applicationsOn($user, $server)->where('type', ApplicationType::Php->value)->exists() ? ['name' => 'PHP-FPM', 'detail' => 'One pool per PHP app, running as that app\'s user.'] : null,
            ['name' => 'asdf', 'detail' => 'Installs the language versions apps use, in ' . AsdfManager::DATA_DIR . ', owned by ' . $server->runtimeUser() . '.'],
            $installed === [] ? null : ['name' => implode(', ', $installed), 'detail' => 'Each app database has a user and password of its own, on localhost only.'],
            ['name' => FirewallManagerFactory::packageFor($server), 'detail' => $server->runs_coresmith ? 'The firewall this machine already had, with the web ports opened.' : 'Closed by default, with SSH and the web ports open.'],
        ]));
    }

    /**
     * @return list<array{path: string, what: string}>
     */
    private function files(Server $server): array
    {
        $apt = in_array($server->os_name, ProtectionCheck::APT_FAMILY, true);

        return array_values(array_filter([
            ['path' => ReleasePaths::ROOT, 'what' => 'A folder per app, with its releases and the files kept between them.'],
            $server->runs_coresmith ? null : ['path' => SecurityHardening::SSHD_CONFIG, 'what' => 'SSH settings: keys only, modern ciphers.'],
            ['path' => SecurityHardening::FAIL2BAN_JAIL, 'what' => 'fail2ban blocks an address for an hour after 5 failed sign-ins in 10 minutes.'],
            ['path' => SecurityHardening::SYSCTL_CONFIG, 'what' => 'Kernel network and process settings.'],
            $apt
                ? ['path' => '/etc/apt/apt.conf.d/20auto-upgrades', 'what' => 'unattended-upgrades installs security updates every day.']
                : ['path' => '/etc/dnf/automatic.conf', 'what' => 'dnf-automatic installs security updates.'],
            $server->audit_logging_enabled ? ['path' => SecurityHardening::AUDIT_RULES, 'what' => 'auditd records changes to sudo rules, SSH settings, users and cron jobs.'] : null,
        ]));
    }
}
