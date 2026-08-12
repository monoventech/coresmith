<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationDeploymentsController;
use App\Http\Controllers\ApplicationPowerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\EnvController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerFirewallController;
use App\Http\Controllers\ServerHostKeyController;
use App\Http\Controllers\SshKeyController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamMemberController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('applications', [OverviewController::class, 'applications'])->name('applications.index');
    Route::get('databases', [OverviewController::class, 'databases'])->name('databases.index');

    Route::resource('servers', ServerController::class);
    Route::post('servers/{server}/test-connection', [ServerController::class, 'testConnection'])
        ->name('servers.test-connection');
    Route::post('servers/{server}/provision', [ServerController::class, 'provision'])
        ->name('servers.provision');

    Route::resource('servers.applications', ApplicationController::class);
    Route::get('servers/{server}/firewall', [ServerFirewallController::class, 'index'])
        ->name('servers.firewall.index');
    Route::post('servers/{server}/firewall', [ServerFirewallController::class, 'store'])
        ->name('servers.firewall.store');

    Route::get('servers/{server}/applications/{application}/deployments', [ApplicationDeploymentsController::class, 'index'])
        ->name('servers.applications.deployments.index');
    Route::get('servers/{server}/applications/{application}/features', [ApplicationDeploymentsController::class, 'features'])
        ->name('servers.applications.features');

    Route::post('servers/{server}/host-key/accept', [ServerHostKeyController::class, 'accept'])
        ->name('servers.host-key.accept');
    Route::post('servers/{server}/host-key/reject', [ServerHostKeyController::class, 'reject'])
        ->name('servers.host-key.reject');
    Route::get('servers/{server}/applications/{application}/deployments/{deployment}', [DeploymentController::class, 'show'])
        ->name('servers.applications.deployments.show');
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
    Route::get('servers/{server}/applications/{application}/env', [EnvController::class, 'show'])
        ->name('servers.applications.env.show');
    Route::put('servers/{server}/applications/{application}/env', [EnvController::class, 'update'])
        ->name('servers.applications.env.update');

    Route::resource('servers.databases', DatabaseController::class)->except(['edit', 'update']);
    Route::post('servers/{server}/databases/{database}/backup', [DatabaseController::class, 'backup'])
        ->name('servers.databases.backup');

    Route::resource('ssh-keys', SshKeyController::class)->only(['index', 'store', 'destroy']);

    Route::get('teams', [TeamController::class, 'index'])->name('teams.index');
    Route::get('teams/{team}', [TeamController::class, 'show'])->name('teams.show');
    Route::post('teams/{team}/members', [TeamMemberController::class, 'store'])->name('teams.members.store');
    Route::put('teams/{team}/members/{user}', [TeamMemberController::class, 'update'])->name('teams.members.update');
    Route::delete('teams/{team}/members/{user}', [TeamMemberController::class, 'destroy'])->name('teams.members.destroy');
});

require __DIR__.'/settings.php';

Route::middleware('setup.token')->group(function () {
    Route::get('install', [InstallationController::class, 'show'])->name('install.show');
    Route::post('install', [InstallationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('install.store');
});
