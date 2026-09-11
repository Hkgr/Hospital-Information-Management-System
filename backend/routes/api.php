<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware(['auth:sanctum', 'account.active', 'abilities:api'])->group(function () {
    Route::prefix('clinics')->name('clinics.')->group(function () {
        Route::get('/', [ClinicController::class, 'index'])->name('index');
        Route::post('/', [ClinicController::class, 'store'])->name('store');
        Route::get('/options/doctors', [ClinicController::class, 'doctorOptions'])->name('doctorOptions');
        Route::get('/options/specialties', [ClinicController::class, 'specialties'])->name('specialties');
        Route::get('/export/{format}', [ClinicController::class, 'export'])->whereIn('format', ['xlsx', 'pdf'])->name('export');
        Route::get('/{clinic}', [ClinicController::class, 'show'])->whereNumber('clinic')->name('show');
        Route::put('/{clinic}', [ClinicController::class, 'update'])->whereNumber('clinic')->name('update');
        Route::delete('/{clinic}', [ClinicController::class, 'destroy'])->whereNumber('clinic')->name('destroy');
        Route::post('/{clinic}/deactivate', [ClinicController::class, 'deactivate'])->whereNumber('clinic')->name('deactivate');
        Route::get('/{clinic}/doctors', [ClinicController::class, 'doctors'])->whereNumber('clinic')->name('doctors');
        Route::get('/{clinic}/report', [ClinicController::class, 'report'])->whereNumber('clinic')->name('report');
    });
    Route::get('/dashboards', [DashboardController::class, 'index'])->name('dashboards');
    Route::get('/dashboards/{key}', [DashboardController::class, 'show'])->name('dashboards.show');
});

Route::middleware(['auth:sanctum', 'account.active', 'abilities:api'])->group(function () {
    Route::get('/user', [AuthController::class, 'currentUser'])->name('currentUser');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
