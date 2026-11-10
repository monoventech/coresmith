<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Panel;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;

final class Formats
{
    private const CURRENCY_SYMBOLS = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];

    public static function money(?string $amount, ?string $currency): ?string
    {
        if ($amount === null || $currency === null || !is_numeric($amount)) {
            return null;
        }

        $code = strtoupper($currency);
        $symbol = self::CURRENCY_SYMBOLS[$code] ?? null;
        $value = number_format((float) $amount, 2, '.', '');

        if ($symbol === null) {
            return "{$value} {$code}";
        }

        return $symbol . (str_ends_with($value, '.00') ? substr($value, 0, -3) : $value);
    }

    public static function cents(int $minor, string $currency): string
    {
        if ($minor < 0) {
            return '-' . self::cents(-$minor, $currency);
        }

        return self::money(number_format($minor / 100, 2, '.', ''), strtoupper($currency)) ?? '';
    }

    public static function monthly(?string $amount, ?string $currency): ?string
    {
        $money = self::money($amount, $currency);

        return $money === null ? null : $money . '/mo';
    }

    public static function memory(?int $megabytes): ?string
    {
        if ($megabytes === null || $megabytes <= 0) {
            return null;
        }

        $gigabytes = $megabytes / 1024;

        return $gigabytes < 1 ? $megabytes . ' MB' : round($gigabytes) . ' GB';
    }

    public static function size(int $megabytes): string
    {
        $gigabytes = $megabytes / 1024;

        return match (true) {
            $megabytes < 1024 => max(0, $megabytes) . "\u{00A0}MB",
            $gigabytes < 10 => self::oneDecimal($gigabytes) . "\u{00A0}GB",
            $gigabytes < 1024 => (int) round($gigabytes) . "\u{00A0}GB",
            default => self::oneDecimal($gigabytes / 1024) . "\u{00A0}TB",
        };
    }

    private static function oneDecimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    public static function uptime(?CarbonInterface $bootedAt): ?string
    {
        if ($bootedAt === null) {
            return null;
        }

        $days = (int) $bootedAt->diffInDays(now());

        if ($days >= 1) {
            return 'up ' . $days . ' ' . ($days === 1 ? 'day' : 'days');
        }

        $hours = max(1, (int) $bootedAt->diffInHours(now()));

        return 'up ' . $hours . ' ' . ($hours === 1 ? 'hour' : 'hours');
    }

    public static function clock(?CarbonInterface $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        return $moment->isToday() ? $moment->format('H:i') : $moment->format('M j');
    }

    public static function when(?CarbonInterface $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        if ($moment->diffInSeconds(now()) < 60) {
            return 'now';
        }

        return $moment->isToday() ? 'today ' . $moment->format('H:i') : $moment->format('M j');
    }

    public static function absolute(?CarbonInterface $moment): ?string
    {
        return $moment?->format('M j, Y \a\t H:i T');
    }

    public static function duration(?CarbonInterface $from, ?CarbonInterface $to): ?string
    {
        if ($from === null || $to === null) {
            return null;
        }

        $seconds = max(0, (int) $from->diffInSeconds($to));

        if ($seconds < 60) {
            return $seconds . 's';
        }

        return intdiv($seconds, 60) . 'm ' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
    }

    public static function downFor(int $seconds): string
    {
        if ($seconds >= 3600) {
            return CarbonInterval::seconds($seconds)->cascade()->forHumans(['parts' => 2]);
        }

        $moment = CarbonImmutable::now();

        return (string) self::duration($moment, $moment->addSeconds($seconds));
    }

    public static function fingerprint(?string $fingerprint): ?string
    {
        if ($fingerprint === null || strlen($fingerprint) <= 20) {
            return $fingerprint;
        }

        return substr($fingerprint, 0, 11) . '…' . substr($fingerprint, -4);
    }

    public static function release(?string $release): ?string
    {
        if ($release === null) {
            return null;
        }

        return preg_match('/^(\d{8})(\d{6})$/', $release, $matches) === 1 ? $matches[1] . '-' . $matches[2] : $release;
    }
}
