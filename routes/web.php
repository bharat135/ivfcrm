<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CallLogController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PublicLandingPageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\LeadImportController;
use App\Http\Controllers\Settings\AssignmentRuleController;
use App\Http\Controllers\Settings\CenterController;
use App\Http\Controllers\Settings\DispositionController;
use App\Http\Controllers\Settings\LeadSourceController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\UserController as SettingsUserController;
use Illuminate\Support\Facades\Route;

// Auth
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->name('login.submit');
});

Route::post('logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Public landing pages (no auth — this is the ad destination)
Route::get('/lp/{slug}', [PublicLandingPageController::class, 'show'])->name('landing.show');
Route::post('/lp/{slug}', [PublicLandingPageController::class, 'submit'])->name('landing.submit');

// Authenticated + Single Device
Route::middleware(['auth', 'single.device'])->group(function () {

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/pipeline', [DashboardController::class, 'pipeline'])->name('dashboard.pipeline');
    Route::get('/telecaller-stats', [DashboardController::class, 'telecallerStats'])->name('dashboard.telecaller_stats');

    // Leads
    Route::prefix('leads')->name('leads.')->group(function () {
        Route::get('/', [LeadController::class, 'index'])->name('index');
        Route::get('/next', [LeadController::class, 'next'])->name('next');
        Route::get('/create', [LeadController::class, 'create'])->middleware('can:leads.create')->name('create');
        Route::post('/', [LeadController::class, 'store'])->middleware('can:leads.create')->name('store');
        // Static paths before the {lead} wildcard, or it swallows them.
        Route::get('/quick-search', [LeadController::class, 'quickSearch'])->name('quick_search');
        Route::get('/assigned-tray', [LeadController::class, 'assignedTray'])->name('assigned_tray');
        Route::get('/{lead}', [LeadController::class, 'show'])->whereNumber('lead')->name('show');
        Route::get('/{lead}/edit', [LeadController::class, 'edit'])->name('edit');
        Route::put('/{lead}', [LeadController::class, 'update'])->name('update');
        Route::delete('/{lead}', [LeadController::class, 'destroy'])->name('destroy');
        Route::post('/{lead}/disposition', [LeadController::class, 'updateDisposition'])->name('disposition');
        Route::post('/{lead}/reassign', [LeadController::class, 'reassign'])->name('reassign');
        Route::post('/{lead}/stage', [LeadController::class, 'stageUpdate'])->name('stage');
        Route::patch('/{lead}/center', [LeadController::class, 'updateCenter'])->name('center');
        Route::post('/bulk-assign', [LeadController::class, 'bulkAssign'])->name('bulk_assign');

        // Bulk calling: dial the selected leads one after another.
        Route::middleware('can:call_logs.create')->prefix('bulk-call')->name('bulk_call.')->group(function () {
            Route::post('/', [\App\Http\Controllers\Telephony\DialSessionController::class, 'store'])->name('start');
            Route::get('/current', [\App\Http\Controllers\Telephony\DialSessionController::class, 'current'])->name('current');
            Route::post('/skip', [\App\Http\Controllers\Telephony\DialSessionController::class, 'skip'])->name('skip');
            Route::post('/stop', [\App\Http\Controllers\Telephony\DialSessionController::class, 'stop'])->name('stop');
        });
    });

    // Follow-ups
    Route::prefix('followups')->name('followups.')->group(function () {
        Route::get('/', [FollowUpController::class, 'index'])->name('index');
        Route::post('/', [FollowUpController::class, 'store'])->name('store');
        Route::post('/{followUp}/complete', [FollowUpController::class, 'complete'])->name('complete');
        Route::post('/{followUp}/cancel', [FollowUpController::class, 'cancel'])->name('cancel');
    });

    // Call Logs
    Route::prefix('call-logs')->name('call_logs.')->group(function () {
        Route::get('/', [CallLogController::class, 'index'])->name('index');
        Route::post('/', [CallLogController::class, 'store'])->name('store');
        Route::get('/{call}', [CallLogController::class, 'show'])->name('show');
    });

    // Appointments
    Route::prefix('appointments')->name('appointments.')->group(function () {
        Route::get('/', [AppointmentController::class, 'index'])->name('index');
        Route::post('/', [AppointmentController::class, 'store'])->name('store');
        Route::put('/{appointment}', [AppointmentController::class, 'update'])->name('update');
        Route::get('/calendar', [AppointmentController::class, 'calendar'])->name('calendar');
    });

    // Patients
    Route::prefix('patients')->name('patients.')->group(function () {
        Route::get('/', [PatientController::class, 'index'])->name('index');
        Route::post('/', [PatientController::class, 'store'])->name('store');
        Route::get('/{patient}', [PatientController::class, 'show'])->name('show');
        Route::put('/{patient}', [PatientController::class, 'update'])->name('update');
        Route::post('/{patient}/stage', [PatientController::class, 'advance'])->name('advance');
        Route::get('/{patient}/recordings', [PatientController::class, 'recordings'])->name('recordings');
    });

    // Campaigns
    Route::prefix('campaigns')->name('campaigns.')->group(function () {
        Route::get('/', [CampaignController::class, 'index'])->name('index');
        Route::post('/', [CampaignController::class, 'store'])->name('store');
        Route::get('/analytics', [CampaignController::class, 'analytics'])->name('analytics');
    });

    // WhatsApp inbox, Meta marketing suite, landing pages, integrations
    require __DIR__ . '/marketing.php';

    // Asterisk/MicroSIP calling, WhatsApp calling, call console
    require __DIR__ . '/telephony.php';

    // Tata Teleservices (Smartflo) configuration + two-way sync
    require __DIR__ . '/tata.php';

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/leads', [ReportController::class, 'leadsReport'])->name('leads');
        Route::get('/telecaller', [ReportController::class, 'telecallerReport'])->name('telecaller');
        Route::get('/source', [ReportController::class, 'sourceReport'])->name('source');
    });

    // Lead Imports
    Route::prefix('imports')->name('imports.')->middleware('can:lead_imports.create')->group(function () {
        Route::get('/', [LeadImportController::class, 'index'])->name('index')->withoutMiddleware('can:lead_imports.create');
        Route::get('/create', [LeadImportController::class, 'create'])->name('create');
        Route::post('/', [LeadImportController::class, 'store'])->name('store');
        Route::get('/sample', [LeadImportController::class, 'downloadSample'])->name('sample')->withoutMiddleware('can:lead_imports.create');
        Route::get('/{import}', [LeadImportController::class, 'show'])->name('show')->withoutMiddleware('can:lead_imports.create');
        Route::get('/{import}/errors', [LeadImportController::class, 'downloadErrors'])->name('errors')->withoutMiddleware('can:lead_imports.create');
    });

    // Settings
    Route::prefix('settings')->name('settings.')->group(function () {

        // Users
        Route::prefix('users')->name('users.')->middleware('can:users.view')->group(function () {
            Route::get('/', [SettingsUserController::class, 'index'])->name('index');
            Route::post('/', [SettingsUserController::class, 'store'])->middleware('can:users.create')->name('store');
            Route::get('/{user}/edit', [SettingsUserController::class, 'edit'])->middleware('can:users.edit')->name('edit');
            Route::put('/{user}', [SettingsUserController::class, 'update'])->middleware('can:users.edit')->name('update');
            Route::delete('/{user}', [SettingsUserController::class, 'destroy'])->middleware('can:users.delete')->name('destroy');
            Route::patch('/{user}/toggle-status', [SettingsUserController::class, 'toggleStatus'])->middleware('can:users.edit')->name('toggle_status');
        });

        // Roles
        Route::prefix('roles')->name('roles.')->middleware('can:roles.view')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->name('index');
            Route::post('/', [RoleController::class, 'store'])->middleware('can:roles.create')->name('store');
            Route::get('/{role}/edit', [RoleController::class, 'edit'])->middleware('can:roles.edit')->name('edit');
            Route::put('/{role}', [RoleController::class, 'update'])->middleware('can:roles.edit')->name('update');
            Route::delete('/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete')->name('destroy');
            Route::get('/{role}/permissions', [RoleController::class, 'permissions'])->name('permissions');
        });

        // Dispositions
        Route::prefix('dispositions')->name('dispositions.')->middleware('can:dispositions.view')->group(function () {
            Route::get('/', [DispositionController::class, 'index'])->name('index');
            Route::post('/', [DispositionController::class, 'store'])->middleware('can:dispositions.create')->name('store');
            Route::get('/{disposition}/edit', [DispositionController::class, 'edit'])->middleware('can:dispositions.edit')->name('edit');
            Route::put('/{disposition}', [DispositionController::class, 'update'])->middleware('can:dispositions.edit')->name('update');
            Route::delete('/{disposition}', [DispositionController::class, 'destroy'])->middleware('can:dispositions.delete')->name('destroy');
            Route::post('/reorder', [DispositionController::class, 'reorder'])->middleware('can:dispositions.edit')->name('reorder');
        });

        // Centers
        Route::prefix('centers')->name('centers.')->middleware('can:centers.view')->group(function () {
            Route::get('/', [CenterController::class, 'index'])->name('index');
            Route::post('/', [CenterController::class, 'store'])->middleware('can:centers.create')->name('store');
            Route::get('/{center}/edit', [CenterController::class, 'edit'])->middleware('can:centers.edit')->name('edit');
            Route::put('/{center}', [CenterController::class, 'update'])->middleware('can:centers.edit')->name('update');
            Route::delete('/{center}', [CenterController::class, 'destroy'])->middleware('can:centers.delete')->name('destroy');
        });

        // Doctors (who consultations can be booked with)
        Route::prefix('doctors')->name('doctors.')->middleware('can:doctors.view')->group(function () {
            Route::get('/', [\App\Http\Controllers\Settings\DoctorController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Settings\DoctorController::class, 'store'])->middleware('can:doctors.create')->name('store');
            Route::get('/{doctor}/edit', [\App\Http\Controllers\Settings\DoctorController::class, 'edit'])->middleware('can:doctors.edit')->name('edit');
            Route::put('/{doctor}', [\App\Http\Controllers\Settings\DoctorController::class, 'update'])->middleware('can:doctors.edit')->name('update');
            Route::patch('/{doctor}/toggle', [\App\Http\Controllers\Settings\DoctorController::class, 'toggle'])->middleware('can:doctors.edit')->name('toggle');
            Route::delete('/{doctor}', [\App\Http\Controllers\Settings\DoctorController::class, 'destroy'])->middleware('can:doctors.delete')->name('destroy');
        });

        // Lead Sources
        Route::prefix('lead-sources')->name('lead_sources.')->middleware('can:lead_sources.view')->group(function () {
            Route::get('/', [LeadSourceController::class, 'index'])->name('index');
            Route::post('/', [LeadSourceController::class, 'store'])->middleware('can:lead_sources.create')->name('store');
            Route::get('/{leadSource}/edit', [LeadSourceController::class, 'edit'])->middleware('can:lead_sources.edit')->name('edit');
            Route::put('/{leadSource}', [LeadSourceController::class, 'update'])->middleware('can:lead_sources.edit')->name('update');
            Route::delete('/{leadSource}', [LeadSourceController::class, 'destroy'])->middleware('can:lead_sources.delete')->name('destroy');
        });

        // Holidays & leaves (follow-up cover while an agent is away)
        Route::prefix('leaves')->name('leaves.')->middleware('can:leaves.view')->group(function () {
            Route::get('/', [\App\Http\Controllers\Settings\LeaveController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Settings\LeaveController::class, 'store'])->middleware('can:leaves.create')->name('store');
            Route::get('/{leave}/edit', [\App\Http\Controllers\Settings\LeaveController::class, 'edit'])->middleware('can:leaves.edit')->name('edit');
            Route::put('/{leave}', [\App\Http\Controllers\Settings\LeaveController::class, 'update'])->middleware('can:leaves.edit')->name('update');
            Route::delete('/{leave}', [\App\Http\Controllers\Settings\LeaveController::class, 'destroy'])->middleware('can:leaves.delete')->name('destroy');
        });

        // Lead assignment rules + fallback
        Route::prefix('assignment-rules')->name('assignment_rules.')->middleware('can:assignment_rules.view')->group(function () {
            Route::get('/', [AssignmentRuleController::class, 'index'])->name('index');
            Route::post('/', [AssignmentRuleController::class, 'store'])->middleware('can:assignment_rules.create')->name('store');
            Route::put('/fallback', [AssignmentRuleController::class, 'updateFallback'])->middleware('can:assignment_rules.edit')->name('fallback');
            Route::get('/{rule}/edit', [AssignmentRuleController::class, 'edit'])->name('edit');
            Route::put('/{rule}', [AssignmentRuleController::class, 'update'])->middleware('can:assignment_rules.edit')->name('update');
            Route::patch('/{rule}/toggle', [AssignmentRuleController::class, 'toggle'])->middleware('can:assignment_rules.edit')->name('toggle');
            Route::delete('/{rule}', [AssignmentRuleController::class, 'destroy'])->middleware('can:assignment_rules.delete')->name('destroy');
        });
    });

    // Live API documentation + token manager (admin).
    Route::prefix('developers')->name('developers.')->group(function () {
        Route::get('/', [\App\Http\Controllers\DeveloperController::class, 'docs'])->name('index');
        Route::post('/tokens', [\App\Http\Controllers\DeveloperController::class, 'createToken'])->name('tokens.store');
        Route::delete('/tokens/{id}', [\App\Http\Controllers\DeveloperController::class, 'revokeToken'])->name('tokens.destroy');
    });
});
