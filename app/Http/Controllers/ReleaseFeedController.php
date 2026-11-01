<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Updates\InvalidFeed;
use App\Services\Updates\ReleaseFeed;
use Illuminate\Http\JsonResponse;

final class ReleaseFeedController extends Controller
{
    public function __invoke(ReleaseFeed $feed): JsonResponse
    {
        abort_unless((bool) config('coresmith.release_feed.enabled'), 404);

        try {
            $document = $feed->document();
        } catch (InvalidFeed $e) {
            report($e);

            return response()->json(['message' => 'The release feed is misconfigured.'], 503);
        }

        return response()->json($document->toArray())->setPublic()->setMaxAge(300);
    }
}
