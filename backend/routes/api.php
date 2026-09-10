<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware(['auth:sanctum', 'account.active', 'abilities:api'])->group(function () {
    Route::get('/dashboards', [DashboardController::class, 'index'])->name('dashboards');
    Route::get('/dashboards/{key}', [DashboardController::class, 'show'])->name('dashboards.show');
});

Route::middleware(['auth:sanctum', 'account.active', 'abilities:api'])->group(function () {
    Route::get('/user', [AuthController::class, 'currentUser'])->name('currentUser');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
