<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SourceProviderConnectController;
use App\Http\Controllers\Settings\SourceProviderController;
use App\Http\Controllers\Settings\TeamDomainController;
use App\Http\Controllers\Settings\TeamUrlController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings', [GeneralSettingsController::class, 'show'])->name('settings.general');
    Route::put('settings/instance', [GeneralSettingsController::class, 'update'])->name('settings.instance.update');
    Route::post('settings/backups', [GeneralSettingsController::class, 'backup'])
        ->middleware('throttle:6,1')
        ->name('settings.backups.store');

    Route::get('settings/team', [TeamController::class, 'index'])->name('teams.index');
    Route::put('settings/team/url', [TeamUrlController::class, 'update'])->name('settings.team-url.update');
    Route::post('settings/team/domain', [TeamDomainController::class, 'store'])->name('settings.team-domain.store');
    Route::post('settings/team/domain/verify', [TeamDomainController::class, 'verify'])
        ->middleware('throttle:12,1')
        ->name('settings.team-domain.verify');
    Route::delete('settings/team/domain', [TeamDomainController::class, 'destroy'])->name('settings.team-domain.destroy');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('settings.notifications');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');

    Route::get('settings/billing', [BillingController::class, 'show'])->name('settings.billing');

    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    Route::get('settings/git', [SourceProviderController::class, 'index'])->name('source-providers.index');
    Route::get('settings/source-providers/{type}/connect', [SourceProviderConnectController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('source-providers.connect');
    Route::get('settings/source-providers/{type}/callback', [SourceProviderConnectController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('source-providers.callback');
    Route::delete('settings/source-providers/{sourceProvider}', [SourceProviderController::class, 'destroy'])->name('source-providers.destroy');
});
