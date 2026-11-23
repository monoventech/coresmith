<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Database;

use App\Models\BackupDestination;
use App\Services\Network\OutboundAddress;
use Closure;
use Illuminate\Http\Request;

final class S3DestinationInput
{
    public const ENDPOINT = 'Use an https:// address, like https://s3.eu-central-1.amazonaws.com.';

    public const REGION = "Use the bucket's region, like eu-central-1.";

    public const BUCKET = "That isn't a bucket name. Use lowercase letters, digits, dots and hyphens.";

    public const PREFIX = "Use letters, digits and ! _ . * ' ( ) / -, with no .. and no leading /.";

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(?BackupDestination $existing): array
    {
        $saved = $existing instanceof BackupDestination ? 'nullable' : 'required';

        return [
            'endpoint' => ['nullable', 'string', 'max:255', 'url:https', self::withoutCredentials(...), self::reachable(...)],
            'region' => ['required', 'string', 'regex:/^[a-z0-9-]{2,32}$/'],
            'bucket' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/'],
            'prefix' => ['nullable', 'string', 'max:200', "regex:/^[A-Za-z0-9!_.*'()\\/-]{0,200}$/", 'not_regex:/\.\./', 'not_regex:/^\//'],
            'access_key_id' => [$saved, 'string', 'max:128'],
            'secret_access_key' => [$saved, 'string', 'max:256'],
            'path_style' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'endpoint.string' => self::ENDPOINT,
            'endpoint.max' => self::ENDPOINT,
            'endpoint.url' => self::ENDPOINT,
            'region.*' => self::REGION,
            'bucket.*' => self::BUCKET,
            'prefix.*' => self::PREFIX,
            'access_key_id.required' => 'Enter the access key ID.',
            'access_key_id.string' => 'Enter the access key ID.',
            'access_key_id.max' => 'An access key ID is at most 128 characters.',
            'secret_access_key.required' => 'Enter the secret access key.',
            'secret_access_key.string' => 'Enter the secret access key.',
            'secret_access_key.max' => 'A secret access key is at most 256 characters.',
            'path_style.boolean' => 'Turn path-style addressing on or off.',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function values(array $validated, Request $request): array
    {
        return array_filter([
            'endpoint' => isset($validated['endpoint']) ? rtrim((string) $validated['endpoint'], '/') : null,
            'region' => $validated['region'],
            'bucket' => $validated['bucket'],
            'prefix' => isset($validated['prefix']) ? trim((string) $validated['prefix'], '/') : null,
            'access_key_id' => $validated['access_key_id'] ?? null,
            'secret_access_key' => $validated['secret_access_key'] ?? null,
            'path_style' => $request->boolean('path_style'),
        ], fn(mixed $value, string $key): bool => !in_array($key, ['access_key_id', 'secret_access_key'], true) || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    private static function reachable(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https' || parse_url($value, PHP_URL_USER) !== null) {
            return;
        }

        match (app(OutboundAddress::class)->check($value)) {
            OutboundAddress::PRIVATE => $fail("That endpoint points at a private address, so Coresmith Cloud can't reach it. Use your storage provider's public endpoint."),
            OutboundAddress::UNRESOLVED => $fail("Coresmith Cloud couldn't find an address for " . strtolower((string) parse_url($value, PHP_URL_HOST)) . '. Check the endpoint.'),
            default => null,
        };
    }

    private static function withoutCredentials(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null)) {
            $fail(self::ENDPOINT);
        }
    }
}
