<?php

use App\Http\Controllers\Settings\TelephonyController;
use App\Http\Controllers\Telephony\CallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Telephony routes
|--------------------------------------------------------------------------
| Loaded from routes/web.php inside the ['auth', 'single.device'] group.
| The Asterisk dialplan webhook lives in routes/api.php instead, because the
| PBX cannot carry a session cookie or CSRF token.
*/

// -----------------------------------------------------------------------
// Agent call console + click-to-call
// -----------------------------------------------------------------------
Route::prefix('calls')->name('calls.')->middleware('can:call_logs.view')->group(function () {
    Route::get('/console', [CallController::class, 'console'])->name('console');

    // Polled by the topbar dialer widget.
    Route::get('/current', [CallController::class, 'current'])->name('current');
    Route::get('/transcription-config', [CallController::class, 'transcriptionConfig'])->name('transcription_config');
    Route::get('/recent', [CallController::class, 'recent'])->name('recent');
    Route::get('/board', [CallController::class, 'board'])->name('board');

    Route::post('/status', [CallController::class, 'setStatus'])->name('status');

    // Placing calls
    Route::post('/dial', [CallController::class, 'dial'])->middleware('can:call_logs.create')->name('dial');
    // In-browser softphone (WebRTC) originates the audio; these only log it.
    Route::post('/webrtc/start', [CallController::class, 'webrtcStart'])->middleware('can:call_logs.create')->name('webrtc_start');
    Route::post('/{call}/webrtc-event', [CallController::class, 'webrtcEvent'])->name('webrtc_event');
    Route::post('/dial-whatsapp', [CallController::class, 'dialWhatsApp'])->middleware('can:call_logs.create')->name('dial_whatsapp');
    Route::post('/request-permission', [CallController::class, 'requestCallPermission'])->name('request_permission');
    Route::post('/log', [CallController::class, 'logManual'])->middleware('can:call_logs.create')->name('log');

    // Acting on a specific call
    Route::prefix('{call}')->group(function () {
        Route::post('/hangup', [CallController::class, 'hangup'])->name('hangup');
        Route::post('/transfer', [CallController::class, 'transfer'])->name('transfer');
        Route::post('/monitor', [CallController::class, 'monitor'])->name('monitor');
        Route::post('/dtmf', [CallController::class, 'sendDtmf'])->name('dtmf');
        Route::post('/disposition', [CallController::class, 'disposition'])->name('disposition');
        Route::post('/transcript', [CallController::class, 'appendTranscript'])->name('transcript');
        Route::post('/summarize', [CallController::class, 'summarizeTranscript'])->name('summarize');
        Route::get('/recording', [CallController::class, 'recording'])->name('recording');
    });
});

// -----------------------------------------------------------------------
// Settings → Telephony
// -----------------------------------------------------------------------
Route::prefix('settings/telephony')->name('settings.telephony.')
    ->middleware('can:telephony.view')->group(function () {
        Route::get('/', [TelephonyController::class, 'index'])->name('index');
        Route::put('/', [TelephonyController::class, 'update'])->middleware('can:telephony.edit')->name('update');
        Route::post('/test', [TelephonyController::class, 'test'])->name('test');
        Route::get('/tata/agents', [TelephonyController::class, 'tataAgents'])->name('tata_agents');
        Route::post('/sync-registrations', [TelephonyController::class, 'syncRegistrations'])->name('sync_registrations');

        // WhatsApp calling
        Route::get('/whatsapp-calling', [TelephonyController::class, 'whatsappCallingStatus'])->name('whatsapp_status');
        Route::post('/whatsapp-calling', [TelephonyController::class, 'enableWhatsappCalling'])
            ->middleware('can:telephony.edit')->name('whatsapp_enable');

        // SIP extensions
        Route::prefix('extensions')->name('extensions.')->group(function () {
            Route::post('/', [TelephonyController::class, 'storeExtension'])->middleware('can:telephony.create')->name('store');
            Route::get('/{extension}/edit', [TelephonyController::class, 'editExtension'])->name('edit');
            Route::put('/{extension}', [TelephonyController::class, 'updateExtension'])->middleware('can:telephony.edit')->name('update');
            Route::delete('/{extension}', [TelephonyController::class, 'destroyExtension'])->middleware('can:telephony.delete')->name('destroy');
            Route::post('/{extension}/regenerate', [TelephonyController::class, 'regenerateSecret'])->middleware('can:telephony.edit')->name('regenerate');
            Route::get('/{extension}/secret', [TelephonyController::class, 'revealSecret'])->name('secret');
            Route::get('/{extension}/pbx-config', [TelephonyController::class, 'pbxConfig'])->name('pbx_config');
            Route::get('/{extension}/microsip', [TelephonyController::class, 'microsipConfig'])->name('microsip');
        });
    });

// Every agent may download their own MicroSIP profile without needing the
// telephony admin permission.
Route::get('/my-softphone', [TelephonyController::class, 'microsipConfig'])->name('telephony.my_microsip');
