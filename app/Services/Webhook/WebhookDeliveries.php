<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Webhook;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

final class WebhookDeliveries
{
    public const REPLAY_WINDOW_HOURS = 24;

    public const REJECTION_LOG_MINUTES = 10;

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
        $interval = intdiv(now()->getTimestamp(), self::REJECTION_LOG_MINUTES * 60);
        $key = 'webhook:rejected:' . sha1((string) json_encode([$subject?->getMorphClass(), $subject?->getKey(), $properties, $request->ip(), $interval]));
        $until = now()->addMinutes(self::REJECTION_LOG_MINUTES * 2);
        $opensInterval = Cache::add($key, 0, $until);
        $details = [...$properties, 'ip' => $request->ip(), 'count' => (int) Cache::increment($key)];

        if ($opensInterval) {
            $logger = activity();

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            Cache::put($key . ':row', $logger->withProperties($details)->log('webhook_signature_rejected')?->getKey(), $until);

            return;
        }

        $row = Cache::get($key . ':row');

        if ($row !== null) {
            Activity::query()->whereKey($row)->update(['properties' => json_encode($details)]);
        }
    }
}
