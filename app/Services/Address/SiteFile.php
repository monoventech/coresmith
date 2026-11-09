<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Address;

final class SiteFile
{
    private const IF_IP = '__IF_IP__';

    /**
     * @param  list<string>  $addresses
     * @param  array<string, string>  $redirects
     */
    public function render(array $addresses, array $redirects = []): string
    {
        $template = (string) file_get_contents(resource_path('caddy/panel.caddy'));
        $blocks = array_map(
            fn(string $from, string $to): string => "\n" . InstallationAddress::urlHost($from) . " {\n    redir https://" . InstallationAddress::urlHost($to) . "{uri} permanent\n}\n",
            array_keys($redirects),
            array_values($redirects),
        );
        $onIp = array_filter([...$addresses, ...array_keys($redirects)], fn(string $host): bool => filter_var($host, FILTER_VALIDATE_IP) !== false) !== [];
        $template = $onIp ? str_replace(self::IF_IP, '', $template) : (string) preg_replace('/^.*' . self::IF_IP . ".*\n/m", '', $template);

        return str_replace(['__ADDRESSES__', "__REDIRECTS__\n"], [implode(', ', array_map(InstallationAddress::urlHost(...), $addresses)), implode('', $blocks)], $template);
    }

    /**
     * @param  list<string>  $addresses
     * @param  array<string, string>  $redirects
     */
    public function write(array $addresses, array $redirects = []): void
    {
        $path = (string) config('coresmith.address.site');

        AtomicFile::write($path, $this->render($addresses, $redirects), 0640, "Coresmith couldn't write its Caddy site at {$path}");
    }
}
