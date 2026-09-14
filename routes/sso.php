<?php

use App\Http\Controllers\Auth\SingleSignOnController;
use App\Http\Controllers\Settings\SingleSignOnController as SingleSignOnSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('sso', [SingleSignOnController::class, 'show'])->name('sso');
    Route::post('sso', [SingleSignOnController::class, 'lookup'])
        ->middleware('throttle:10,1,sso-lookup')
        ->name('sso.lookup');
    Route::get('sso/callback', [SingleSignOnController::class, 'callback'])
        ->middleware('throttle:20,1,sso-callback')
        ->name('sso.callback');
    Route::get('sso/{team:slug}/start', [SingleSignOnController::class, 'start'])
        ->middleware('throttle:20,1,sso-start')
        ->name('sso.start');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::put('settings/team/sso', [SingleSignOnSettingsController::class, 'update'])->name('settings.sso.update');
    Route::post('settings/team/sso/verify', [SingleSignOnSettingsController::class, 'verify'])
        ->middleware('throttle:12,1,sso-verify')
        ->name('settings.sso.verify');
    Route::put('settings/team/sso/enabled', [SingleSignOnSettingsController::class, 'enabled'])
        ->middleware('throttle:12,1,sso-enable')
        ->name('settings.sso.enabled');
    Route::put('settings/team/sso/provisioning', [SingleSignOnSettingsController::class, 'provisioning'])->name('settings.sso.provisioning');
    Route::delete('settings/team/sso', [SingleSignOnSettingsController::class, 'destroy'])->name('settings.sso.destroy');
});
