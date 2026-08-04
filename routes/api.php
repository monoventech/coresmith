<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/{webhook}/deploy', [WebhookController::class, 'deploy'])
    ->middleware('throttle:30,1')
    ->name('webhooks.deploy');
