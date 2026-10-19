<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Webhook;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class WebhookDeliveries
{
    public const REPLAY_WINDOW_HOURS = 24;

    private const PLAIN_VALUE = '/^[A-Za-z0-9:._-]{1,64}$/';

    /**
     * @param  list<string>  $names
     */
    public function header(Request $request, array $names, string $pattern = self::PLAIN_VALUE): ?string
    {
        foreach ($names as $name) {
            $value = $request->header($name);

            if (is_string($value) && preg_match($pattern, $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    public function claim(string $scope, string $delivery): bool
    {
        return Cache::add($scope . ':' . $delivery, true, now()->addHours(self::REPLAY_WINDOW_HOURS));
    }

    public function release(string $scope, string $delivery): void
    {
        Cache::forget($scope . ':' . $delivery);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function signatureRejected(Request $request, ?Model $subject, array $properties): void
    {
        $logger = activity();

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        $logger->withProperties([...$properties, 'ip' => $request->ip()])->log('webhook_signature_rejected');
    }
}
