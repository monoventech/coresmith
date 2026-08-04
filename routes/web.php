<?php

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
});

require __DIR__.'/settings.php';
