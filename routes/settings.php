<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SourceProviderConnectController;
use App\Http\Controllers\Settings\SourceProviderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/source-providers', [SourceProviderController::class, 'index'])->name('source-providers.index');
    Route::get('settings/source-providers/{type}/connect', [SourceProviderConnectController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('source-providers.connect');
    Route::get('settings/source-providers/{type}/callback', [SourceProviderConnectController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('source-providers.callback');
    Route::delete('settings/source-providers/{sourceProvider}', [SourceProviderController::class, 'destroy'])->name('source-providers.destroy');
});
