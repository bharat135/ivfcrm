<?php

use App\Http\Controllers\Tata\TataAgentController;
use App\Http\Controllers\Tata\TataBroadcastController;
use App\Http\Controllers\Tata\TataCallController;
use App\Http\Controllers\Tata\TataConfigController;
use App\Http\Controllers\Tata\TataDispositionController;
use App\Http\Controllers\Tata\TataNumberController;
use App\Http\Controllers\Tata\TataOverviewController;
use App\Http\Controllers\Tata\TataSoftphoneController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tata Teleservices (Smartflo) — configuration + two-way sync
|--------------------------------------------------------------------------
| Loaded from routes/web.php inside the ['auth', 'single.device'] group.
| Everything under /tata needs the `tata` permission module, except the
| agent's own softphone page which any caller may open.
*/

Route::prefix('tata')->name('tata.')->group(function () {

    // Every agent may set up their own in-browser softphone.
    Route::get('/softphone', [TataSoftphoneController::class, 'index'])->name('softphone.index');
    Route::put('/softphone', [TataSoftphoneController::class, 'update'])->name('softphone.update');
    Route::get('/softphone/credentials', [TataSoftphoneController::class, 'credentials'])->name('softphone.credentials');
    Route::delete('/softphone', [TataSoftphoneController::class, 'destroy'])->name('softphone.destroy');

    // Stream a live Smartflo recording (CDR read-through) — any caller with call access.
    Route::get('/recording', [\App\Http\Controllers\Tata\TataCallController::class, 'recording'])
        ->middleware('can:call_logs.view')->name('recording');

    Route::middleware('can:tata.view')->group(function () {
        Route::get('/', [TataOverviewController::class, 'index'])->name('index');
        Route::post('/sync-all', [TataOverviewController::class, 'syncAll'])->middleware('can:tata.edit')->name('sync_all');
        Route::get('/live', [TataOverviewController::class, 'live'])->name('live');

        // Configuration
        Route::get('/config', [TataConfigController::class, 'index'])->name('config.index');
        Route::put('/config', [TataConfigController::class, 'update'])->middleware('can:tata.edit')->name('config.update');
        Route::post('/config/test', [TataConfigController::class, 'test'])->name('config.test');
        Route::post('/config/generate-token', [TataConfigController::class, 'generateToken'])->middleware('can:tata.edit')->name('config.generate_token');

        // Agents (CRM users ↔ Smartflo users)
        Route::prefix('agents')->name('agents.')->group(function () {
            Route::get('/', [TataAgentController::class, 'index'])->name('index');
            Route::post('/pull', [TataAgentController::class, 'pull'])->middleware('can:tata.edit')->name('pull');
            Route::post('/create-user', [TataAgentController::class, 'createUser'])->middleware('can:tata.create')->name('create_user');
            Route::post('/enable-softphone', [TataAgentController::class, 'enableSoftphone'])->middleware('can:tata.edit')->name('enable_softphone');
            Route::get('/{agent}/details', [TataAgentController::class, 'details'])->name('details');
            Route::post('/{agent}/modify', [TataAgentController::class, 'modify'])->middleware('can:tata.edit')->name('modify');
            Route::post('/{user}/push', [TataAgentController::class, 'push'])->middleware('can:tata.create')->name('push');
            Route::post('/{user}/update', [TataAgentController::class, 'update'])->middleware('can:tata.edit')->name('update');
            Route::post('/{agent}/link', [TataAgentController::class, 'link'])->middleware('can:tata.edit')->name('link');
            Route::post('/{agent}/unlink', [TataAgentController::class, 'unlink'])->middleware('can:tata.edit')->name('unlink');
        });

        // Dispositions (CRM ↔ Smartflo dialer disposition list)
        Route::prefix('dispositions')->name('dispositions.')->group(function () {
            Route::get('/', [TataDispositionController::class, 'index'])->name('index');
            Route::post('/sync', [TataDispositionController::class, 'sync'])->middleware('can:tata.edit')->name('sync');
            Route::post('/push', [TataDispositionController::class, 'push'])->middleware('can:tata.edit')->name('push');
            Route::post('/pull', [TataDispositionController::class, 'pull'])->middleware('can:tata.edit')->name('pull');
        });

        // Numbers (DIDs), departments, IVRs
        Route::prefix('numbers')->name('numbers.')->group(function () {
            Route::get('/', [TataNumberController::class, 'index'])->name('index');
            Route::post('/pull', [TataNumberController::class, 'pull'])->middleware('can:tata.edit')->name('pull');
            Route::post('/{number}/default', [TataNumberController::class, 'setDefault'])->middleware('can:tata.edit')->name('default');
            Route::patch('/{number}', [TataNumberController::class, 'update'])->middleware('can:tata.edit')->name('update');
        });

        // Broadcasts
        Route::prefix('broadcasts')->name('broadcasts.')->group(function () {
            Route::get('/', [TataBroadcastController::class, 'index'])->name('index');
            Route::get('/create', [TataBroadcastController::class, 'create'])->middleware('can:tata.create')->name('create');
            Route::post('/preview', [TataBroadcastController::class, 'preview'])->name('preview');
            Route::post('/', [TataBroadcastController::class, 'store'])->middleware('can:tata.create')->name('store');
            Route::get('/{broadcast}', [TataBroadcastController::class, 'show'])->name('show');
            Route::post('/{broadcast}/refresh', [TataBroadcastController::class, 'refresh'])->name('refresh');
            Route::post('/{broadcast}/{action}', [TataBroadcastController::class, 'control'])
                ->middleware('can:tata.edit')->where('action', 'start|pause|resume|end')->name('control');
        });

        // Call records (CDR backfill, outcome sync, recordings)
        Route::prefix('calls')->name('calls.')->group(function () {
            Route::get('/', [TataCallController::class, 'index'])->name('index');
            Route::post('/pull', [TataCallController::class, 'pull'])->middleware('can:tata.edit')->name('pull');
            Route::post('/{call}/resync', [TataCallController::class, 'resync'])->middleware('can:tata.edit')->name('resync');
        });
    });
});
