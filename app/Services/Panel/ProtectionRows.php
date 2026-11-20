<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use App\Models\Server;
use App\Services\Server\ProtectionCheck;
use Carbon\CarbonImmutable;

final class ProtectionRows
{
    public const BUSY_MINUTES = 15;

    private const PENDING = 'Coresmith checks this within the hour.';

    /**
     * @return array{checked: string|null, rows: list<array{key: string, label: string, state: string, detail: string, fix: string|null}>}
     */
    public function for(Server $server): array
    {
        $meta = (array) $server->meta;
        $reading = (array) ($meta[ProtectionCheck::META] ?? []);
        $checked = $reading['checked_at'] ?? null;

        return [
            'checked' => is_string($checked) ? Formats::when(CarbonImmutable::parse($checked)) : null,
            'rows' => array_map(fn(array $row): array => $this->busy($row, $meta), [
                $this->ssh($server, $reading),
                $this->managed('fail2ban', 'Repeated failed sign-ins blocked', $reading, $meta, [
                    'on' => 'An address that fails to sign in 5 times in 10 minutes is blocked for an hour.',
                    'off' => "It isn't running on this server.",
                    'fix' => 'Turn it back on',
                    'failed' => "Coresmith couldn't turn it back on: ",
                ]),
                $this->managed('kernel', 'Network and process protections', $reading, $meta, [
                    'on' => "Spoofed and redirected traffic is dropped, and processes can't inspect each other.",
                    'off' => $this->kernelDetail($reading),
                    'fix' => 'Set them again',
                    'failed' => "Coresmith couldn't set them again: ",
                ]),
                $this->managed('patches', 'Security updates install themselves', $reading, $meta, [
                    'on' => 'The server installs security updates every day.',
                    'off' => "Security updates aren't installing on their own.",
                    'fix' => 'Turn them back on',
                    'failed' => "Coresmith couldn't turn them back on: ",
                ]),
                $this->maintenance($reading, $meta),
                $this->managed('http3', 'Browsers can use HTTP/3', $reading, $meta, [
                    'on' => 'The firewall lets HTTP/3 through.',
                    'off' => 'The firewall blocks HTTP/3, so browsers try it first and fall back to HTTP/2.',
                    'fix' => 'Open it',
                    'failed' => "Coresmith couldn't open it: ",
                ]),
                $this->controls($server, $reading),
                $this->unknownHosts($server),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $reading
     * @return array{key: string, label: string, state: string, detail: string, fix: string|null}
     */
    private function ssh(Server $server, array $reading): array
    {
        $row = ['key' => 'ssh', 'label' => 'Sign-in with SSH keys only'];
        $error = ((array) $server->meta)['ssh_hardening_error'] ?? null;

        return $row + match (true) {
            (bool) $server->runs_coresmith => ['state' => 'yours', 'detail' => $server->sshPasswordsOff() ? "Password login is off in this machine's own SSH settings." : "Password login is on in this machine's own SSH settings. See SSH access below.", 'fix' => null],
            ($reading['ssh'] ?? null) === 'on' => ['state' => 'on', 'detail' => 'Password login is off.', 'fix' => null],
            ($reading['ssh'] ?? null) === 'off' => ['state' => 'off', 'detail' => 'Password login is on again.', 'fix' => 'Turn it off'],
            $server->sshHardened() => ['state' => 'on', 'detail' => 'Password login is off.', 'fix' => null],
            is_string($error) => ['state' => 'off', 'detail' => "Coresmith couldn't turn off password login yet: {$error}", 'fix' => 'Try again now'],
            default => ['state' => 'pending', 'detail' => 'Coresmith turns off password login within the hour.', 'fix' => null],
        };
    }

    /**
     * @param  array<string, mixed>  $reading
     * @param  array<string, mixed>  $meta
     * @param  array{on: string, off: string, fix: string, failed: string}  $copy
     * @return array{key: string, label: string, state: string, detail: string, fix: string|null}
     */
    private function managed(string $key, string $label, array $reading, array $meta, array $copy): array
    {
        $error = ((array) ($meta['protections_errors'] ?? []))[$key] ?? null;

        return ['key' => $key, 'label' => $label] + match (true) {
            is_string($error) => ['state' => 'off', 'detail' => $copy['failed'] . $error, 'fix' => 'Try again'],
            ($reading[$key] ?? null) === 'on' => ['state' => 'on', 'detail' => $copy['on'], 'fix' => null],
            ($reading[$key] ?? null) === 'off' => ['state' => 'off', 'detail' => $copy['off'], 'fix' => $copy['fix']],
            default => ['state' => 'pending', 'detail' => self::PENDING, 'fix' => null],
        };
    }

    /**
     * @param  array<string, mixed>  $reading
     * @param  array<string, mixed>  $meta
     * @return array{key: string, label: string, state: string, detail: string, fix: string|null}
     */
    private function maintenance(array $reading, array $meta): array
    {
        $row = $this->managed('maintenance', 'Old packages and logs cleared weekly', $reading, $meta, [
            'on' => "Runs every Sunday morning. It hasn't run yet.",
            'off' => 'Old packages and system logs build up on this server.',
            'fix' => 'Set it up',
            'failed' => "Coresmith couldn't set it up: ",
        ]);
        $ran = $reading['maintenance_ran'] ?? null;

        if ($row['state'] !== 'on' || !is_string($ran)) {
            return $row;
        }

        if (($reading['maintenance_failed'] ?? false) === true) {
            return [...$row, 'state' => 'off', 'detail' => "The last clean-up didn't finish. Coresmith runs it again next Sunday.", 'fix' => 'Run it now'];
        }

        $freed = $reading['maintenance_freed'] ?? null;
        $journal = self::journalSize($reading['journal'] ?? null);

        $when = Formats::when(CarbonImmutable::parse($ran));

        return [...$row, 'detail' => 'Last ran ' . ($when === 'now' ? 'a moment ago' : $when)
            . match (true) {
                !is_int($freed) => '.',
                $freed > 0 => ', and freed ' . Formats::bytes($freed) . '.',
                default => '. There was nothing to clear.',
            }
            . ($journal === null ? '' : " System logs take up {$journal}.")];
    }

    private static function journalSize(mixed $size): ?string
    {
        if (!is_string($size) || preg_match('/^([\d.]+)([BKMGT]?)$/', $size, $match) !== 1) {
            return null;
        }

        $power = ['' => 0, 'B' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4][$match[2]];

        return Formats::bytes((int) round((float) $match[1] * 1024 ** $power));
    }

    /**
     * @param  array<string, mixed>  $reading
     */
    private function kernelDetail(array $reading): string
    {
        $changed = count((array) ($reading['kernel_off'] ?? []));
        $total = count(app(ProtectionCheck::class)->kernel());

        return "{$changed} of {$total} " . ($changed === 1 ? 'setting' : 'settings') . ' changed since Coresmith set them.';
    }

    /**
     * @param  array<string, mixed>  $reading
     * @return array{key: string, label: string, state: string, detail: string, fix: string|null}
     */
    private function controls(Server $server, array $reading): array
    {
        $row = ['key' => 'controls', 'label' => 'Only Coresmith changes routing'];
        $error = ((array) $server->meta)['caddy_admin_error'] ?? null;
        $state = $reading['controls'] ?? ($server->caddyAdminOnSocket() ? 'on' : null);

        return $row + match (true) {
            $state === 'on' => ['state' => 'on', 'detail' => "Apps on this server can't reach the controls that route traffic to them.", 'fix' => null],
            is_string($error) => ['state' => 'off', 'detail' => "Apps on this server can still reach the controls that route traffic. Coresmith's last try to close them failed: {$error}", 'fix' => 'Try again now'],
            $state === 'off' => ['state' => 'off', 'detail' => 'Apps on this server can still reach the controls that route traffic. Coresmith closes them within the hour.', 'fix' => 'Try again now'],
            default => ['state' => 'pending', 'detail' => 'Coresmith closes them to apps within the hour.', 'fix' => null],
        };
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, fix: string|null}
     */
    private function unknownHosts(Server $server): array
    {
        $row = ['key' => 'unknown_hosts', 'label' => 'Only your domains get an answer'];

        return $row + match (true) {
            (bool) $server->runs_coresmith => ['state' => 'on', 'detail' => "Requests for addresses you didn't add never reach your apps.", 'fix' => null],
            $server->unknownHostsClosed() => ['state' => 'on', 'detail' => "Requests for addresses you didn't add are closed without an answer.", 'fix' => null],
            default => ['state' => 'pending', 'detail' => "Coresmith closes requests for addresses you didn't add within the hour.", 'fix' => 'Close them now'],
        };
    }

    /**
     * @param  array{key: string, label: string, state: string, detail: string, fix: string|null}  $row
     * @param  array<string, mixed>  $meta
     * @return array{key: string, label: string, state: string, detail: string, fix: string|null}
     */
    private function busy(array $row, array $meta): array
    {
        $since = ((array) ($meta['protections_restoring'] ?? []))[$row['key']] ?? null;

        if (!is_string($since) || CarbonImmutable::parse($since)->lt(now()->subMinutes(self::BUSY_MINUTES))) {
            return $row;
        }

        return [...$row, 'state' => 'busy', 'detail' => match ($row['key']) {
            'kernel' => 'Setting them again…',
            'patches' => 'Turning them back on…',
            'ssh' => 'Turning off password login…',
            'controls', 'unknown_hosts' => 'Closing them now…',
            'http3' => 'Opening it…',
            'maintenance' => 'Setting it up and running it once…',
            default => 'Turning it back on…',
        }, 'fix' => null];
    }
}
