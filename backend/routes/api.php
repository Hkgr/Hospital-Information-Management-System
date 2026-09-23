<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BloodBankController;
use App\Http\Controllers\Api\BloodEventController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\DossierCompletionController;
use App\Http\Controllers\Api\DossierController;
use App\Http\Controllers\Api\DossierImportController;
use App\Http\Controllers\Api\DossierPathologyController;
use App\Http\Controllers\Api\DossierWizardController;
use App\Http\Controllers\Api\FacilityReportController;
use App\Http\Controllers\Api\OncologyController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

Route::middleware(['auth:sanctum', 'account.active', 'abilities:api'])->group(function () {
    Route::prefix('dossiers')->name('dossiers.')->group(function () {
        $imports = DossierImportController::class;
        Route::get('/import-template.xlsx', [$imports, 'template'])->name('imports.template');
        Route::get('/imports', [$imports, 'index'])->name('imports.index');
        Route::post('/imports', [$imports, 'upload'])->name('imports.upload');
        Route::get('/imports/{batch}', [$imports, 'show'])->whereNumber('batch')->name('imports.show');
        Route::get('/imports/{batch}/errors.xlsx', [$imports, 'errors'])->whereNumber('batch')->name('imports.errors');
        foreach (['validate', 'commit', 'cancel'] as $operation) {
            Route::post('/imports/{batch}/'.$operation, [$imports, 'step'])->whereNumber('batch')->defaults('operation', $operation)->name('imports.'.$operation);
        }
        $oncology = OncologyController::class;
        Route::get('/treatment-options', [$oncology, 'options'])->name('treatment.options');
        Route::get('/{dossier}/treatment-plans', [$oncology, 'index'])->whereNumber('dossier')->name('treatment.index');
        Route::post('/{dossier}/treatment-plans', [$oncology, 'savePlan'])->whereNumber('dossier')->name('treatment.create');
        Route::get('/{dossier}/treatment-plans/{plan}', [$oncology, 'show'])->whereNumber(['dossier', 'plan'])->name('treatment.show');
        Route::put('/{dossier}/treatment-plans/{plan}', [$oncology, 'savePlan'])->whereNumber(['dossier', 'plan'])->name('treatment.update');
        Route::post('/{dossier}/treatment-plans/{plan}/status', [$oncology, 'status'])->whereNumber(['dossier', 'plan'])->name('treatment.status');
        Route::post('/{dossier}/treatment-plans/{plan}/sessions', [$oncology, 'schedule'])->whereNumber(['dossier', 'plan'])->name('treatment.schedule');
        Route::get('/{dossier}/treatment-sessions', [$oncology, 'sessions'])->whereNumber('dossier')->name('treatment.sessions');
        Route::post('/{dossier}/treatment-sessions', [$oncology, 'appointment'])->whereNumber('dossier')->name('treatment.appointment');
        Route::get('/{dossier}/treatment-sessions/{session}', [$oncology, 'session'])->whereNumber(['dossier', 'session'])->name('treatment.session');
        Route::put('/{dossier}/treatment-sessions/{session}', [$oncology, 'updateSession'])->whereNumber(['dossier', 'session'])->name('treatment.reschedule');
        Route::post('/{dossier}/treatment-sessions/{session}/session-doses', [$oncology, 'sessionDose'])->whereNumber(['dossier', 'session'])->name('treatment.session-dose');
        Route::put('/{dossier}/treatment-sessions/{session}/session-doses/{sessionDose}', [$oncology, 'sessionDose'])->whereNumber(['dossier', 'session', 'sessionDose'])->name('treatment.session-dose.update');
        Route::get('/{dossier}/visits/{visit}/doses', [$oncology, 'doses'])->whereNumber(['dossier', 'visit'])->name('treatment.doses');
        foreach (['doses' => ['administer', 'dose'], 'dispensing' => ['dispense', 'dispensing']] as $segment => [$handler, $parameter]) {
            Route::post('/{dossier}/visits/{visit}/'.$segment, [$oncology, $handler])->whereNumber(['dossier', 'visit'])->name('treatment.'.$segment.'.create');
            Route::put('/{dossier}/visits/{visit}/'.$segment.'/{'.$parameter.'}', [$oncology, $handler])->whereNumber(['dossier', 'visit', $parameter])->name('treatment.'.$segment.'.correct');
            Route::post('/{dossier}/visits/{visit}/'.$segment.'/{'.$parameter.'}/void', [$oncology, $handler])->whereNumber(['dossier', 'visit', $parameter])->name('treatment.'.$segment.'.void');
        }
        Route::get('/{dossier}/pathology', [DossierPathologyController::class, 'index'])->whereNumber('dossier')->name('pathology.history');
        Route::get('/{dossier}/visits/{visit}/pathology', [DossierPathologyController::class, 'index'])->whereNumber(['dossier', 'visit'])->name('pathology.index');
        Route::post('/{dossier}/visits/{visit}/pathology', [DossierPathologyController::class, 'save'])->whereNumber(['dossier', 'visit'])->name('pathology.create');
        Route::get('/{dossier}/visits/{visit}/pathology/{pathology}', [DossierPathologyController::class, 'show'])->whereNumber(['dossier', 'visit', 'pathology'])->name('pathology.show');
        Route::put('/{dossier}/visits/{visit}/pathology/{pathology}', [DossierPathologyController::class, 'save'])->whereNumber(['dossier', 'visit', 'pathology'])->name('pathology.update');
        Route::post('/{dossier}/visits/{visit}/pathology/{pathology}/void', [DossierPathologyController::class, 'save'])->whereNumber(['dossier', 'visit', 'pathology'])->name('pathology.void');
        Route::get('/{dossier}/visits/{visit}/diagnostic-assessment', [DossierPathologyController::class, 'assessment'])->whereNumber(['dossier', 'visit'])->name('assessment.show');
        Route::put('/{dossier}/visits/{visit}/diagnostic-assessment', [DossierPathologyController::class, 'saveAssessment'])->whereNumber(['dossier', 'visit'])->name('assessment.update');
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
        Route::get('/visits', [DossierController::class, 'visitDirectory'])->name('visit-directory');
        Route::get('/', [DossierController::class, 'index'])->name('index');
        Route::get('/{dossier}', [DossierController::class, 'show'])->whereNumber('dossier')->name('show');
        Route::delete('/{dossier}', [DossierController::class, 'destroy'])->whereNumber('dossier')->name('destroy');
        Route::get('/{dossier}/audit', [DossierController::class, 'audit'])->whereNumber('dossier')->name('audit');
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
        Route::prefix('{kind}/{item}')->whereIn('kind', ['service', 'procedure', 'medication'])->whereNumber('item')->group(function () {
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
        Route::get('/{doctor}/patients', [DoctorController::class, 'patients'])->whereNumber('doctor')->name('patients');
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
        Route::get('/{clinic}/patients', [ClinicController::class, 'patients'])->whereNumber('clinic')->name('patients');
        Route::get('/{clinic}/report', [ClinicController::class, 'report'])->whereNumber('clinic')->name('report');
    });
    Route::prefix('stock')->name('stock.')->group(function () {
        Route::get('/options', [StockController::class, 'options'])->name('options');
        Route::get('/receipts', [StockController::class, 'receipts'])->name('receipts');
        Route::post('/receipts', [StockController::class, 'createReceipt'])->name('receipts.store');
        Route::get('/receipts/{receipt}', [StockController::class, 'receipt'])->whereNumber('receipt')->name('receipts.show');
        Route::put('/receipts/{receipt}', [StockController::class, 'updateReceipt'])->whereNumber('receipt')->name('receipts.update');
        Route::post('/receipts/{receipt}/confirm', [StockController::class, 'confirm'])->whereNumber('receipt')->name('receipts.confirm');
        foreach (['suppliers', 'stores'] as $directory) {
            Route::get('/'.$directory, [StockController::class, 'index'])->defaults('directory', $directory)->name($directory);
            Route::post('/'.$directory, [StockController::class, 'store'])->defaults('directory', $directory)->name($directory.'.store');
            Route::get('/'.$directory.'/{item}', [StockController::class, 'show'])->defaults('directory', $directory)->whereNumber('item')->name($directory.'.show');
            Route::put('/'.$directory.'/{item}', [StockController::class, 'update'])->defaults('directory', $directory)->whereNumber('item')->name($directory.'.update');
            Route::delete('/'.$directory.'/{item}', [StockController::class, 'destroy'])->defaults('directory', $directory)->whereNumber('item')->name($directory.'.delete');
            Route::get('/'.$directory.'/{item}/deletion-preview', [StockController::class, 'deletionPreview'])->defaults('directory', $directory)->whereNumber('item')->name($directory.'.deletionPreview');
            foreach (['archive', 'restore', 'deactivate', 'reactivate'] as $action) {
                Route::post('/'.$directory.'/{item}/'.$action, [StockController::class, 'lifecycle'])->defaults('directory', $directory)->whereNumber('item')->name($directory.'.'.$action);
            }
        }
    });
    Route::get('/dashboards', [DashboardController::class, 'index'])->name('dashboards');
    Route::get('/dashboards/{key}', [DashboardController::class, 'show'])->name('dashboards.show');
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::get('/options', [UserController::class, 'options'])->name('options');
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
        Route::delete('/{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('destroy');
    });
    Route::get('/audit', [AuditLogController::class, 'index'])->name('audit');
    Route::get('/audit/{id}', [AuditLogController::class, 'show'])->whereNumber('id')->name('audit.show');
    Route::get('/reports', [FacilityReportController::class, 'index'])->name('reports');
    Route::get('/reports/export/pdf', [FacilityReportController::class, 'export'])->name('reports.export');
});

Route::middleware(['auth:sanctum', 'account.active', 'abilities:api'])->group(function () {
    Route::get('/user', [AuthController::class, 'currentUser'])->name('currentUser');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
