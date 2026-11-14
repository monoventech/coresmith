<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

enum Route
{
    case PhpFastcgi;
    case ReverseProxy;
    case ReverseProxyHttp2;
    case FileServer;

    public function servesFiles(): bool
    {
        return !$this->proxies();
    }

    public function proxies(): bool
    {
        return $this === self::ReverseProxy || $this === self::ReverseProxyHttp2;
    }
}
