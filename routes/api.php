<?php

use App\Http\Controllers\Api\V1\AsteriskWebhookController;
use App\Http\Controllers\Api\V1\LeadApiController;
use App\Http\Controllers\Api\V1\MetaWebhookController;
use App\Http\Controllers\Api\V1\TataWebhookController;
use App\Http\Controllers\Api\V1\ThirdPartyWhatsAppWebhookController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\CallLogController;
use Illuminate\Support\Facades\Route;

// Webhook endpoints (no auth — platform-side)
Route::prefix('webhooks')->group(function () {
    // Unified Meta webhook: WhatsApp messages/statuses, Lead Ads, template reviews.
    // Register this URL in the Meta App dashboard for the whatsapp_business_account
    // and page (leadgen) subscriptions.
    Route::get('/meta',  [MetaWebhookController::class, 'verify']);
    Route::post('/meta', [MetaWebhookController::class, 'handle']);

    // Third-party WhatsApp API (1automations). The token is generated per
    // account in Settings → Integrations and is the authentication.
    Route::get('/whatsapp-provider/{token}',  [ThirdPartyWhatsAppWebhookController::class, 'verify'])->where('token', '[A-Za-z0-9]+');
    Route::post('/whatsapp-provider/{token}', [ThirdPartyWhatsAppWebhookController::class, 'handle'])->where('token', '[A-Za-z0-9]+');

    // Legacy Facebook leadgen endpoint, kept so existing subscriptions keep working.
    Route::get('/facebook',  [WebhookController::class, 'facebook']);
    Route::post('/facebook', [WebhookController::class, 'facebook']);

    // Asterisk dialplan callbacks — shared-secret authenticated, since the PBX
    // cannot present a session cookie or CSRF token.
    Route::match(['get', 'post'], '/asterisk/{event}', [AsteriskWebhookController::class, 'handle'])
        ->where('event', '[a-z_]+');

    // Tata Tele Business Services (Smartflo) call events. Configured in the
    // Smartflo portal (API Connect → Webhook); authenticated by a shared token
    // in the URL or an X-Webhook-Token header.
    Route::match(['get', 'post'], '/tata/{event?}', [TataWebhookController::class, 'handle'])
        ->where('event', '[a-z_]+');

    // WhatsApp Flow data-exchange endpoint. Encrypted end to end, so it has
    // no CSRF or session — the RSA key pair is the authentication.
    Route::post('/whatsapp-flow', \App\Http\Controllers\Api\V1\WhatsAppFlowEndpointController::class);

    Route::post('/telephony/{provider}', [CallLogController::class, 'webhook']);

    // Catch-all for other lead sources — declared last so it cannot shadow the above.
    Route::post('/{source}', [WebhookController::class, 'generic']);
});

// Versioned API — Sanctum protected
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    // Leads
    Route::get('leads',                      [LeadApiController::class, 'index']);
    Route::post('leads',                     [LeadApiController::class, 'store']);
    Route::get('leads/{lead}',               [LeadApiController::class, 'show']);
    Route::post('leads/{lead}/disposition',  [LeadApiController::class, 'updateDisposition']);
});
