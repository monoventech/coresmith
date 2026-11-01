<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReleaseProbeController extends Controller
{
    private const LOOPBACK = ['127.0.0.1', '::1'];

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(in_array($request->server('REMOTE_ADDR'), self::LOOPBACK, true), 404);

        return response()->json([
            'release' => basename(base_path()),
            'version' => (string) config('coresmith.version'),
        ]);
    }
}
