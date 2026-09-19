<?php

/*
|--------------------------------------------------------------------------
| Meta (Facebook / Instagram / WhatsApp) integration
|--------------------------------------------------------------------------
| Only non-secret defaults live here. Every credential is entered by the user
| under Settings -> Integrations and stored encrypted in the `settings` table
| (see App\Support\SettingsRepository). The .env values below are optional
| bootstrap fallbacks for local development only.
*/

return [

    'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
    'graph_url'     => env('META_GRAPH_URL', 'https://graph.facebook.com'),

    // Seconds before an outbound Graph call is abandoned.
    'timeout'       => (int) env('META_HTTP_TIMEOUT', 30),
    'retries'       => (int) env('META_HTTP_RETRIES', 2),

    // Fallbacks used only when the corresponding setting row is empty.
    'defaults' => [
        'app_id'              => env('META_APP_ID'),
        'app_secret'          => env('META_APP_SECRET'),
        'access_token'        => env('META_ACCESS_TOKEN'),
        'business_id'         => env('META_BUSINESS_ID'),
        'ad_account_id'       => env('META_AD_ACCOUNT_ID'),
        'page_id'             => env('META_PAGE_ID'),
        'waba_id'             => env('META_WABA_ID'),
        'phone_number_id'     => env('META_PHONE_NUMBER_ID'),
        'webhook_verify_token'=> env('META_WEBHOOK_VERIFY_TOKEN'),
        'pixel_id'            => env('META_PIXEL_ID'),
    ],

    'whatsapp' => [

        /*
         * An inbound WhatsApp message to the clinic's business number is an
         * enquiry, so it opens a lead — and therefore a patient record — the
         * moment it arrives. Turn this off only if the number also receives
         * non-enquiry traffic that would pollute the funnel.
         */
        'create_leads_from_inbound' => (bool) env('WHATSAPP_CREATE_LEADS_FROM_INBOUND', true),
        // Disk used to store inbound/outbound media.
        'media_disk' => env('WHATSAPP_MEDIA_DISK', 'local'),
        'media_path' => 'whatsapp',
        // Meta caps inbound media at 100MB; we cap uploads far lower.
        'max_upload_mb' => (int) env('WHATSAPP_MAX_UPLOAD_MB', 16),
        // The customer-service window, in hours, during which free-form replies
        // are allowed. Outside it Meta only accepts approved templates.
        'session_window_hours' => 24,
        // Throttle for broadcasts (messages per minute) to stay inside tier limits.
        'broadcast_rate_per_minute' => (int) env('WHATSAPP_BROADCAST_RPM', 60),

        'allowed_mimes' => [
            'image'    => ['image/jpeg', 'image/png', 'image/webp'],
            'video'    => ['video/mp4', 'video/3gpp'],
            'audio'    => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg'],
            'document' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
            ],
        ],
    ],

    'ads' => [
        // Objectives supported by the campaign builder (Outcome-Driven Ad Experiences).
        'objectives' => [
            'OUTCOME_LEADS'       => 'Leads',
            'OUTCOME_TRAFFIC'     => 'Traffic',
            'OUTCOME_ENGAGEMENT'  => 'Engagement',
            'OUTCOME_AWARENESS'   => 'Awareness',
            'OUTCOME_SALES'       => 'Sales',
            'OUTCOME_APP_PROMOTION' => 'App Promotion',
        ],

        'optimization_goals' => [
            'LEAD_GENERATION'         => 'Lead Generation',
            'QUALITY_LEAD'            => 'Quality Leads',
            'CONVERSATIONS'           => 'Conversations (Click to WhatsApp)',
            'LINK_CLICKS'             => 'Link Clicks',
            'LANDING_PAGE_VIEWS'      => 'Landing Page Views',
            'IMPRESSIONS'             => 'Impressions',
            'REACH'                   => 'Reach',
            'OFFSITE_CONVERSIONS'     => 'Conversions',
            'THRUPLAY'                => 'ThruPlay',
        ],

        'billing_events' => [
            'IMPRESSIONS' => 'Impressions',
            'LINK_CLICKS' => 'Link Clicks',
            'THRUPLAY'    => 'ThruPlay',
        ],

        'bid_strategies' => [
            'LOWEST_COST_WITHOUT_CAP' => 'Highest volume (automatic)',
            'LOWEST_COST_WITH_BID_CAP'=> 'Bid cap',
            'COST_CAP'                => 'Cost per result goal',
        ],

        'destination_types' => [
            'ON_AD'     => 'Instant Form (on Facebook)',
            'WEBSITE'   => 'Website / Landing Page',
            'WHATSAPP'  => 'WhatsApp',
            'MESSENGER' => 'Messenger',
        ],

        'special_ad_categories' => [
            'NONE'          => 'None',
            'CREDIT'        => 'Credit',
            'EMPLOYMENT'    => 'Employment',
            'HOUSING'       => 'Housing',
            'ISSUES_ELECTIONS_POLITICS' => 'Social Issues / Elections',
        ],

        // Fields requested from the Insights edge.
        'insight_fields' => [
            'impressions', 'reach', 'clicks', 'unique_clicks', 'spend',
            'frequency', 'ctr', 'cpc', 'cpm', 'actions', 'cost_per_action_type',
        ],
    ],

    // Funnel stage slugs used by the Lead -> Visit -> Booking report.
    'funnel' => [
        'lead'         => ['new-lead'],
        'contacted'    => ['contacted'],
        'interested'   => ['interested'],
        'booked'       => ['consultation-booked'],
        'visited'      => ['consultation-visited'],
        'treatment'    => ['investigation', 'treatment-planning', 'ivf-started', 'embryo-transfer'],
        'won'          => ['pregnancy-positive', 'delivery'],
    ],
];
