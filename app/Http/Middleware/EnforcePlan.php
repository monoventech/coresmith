<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Edition;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\Billing\PlanGate;
use App\Services\Teams\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforcePlan
{
    public const OPEN_WHILE_READ_ONLY = [
        'logout',
        'current-team.update',
        'password.confirm.store',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.regenerate-recovery-codes',
        'verification.send',
        'profile.update',
        'profile.destroy',
        'user-password.update',
        'settings.notifications.update',
        'api-tokens.destroy',
        'ssh-keys.destroy',
        'teams.members.destroy',
        'teams.invitations.destroy',
        'invitations.accept',
        'source-providers.destroy',
        'github-apps.destroy',
        'cloud-providers.destroy',
        'settings.sso.destroy',
        'settings.sso.enabled',
        'settings.team-domain.destroy',
        'servers.destroy',
        'servers.cloud.destroy',
        'settings.billing.plan.update',
        'settings.billing.plan.destroy',
        'settings.billing.plan.resume',
        'settings.billing.portal',
        'settings.offsite-backups.destroy',
    ];

    public const BLOCKED_READS = ['source-providers.connect', 'source-providers.callback', 'github-apps.registered'];

    public function __construct(private readonly PlanGate $gate, private readonly TeamContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (Edition::current()->isSelfHosted() || !$user instanceof User) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if ($request->isMethodSafe() && !in_array($route, self::BLOCKED_READS, true)) {
            return $next($request);
        }

        if (in_array($route, self::OPEN_WHILE_READ_ONLY, true) || $request->is('broadcasting/auth')) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        $team = $token instanceof ApiToken ? $token->team : $this->context->team();

        if ($team !== null) {
            $this->gate->ensureWritable($team, $user);
        }

        return $next($request);
    }
}
