<?php

use App\Http\Controllers\Chat\InboxController;
use App\Http\Controllers\Marketing\AnalyticsController;
use App\Http\Controllers\Marketing\BroadcastController;
use App\Http\Controllers\Marketing\LandingPageController;
use App\Http\Controllers\Marketing\FlowController;
use App\Http\Controllers\Marketing\LeadFormController;
use App\Http\Controllers\Marketing\MetaAdController;
use App\Http\Controllers\Marketing\MetaAdsetController;
use App\Http\Controllers\Marketing\MetaCampaignController;
use App\Http\Controllers\Marketing\WhatsappTemplateController;
use App\Http\Controllers\Marketing\AudienceController;
use App\Http\Controllers\Settings\GoogleAdsController;
use App\Http\Controllers\Settings\IntegrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| WhatsApp + Meta Marketing routes
|--------------------------------------------------------------------------
| Loaded from routes/web.php inside the ['auth', 'single.device'] group, so
| every route here is already authenticated and single-device guarded.
*/

// -----------------------------------------------------------------------
// Unified WhatsApp inbox
// -----------------------------------------------------------------------
Route::prefix('chat')->name('chat.')->middleware('can:conversations.view')->group(function () {
    Route::get('/', [InboxController::class, 'index'])->name('index');
    Route::get('/poll', [InboxController::class, 'poll'])->name('poll');
    Route::get('/conversations', [InboxController::class, 'conversations'])->name('conversations');
    Route::post('/start', [InboxController::class, 'start'])->name('start');

    Route::get('/media/{attachment}', [InboxController::class, 'media'])->name('media');

    Route::prefix('conversations/{conversation}')->group(function () {
        Route::get('/', [InboxController::class, 'show'])->name('show');
        Route::post('/messages', [InboxController::class, 'sendText'])->name('send');
        Route::post('/media', [InboxController::class, 'sendMedia'])->name('send_media');
        Route::post('/template', [InboxController::class, 'sendTemplate'])->name('send_template');
        Route::post('/note', [InboxController::class, 'addNote'])->name('note');
        Route::post('/read', [InboxController::class, 'markRead'])->name('read');
        Route::post('/assign', [InboxController::class, 'assign'])->name('assign');
        Route::post('/status', [InboxController::class, 'setStatus'])->name('status');
        Route::post('/link', [InboxController::class, 'link'])->name('link');
    });
});

