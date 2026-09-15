<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BloodBankController;
use App\Http\Controllers\Api\BloodEventController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\DossierCompletionController;
use App\Http\Controllers\Api\DossierController;
use App\Http\Controllers\Api\DossierWizardController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware(['auth:sanctum', 'account.active', 'abilities:api'])->group(function () {
    Route::prefix('dossiers')->name('dossiers.')->group(function () {
        $completion = DossierCompletionController::class;
        Route::post('/export/{format}', [$completion, 'report'])->whereIn('format', ['pdf', 'xlsx'])->name('export');
        Route::post('/{dossier}/report/{format}', [$completion, 'report'])->whereNumber('dossier')->whereIn('format', ['pdf', 'xlsx'])->name('report');
        Route::post('/{dossier}/visits/{visit}/report/{format}', [$completion, 'report'])->whereNumber(['dossier', 'visit'])->whereIn('format', ['pdf', 'xlsx'])->name('visits.report');
        Route::post('/medications', [$completion, 'medication'])->name('medications.store');
        Route::get('/{dossier}/visits/new', [$completion, 'newVisit'])->whereNumber('dossier')->name('visits.new');
        Route::post('/{dossier}/visits/subsequent', [$completion, 'subsequent'])->whereNumber('dossier')->defaults('section', 'visit')->name('visits.subsequent');
        Route::get('/{dossier}/visits/{visit}/progress', [$completion, 'progress'])->whereNumber(['dossier', 'visit'])->name('visits.progress');
        foreach (['clinical', 'medications'] as $section) {
            Route::put('/{dossier}/visits/{visit}/'.$section, [$completion, 'clinical'])->whereNumber(['dossier', 'visit'])->defaults('section', $section)->name('visits.'.$section);
        }
        Route::post('/{dossier}/visits/{visit}/review', [$completion, 'review'])->whereNumber(['dossier', 'visit'])->name('visits.review');
        Route::post('/{dossier}/visits/{visit}/complete', [$completion, 'complete'])->whereNumber(['dossier', 'visit'])->name('visits.complete');
        Route::get('/{dossier}/attachments', [$completion, 'attachments'])->whereNumber('dossier')->name('attachments');
        Route::post('/{dossier}/visits/{visit}/uploads', [$completion, 'beginUpload'])->whereNumber(['dossier', 'visit'])->name('uploads.begin');
        Route::post('/{dossier}/visits/{visit}/uploads/{upload}', [$completion, 'finishUpload'])->whereNumber(['dossier', 'visit', 'upload'])->name('uploads.finish');
        Route::post('/{dossier}/visits/{visit}/uploads/{upload}/cancel', [$completion, 'cancelUpload'])->whereNumber(['dossier', 'visit', 'upload'])->name('uploads.cancel');
        Route::get('/{dossier}/visits/{visit}/attachments/{attachment}/download', [$completion, 'download'])->whereNumber(['dossier', 'visit', 'attachment'])->name('attachments.download');
        Route::post('/{dossier}/visits/{visit}/attachments/{attachment}/void', [$completion, 'voidAttachment'])->whereNumber(['dossier', 'visit', 'attachment'])->name('attachments.void');
        Route::post('/', [DossierWizardController::class, 'personal'])->defaults('section', 'personal')->name('store');
        Route::get('/options', [DossierWizardController::class, 'options'])->name('options');
        foreach (['patients', 'cities', 'clinics', 'doctors', 'diagnoses', 'services', 'procedures', 'medications', 'outcomes'] as $lookup) {
            Route::get('/options/'.$lookup, [DossierWizardController::class, 'lookup'])->defaults('lookup', $lookup)->name('options.'.$lookup);
        }
        Route::post('/diagnoses', [DossierWizardController::class, 'diagnosis'])->name('diagnoses.store');
        Route::get('/{dossier}/progress', [DossierWizardController::class, 'progress'])->whereNumber('dossier')->name('progress');
        Route::put('/{dossier}/personal', [DossierWizardController::class, 'personal'])->whereNumber('dossier')->defaults('section', 'personal')->name('personal');
        Route::put('/{dossier}/medical', [DossierWizardController::class, 'medical'])->whereNumber('dossier')->defaults('section', 'medical')->name('medical');
        Route::post('/{dossier}/visits', [DossierWizardController::class, 'visit'])->whereNumber('dossier')->defaults('section', 'visit')->name('visits.store');
        Route::put('/{dossier}/visits/{visit}', [DossierWizardController::class, 'visit'])->whereNumber(['dossier', 'visit'])->defaults('section', 'visit')->name('visits.update');
        Route::get('/', [DossierController::class, 'index'])->name('index');
        Route::get('/{dossier}', [DossierController::class, 'show'])->whereNumber('dossier')->name('show');
        Route::get('/{dossier}/visits', [DossierController::class, 'visits'])->whereNumber('dossier')->name('visits');
        Route::get('/{dossier}/visits/{visit}', [DossierController::class, 'visit'])->whereNumber(['dossier', 'visit'])->name('visit');
    });
    Route::prefix('blood-bank')->name('blood-bank.')->group(function () {
        Route::get('/events', [BloodEventController::class, 'index'])->name('events');
        Route::post('/events', [BloodEventController::class, 'store'])->name('events.store');
        Route::get('/events/export/{format}', [BloodEventController::class, 'export'])->whereIn('format', ['pdf', 'xlsx'])->name('events.export');
        Route::get('/events/{event}', [BloodEventController::class, 'event'])->whereNumber('event')->name('events.show');
        Route::put('/events/{event}', [BloodEventController::class, 'update'])->whereNumber('event')->name('events.update');
        Route::get('/events/{event}/report/{format}', [BloodEventController::class, 'eventReport'])->whereNumber('event')->whereIn('format', ['pdf', 'xlsx'])->name('events.report');
        Route::get('/people', [BloodEventController::class, 'people'])->name('people');
        Route::get('/people/{person}', [BloodEventController::class, 'person'])->whereNumber('person')->name('people.show');
        Route::put('/people/{person}', [BloodEventController::class, 'updatePerson'])->whereNumber('person')->name('people.update');
        Route::get('/people/{person}/report/{format}', [BloodEventController::class, 'personReport'])->whereNumber('person')->whereIn('format', ['pdf', 'xlsx'])->name('people.report');
        Route::get('/legacy/{kind}/{item}', [BloodEventController::class, 'legacy'])->whereIn('kind', ['donor', 'recipient'])->whereNumber('item')->name('legacy');
        Route::get('/legacy/donor/{item}/donations/{donation}', [BloodEventController::class, 'legacyDonation'])->whereNumber(['item', 'donation'])->name('legacy.donation');
        Route::get('/export/{format}', [BloodBankController::class, 'export'])->whereIn('format', ['pdf', 'xlsx'])->name('export');
        Route::get('/{kind}/{item}/report/{format}', [BloodBankController::class, 'report'])->whereIn('kind', ['donor', 'recipient'])->whereNumber('item')->whereIn('format', ['pdf', 'xlsx'])->name('report');
        Route::get('/donor/{donor}/donations/{donation}/report/{format}', [BloodBankController::class, 'donationReport'])->whereNumber(['donor', 'donation'])->whereIn('format', ['pdf', 'xlsx'])->name('donationReport');
        Route::get('/', [BloodBankController::class, 'index'])->name('index');
        Route::post('/', [BloodEventController::class, 'retired'])->name('store.retired');
        foreach (['options', 'cities', 'clinics', 'doctors', 'patients'] as $action) {
            Route::get('/'.$action, [BloodBankController::class, $action])->name($action);
        }
        Route::get('/patients/{patient}', [BloodBankController::class, 'patient'])->whereNumber('patient')->name('patient');
        Route::get('/donor/{donor}/donations', [BloodBankController::class, 'donations'])->whereNumber('donor')->name('donations');
        Route::post('/donor/{donor}/donations', [BloodEventController::class, 'retired'])->whereNumber('donor')->name('donations.store.retired');
        Route::get('/donor/{donor}/donations/{donation}', [BloodBankController::class, 'donation'])->whereNumber(['donor', 'donation'])->name('donations.show');
        Route::put('/donor/{donor}/donations/{donation}', [BloodEventController::class, 'retired'])->whereNumber(['donor', 'donation'])->name('donations.update.retired');
        Route::get('/{kind}/{item}', [BloodBankController::class, 'show'])->whereIn('kind', ['donor', 'recipient'])->whereNumber('item')->name('show');
        Route::put('/{kind}/{item}', [BloodEventController::class, 'retired'])->whereIn('kind', ['donor', 'recipient'])->whereNumber('item')->name('update.retired');
    });
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
