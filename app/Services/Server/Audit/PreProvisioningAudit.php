<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server\Audit;

use App\Enums\AuditSeverity;
use App\Enums\Edition;
use App\Models\Server;
use App\Services\Network\OwnAddress;
use App\Services\Server\PortAllocator;
use App\Services\Server\PortScanner;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Str;

final class PreProvisioningAudit
{
    public const FIXES = ['stop-nginx' => 'nginx', 'stop-apache2' => 'apache2', 'stop-httpd' => 'httpd'];

    private const CORESMITH_INTERNAL_PORT = 8484;

    private const RUNTIMES = ['php' => 'PHP', 'node' => 'Node.js', 'ruby' => 'Ruby', 'bun' => 'Bun', 'deno' => 'Deno'];

    public const PANEL_CHECK = 'ls -d /opt/coresmith /etc/systemd/system/coresmith-horizon.service /etc/systemd/system/coresmith-reverb.service /etc/systemd/system/coresmith-scheduler.timer /etc/systemd/system/coresmith-update.path 2>/dev/null';

    private const PROBE = <<<'BASH'
    echo '== ports'
    ss -tlnpH 2>/dev/null
    echo '== services'
    systemctl list-units --type=service --state=running --no-legend --plain 2>/dev/null | awk '{print $1}'
    echo '== users'
    getent passwd | cut -d: -f1 | grep -E '^coresmith(-app-.+)?$'
    echo '== disk'
    df -Pk / | awk 'NR==2 {print $4}'
    echo '== memory'
    awk '/^MemTotal:/ {print $2}' /proc/meminfo
    echo '== selinux'
    getenforce 2>/dev/null
    echo '== apparmor'
    cat /sys/module/apparmor/parameters/enabled 2>/dev/null
    echo '== runtimes'
    for bin in php node ruby bun deno; do
      if command -v "$bin" >/dev/null 2>&1; then printf '%s %s\n' "$bin" "$("$bin" --version 2>/dev/null | head -n 1)"; fi
    done
    echo '== managers'
    ls -d /root/.nvm /root/.rbenv /root/.rvm /home/*/.nvm /home/*/.rbenv /home/*/.rvm 2>/dev/null
    echo '== docker'
    ps -C dockerd -o rss= 2>/dev/null | head -n 1
    echo '== panel'
    BASH . "\n" . self::PANEL_CHECK . "\nexit 0";

    public function __construct(
        private readonly PortScanner $ports,
        private readonly OwnAddress $own,
    ) {}

    public function run(SSHConnection $connection): AuditReport
    {
        $server = $connection->server();
        $sudo = $server->ssh_user === 'root' ? '' : 'sudo ';
        $command = 'echo ' . escapeshellarg(base64_encode(self::PROBE)) . ' | base64 -d | ' . $sudo . 'bash -s';

        return $this->report($connection->execute($command)->throw()->output, $server);
    }

    public function report(string $output, Server $server): AuditReport
    {
        $sections = $this->sections($output);
        $ownMachine = (bool) $server->runs_coresmith;

        if (($sections['panel'] ?? []) !== [] && !$ownMachine) {
            return new AuditReport([new AuditFinding('panel_host', AuditSeverity::Blocker, 'This server runs Coresmith itself', !Edition::current()->isCloud() && $this->own->is((string) $server->ip_address)
                ? 'Detach it here, then add it again with This machine under Provider in Add server.'
                : 'Add a different server for your apps.')]);
        }

        $listeners = $this->ports->parse(implode("\n", $sections['ports'] ?? []));
        $services = array_map(fn(string $unit): string => Str::before($unit, '.service'), $sections['services'] ?? []);
        $ports = $this->portFindings($listeners, $services, $ownMachine);
        $covered = array_filter(array_map(fn(AuditFinding $finding): ?string => $finding->fix, $ports));

        return new AuditReport([
            ...(($sections['panel'] ?? []) !== [] ? [new AuditFinding('panel_host', AuditSeverity::Info, 'Coresmith runs on this machine', 'It keeps running beside your apps, which get users, folders and processes of their own.')] : []),
            ...$ports,
            ...$this->serviceFindings($services, $covered, (int) ($sections['docker'][0] ?? 0), $ownMachine),
            ...$this->userFindings($sections['users'] ?? [], $server),
            ...$this->capacityFindings((int) ($sections['disk'][0] ?? 0), (int) ($sections['memory'][0] ?? 0)),
            ...$this->securityFindings((string) ($sections['selinux'][0] ?? ''), (string) ($sections['apparmor'][0] ?? '')),
            ...$this->runtimeFindings($sections['runtimes'] ?? [], $sections['managers'] ?? []),
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function sections(string $output): array
    {
        $sections = [];
        $current = null;

        foreach (preg_split('/\r\n|\n|\r/', $output) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^== ([a-z]+)$/', $line, $matches) === 1) {
                $current = $matches[1];
                $sections[$current] = [];
            } elseif ($current !== null && $line !== '') {
                $sections[$current][] = $line;
            }
        }

        return $sections;
    }

    /**
     * @param  array<int, string>  $listeners
     * @param  list<string>  $services
     * @return list<AuditFinding>
     */
    private function portFindings(array $listeners, array $services, bool $ownMachine): array
    {
        $findings = [];
        $coresmiths = [];

        if ($listeners === []) {
            return [new AuditFinding('ports_unknown', AuditSeverity::Warning, 'Coresmith could not read the listening ports', 'Install iproute2 so ss is available, then check again, or confirm yourself that nothing holds ports 80 and 443.')];
        }

        foreach ([80, 443] as $port) {
            if (!isset($listeners[$port])) {
                continue;
            }

            $process = $listeners[$port];

            if ($ownMachine && $process === 'caddy') {
                $coresmiths[] = $port;

                continue;
            }
            $fix = $this->webServerFix($process, $services);

            $findings[] = new AuditFinding(
                "port_{$port}",
                AuditSeverity::Blocker,
                "Port {$port} is in use by {$process}",
                "Caddy needs port {$port} to serve your apps over HTTPS. " . ($fix === null ? "Stop {$process} and free the port, then check again." : "Stop and disable {$process}, or let Coresmith do it."),
                $fix,
            );
        }

        if ($coresmiths !== []) {
            $findings[] = new AuditFinding('ports_coresmith', AuditSeverity::Info, "Coresmith's Caddy holds " . (count($coresmiths) === 2 ? 'ports 80 and 443' : "port {$coresmiths[0]}"), 'Provisioning gives it the config every server uses, so it serves your apps beside Coresmith.');
        }

        if (!isset($listeners[80]) && !isset($listeners[443])) {
            $findings[] = new AuditFinding('ports_free', AuditSeverity::Info, 'Ports 80 and 443 are free', 'Caddy takes them when provisioning runs.');
        }

        foreach ($listeners as $port => $process) {
            if (!PortAllocator::isInAllocatableRange($port)) {
                continue;
            }

            $findings[] = $ownMachine && $port === self::CORESMITH_INTERNAL_PORT && $process === 'caddy'
                ? new AuditFinding("app_port_{$port}", AuditSeverity::Info, "Port {$port} is Coresmith's", "Caddy asks Coresmith there before it issues a certificate for a team's domain. Apps skip that port block.")
                : new AuditFinding("app_port_{$port}", AuditSeverity::Warning, "Port {$port} is in use by {$process}", 'It is inside the range Coresmith gives apps (8000–9999). Apps skip that port block, or stop the process to free it.');
        }

        return $findings;
    }

