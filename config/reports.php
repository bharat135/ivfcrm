<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily telecalling report
    |--------------------------------------------------------------------------
    |
    | Emailed every evening with the day's agent scorecard (dials, connects,
    | appointments, visits per centre, FTD/MTD). Recipients are a comma
    | separated list; the time is in the app timezone (Asia/Kolkata).
    |
    */

    'daily' => [
        'recipients' => array_values(array_filter(array_map('trim', explode(',', env(
            'DAILY_REPORT_RECIPIENTS',
            'clinical.operations@prevoirivf.com,CEO@prevoirivf.com,CCO@prevoirivf.com,crm.qaqc@prevoirivf.com'
        ))))),
        'send_at' => env('DAILY_REPORT_SEND_AT', '20:30'),
    ],

];
