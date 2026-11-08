<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

use RuntimeException;

final class SiteFile
{
    /**
     * @param  list<string>  $addresses
     * @param  array<string, string>  $redirects
     */
    public function render(array $addresses, array $redirects = []): string
    {
        $template = (string) file_get_contents(resource_path('caddy/panel.caddy'));
        $blocks = array_map(
            fn(string $from, string $to): string => "\n" . self::address($from) . " {\n    redir https://" . self::address($to) . "{uri} permanent\n}\n",
            array_keys($redirects),
            array_values($redirects),
        );

        return str_replace(['__ADDRESSES__', "__REDIRECTS__\n"], [implode(', ', array_map(self::address(...), $addresses)), implode('', $blocks)], $template);
    }

    /**
     * @param  list<string>  $addresses
     * @param  array<string, string>  $redirects
     */
    public function write(array $addresses, array $redirects = []): void
    {
        $path = (string) config('coresmith.address.site');
        $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $this->render($addresses, $redirects)) === false || !chmod($temporary, 0640) || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Coresmith couldn't write its Caddy site at {$path}.");
        }
    }

    private static function address(string $host): string
    {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$host}]" : $host;
    }
}
