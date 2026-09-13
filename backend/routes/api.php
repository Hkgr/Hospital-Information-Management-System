<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DoctorController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware(['auth:sanctum', 'account.active', 'abilities:api'])->group(function () {
    Route::prefix('service-catalog')->name('catalog.')->group(function () {
        Route::get('/context', [CatalogController::class, 'context'])->name('context');
        Route::post('/categories', [CatalogController::class, 'createCategory'])->name('categories.store');
        Route::get('/', [CatalogController::class, 'index'])->name('index');
        Route::post('/', [CatalogController::class, 'store'])->name('store');
        Route::get('/options', [CatalogController::class, 'options'])->name('options');
        Route::get('/classifications', [CatalogController::class, 'classifications'])->name('classifications');
        Route::get('/export/{format}', [CatalogController::class, 'export'])->whereIn('format', ['xlsx', 'pdf'])->name('export');
        Route::prefix('{kind}/{item}')->whereIn('kind', ['service', 'procedure'])->whereNumber('item')->group(function () {
            Route::get('/', [CatalogController::class, 'show'])->name('show');
            Route::put('/', [CatalogController::class, 'update'])->name('update');
            Route::delete('/', [CatalogController::class, 'lifecycle'])->name('delete');
            Route::get('/deletion-preview', [CatalogController::class, 'deletionPreview'])->name('deletionPreview');
            Route::get('/beneficiaries', [CatalogController::class, 'beneficiaries'])->name('beneficiaries');
            Route::get('/events', [CatalogController::class, 'events'])->name('events');
            Route::get('/history', [CatalogController::class, 'history'])->name('history');
            Route::get('/report', [CatalogController::class, 'report'])->name('report');
            foreach (['archive', 'restore', 'deactivate', 'reactivate'] as $action) {
                Route::post('/'.$action, [CatalogController::class, 'lifecycle'])->name($action);
            }
        });
    });
    Route::prefix('doctors')->name('doctors.')->group(function () {
        Route::get('/', [DoctorController::class, 'index'])->name('index');
        Route::post('/', [DoctorController::class, 'store'])->name('store');
        Route::get('/options', [DoctorController::class, 'options'])->name('options');
        Route::get('/options/clinics', [DoctorController::class, 'clinicOptions'])->name('clinicOptions');
        Route::get('/export/{format}', [DoctorController::class, 'export'])->whereIn('format', ['xlsx', 'pdf'])->name('export');
        Route::get('/{doctor}', [DoctorController::class, 'show'])->whereNumber('doctor')->name('show');
        Route::put('/{doctor}', [DoctorController::class, 'update'])->whereNumber('doctor')->name('update');
        Route::delete('/{doctor}', [DoctorController::class, 'destroy'])->whereNumber('doctor')->name('destroy');
        Route::get('/{doctor}/link-history', [DoctorController::class, 'linkHistory'])->whereNumber('doctor')->name('linkHistory');
        Route::get('/{doctor}/deletion-preview', [DoctorController::class, 'deletionPreview'])->whereNumber('doctor')->name('deletionPreview');
        Route::post('/{doctor}/archive', [DoctorController::class, 'archive'])->whereNumber('doctor')->name('archive');
        Route::post('/{doctor}/restore', [DoctorController::class, 'restore'])->whereNumber('doctor')->name('restore');
        Route::post('/{doctor}/reactivate', [DoctorController::class, 'reactivate'])->whereNumber('doctor')->name('reactivate');
        Route::post('/{doctor}/deactivate', [DoctorController::class, 'deactivate'])->whereNumber('doctor')->name('deactivate');
        Route::get('/{doctor}/clinics', [DoctorController::class, 'clinics'])->whereNumber('doctor')->name('clinics');
        Route::put('/{doctor}/clinics', [DoctorController::class, 'updateClinics'])->whereNumber('doctor')->name('updateClinics');
        Route::get('/{doctor}/report', [DoctorController::class, 'report'])->whereNumber('doctor')->name('report');
    });
    Route::prefix('clinics')->name('clinics.')->group(function () {
        Route::get('/', [ClinicController::class, 'index'])->name('index');
        Route::post('/', [ClinicController::class, 'store'])->name('store');
        Route::get('/options/doctors', [ClinicController::class, 'doctorOptions'])->name('doctorOptions');
        Route::get('/options/specialties', [ClinicController::class, 'specialties'])->name('specialties');
        Route::get('/export/{format}', [ClinicController::class, 'export'])->whereIn('format', ['xlsx', 'pdf'])->name('export');
        Route::get('/{clinic}', [ClinicController::class, 'show'])->whereNumber('clinic')->name('show');
        Route::put('/{clinic}', [ClinicController::class, 'update'])->whereNumber('clinic')->name('update');
        Route::delete('/{clinic}', [ClinicController::class, 'destroy'])->whereNumber('clinic')->name('destroy');
        Route::get('/{clinic}/link-history', [ClinicController::class, 'linkHistory'])->whereNumber('clinic')->name('linkHistory');
        Route::get('/{clinic}/deletion-preview', [ClinicController::class, 'deletionPreview'])->whereNumber('clinic')->name('deletionPreview');
        Route::post('/{clinic}/archive', [ClinicController::class, 'archive'])->whereNumber('clinic')->name('archive');
        Route::post('/{clinic}/restore', [ClinicController::class, 'restore'])->whereNumber('clinic')->name('restore');
        Route::post('/{clinic}/reactivate', [ClinicController::class, 'reactivate'])->whereNumber('clinic')->name('reactivate');
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
