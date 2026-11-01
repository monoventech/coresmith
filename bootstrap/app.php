<?php

use App\Exceptions\PlanRefusal;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnforcePlan;
use App\Http\Middleware\EnforceTokenAbility;
use App\Http\Middleware\EnsureCloudEdition;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureTeamContext;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireSetupToken;
use App\Http\Middleware\ResolveTeamUrl;
use App\Http\Middleware\ThrottleSignups;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->preventRequestsDuringMaintenance(except: ['api/up/release']);

        $middleware->append(ResolveTeamUrl::class);

        $middleware->alias([
            'token.can' => EnforceTokenAbility::class,
            'setup.token' => RequireSetupToken::class,
            'cloud' => EnsureCloudEdition::class,
            'verified' => EnsureEmailIsVerified::class,
            'plan' => EnforcePlan::class,
        ]);

        $middleware->web(prepend: [ContentSecurityPolicy::class, EnsureInstalled::class]);
        $middleware->api(prepend: [EnsureInstalled::class]);

        $middleware->web(append: [
            EnsureTeamContext::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ThrottleSignups::class,
            EnforcePlan::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['api_token']);
        $exceptions->dontReport([PlanRefusal::class]);
        Integration::handles($exceptions);
    })->create();
