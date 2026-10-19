<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\GitHubAppController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SourceProviderConnectController;
use App\Http\Controllers\Settings\SourceProviderController;
use App\Http\Controllers\Settings\SourceRepositoryController;
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
        ->middleware('throttle:6,1,backups')
        ->name('settings.backups.store');

    Route::get('settings/team', [TeamController::class, 'index'])->name('teams.index');
    Route::put('settings/team/url', [TeamUrlController::class, 'update'])->name('settings.team-url.update');
    Route::post('settings/team/domain', [TeamDomainController::class, 'store'])->name('settings.team-domain.store');
    Route::post('settings/team/domain/verify', [TeamDomainController::class, 'verify'])
        ->middleware('throttle:12,1,domain-verify')
        ->name('settings.team-domain.verify');
    Route::delete('settings/team/domain', [TeamDomainController::class, 'destroy'])->name('settings.team-domain.destroy');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('settings.notifications');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');

    Route::get('settings/billing', [BillingController::class, 'show'])->name('settings.billing');

    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1,password-update')
        ->name('user-password.update');

    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

    Route::get('settings/git', [SourceProviderController::class, 'index'])->name('source-providers.index');
    Route::get('settings/git/github/registered', [GitHubAppController::class, 'registered'])
        ->middleware('throttle:10,1,github-app-registered')
        ->name('github-apps.registered');
    Route::get('settings/source-providers/{type}/connect', [SourceProviderConnectController::class, 'redirect'])
        ->middleware('throttle:10,1,source-connect')
        ->name('source-providers.connect');
    Route::get('settings/source-providers/{type}/callback', [SourceProviderConnectController::class, 'callback'])
        ->middleware('throttle:10,1,source-callback')
        ->name('source-providers.callback');
    Route::post('settings/source-providers/github_app/installations', [SourceProviderConnectController::class, 'chooseInstallation'])
        ->middleware('throttle:10,1,github-choose')
        ->name('github.installations.store');
    Route::delete('settings/source-providers/{sourceProvider}', [SourceProviderController::class, 'destroy'])
        ->whereUlid('sourceProvider')
        ->name('source-providers.destroy');
    Route::get('settings/source-providers/{sourceProvider}/repositories', [SourceRepositoryController::class, 'index'])
        ->whereUlid('sourceProvider')
        ->middleware('throttle:30,1,source-repositories')
        ->name('source-providers.repositories');
    Route::get('settings/source-providers/{sourceProvider}/repositories/{repositoryId}/branches', [SourceRepositoryController::class, 'branches'])
        ->whereUlid('sourceProvider')
        ->whereNumber('repositoryId')
        ->middleware('throttle:30,1,source-branches')
        ->name('source-providers.branches');
});
