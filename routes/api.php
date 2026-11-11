<?php

use App\Http\Controllers\Api\ApplicationApiController;
use App\Http\Controllers\Api\DeploymentApiController;
use App\Http\Controllers\Api\ProjectApiController;
use App\Http\Controllers\Api\ServerApiController;
use App\Http\Controllers\CaddyAskController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\ReleaseFeedController;
use App\Http\Controllers\ReleaseProbeController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\VerifyStripeSignature;
use Illuminate\Support\Facades\Route;

Route::get('caddy/ask', CaddyAskController::class)
    ->middleware('throttle:240,1,caddy-ask')
    ->name('caddy.ask');

Route::post('webhooks/{webhook}/deploy', [WebhookController::class, 'deploy'])
    ->middleware('throttle:30,1,webhook-deploy')
    ->name('webhooks.deploy');

Route::post('webhooks/github', [GitHubWebhookController::class, 'environment'])
    ->middleware('throttle:1200,1,github-webhook')
    ->name('webhooks.github');

Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->middleware(['cloud', 'throttle:240,1,stripe-webhook', VerifyStripeSignature::class])
    ->name('cashier.webhook');

Route::post('webhooks/github/{githubApp}', [GitHubWebhookController::class, 'registered'])
    ->whereUlid('githubApp')
    ->middleware('throttle:1200,1,github-webhook')
    ->name('webhooks.github.app');

Route::get('v1/version', ReleaseFeedController::class)
    ->withoutMiddleware(EnsureInstalled::class)
    ->middleware('throttle:60,1,release-feed')
    ->name('release-feed');

Route::get('up/release', ReleaseProbeController::class)
    ->withoutMiddleware(EnsureInstalled::class)
    ->middleware('throttle:60,1,release-probe')
    ->name('release.probe');

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:120,1,api-v1', 'plan'])->group(function () {
    Route::get('servers', [ServerApiController::class, 'index'])
        ->middleware('token.can:server.view')->name('api.servers.index');
    Route::get('servers/{server}', [ServerApiController::class, 'show'])
        ->middleware('token.can:server.view')->name('api.servers.show');

    Route::get('projects', [ProjectApiController::class, 'index'])
        ->middleware('token.can:application.view')->name('api.projects.index');
    Route::get('projects/{project}', [ProjectApiController::class, 'show'])
        ->middleware('token.can:application.view')->name('api.projects.show');

    Route::get('applications', [ApplicationApiController::class, 'index'])
        ->middleware('token.can:application.view')->name('api.applications.index');
    Route::get('applications/{application}', [ApplicationApiController::class, 'show'])
        ->middleware('token.can:application.view')->name('api.applications.show');
    Route::post('applications/{application}/deploy', [ApplicationApiController::class, 'deploy'])
        ->middleware('token.can:application.deploy')->name('api.applications.deploy');

    Route::put('applications/{application}/maintenance', [ApplicationApiController::class, 'maintenance'])
        ->middleware('token.can:application.update')->name('api.applications.maintenance');

    Route::get('applications/{application}/deployments', [DeploymentApiController::class, 'index'])
        ->middleware('token.can:application.view')->name('api.deployments.index');
    Route::get('applications/{application}/deployments/{deployment}', [DeploymentApiController::class, 'show'])
        ->middleware('token.can:application.view')->name('api.deployments.show');
});
