<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

final class PublicSuffix
{
    public const LIST = 'public-suffix/public_suffix_list.dat';

    private const END = '// ===END ICANN DOMAINS===';

    /** @var array<string, true>|null */
    private static ?array $rules = null;

    public function registrable(string $domain): ?string
    {
        $labels = explode('.', strtolower(trim($domain, '.')));

        if (count($labels) < 2 || in_array('', $labels, true)) {
            return null;
        }

        $suffix = $this->suffixLength($labels);

        return count($labels) > $suffix ? implode('.', array_slice($labels, -($suffix + 1))) : null;
    }

    /**
     * @param  list<string>  $labels
     */
    private function suffixLength(array $labels): int
    {
        $rules = self::rules();
        $length = 1;

        for ($n = 1; $n <= count($labels); $n++) {
            $candidate = implode('.', array_slice($labels, -$n));

            if (isset($rules['!' . $candidate])) {
                return $n - 1;
            }

            if (isset($rules[$candidate]) || ($n > 1 && isset($rules['*.' . implode('.', array_slice($labels, -($n - 1)))]))) {
                $length = $n;
            }
        }

        return $length;
    }

    /**
     * @return array<string, true>
     */
    private static function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }

        $rules = [];

        foreach (file(resource_path(self::LIST), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, self::END)) {
                break;
            }

            $rule = strtolower(trim(strtok($line, " \t") ?: ''));

            if ($rule === '' || str_starts_with($rule, '//')) {
                continue;
            }

            $ascii = self::ascii($rule);

            if ($ascii !== null) {
                $rules[$ascii] = true;
            }
        }

        return self::$rules = $rules;
    }

    private static function ascii(string $rule): ?string
    {
        if (preg_match('/[^\x20-\x7e]/', $rule) !== 1) {
            return $rule;
        }

        if (!function_exists('idn_to_ascii')) {
            return null;
        }

        $prefix = str_starts_with($rule, '!') ? '!' : '';
        $labels = explode('.', ltrim($rule, '!'));

        foreach ($labels as $index => $label) {
            if ($label === '*') {
                continue;
            }

            $converted = idn_to_ascii($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($converted === false) {
                return null;
            }

            $labels[$index] = $converted;
        }

        return $prefix . implode('.', $labels);
    }
}
