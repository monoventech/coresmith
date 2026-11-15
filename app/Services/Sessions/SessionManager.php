<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Services\Sessions;

use Illuminate\Session\CookieSessionHandler;
use Illuminate\Session\SessionManager as LaravelSessionManager;

final class SessionManager extends LaravelSessionManager
{
    protected function buildSession($handler)
    {
        return parent::buildSession($handler instanceof CookieSessionHandler
            ? $handler
            : new EndedSessions($handler, $this->container->make('cache')->store(), (int) $this->config->get('session.lifetime')));
    }
}
