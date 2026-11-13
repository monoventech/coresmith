<?php

use App\Http\Controllers\Settings\AccountGitController;
use App\Http\Controllers\Settings\AddressController;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\CloudProviderController;
use App\Http\Controllers\Settings\GeneralSettingsController;
use App\Http\Controllers\Settings\GitHubAppController;
use App\Http\Controllers\Settings\MoveCodeController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\OffsiteBackupController;
use App\Http\Controllers\Settings\PanelUpdateController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RemovalController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SourceProviderConnectController;
use App\Http\Controllers\Settings\SourceProviderController;
use App\Http\Controllers\Settings\SourceRepositoryController;
use App\Http\Controllers\Settings\TeamDeletionController;
use App\Http\Controllers\Settings\TeamDomainController;
use App\Http\Controllers\Settings\TeamMoveController;
use App\Http\Controllers\Settings\TeamUrlController;
use App\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings', [GeneralSettingsController::class, 'show'])->name('settings.general');
    Route::post('settings/removal', [RemovalController::class, 'store'])
        ->middleware('throttle:5,1,removal')
        ->name('settings.removal.store');
    Route::get('settings/removal', [RemovalController::class, 'show'])->name('settings.removal.show');
    Route::get('settings/removal/status', [RemovalController::class, 'status'])->name('settings.removal.status');
    Route::put('settings/instance', [GeneralSettingsController::class, 'update'])->name('settings.instance.update');
    Route::put('settings/address', [AddressController::class, 'update'])
        ->middleware('throttle:6,1,settings-address')
        ->name('settings.address.update');
    Route::post('settings/address/check', [AddressController::class, 'check'])
        ->middleware('throttle:10,1,settings-address-check')
        ->name('settings.address.check');
    Route::post('settings/address/cloudflare', [AddressController::class, 'connectCloudflare'])
        ->middleware('throttle:6,1,settings-address-cloudflare')
        ->name('settings.address.cloudflare.store');
    Route::delete('settings/address/cloudflare', [AddressController::class, 'disconnectCloudflare'])
        ->name('settings.address.cloudflare.destroy');
    Route::post('settings/address/point', [AddressController::class, 'point'])
        ->middleware('throttle:6,1,settings-address-point')
        ->name('settings.address.point');
    Route::post('settings/backups', [GeneralSettingsController::class, 'backup'])
        ->middleware('throttle:6,1,backups')
        ->name('settings.backups.store');

    Route::get('settings/updates/status', [PanelUpdateController::class, 'status'])->name('settings.updates.status');
    Route::post('settings/updates/check', [PanelUpdateController::class, 'check'])
        ->middleware('throttle:6,1,update-check')
        ->name('settings.updates.check');
    Route::post('settings/updates', [PanelUpdateController::class, 'store'])
        ->middleware('throttle:6,1,update-request')
        ->name('settings.updates.store');
    Route::delete('settings/updates/request', [PanelUpdateController::class, 'cancel'])->name('settings.updates.cancel');
    Route::post('settings/updates/recover', [PanelUpdateController::class, 'recover'])->name('settings.updates.recover');
    Route::put('settings/updates/auto', [PanelUpdateController::class, 'auto'])->name('settings.updates.auto');
    Route::get('settings/updates/{run}/log', [PanelUpdateController::class, 'log'])
        ->where('run', '[0-9A-Za-z]{26}')
        ->name('settings.updates.log');

    Route::get('settings/team', [TeamController::class, 'index'])->name('teams.index');
    Route::delete('settings/team', [TeamDeletionController::class, 'destroy'])
        ->middleware(['cloud', 'password.confirm', 'throttle:6,1,team-delete'])
        ->name('teams.destroy');
    Route::put('settings/team/url', [TeamUrlController::class, 'update'])->name('settings.team-url.update');
    Route::post('settings/team/domain', [TeamDomainController::class, 'store'])->name('settings.team-domain.store');
    Route::post('settings/team/domain/verify', [TeamDomainController::class, 'verify'])
        ->middleware('throttle:12,1,domain-verify')
        ->name('settings.team-domain.verify');
    Route::delete('settings/team/domain', [TeamDomainController::class, 'destroy'])->name('settings.team-domain.destroy');
    Route::get('settings/team/audit-log', AuditLogController::class)
        ->middleware('throttle:6,1,audit-export')
        ->name('settings.audit-log');
    Route::put('settings/team/offsite-backups', [OffsiteBackupController::class, 'update'])
        ->middleware('throttle:10,1,offsite-backups')
        ->name('settings.offsite-backups.update');
    Route::delete('settings/team/offsite-backups', [OffsiteBackupController::class, 'destroy'])->name('settings.offsite-backups.destroy');

    Route::post('settings/cloud-providers', [CloudProviderController::class, 'store'])
        ->middleware('throttle:10,1,cloud-provider-connect')
        ->name('cloud-providers.store');
    Route::put('settings/cloud-providers/{cloudProvider}', [CloudProviderController::class, 'update'])
        ->middleware('throttle:10,1,cloud-provider-update')
        ->name('cloud-providers.update');
    Route::delete('settings/cloud-providers/{cloudProvider}', [CloudProviderController::class, 'destroy'])
        ->name('cloud-providers.destroy');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('settings.notifications');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('settings.notifications.update');

    Route::get('settings/billing', [BillingController::class, 'show'])->middleware('cloud')->name('settings.billing');
    Route::put('settings/billing/plan', [BillingController::class, 'update'])
        ->middleware(['cloud', 'throttle:10,1,billing-plan'])
        ->name('settings.billing.plan.update');
    Route::delete('settings/billing/plan', [BillingController::class, 'destroy'])
        ->middleware(['cloud', 'throttle:10,1,billing-plan-end'])
        ->name('settings.billing.plan.destroy');
    Route::post('settings/billing/plan/resume', [BillingController::class, 'resume'])
        ->middleware(['cloud', 'throttle:10,1,billing-plan-resume'])
        ->name('settings.billing.plan.resume');
    Route::post('settings/billing/portal', [BillingController::class, 'portal'])
        ->middleware(['cloud', 'throttle:10,1,billing-portal'])
        ->name('settings.billing.portal');
    Route::post('settings/billing/move', [TeamMoveController::class, 'store'])
        ->middleware(['cloud', 'throttle:10,1,team-move'])
        ->name('settings.billing.move.store');
    Route::get('settings/billing/move', [TeamMoveController::class, 'show'])->middleware('cloud')->name('settings.billing.move.file');
    Route::delete('settings/billing/move', [TeamMoveController::class, 'destroy'])
        ->middleware(['cloud', 'throttle:10,1,team-move-cancel'])
        ->name('settings.billing.move.destroy');

    Route::post('settings/moves', [MoveCodeController::class, 'store'])->middleware('throttle:10,1,move-code')->name('settings.moves.store');
    Route::post('settings/moves/{moveCode}/file', [MoveCodeController::class, 'upload'])
        ->middleware('throttle:20,1,move-file')
        ->name('settings.moves.upload');
    Route::post('settings/moves/{moveCode}/import', [MoveCodeController::class, 'import'])
        ->middleware('throttle:10,1,move-import')
        ->name('settings.moves.import');
    Route::delete('settings/moves/{moveCode}', [MoveCodeController::class, 'destroy'])->name('settings.moves.destroy');

    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::get('settings/account/git', [AccountGitController::class, 'show'])->name('account.git');
    Route::delete('settings/account/git', [AccountGitController::class, 'destroy'])->name('account.git.destroy');
    Route::delete('settings/git/members/{sourceProvider}', [AccountGitController::class, 'destroyMember'])
        ->whereUlid('sourceProvider')
        ->name('source-providers.members.destroy');

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
    Route::post('settings/git/github/apps/{githubApp}/webhook', [GitHubAppController::class, 'webhook'])
        ->whereUlid('githubApp')
        ->middleware('throttle:6,1,github-app-webhook-sync')
        ->name('github-apps.webhook');
    Route::delete('settings/git/github/apps/{githubApp}', [GitHubAppController::class, 'destroy'])
        ->whereUlid('githubApp')
        ->name('github-apps.destroy');
    Route::get('settings/source-providers/{type}/connect', [SourceProviderConnectController::class, 'redirect'])
        ->middleware('throttle:10,1,source-connect')
        ->name('source-providers.connect');
    Route::get('settings/source-providers/{type}/callback', [SourceProviderConnectController::class, 'callback'])
        ->middleware('throttle:10,1,source-callback')
        ->name('source-providers.callback');
    Route::post('settings/source-providers/github_app/installations', [SourceProviderConnectController::class, 'chooseInstallation'])
        ->middleware('throttle:10,1,github-choose')
        ->name('github.installations.store');
    Route::delete('settings/source-providers/github_app/installations', [SourceProviderConnectController::class, 'cancelChoice'])
        ->middleware('throttle:10,1,github-choose-cancel')
        ->name('github.installations.destroy');
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
