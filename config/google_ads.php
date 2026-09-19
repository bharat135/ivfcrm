<?php

/*
|--------------------------------------------------------------------------
| Google Ads
|--------------------------------------------------------------------------
| The clinic runs Google Ads alongside Meta, and both land on the same landing
| pages. Everything here exists so a Google-sourced enquiry can be told apart
| from a Meta one and, once the couple books, credited back to the keyword that
| paid for the click.
|
| Credentials are entered at Settings → Integrations → Google Ads, never here.
| These values are only fallbacks and shape.
*/

return [

    'api_version' => env('GOOGLE_ADS_API_VERSION', 'v18'),
    'api_url'     => env('GOOGLE_ADS_API_URL', 'https://googleads.googleapis.com'),
    'oauth_url'   => env('GOOGLE_ADS_OAUTH_URL', 'https://oauth2.googleapis.com/token'),

    'timeout' => (int) env('GOOGLE_ADS_TIMEOUT', 30),
    'retries' => (int) env('GOOGLE_ADS_RETRIES', 2),

    'defaults' => [
        'client_id'         => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret'     => env('GOOGLE_ADS_CLIENT_SECRET'),
        'refresh_token'     => env('GOOGLE_ADS_REFRESH_TOKEN'),
        'developer_token'   => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'customer_id'       => env('GOOGLE_ADS_CUSTOMER_ID'),
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        'currency'          => env('GOOGLE_ADS_CURRENCY', 'INR'),
    ],

    /*
     | Which conversion action each funnel stage reports to.
     |
     | Google needs a separate conversion action per outcome so bidding can be
     | told to optimise for booked consultations rather than raw form fills.
     | The value is a conversion action resource name
     | ("customers/123/conversionActions/456"), configured in settings — the
     | keys here are the CRM's own journey stages.
    */
    'conversion_actions' => [
        'enquiry'      => env('GOOGLE_ADS_ACTION_ENQUIRY'),
        'contacted'    => env('GOOGLE_ADS_ACTION_CONTACTED'),
        'consultation' => env('GOOGLE_ADS_ACTION_CONSULTATION'),
        'treatment'    => env('GOOGLE_ADS_ACTION_TREATMENT'),
    ],

    /*
     | Enhanced conversions for leads. When the gclid has been lost — the
     | visitor cleared cookies, or enquired by phone after seeing the ad —
     | Google can still match on a hashed email or phone number.
    */
    'enhanced_conversions' => (bool) env('GOOGLE_ADS_ENHANCED_CONVERSIONS', true),

    /*
     | Google rejects a click conversion dated before the click or more than 90
     | days after it.
    */
    'max_conversion_age_days' => 90,
];
