<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationCronJobController;
use App\Http\Controllers\ApplicationLogController;
use App\Http\Controllers\ApplicationPowerController;
use App\Http\Controllers\ApplicationSchedulerLogController;
use App\Http\Controllers\ApplicationToggleController;
use App\Http\Controllers\CloudServerController;
use App\Http\Controllers\CurrentTeamController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DatabasePasswordController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DeploymentsController;
use App\Http\Controllers\EnvController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\NoTeamController;
use App\Http\Controllers\ReleasesController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerFirewallController;
use App\Http\Controllers\ServerHostKeyController;
use App\Http\Controllers\ServerTabsController;
use App\Http\Controllers\ServerTakeoverController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SharedRedisController;
use App\Http\Controllers\SshKeyController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('deployments', [DeploymentsController::class, 'index'])->name('deployments.index');
    Route::get('releases', [ReleasesController::class, 'latest'])->name('releases.index');
    Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
    Route::get('start', [NoTeamController::class, 'show'])->middleware('cloud')->name('start.show');
    Route::post('start', [NoTeamController::class, 'store'])
        ->middleware(['cloud', 'throttle:6,1,team-start'])
        ->name('start.store');

    Route::get('servers', fn() => to_route('dashboard'))->name('servers.index');
    Route::post('servers/cloud', [CloudServerController::class, 'store'])
        ->middleware('throttle:10,1,cloud-server-create')
        ->name('servers.cloud.store');
    Route::post('servers/{server}/cloud/retry', [CloudServerController::class, 'retry'])
        ->name('servers.cloud.retry');
    Route::delete('servers/{server}/cloud', [CloudServerController::class, 'destroy'])
        ->middleware('throttle:10,1,cloud-server-destroy')
        ->name('servers.cloud.destroy');
    Route::get('servers/{server}/cloud/sizes', [CloudServerController::class, 'sizes'])
        ->middleware('throttle:30,1,cloud-server-sizes')
        ->name('servers.cloud.sizes');
    Route::post('servers/{server}/cloud/resize', [CloudServerController::class, 'resize'])
        ->middleware('throttle:10,1,cloud-server-resize')
        ->name('servers.cloud.resize');
    Route::delete('servers/{server}/cloud/task', [CloudServerController::class, 'dismiss'])
        ->name('servers.cloud.dismiss');
    Route::resource('servers', ServerController::class)->except(['index', 'edit'])->withTrashed(['show'])
        ->middlewareFor('store', 'throttle:server-password');
    Route::post('servers/{server}/test-connection', [ServerController::class, 'testConnection'])
        ->middleware('throttle:10,1,server-test')
        ->name('servers.test-connection');
    Route::post('servers/{server}/connect', [ServerController::class, 'connect'])
        ->middleware('throttle:server-password')
        ->name('servers.connect');
    Route::post('servers/{server}/provision', [ServerController::class, 'provision'])
        ->name('servers.provision');
    Route::post('servers/{server}/provisioning/retry', [ServerController::class, 'retryProvisioning'])
        ->name('servers.provisioning.retry');
    Route::post('servers/{server}/takeover', [ServerTakeoverController::class, 'store'])
        ->middleware('throttle:10,1,server-takeover')
        ->name('servers.takeover.store');
    Route::post('servers/{server}/provisioning/restart', [ServerController::class, 'restartProvisioning'])
        ->name('servers.provisioning.restart');
    Route::post('servers/{server}/audit', [ServerController::class, 'audit'])
        ->name('servers.audit');
    Route::post('servers/{server}/audit/fixes/{fix}', [ServerController::class, 'fixAudit'])
        ->where('fix', 'stop-[a-z0-9]+')
        ->name('servers.audit.fix');
    Route::post('servers/{server}/reboot', [ServerController::class, 'reboot'])
        ->name('servers.reboot');
    Route::post('servers/{server}/prune-releases', [ServerController::class, 'prune'])
        ->name('servers.prune-releases');

    Route::get('servers/{server}/hardening', [ServerTabsController::class, 'hardening'])
        ->withTrashed()
        ->name('servers.hardening');
    Route::get('servers/{server}/activity', [ServerTabsController::class, 'activity'])
        ->withTrashed()
        ->name('servers.activity');
    Route::post('servers/{server}/firewall', [ServerFirewallController::class, 'store'])
        ->name('servers.firewall.store');

    Route::post('servers/{server}/host-key/accept', [ServerHostKeyController::class, 'accept'])
        ->name('servers.host-key.accept');
    Route::post('servers/{server}/host-key/reject', [ServerHostKeyController::class, 'reject'])
        ->name('servers.host-key.reject');

    Route::resource('servers.applications', ApplicationController::class)->except(['index', 'edit']);
    Route::get('servers/{server}/applications/{application}/releases', [ReleasesController::class, 'index'])
        ->name('servers.applications.releases.index');
    Route::get('servers/{server}/applications/{application}/deployments/{deployment}', [DeploymentController::class, 'show'])
        ->name('servers.applications.deployments.show');
    Route::post('servers/{server}/applications/{application}/deployments/{deployment}/cancel', [DeploymentController::class, 'cancel'])
        ->middleware('throttle:10,1,deploy-cancel')
        ->name('servers.applications.deployments.cancel');
    Route::post('servers/{server}/applications/{application}/stop', [ApplicationPowerController::class, 'stop'])
        ->name('servers.applications.stop');
    Route::post('servers/{server}/applications/{application}/start', [ApplicationPowerController::class, 'start'])
        ->name('servers.applications.start');
    Route::put('servers/{server}/applications/{application}/maintenance', [ApplicationPowerController::class, 'maintenance'])
        ->name('servers.applications.maintenance');
    Route::post('servers/{server}/applications/{application}/deploy', [DeploymentController::class, 'deploy'])
        ->name('servers.applications.deploy');
    Route::post('servers/{server}/applications/{application}/rollback', [DeploymentController::class, 'rollback'])
        ->name('servers.applications.rollback');
    Route::put('servers/{server}/applications/{application}/env', [EnvController::class, 'update'])
        ->name('servers.applications.env.update');
    Route::put('servers/{server}/applications/{application}/toggles/{toggle}', [ApplicationToggleController::class, 'update'])
        ->where('toggle', '[a-z_]+')
        ->name('servers.applications.toggles.update');
    Route::post('servers/{server}/applications/{application}/cron-jobs', [ApplicationCronJobController::class, 'store'])
        ->name('servers.applications.cron-jobs.store');
    Route::post('servers/{server}/applications/{application}/cron-jobs/sync', [ApplicationCronJobController::class, 'sync'])
        ->name('servers.applications.cron-jobs.sync');
    Route::put('servers/{server}/applications/{application}/cron-jobs/{cronJob}', [ApplicationCronJobController::class, 'update'])
        ->where('cronJob', '[0-9a-f]{16}')
        ->name('servers.applications.cron-jobs.update');
    Route::delete('servers/{server}/applications/{application}/cron-jobs/{cronJob}', [ApplicationCronJobController::class, 'destroy'])
        ->where('cronJob', '[0-9a-f]{16}')
        ->name('servers.applications.cron-jobs.destroy');
    Route::get('servers/{server}/applications/{application}/logs', [ApplicationLogController::class, 'index'])
        ->middleware('throttle:30,1,app-logs')
        ->name('servers.applications.logs');
    Route::get('servers/{server}/applications/{application}/cron-jobs/log', [ApplicationSchedulerLogController::class, 'index'])
        ->middleware('throttle:30,1,scheduler-log')
        ->name('servers.applications.cron-jobs.log');

    Route::resource('servers.databases', DatabaseController::class)->except(['edit', 'update'])->withTrashed(['index']);
    Route::post('servers/{server}/databases/{database}/backup', [DatabaseController::class, 'backup'])
        ->name('servers.databases.backup');
    Route::post('servers/{server}/databases/{database}/password', [DatabasePasswordController::class, 'show'])
        ->middleware('throttle:30,1,database-password')
        ->name('servers.databases.password.show');
    Route::put('servers/{server}/databases/{database}/password', [DatabasePasswordController::class, 'update'])
        ->middleware('throttle:10,1,database-password-rotation')
        ->name('servers.databases.password.update');
    Route::post('servers/{server}/databases/{database}/own-redis', [SharedRedisController::class, 'move'])
        ->middleware('throttle:10,1,shared-redis-move')
        ->name('servers.databases.own-redis');
    Route::post('servers/{server}/shared-redis/off', [SharedRedisController::class, 'turnOff'])
        ->middleware('throttle:6,1,shared-redis-off')
        ->name('servers.shared-redis.off');

    Route::resource('ssh-keys', SshKeyController::class)->only(['store', 'destroy']);

    Route::put('current-team', [CurrentTeamController::class, 'update'])->name('current-team.update');
    Route::get('teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::post('teams/{team}/members', [TeamMemberController::class, 'store'])->name('teams.members.store');
    Route::put('teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
    Route::delete('teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
    Route::delete('teams/{team}/invitations/{invitation}', [TeamInvitationController::class, 'destroy'])->name('teams.invitations.destroy');
});

Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationController::class, 'register'])
    ->middleware(['guest', 'throttle:10,1,invitation-register'])
    ->name('invitations.register');
Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])
    ->middleware(['auth', 'throttle:10,1,invitation-accept'])
    ->name('invitations.accept');

require __DIR__ . '/settings.php';
require __DIR__ . '/sso.php';

Route::middleware('setup.token')->group(function () {
    Route::get('install', [InstallationController::class, 'show'])->name('install.show');
    Route::post('install', [InstallationController::class, 'store'])
        ->middleware('throttle:10,1,install')
        ->name('install.store');
});
