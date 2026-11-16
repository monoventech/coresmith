<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Requests\Application;

final class SetupSource
{
    public static function fullName(string $url): string
    {
        $path = (string) preg_replace('#^(?:[a-z+]+://[^/]+/|[^@/]+@[^:]+:)#i', '', trim($url));

        return (string) preg_replace('/\.git$/', '', trim($path, '/'));
    }

    public static function display(string $url): string
    {
        $address = (string) preg_replace('#^[a-z+]+://#i', '', trim($url));
        $address = (string) preg_replace('/^[^@\/]+@([^:]+):/', '$1/', $address);

        return (string) preg_replace('/\.git$/', '', rtrim($address, '/'));
    }
}
