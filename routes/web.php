<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\EnvController;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::resource('servers', ServerController::class);
    Route::post('servers/{server}/test-connection', [ServerController::class, 'testConnection'])
        ->name('servers.test-connection');
    Route::post('servers/{server}/provision', [ServerController::class, 'provision'])
        ->name('servers.provision');

    Route::resource('servers.applications', ApplicationController::class);
    Route::post('servers/{server}/applications/{application}/deploy', [DeploymentController::class, 'deploy'])
        ->name('servers.applications.deploy');
    Route::post('servers/{server}/applications/{application}/rollback', [DeploymentController::class, 'rollback'])
        ->name('servers.applications.rollback');
    Route::get('servers/{server}/applications/{application}/env', [EnvController::class, 'show'])
        ->name('servers.applications.env.show');
    Route::put('servers/{server}/applications/{application}/env', [EnvController::class, 'update'])
        ->name('servers.applications.env.update');
});

require __DIR__.'/settings.php';
