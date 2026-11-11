<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Server;

use Carbon\CarbonInterface;

final readonly class PasswordLogins
{
    public const AWK = '{ line = $0; sub(/^[ \t]+/, "", line); n = split(line, w, /[ \t]*=[ \t]*|[ \t]+/); k = tolower(w[1]); v = tolower(w[2]); gsub(/"/, "", v) } '
        . 'k == "match" { m = (v != "all"); next } '
        . '!m && n >= 2 && (k == "passwordauthentication" || k == "kbdinteractiveauthentication" || k == "challengeresponseauthentication" || k == "permitrootlogin") { print f "\t" k "\t" v }';

    public const META_KEYS = ['ssh_passwords', 'ssh_password_logins', 'ssh_password_sources', 'ssh_passwords_checked_at'];

    private const FILES = '== files';

    private const ORDER = ['password', 'keyboard', 'root'];

    private const LINES = [
        'password' => 'PasswordAuthentication no',
        'keyboard' => 'KbdInteractiveAuthentication no',
        'root' => 'PermitRootLogin prohibit-password',
    ];

    private const SETTINGS = [
        'passwordauthentication' => 'password',
        'kbdinteractiveauthentication' => 'keyboard',
        'challengeresponseauthentication' => 'keyboard',
        'permitrootlogin' => 'root',
    ];

    /**
     * @param  list<string>  $on
     * @param  array<string, string>  $sources
     */
    public function __construct(public array $on, public array $sources) {}

    public static function command(): string
    {
        return 'sshd -T; echo ' . escapeshellarg(self::FILES) . '; for f in /etc/ssh/sshd_config.d/*.conf /etc/ssh/sshd_config; do if [ -f "$f" ]; then awk -v f="$f" ' . escapeshellarg(self::AWK) . ' "$f"; fi; done';
    }

    public static function parse(string $output): ?self
    {
        [$report, $files] = array_pad(explode(self::FILES . "\n", str_replace("\r\n", "\n", $output), 2), 2, '');
        $settings = self::pairs($report);

        if (!isset($settings['passwordauthentication'], $settings['permitrootlogin'])) {
            return null;
        }

        $on = array_values(array_filter(self::ORDER, fn(string $login): bool => match ($login) {
            'password' => $settings['passwordauthentication'] !== 'no',
            'keyboard' => ($settings['kbdinteractiveauthentication'] ?? 'no') !== 'no' || ($settings['challengeresponseauthentication'] ?? 'no') !== 'no',
            default => self::rootByPassword($settings['permitrootlogin']),
        }));

        return new self($on, self::sources($on, $files));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function fromMeta(array $meta): ?self
    {
        return match ($meta['ssh_passwords'] ?? null) {
            SecurityHardening::PASSWORDS_OFF => new self([], []),
            SecurityHardening::PASSWORDS_ON => new self(
                array_values(array_intersect(self::ORDER, (array) ($meta['ssh_password_logins'] ?? []))),
                array_filter((array) ($meta['ssh_password_sources'] ?? []), fn(mixed $file, mixed $login): bool => is_string($file) && in_array($login, self::ORDER, true), ARRAY_FILTER_USE_BOTH),
            ),
            default => null,
        };
    }

    public function off(): bool
    {
        return $this->on === [];
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_map(fn(string $login): string => self::LINES[$login], array_intersect(self::ORDER, $this->on)));
    }

    /**
     * @return array<string, mixed>
     */
    public function toMeta(CarbonInterface $at): array
    {
        if ($this->off()) {
            return ['ssh_passwords' => SecurityHardening::PASSWORDS_OFF, 'ssh_passwords_checked_at' => $at->toIso8601String()];
        }

        return [
            'ssh_passwords' => SecurityHardening::PASSWORDS_ON,
            'ssh_password_logins' => $this->on,
            'ssh_password_sources' => $this->sources,
            'ssh_passwords_checked_at' => $at->toIso8601String(),
        ];
    }

    private static function rootByPassword(string $value): bool
    {
        return !in_array($value, SecurityHardening::ROOT_WITHOUT_PASSWORD, true);
    }

    /**
     * @return array<string, string>
     */
    private static function pairs(string $report): array
    {
        $settings = [];

        foreach (preg_split('/\n/', $report) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line), 2) ?: [];

            if (count($parts) === 2) {
                $settings[strtolower($parts[0])] = strtolower($parts[1]);
            }
        }

        return $settings;
    }

    /**
     * @param  list<string>  $on
     * @return array<string, string>
     */
    private static function sources(array $on, string $files): array
    {
        $first = [];

        foreach (preg_split('/\n/', $files) ?: [] as $row) {
            $columns = explode("\t", $row);

            if (count($columns) !== 3 || !isset(self::SETTINGS[$columns[1]])) {
                continue;
            }

            $first[self::SETTINGS[$columns[1]]] ??= [$columns[0], $columns[1], $columns[2]];
        }

        $sources = [];

        foreach ($on as $login) {
            [$file, $setting, $value] = $first[$login] ?? [null, null, null];

            if ($file !== null && self::turnsOn($setting, (string) $value)) {
                $sources[$login] = $file;
            }
        }

        return $sources;
    }

    private static function turnsOn(?string $setting, string $value): bool
    {
        return $setting === 'permitrootlogin' ? self::rootByPassword($value) : $value !== 'no';
    }
}