    /**
     * @param  list<string>  $services
     */
    private function webServerFix(string $process, array $services): ?string
    {
        $fix = 'stop-' . $process;

        return isset(self::FIXES[$fix]) && in_array($process, $services, true) ? $fix : null;
    }

    /**
     * @param  list<string>  $services
     * @param  array<int, string>  $covered
     * @return list<AuditFinding>
     */
    private function serviceFindings(array $services, array $covered, int $dockerRssKb, bool $ownMachine): array
    {
        $findings = [];

        foreach (self::FIXES as $fix => $service) {
            if (in_array($service, $services, true) && !in_array($fix, $covered, true)) {
                $findings[] = new AuditFinding("service_{$service}", AuditSeverity::Blocker, "{$service} is running", "Coresmith serves apps through Caddy, so {$service} has to stop. Stop and disable it, or let Coresmith do it.", $fix);
            }
        }

        $mysql = array_values(array_intersect(['mariadb', 'mysql', 'mysqld'], $services));

        if ($mysql !== []) {
            $name = $mysql[0] === 'mariadb' ? 'MariaDB' : 'MySQL';
            $findings[] = new AuditFinding('mysql', AuditSeverity::Info, "{$name} is already running", 'Coresmith creates app databases on it and never reconfigures, restarts or removes it.');
        }

        if (array_filter($services, fn(string $service): bool => $service === 'postgresql' || str_starts_with($service, 'postgresql@')) !== []) {
            $findings[] = new AuditFinding('postgresql', AuditSeverity::Info, 'PostgreSQL is already running', 'Coresmith creates app databases on it and never reconfigures, restarts or removes it.');
        }

        if (array_intersect(['redis-server', 'redis'], $services) !== []) {
            $findings[] = new AuditFinding('redis', AuditSeverity::Info, 'Redis is already running', 'Coresmith leaves it alone and gives each Redis database an instance of its own, with its own password.');
        }

        if (array_filter($services, fn(string $service): bool => preg_match('/^php[0-9.]*-fpm$/', $service) === 1) !== []) {
            $findings[] = $ownMachine
                ? new AuditFinding('php_fpm', AuditSeverity::Info, 'PHP-FPM runs Coresmith here', 'Coresmith adds a separate pool per app beside its own.')
                : new AuditFinding('php_fpm', AuditSeverity::Warning, 'PHP-FPM is running with its own pools', 'Coresmith adds a separate pool per app and leaves the existing pools alone.');
        }

        if (in_array('docker', $services, true)) {
            $findings[] = new AuditFinding('docker', AuditSeverity::Info, 'Docker is running, using about ' . intdiv($dockerRssKb, 1024) . ' MB of memory', 'Coresmith does not use Docker. Leave it running, or stop it to free the memory.');
        }

        return $findings;
    }

