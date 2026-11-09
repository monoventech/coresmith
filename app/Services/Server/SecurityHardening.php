<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\SshKey;
use App\Services\SafeName;
use App\Services\SSH\SSHConnection;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class SecurityHardening
{
    public const SSHD_CONFIG = '/etc/ssh/sshd_config.d/00-coresmith.conf';

    public const LEGACY_SSHD_CONFIG = '/etc/ssh/sshd_config.d/99-coresmith.conf';

    public const SSHD_MAIN_CONFIG = '/etc/ssh/sshd_config';

    public const SSHD_BLOCK_BEGIN = '# coresmith-sshd begin';

    public const SSHD_BLOCK_END = '# coresmith-sshd end';

    public const READS_DROP_INS = "grep -Eqi '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\\.d/\\*\\.conf([[:space:]]|$)' /etc/ssh/sshd_config";

    private const SSHD_SETTINGS = [
        'PasswordAuthentication no',
        'KbdInteractiveAuthentication no',
        'ChallengeResponseAuthentication no',
        'PermitRootLogin prohibit-password',
        'PubkeyAuthentication yes',
        'MaxAuthTries 3',
        'ClientAliveInterval 120',
        'ClientAliveCountMax 3',
        'AllowAgentForwarding no',
        'AllowTcpForwarding no',
        'X11Forwarding no',
    ];

    private const SSHD_KEX = 'curve25519-sha256,curve25519-sha256@libssh.org';

    private const SSHD_CRYPTO = [
        'Ciphers chacha20-poly1305@openssh.com,aes256-gcm@openssh.com,aes128-gcm@openssh.com',
        'MACs hmac-sha2-512-etm@openssh.com,hmac-sha2-256-etm@openssh.com',
        'HostKeyAlgorithms ssh-ed25519,rsa-sha2-512,rsa-sha2-256',
    ];

    private const ROOT_WITHOUT_PASSWORD = ['without-password', 'prohibit-password', 'forced-commands-only', 'no'];

    private const STRIP_SSHD_BLOCK = '$0 == start { inside = 1; next } $0 == stop { inside = 0; next } !inside { print }';

    public const SYSCTL_CONFIG = '/etc/sysctl.d/99-coresmith-hardening.conf';

    public const FAIL2BAN_JAIL = '/etc/fail2ban/jail.d/coresmith-ssh.conf';

    public const AUDIT_RULES = '/etc/audit/rules.d/coresmith.rules';

    public const PUBLIC_KEY_PATTERN = '/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp[0-9]+) [A-Za-z0-9+\/=]+( [^\x00-\x1F\x7F]*)?\z/';

    public const MANAGED_KEYS_BEGIN = '# coresmith-managed-keys begin';

    public const MANAGED_KEYS_END = '# coresmith-managed-keys end';

    private const STRIP_MANAGED_KEYS = '$0 == start { printf "%s", held; held = ""; inside = 1; next } $0 == stop { held = ""; inside = 0; next } inside { held = held $0 ORS; next } { print } END { printf "%s", held }';

    public function harden(SSHConnection $connection): void
    {
        $this->syncUserKeys($connection);
        $this->hardenSsh($connection);
        $this->installFail2ban($connection);
        $this->applySysctls($connection);
        $this->configureAutomaticUpdates($connection);
        $this->setTimezone($connection);
        $this->checkAppArmor($connection);
        $this->configureAuditd($connection);
    }

    private function configureAuditd(SSHConnection $connection): void
    {
        if (!$connection->server()->audit_logging_enabled) {
            return;
        }

        $packages = PackageManagerFactory::for($connection->server());

        $connection->execute(
            'command -v auditctl >/dev/null 2>&1 || { ' . $packages->install(['auditd']) . '; }',
            sudo: true,
        )->throw();

        $connection->execute('mkdir -p /etc/audit/rules.d', sudo: true)->throw();
        $connection->uploadContent($this->auditRules(), self::AUDIT_RULES);
        $connection->execute('systemctl enable --now auditd && augenrules --load || true', sudo: true)->throw();
    }

    public function auditRules(): string
    {
        return <<<'RULES'
        -w /etc/sudoers -p wa -k sudoers
        -w /etc/sudoers.d/ -p wa -k sudoers

        -w /etc/ssh/sshd_config -p wa -k sshd_config
        -w /etc/ssh/sshd_config.d/ -p wa -k sshd_config

        -w /etc/passwd -p wa -k identity
        -w /etc/group -p wa -k identity
        -w /etc/shadow -p wa -k identity

        -w /etc/coresmith/ -p wa -k coresmith_config

        -w /etc/crontab -p wa -k crontab
        -w /etc/cron.d/ -p wa -k crontab
        -w /etc/coresmith/cron/ -p wa -k crontab
        -w /var/spool/cron/ -p wa -k crontab

        RULES;
    }

    public function syncUserKeys(SSHConnection $connection): int
    {
        $server = $connection->server();
        $keys = $this->userKeys($server);

        $connection->execute($this->authorizedKeysSyncCommand((string) $server->ssh_user, $keys))->throw();

        return count($keys);
    }

    /**
     * @return list<string>
     */
    public function userKeys(Server $server): array
    {
        $team = $server->team;

        if ($team === null) {
            return [];
        }

        $managers = $team->members()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->pluck('users.id');

        return SshKey::query()
            ->whereIn('user_id', $managers)
            ->orderBy('created_at')
            ->pluck('public_key')
            ->map(fn(mixed $key): string => trim((string) $key))
            ->filter(fn(string $key): bool => preg_match(self::PUBLIC_KEY_PATTERN, $key) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $keys
     */
    public function authorizedKeysSyncCommand(string $sshUser, array $keys): string
    {
        $user = SafeName::assertSystemUser($sshUser);
        $home = $user === 'root' ? '/root' : '/home/' . $user;
        $directory = escapeshellarg($home . '/.ssh');
        $file = escapeshellarg($home . '/.ssh/authorized_keys');

        foreach ($keys as $key) {
            if (preg_match(self::PUBLIC_KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException('Only single line OpenSSH public keys can go in the managed block.');
            }
        }

        $rewrite = ['awk -v start=' . escapeshellarg(self::MANAGED_KEYS_BEGIN) . ' -v stop=' . escapeshellarg(self::MANAGED_KEYS_END) . ' ' . escapeshellarg(self::STRIP_MANAGED_KEYS) . ' ' . $file . ' > "$tmp"'];

        if ($keys !== []) {
            $rewrite[] = "printf '%s\\n' " . implode(' ', array_map(escapeshellarg(...), [self::MANAGED_KEYS_BEGIN, ...$keys, self::MANAGED_KEYS_END])) . ' >> "$tmp"';
        }

        $steps = [
            'tmp=$(mktemp ' . escapeshellarg($home . '/.ssh/authorized_keys.XXXXXX') . ')',
            '{ ' . implode(' && ', [...$rewrite, 'chmod 600 "$tmp"', 'mv -f "$tmp" ' . $file]) . ' || { rm -f "$tmp"; false; }; }',
            'chmod 700 ' . $directory,
            'chown -R ' . escapeshellarg($user . ':') . ' ' . $directory,
        ];

        if ($keys === []) {
            return 'if [ -f ' . $file . ' ]; then ' . implode(' && ', $steps) . '; fi';
        }

        return implode(' && ', ['mkdir -p ' . $directory, 'touch ' . $file, ...$steps]);
    }

    public function hardenSsh(SSHConnection $connection): void
    {
        $server = $connection->server();

        $connection->execute('mkdir -p /etc/ssh/sshd_config.d', sudo: true)->throw();
        $connection->uploadContent($this->sshdConfig($server->ssh_port), self::SSHD_CONFIG);
        $connection->execute('chmod 600 ' . self::SSHD_CONFIG . ' && rm -f ' . self::LEGACY_SSHD_CONFIG, sudo: true)->throw();

        $atTop = !$connection->execute(self::READS_DROP_INS, sudo: true)->successful();
        $this->applySsh($connection, $atTop);
        $problem = $this->passwordProblem($connection);

        if ($problem !== null && !$atTop) {
            $this->applySsh($connection, true);
            $problem = $this->passwordProblem($connection);
        }

        $this->recordSsh($server, $problem === null ? ['ssh_hardening' => 'verified'] : ['ssh_hardening_error' => $problem]);
    }

    /**
     * @param  array<string, string>  $result
     */
    public function recordSsh(Server $server, array $result): void
    {
        $fresh = $server->fresh() ?? $server;

        $fresh->forceFill(['meta' => [...Arr::except((array) $fresh->meta, ['ssh_hardening', 'ssh_hardening_error']), ...$result]])->save();
    }

    /**
     * @return list<string>
     */
    private function sshdBlock(): array
    {
        return [...self::SSHD_SETTINGS, 'KexAlgorithms ' . self::SSHD_KEX, ...self::SSHD_CRYPTO];
    }

    private function applySsh(SSHConnection $connection, bool $atTop): void
    {
        $main = self::SSHD_MAIN_CONFIG;
        $block = $atTop
            ? "printf '%s\\n' " . implode(' ', array_map(escapeshellarg(...), [self::SSHD_BLOCK_BEGIN, ...$this->sshdBlock(), self::SSHD_BLOCK_END])) . ' && '
            : '';
        $strip = 'awk -v start=' . escapeshellarg(self::SSHD_BLOCK_BEGIN) . ' -v stop=' . escapeshellarg(self::SSHD_BLOCK_END) . ' ' . escapeshellarg(self::STRIP_SSHD_BLOCK) . ' ' . $main;
        $rewrite = 'tmp=$(mktemp ' . $main . '.XXXXXX) && { { ' . $block . $strip . '; } > "$tmp" && sshd -t -f "$tmp" && cat "$tmp" > ' . $main . '; status=$?; rm -f "$tmp"; [ "$status" -eq 0 ]; }';

        $connection->execute(
            $atTop ? $rewrite : 'if grep -qxF ' . escapeshellarg(self::SSHD_BLOCK_BEGIN) . ' ' . $main . '; then ' . $rewrite . '; fi',
            sudo: true,
        )->throw();

        $connection->execute('sshd -t && { systemctl reload sshd || systemctl reload ssh; }', sudo: true)->throw();
    }

    private function passwordProblem(SSHConnection $connection): ?string
    {
        $settings = [];

        foreach (preg_split('/\R/', $connection->execute('sshd -T', sudo: true)->throw()->output) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line), 2) ?: [];

            if (count($parts) === 2) {
                $settings[strtolower($parts[0])] = strtolower($parts[1]);
            }
        }

        if (!isset($settings['passwordauthentication'], $settings['permitrootlogin'])) {
            return "sshd didn't report its settings";
        }

        $open = array_keys(array_filter([
            'passwordauthentication' => $settings['passwordauthentication'] !== 'no',
            'kbdinteractiveauthentication' => ($settings['kbdinteractiveauthentication'] ?? 'no') !== 'no',
            'challengeresponseauthentication' => ($settings['challengeresponseauthentication'] ?? 'no') !== 'no',
            'permitrootlogin' => !in_array($settings['permitrootlogin'], self::ROOT_WITHOUT_PASSWORD, true),
        ]));

        return $open === [] ? null : 'sshd reports ' . implode(', ', array_map(fn(string $key): string => "{$key} {$settings[$key]}", $open));
    }

    private function installFail2ban(SSHConnection $connection): void
    {
        $packages = PackageManagerFactory::for($connection->server());

        $connection->execute(
            'command -v fail2ban-server >/dev/null 2>&1 || { ' . $packages->install(['fail2ban']) . '; systemctl enable --now fail2ban; }',
            sudo: true,
        )->throw();

        $connection->execute('mkdir -p /etc/fail2ban/jail.d', sudo: true)->throw();
        $connection->uploadContent($this->fail2banJail($connection->server()->ssh_port), self::FAIL2BAN_JAIL);
        $connection->execute('systemctl restart fail2ban', sudo: true)->throw();
    }

    private function applySysctls(SSHConnection $connection): void
    {
        $connection->uploadContent($this->sysctlConfig(), self::SYSCTL_CONFIG);
        $connection->execute('sysctl --system', sudo: true)->throw();
    }

    private function configureAutomaticUpdates(SSHConnection $connection): void
    {
        $server = $connection->server();
        $packages = PackageManagerFactory::for($server);

        $command = match ($server->os_name) {
            'ubuntu', 'debian' => $packages->install(['unattended-upgrades']) . ' && systemctl enable --now unattended-upgrades',
            default => $packages->install(['dnf-automatic']) . ' && systemctl enable --now dnf-automatic.timer',
        };

        $connection->execute($command, sudo: true)->throw();
    }

    private function setTimezone(SSHConnection $connection): void
    {
        $connection->execute('timedatectl set-timezone UTC', sudo: true)->throw();
    }

    private function checkAppArmor(SSHConnection $connection): void
    {
        $server = $connection->server();

        if (!in_array($server->os_name, ['ubuntu', 'debian'], true)) {
            return;
        }

        $result = $connection->execute('aa-status --json 2>/dev/null || apparmor_status 2>/dev/null || true', sudo: true);
        $complaining = $this->countComplainProfiles($result->output);

        if ($complaining > 0) {
            activity()
                ->performedOn($server)
                ->withProperties(['profiles_in_complain_mode' => $complaining])
                ->log('apparmor_profiles_in_complain_mode');
        }
    }

    public function countComplainProfiles(string $output): int
    {
        $decoded = json_decode($output, true);

        if (is_array($decoded) && isset($decoded['profiles']) && is_array($decoded['profiles'])) {
            return count(array_filter($decoded['profiles'], static fn(mixed $mode): bool => $mode === 'complain'));
        }

        return preg_match('/(\d+) profiles are in complain mode/', $output, $matches) === 1
            ? (int) $matches[1]
            : 0;
    }

    public function sshdConfig(int $sshPort): string
    {
        return implode("\n", [
            "Port {$sshPort}",
            ...self::SSHD_SETTINGS,
            '',
            'KexAlgorithms sntrup761x25519-sha512@openssh.com,' . self::SSHD_KEX,
            ...self::SSHD_CRYPTO,
        ]) . "\n";
    }

    public function fail2banJail(int $sshPort): string
    {
        return <<<CONFIG
        [sshd]
        enabled = true
        port = {$sshPort}
        filter = sshd
        logpath = %(sshd_log)s
        backend = %(sshd_backend)s
        maxretry = 5
        bantime = 3600
        findtime = 600

        CONFIG;
    }

    public function sysctlConfig(): string
    {
        return <<<'CONFIG'
        net.ipv4.conf.all.rp_filter = 1
        net.ipv4.conf.default.rp_filter = 1
        net.ipv4.conf.all.accept_redirects = 0
        net.ipv4.conf.default.accept_redirects = 0
        net.ipv6.conf.all.accept_redirects = 0
        net.ipv4.conf.all.send_redirects = 0
        net.ipv4.conf.default.send_redirects = 0
        net.ipv4.conf.all.accept_source_route = 0
        net.ipv4.conf.default.accept_source_route = 0
        net.ipv4.tcp_syncookies = 1

        kernel.kptr_restrict = 2
        kernel.yama.ptrace_scope = 1
        kernel.dmesg_restrict = 1
        fs.protected_hardlinks = 1
        fs.protected_symlinks = 1

        CONFIG;
    }
}
