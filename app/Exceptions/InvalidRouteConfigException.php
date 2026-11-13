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
        return new self("Coresmith refused to send the app's traffic to [{$dial}], which isn't one of the app's own ports on this server.");
    }

    public static function socket(string $socket): self
    {
        return new self("Coresmith refused to send the app's traffic to the PHP socket [{$socket}], which isn't the app's own.");
    }

    public static function domain(string $domain): self
    {
        return new self("Coresmith refused to route [{$domain}], which isn't one of the app's domains.");
    }

    public static function missingDomain(): self
    {
        return new self('Coresmith refused to route an app that has no domain.');
    }

    public static function root(string $root): self
    {
        return new self("Coresmith refused to serve [{$root}], which isn't inside the app's folder.");
    }
}
