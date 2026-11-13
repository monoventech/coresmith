<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Server\SecurityHardening;

final class ProtectionReading
{
    /**
     * @param  array<string, list<string>>  $overrides
     */
    public static function output(array $overrides = []): string
    {
        $sections = [
            'fail2ban' => ['active', 'jail'],
            'kernel' => self::kernel(),
            'patches' => ["UNATTENDED='1'", 'installed', 'enabled'],
            'ssh' => ['passwordauthentication no', 'kbdinteractiveauthentication no', 'permitrootlogin without-password'],
            'controls' => ['socket', 'no-tcp'],
            ...$overrides,
        ];

        return implode("\n", array_merge(...array_map(fn(string $name, array $lines): array => ["== {$name}", ...$lines], array_keys($sections), $sections))) . "\n";
    }

    /**
     * @return list<string>
     */
    public static function kernel(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", (new SecurityHardening)->sysctlConfig()))));
    }
}
