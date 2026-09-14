<?php

use App\Http\Controllers\Api\ApplicationApiController;
use App\Http\Controllers\Api\DeploymentApiController;
use App\Http\Controllers\Api\ServerApiController;
use App\Http\Controllers\CaddyAskController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('caddy/ask', CaddyAskController::class)
    ->middleware('throttle:240,1')
    ->name('caddy.ask');

Route::post('webhooks/{webhook}/deploy', [WebhookController::class, 'deploy'])
    ->middleware('throttle:30,1')
    ->name('webhooks.deploy');

Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
    Route::get('servers', [ServerApiController::class, 'index'])
        ->middleware('token.can:server.view')->name('api.servers.index');
    Route::get('servers/{server}', [ServerApiController::class, 'show'])
        ->middleware('token.can:server.view')->name('api.servers.show');

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
