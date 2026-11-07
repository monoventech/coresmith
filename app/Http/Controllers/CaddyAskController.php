<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Teams\TeamUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CaddyAskController extends Controller
{
    public function __invoke(Request $request, TeamUrls $urls): Response
    {
        $host = strtolower(rtrim(trim((string) $request->query('domain')), '.'));

        if ($host !== '' && ($host === $urls->mainHost() || $urls->teamForHost($host) !== null)) {
            return response('', 200);
        }

        return response('', 404);
    }
}
