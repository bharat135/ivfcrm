<?php

namespace App\Providers;

use App\Models\Lead;
use App\Models\User;
use App\Policies\LeadPolicy;
use App\Policies\UserPolicy;
use App\Repositories\FollowUpRepository;
use App\Repositories\LeadRepository;
use App\Services\AssignmentEngine;
use App\Services\Chat\ConversationService;
use App\Services\DashboardService;
use App\Services\LeadService;
use App\Services\Marketing\FunnelService;
use App\Services\Meta\MetaAdsService;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaSyncService;
use App\Services\Meta\Flows\FlowDispatcher;
use App\Services\Meta\Flows\FlowEncryption;
use App\Services\Meta\Flows\FlowEndpointHandler;
use App\Services\Meta\Flows\FlowResponseProcessor;
use App\Services\Meta\Flows\WhatsAppFlowService;
use App\Services\Meta\MessengerService;
use App\Services\Meta\MetaWebhookProcessor;
use App\Services\Meta\SocialMessagingHandler;
use App\Services\Meta\WhatsAppCallingService;
use App\Services\Meta\WhatsAppService;
use App\Services\Telephony\AsteriskManager;
use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\CallService;
use App\Services\Telephony\WhatsAppCallHandler;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LeadRepository::class);
        $this->app->singleton(FollowUpRepository::class);
        $this->app->singleton(AssignmentEngine::class);
        $this->app->singleton(LeadService::class);
        $this->app->singleton(DashboardService::class);

        // Meta / WhatsApp integration. MetaClient is a singleton because it
        // resolves (cached) credentials on every outbound Graph call.
        $this->app->singleton(SettingsRepository::class);
        $this->app->singleton(MetaClient::class);
        $this->app->singleton(WhatsAppService::class);
        $this->app->singleton(MetaAdsService::class);
        $this->app->singleton(MetaSyncService::class);
        $this->app->singleton(MetaWebhookProcessor::class);
        $this->app->singleton(MessengerService::class);
        $this->app->singleton(SocialMessagingHandler::class);
        $this->app->singleton(ConversationService::class);
        $this->app->singleton(FunnelService::class);

        // Telephony: Asterisk/MicroSIP + WhatsApp calling.
        $this->app->singleton(AsteriskManager::class);
        $this->app->singleton(SmartfloClient::class);
        $this->app->singleton(\App\Services\Telephony\Tata\TataSyncService::class);
        $this->app->singleton(\App\Services\Telephony\Tata\TataBroadcastService::class);
        $this->app->singleton(\App\Services\Telephony\Tata\CallSummaryService::class);
        $this->app->singleton(\App\Services\Telephony\Tata\CallTranscriptService::class);
        $this->app->singleton(CallService::class);
        $this->app->singleton(WhatsAppCallingService::class);
        $this->app->singleton(WhatsAppCallHandler::class);

        // WhatsApp Flows.
        $this->app->singleton(FlowEncryption::class);
        $this->app->singleton(WhatsAppFlowService::class);
        $this->app->singleton(FlowDispatcher::class);
        $this->app->singleton(FlowEndpointHandler::class);
        $this->app->singleton(FlowResponseProcessor::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(app()->isLocal());

        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        // Super Admin bypasses all gates
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('Super Admin')) {
                return true;
            }
        });
    }
}
