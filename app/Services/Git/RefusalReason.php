<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Git;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

final class RefusalReason
{
    public static function for(Response $response): string
    {
        foreach (['message', 'error_description', 'error'] as $key) {
            $value = $response->json($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'HTTP ' . $response->status();
    }

    public const SENTENCE_LIMIT = 300;

    public static function sentence(string $reason): string
    {
        $reason = Str::limit(trim($reason), self::SENTENCE_LIMIT);

        return $reason === '' || preg_match('/[.!?]$/D', $reason) === 1 ? $reason : $reason . '.';
    }
}
