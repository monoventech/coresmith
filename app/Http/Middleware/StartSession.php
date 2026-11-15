<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Sessions\EndedSessions;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as LaravelStartSession;

final class StartSession extends LaravelStartSession
{
    public function getSession(Request $request)
    {
        return tap($this->manager->driver(), function (Session $session) use ($request): void {
            $id = $request->cookies->get($session->getName());
            $handler = $session->getHandler();

            $session->setId(is_string($id) && $handler instanceof EndedSessions && $handler->ended($id) ? null : $id);
        });
    }
}