// -----------------------------------------------------------------------
// Meta marketing suite
// -----------------------------------------------------------------------
Route::prefix('marketing')->name('marketing.')->group(function () {

    // Campaigns
    Route::prefix('campaigns')->name('campaigns.')->middleware('can:campaigns.view')->group(function () {
        Route::get('/', [MetaCampaignController::class, 'index'])->name('index');
        Route::post('/sync', [MetaCampaignController::class, 'syncNow'])->name('sync');
        Route::post('/', [MetaCampaignController::class, 'store'])->middleware('can:campaigns.create')->name('store');
        Route::get('/{campaign}', [MetaCampaignController::class, 'show'])->name('show');
        Route::get('/{campaign}/edit', [MetaCampaignController::class, 'edit'])->middleware('can:campaigns.edit')->name('edit');
        Route::put('/{campaign}', [MetaCampaignController::class, 'update'])->middleware('can:campaigns.edit')->name('update');
        Route::patch('/{campaign}/status', [MetaCampaignController::class, 'toggleStatus'])->middleware('can:campaigns.edit')->name('status');
        Route::delete('/{campaign}', [MetaCampaignController::class, 'destroy'])->middleware('can:campaigns.delete')->name('destroy');
    });

    // Ad sets
    Route::prefix('adsets')->name('adsets.')->middleware('can:campaigns.view')->group(function () {
        Route::get('/', [MetaAdsetController::class, 'index'])->name('index');
        Route::get('/search/interests', [MetaAdsetController::class, 'searchInterests'])->name('search_interests');
        Route::get('/search/locations', [MetaAdsetController::class, 'searchLocations'])->name('search_locations');
        Route::post('/reach-estimate', [MetaAdsetController::class, 'reachEstimate'])->name('reach');
        Route::post('/', [MetaAdsetController::class, 'store'])->middleware('can:campaigns.create')->name('store');
        Route::get('/{adset}', [MetaAdsetController::class, 'show'])->name('show');
        Route::get('/{adset}/edit', [MetaAdsetController::class, 'edit'])->middleware('can:campaigns.edit')->name('edit');
        Route::put('/{adset}', [MetaAdsetController::class, 'update'])->middleware('can:campaigns.edit')->name('update');
        Route::patch('/{adset}/status', [MetaAdsetController::class, 'toggleStatus'])->middleware('can:campaigns.edit')->name('status');
        Route::delete('/{adset}', [MetaAdsetController::class, 'destroy'])->middleware('can:campaigns.delete')->name('destroy');
    });

    // Ads
    Route::prefix('ads')->name('ads.')->middleware('can:campaigns.view')->group(function () {
        Route::get('/', [MetaAdController::class, 'index'])->name('index');
        Route::post('/', [MetaAdController::class, 'store'])->middleware('can:campaigns.create')->name('store');
        Route::get('/{ad}/edit', [MetaAdController::class, 'edit'])->middleware('can:campaigns.edit')->name('edit');
        Route::get('/{ad}/preview', [MetaAdController::class, 'preview'])->name('preview');
        Route::put('/{ad}', [MetaAdController::class, 'update'])->middleware('can:campaigns.edit')->name('update');
        Route::patch('/{ad}/status', [MetaAdController::class, 'toggleStatus'])->middleware('can:campaigns.edit')->name('status');
        Route::delete('/{ad}', [MetaAdController::class, 'destroy'])->middleware('can:campaigns.delete')->name('destroy');
    });

    // Lead forms
    Route::prefix('lead-forms')->name('leadforms.')->middleware('can:campaigns.view')->group(function () {
        Route::get('/', [LeadFormController::class, 'index'])->name('index');
        Route::post('/sync', [LeadFormController::class, 'sync'])->name('sync');
        Route::post('/', [LeadFormController::class, 'store'])->middleware('can:campaigns.create')->name('store');
        Route::get('/{form}/edit', [LeadFormController::class, 'edit'])->name('edit');
        Route::put('/{form}', [LeadFormController::class, 'update'])->middleware('can:campaigns.edit')->name('update');
        Route::post('/{form}/pull-leads', [LeadFormController::class, 'pullLeads'])->name('pull');
        Route::post('/{form}/test-lead', [LeadFormController::class, 'testLead'])->middleware('can:leads.create')->name('test');
        Route::delete('/{form}', [LeadFormController::class, 'destroy'])->middleware('can:campaigns.delete')->name('destroy');
    });

    // WhatsApp templates
    Route::prefix('templates')->name('templates.')->middleware('can:whatsapp_templates.view')->group(function () {
        Route::get('/', [WhatsappTemplateController::class, 'index'])->name('index');
        Route::post('/sync', [WhatsappTemplateController::class, 'sync'])->name('sync');
        Route::post('/', [WhatsappTemplateController::class, 'store'])->middleware('can:whatsapp_templates.create')->name('store');
        Route::get('/{template}', [WhatsappTemplateController::class, 'show'])->name('show');
        Route::delete('/{template}', [WhatsappTemplateController::class, 'destroy'])->middleware('can:whatsapp_templates.delete')->name('destroy');
    });

    // WhatsApp Flows
    Route::prefix('flows')->name('flows.')->middleware('can:whatsapp_flows.view')->group(function () {
        Route::get('/', [FlowController::class, 'index'])->name('index');
        Route::post('/sync', [FlowController::class, 'sync'])->name('sync');
        Route::post('/', [FlowController::class, 'store'])->middleware('can:whatsapp_flows.create')->name('store');

        // Endpoint encryption keys
        Route::get('/keys', [FlowController::class, 'keyStatus'])->name('keys');
        Route::post('/keys/generate', [FlowController::class, 'generateKeys'])->middleware('can:whatsapp_flows.edit')->name('keys.generate');
        Route::post('/keys/upload', [FlowController::class, 'uploadKey'])->middleware('can:whatsapp_flows.edit')->name('keys.upload');

        Route::get('/responses/{response}', [FlowController::class, 'responseDetail'])->name('responses.show');
        Route::post('/responses/{response}/reprocess', [FlowController::class, 'reprocess'])
            ->middleware('can:whatsapp_flows.edit')->name('responses.reprocess');

        Route::get('/{flow}', [FlowController::class, 'show'])->name('show');
        Route::put('/{flow}', [FlowController::class, 'update'])->middleware('can:whatsapp_flows.edit')->name('update');
        Route::delete('/{flow}', [FlowController::class, 'destroy'])->middleware('can:whatsapp_flows.delete')->name('destroy');
        Route::post('/{flow}/push', [FlowController::class, 'push'])->middleware('can:whatsapp_flows.edit')->name('push');
        Route::post('/{flow}/publish', [FlowController::class, 'publish'])->middleware('can:whatsapp_flows.edit')->name('publish');
        Route::post('/{flow}/deprecate', [FlowController::class, 'deprecate'])->middleware('can:whatsapp_flows.edit')->name('deprecate');
        Route::get('/{flow}/preview', [FlowController::class, 'preview'])->name('preview');
        Route::post('/{flow}/send', [FlowController::class, 'send'])->middleware('can:whatsapp_flows.create')->name('send');
    });

    // Broadcasts
    Route::prefix('broadcasts')->name('broadcasts.')->middleware('can:broadcasts.view')->group(function () {
        Route::get('/', [BroadcastController::class, 'index'])->name('index');
        Route::post('/estimate', [BroadcastController::class, 'estimate'])->name('estimate');
        Route::post('/', [BroadcastController::class, 'store'])->middleware('can:broadcasts.create')->name('store');
        Route::get('/{broadcast}', [BroadcastController::class, 'show'])->name('show');
        Route::get('/{broadcast}/progress', [BroadcastController::class, 'progress'])->name('progress');
        Route::post('/{broadcast}/send', [BroadcastController::class, 'send'])->middleware('can:broadcasts.create')->name('send');
        Route::post('/{broadcast}/cancel', [BroadcastController::class, 'cancel'])->middleware('can:broadcasts.edit')->name('cancel');
        Route::delete('/{broadcast}', [BroadcastController::class, 'destroy'])->middleware('can:broadcasts.delete')->name('destroy');
    });

    // Landing pages
    Route::prefix('landing-pages')->name('landing.')->middleware('can:landing_pages.view')->group(function () {
        Route::get('/', [LandingPageController::class, 'index'])->name('index');
        Route::get('/create', [LandingPageController::class, 'create'])->middleware('can:landing_pages.create')->name('create');
        Route::post('/', [LandingPageController::class, 'store'])->middleware('can:landing_pages.create')->name('store');
        Route::get('/{landingPage}/edit', [LandingPageController::class, 'edit'])->middleware('can:landing_pages.edit')->name('edit');
        Route::put('/{landingPage}', [LandingPageController::class, 'update'])->middleware('can:landing_pages.edit')->name('update');
        Route::get('/{landingPage}/preview', [LandingPageController::class, 'preview'])->name('preview');
        Route::get('/{landingPage}/submissions', [LandingPageController::class, 'submissions'])->name('submissions');
        Route::post('/{landingPage}/publish', [LandingPageController::class, 'togglePublish'])->middleware('can:landing_pages.edit')->name('publish');
        Route::post('/{landingPage}/duplicate', [LandingPageController::class, 'duplicate'])->middleware('can:landing_pages.create')->name('duplicate');
        Route::delete('/{landingPage}', [LandingPageController::class, 'destroy'])->middleware('can:landing_pages.delete')->name('destroy');
    });

    // Analytics
    Route::prefix('analytics')->name('analytics.')->middleware('can:reports.view')->group(function () {
        Route::get('/', [AnalyticsController::class, 'index'])->name('index');
        Route::get('/trend', [AnalyticsController::class, 'trend'])->name('trend');
        Route::get('/breakdown', [AnalyticsController::class, 'breakdown'])->name('breakdown');
        Route::get('/funnel', [AnalyticsController::class, 'funnel'])->name('funnel');
        Route::get('/landing-pages', [AnalyticsController::class, 'landingPages'])->name('landing_pages');
    });
});

