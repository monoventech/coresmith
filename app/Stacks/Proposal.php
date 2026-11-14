<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

final class Proposal
{
    /**
     * @param  array<string, string>  $sources
     * @param  array<string, string>  $unsettled
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    public static function make(?string $type, ?string $framework, ?string $runtime, ?string $web, array $sources, array $unsettled = []): array
    {
        return ['type' => $type, 'framework' => $framework, 'js_runtime' => $runtime, 'web' => $web, 'sources' => $sources, 'unsettled' => $unsettled];
    }

    /**
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    public static function unsure(string $field, string $reason): array
    {
        return self::make(null, null, null, null, [], [$field => $reason]);
    }

    /**
     * @param  array<string, string>  $sources
     * @return array{type: ?string, framework: ?string, js_runtime: ?string, web: ?string, sources: array<string, string>, unsettled: array<string, string>}
     */
    public static function staticBuild(?string $output, array $sources, string $unknown): array
    {
        return $output === null
            ? self::make('static', '', null, null, $sources, ['web_directory' => $unknown])
            : self::make('static', '', null, $output, [...$sources, 'web_directory' => 'The build writes to ' . ltrim($output, '/')]);
    }
}
