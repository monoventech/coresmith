<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InvalidRouteConfigException extends RuntimeException
{
    public static function upstream(string $dial): self
    {
        return new self("Refusing to push a Caddy route with upstream [{$dial}]. Upstreams must be loopback on a port allocated to this application.");
    }

    public static function socket(string $socket): self
    {
        return new self("Refusing to push a Caddy route with FPM socket [{$socket}]. The socket must belong to this application.");
    }

    public static function domain(string $domain): self
    {
        return new self("Refusing to push a Caddy route for domain [{$domain}]. The domain is not assigned to this application.");
    }

    public static function missingDomain(): self
    {
        return new self('Refusing to push a Caddy route for an application with no domain.');
    }

    public static function root(string $root): self
    {
        return new self("Refusing to push a Caddy route serving [{$root}]. The document root must live inside this application's directory.");
    }
}