// -----------------------------------------------------------------------
// Marketing → Audiences
// -----------------------------------------------------------------------
Route::prefix('marketing/audiences')->name('marketing.audiences.')
    ->middleware('can:campaigns.view')->group(function () {
        Route::get('/', [AudienceController::class, 'index'])->name('index');
        Route::post('/sync', [AudienceController::class, 'sync'])->middleware('can:campaigns.edit')->name('sync');
        Route::post('/lookalike', [AudienceController::class, 'lookalike'])->middleware('can:campaigns.edit')->name('lookalike');
        Route::post('/unlink', [AudienceController::class, 'unlink'])->middleware('can:campaigns.edit')->name('unlink');
    });

// -----------------------------------------------------------------------
// Settings → Integrations
// -----------------------------------------------------------------------
Route::prefix('settings/integrations')->name('settings.integrations.')
    ->middleware('can:integrations.view')->group(function () {
        Route::get('/', [IntegrationController::class, 'index'])->name('index');
        Route::put('/', [IntegrationController::class, 'update'])->middleware('can:integrations.edit')->name('update');
        Route::post('/test', [IntegrationController::class, 'test'])->name('test');
        Route::post('/discover', [IntegrationController::class, 'discover'])->name('discover');
        Route::post('/page-tokens', [IntegrationController::class, 'savePageTokens'])->middleware('can:integrations.edit')->name('page_tokens');
        Route::post('/subscribe-webhook', [IntegrationController::class, 'subscribeWebhook'])->middleware('can:integrations.edit')->name('subscribe');
        Route::post('/subscribe-page', [IntegrationController::class, 'subscribePage'])->middleware('can:integrations.edit')->name('subscribe_page');
        Route::post('/sync', [IntegrationController::class, 'sync'])->middleware('can:integrations.edit')->name('sync');

        Route::post('/accounts', [IntegrationController::class, 'storeAccount'])->middleware('can:integrations.create')->name('accounts.store');
        Route::get('/accounts/{account}/edit', [IntegrationController::class, 'editAccount'])->name('accounts.edit');
        Route::put('/accounts/{account}', [IntegrationController::class, 'updateAccount'])->middleware('can:integrations.edit')->name('accounts.update');
        Route::delete('/accounts/{account}', [IntegrationController::class, 'destroyAccount'])->middleware('can:integrations.delete')->name('accounts.destroy');

        // Third-party WhatsApp API (1automations), alongside the Meta setup.
        Route::prefix('whatsapp-provider')->name('provider.')->group(function () {
            Route::put('/', [\App\Http\Controllers\Settings\ThirdPartyWhatsAppController::class, 'save'])->middleware('can:integrations.edit')->name('save');
            Route::post('/test', [\App\Http\Controllers\Settings\ThirdPartyWhatsAppController::class, 'test'])->name('test');
            Route::post('/webhook', [\App\Http\Controllers\Settings\ThirdPartyWhatsAppController::class, 'registerWebhook'])->middleware('can:integrations.edit')->name('webhook');
            Route::get('/webhooks', [\App\Http\Controllers\Settings\ThirdPartyWhatsAppController::class, 'listWebhooks'])->name('webhooks');
            Route::post('/templates', [\App\Http\Controllers\Settings\ThirdPartyWhatsAppController::class, 'syncTemplates'])->middleware('can:integrations.edit')->name('templates');
        });

        // Google Ads sits under the same permission as Meta: it is the other
        // half of the same paid-acquisition loop.
        Route::prefix('google-ads')->name('google.')->group(function () {
            Route::get('/', [GoogleAdsController::class, 'index'])->name('index');
            Route::put('/', [GoogleAdsController::class, 'update'])->middleware('can:integrations.edit')->name('update');
            Route::post('/test', [GoogleAdsController::class, 'test'])->name('test');
            Route::get('/conversion-actions', [GoogleAdsController::class, 'conversionActions'])->name('actions');
            Route::post('/sync', [GoogleAdsController::class, 'sync'])->middleware('can:integrations.edit')->name('sync');
        });
    });