    /**
     * @param  list<string>  $users
     * @return list<AuditFinding>
     */
    private function userFindings(array $users, Server $server): array
    {
        $findings = [];

        if (in_array('coresmith', $users, true)) {
            $findings[] = match (true) {
                (bool) $server->runs_coresmith => new AuditFinding('user_coresmith', AuditSeverity::Info, "The coresmith user is Coresmith's own here", 'Apps get users of their own, and runtimes belong to coresmith-runtime.'),
                (int) ($server->provisioning_phase ?? 0) > 0 => new AuditFinding('user_coresmith', AuditSeverity::Info, 'The coresmith user exists from an earlier run', 'Provisioning keeps using it.'),
                default => new AuditFinding('user_coresmith', AuditSeverity::Blocker, 'A coresmith user already exists', 'Coresmith creates and owns a user with that name. Rename or remove the existing user, then check again.'),
            };
        }

        $apps = count(array_filter($users, fn(string $user): bool => str_starts_with($user, 'coresmith-app-')));

        if ($apps > 0) {
            $findings[] = new AuditFinding('app_users', AuditSeverity::Warning, $apps . ' Coresmith app ' . ($apps === 1 ? 'user exists' : 'users exist'), 'Coresmith may have provisioned this server before. Provisioning reuses those users.');
        }

        return $findings;
    }

    /**
     * @return list<AuditFinding>
     */
    private function capacityFindings(int $diskKb, int $memoryKb): array
    {
        $findings = [];
        $diskGb = round($diskKb / 1048576, 1);
        $memoryMb = intdiv($memoryKb, 1024);

        if ($diskKb > 0 && $diskGb < 5) {
            $findings[] = new AuditFinding('disk', AuditSeverity::Blocker, "Only {$diskGb} GB of disk is free", 'Coresmith needs at least 5 GB, and 10 GB leaves room for releases. Free some space or grow the disk, then check again.');
        } elseif ($diskKb > 0 && $diskGb < 10) {
            $findings[] = new AuditFinding('disk', AuditSeverity::Warning, "Only {$diskGb} GB of disk is free", 'Provisioning fits, but releases and logs fill it quickly. 10 GB or more is recommended.');
        }

        if ($memoryKb > 0 && $memoryMb < 512) {
            $findings[] = new AuditFinding('memory', AuditSeverity::Blocker, "Only {$memoryMb} MB of memory", 'Coresmith needs at least 512 MB, and 1 GB is recommended. Resize the server, then check again.');
        } elseif ($memoryKb > 0 && $memoryMb < 1024) {
            $findings[] = new AuditFinding('memory', AuditSeverity::Warning, "Only {$memoryMb} MB of memory", 'Enough to start, but apps will be tight. 1 GB or more is recommended.');
        }

        return $findings;
    }

    /**
     * @return list<AuditFinding>
     */
    private function securityFindings(string $selinux, string $apparmor): array
    {
        $findings = [];

        if (strcasecmp($selinux, 'Enforcing') === 0) {
            $findings[] = new AuditFinding('selinux', AuditSeverity::Warning, 'SELinux is enforcing', 'Coresmith has no SELinux policies yet, so Caddy or app processes may be blocked. Set it to permissive before provisioning, or be ready to allow them yourself.');
        }

        if (in_array(strtoupper($apparmor), ['Y', 'YES'], true)) {
            $findings[] = new AuditFinding('apparmor', AuditSeverity::Info, 'AppArmor is on', 'Coresmith works alongside it, nothing to change.');
        }

        return $findings;
    }

    /**
     * @param  list<string>  $runtimes
     * @param  list<string>  $managers
     * @return list<AuditFinding>
     */
    private function runtimeFindings(array $runtimes, array $managers): array
    {
        $findings = [];

        foreach ($runtimes as $line) {
            $bin = Str::before($line, ' ');

            if (!isset(self::RUNTIMES[$bin])) {
                continue;
            }

            $version = preg_match('/\d+\.\d+(?:\.\d+)?/', Str::after($line, ' '), $matches) === 1 ? ' ' . $matches[0] : '';

            $findings[] = new AuditFinding("runtime_{$bin}", AuditSeverity::Info, self::RUNTIMES[$bin] . $version . ' is installed from system packages', 'Coresmith installs its own runtimes with asdf. This one stays, apps just do not use it.');
        }

        foreach ($managers as $path) {
            $tool = ltrim(basename($path), '.');

            if (!in_array($tool, ['nvm', 'rbenv', 'rvm'], true)) {
                continue;
            }

            $findings["manager_{$tool}"] ??= new AuditFinding("manager_{$tool}", AuditSeverity::Info, "{$tool} is installed in " . dirname($path), 'No conflict with asdf, but consider removing version managers nothing uses.');
        }

        return array_values($findings);
    }
}
