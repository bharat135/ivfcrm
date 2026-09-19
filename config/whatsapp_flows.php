<?php

/*
|--------------------------------------------------------------------------
| WhatsApp Flows
|--------------------------------------------------------------------------
| Starter Flow JSON for the three things an IVF clinic actually needs from
| WhatsApp, plus the field mapping that turns each submission into CRM data.
|
| Two modes are represented:
|   - `navigate`      the flow renders entirely from its own JSON. Simplest to
|                     publish; every option must be known up front.
|   - `data_exchange` the flow calls back into the CRM between screens, so
|                     options (appointment slots) can be live. Requires the
|                     encrypted endpoint — see FlowEncryption.
*/

return [

    // Flow JSON schema version. Bumping this can invalidate published flows,
    // so it is pinned rather than tracking Meta's latest.
    'json_version' => env('WHATSAPP_FLOW_JSON_VERSION', '7.0'),

    // Only sent for endpoint flows.
    'data_api_version' => env('WHATSAPP_FLOW_DATA_API_VERSION', '3.0'),

    // Meta's Flow categories.
    'categories' => [
        'SIGN_UP'             => 'Sign up',
        'SIGN_IN'             => 'Sign in',
        'APPOINTMENT_BOOKING' => 'Appointment booking',
        'LEAD_GENERATION'     => 'Lead generation',
        'CONTACT_US'          => 'Contact us',
        'CUSTOMER_SUPPORT'    => 'Customer support',
        'SURVEY'              => 'Survey',
        'OTHER'               => 'Other',
    ],

    // Path to openssl.cnf. openssl_pkey_new() cannot generate a key without a
    // config file, and Windows/XAMPP PHP ships without OPENSSL_CONF set, so the
    // usual locations are probed. Leave null to auto-detect.
    'openssl_conf' => env('OPENSSL_CONF'),

    // How long a flow_token stays valid. Meta expires the message itself after
    // 30 days; sessions are closed earlier so stale ones do not accumulate.
    'session_ttl_days' => 7,

    'starters' => [

        // ------------------------------------------------------------------
        // 1. Lead qualification — static. The highest-value flow for a clinic:
        //    it replaces the "tell me about your case" phone call.
        // ------------------------------------------------------------------
        'lead_qualification' => [
            'label'       => 'Lead qualification',
            'description' => 'Captures the clinical context a counsellor would otherwise gather on the first call.',
            'categories'  => ['LEAD_GENERATION'],
            'mode'        => 'navigate',
            'field_map'   => [
                'full_name'          => 'name',
                'age'                => 'age',
                'city'               => 'city_name',
                'trying_since'       => 'trying_since',
                'previous_treatment' => 'previous_treatment',
                'notes'              => 'message',
            ],
            'json' => [
                'version' => '7.0',
                'screens' => [
                    [
                        'id'       => 'DETAILS',
                        'title'    => 'About you',
                        'terminal' => true,
                        'layout'   => [
                            'type'     => 'SingleColumnLayout',
                            'children' => [
                                [
                                    'type'     => 'Form',
                                    'name'     => 'lead_form',
                                    'children' => [
                                        [
                                            'type'       => 'TextInput',
                                            'name'       => 'full_name',
                                            'label'      => 'Your name',
                                            'input-type' => 'text',
                                            'required'   => true,
                                        ],
                                        [
                                            'type'       => 'TextInput',
                                            'name'       => 'age',
                                            'label'      => 'Your age',
                                            'input-type' => 'number',
                                            'required'   => true,
                                        ],
                                        [
                                            'type'        => 'Dropdown',
                                            'name'        => 'trying_since',
                                            'label'       => 'Trying to conceive for',
                                            'required'    => true,
                                            'data-source' => [
                                                ['id' => 'under_1', 'title' => 'Less than a year'],
                                                ['id' => '1_2',     'title' => '1 to 2 years'],
                                                ['id' => '3_5',     'title' => '3 to 5 years'],
                                                ['id' => 'over_5',  'title' => 'More than 5 years'],
                                            ],
                                        ],
                                        [
                                            'type'        => 'RadioButtonsGroup',
                                            'name'        => 'previous_treatment',
                                            'label'       => 'Previous fertility treatment',
                                            'required'    => true,
                                            'data-source' => [
                                                ['id' => 'none', 'title' => 'None yet'],
                                                ['id' => 'iui',  'title' => 'IUI'],
                                                ['id' => 'ivf',  'title' => 'IVF'],
                                                ['id' => 'other','title' => 'Something else'],
                                            ],
                                        ],
                                        [
                                            'type'       => 'TextInput',
                                            'name'       => 'city',
                                            'label'      => 'Your city',
                                            'input-type' => 'text',
                                        ],
                                        [
                                            'type'      => 'TextArea',
                                            'name'      => 'notes',
                                            'label'     => 'Anything else we should know?',
                                            'required'  => false,
                                        ],
                                        [
                                            'type'            => 'Footer',
                                            'label'           => 'Submit',
                                            'on-click-action' => [
                                                'name'    => 'complete',
                                                'payload' => [
                                                    'full_name'          => '${form.full_name}',
                                                    'age'                => '${form.age}',
                                                    'trying_since'       => '${form.trying_since}',
                                                    'previous_treatment' => '${form.previous_treatment}',
                                                    'city'               => '${form.city}',
                                                    'notes'              => '${form.notes}',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // ------------------------------------------------------------------
        // 2. Appointment booking — endpoint mode. Centres, doctors and slots
        //    are served live by the CRM, so the patient can only pick a slot
        //    that is genuinely free.
        // ------------------------------------------------------------------
        'appointment_booking' => [
            'label'       => 'Appointment booking',
            'description' => 'Live centres, doctors and open slots pulled from the CRM while the patient is choosing.',
            'categories'  => ['APPOINTMENT_BOOKING'],
            'mode'        => 'data_exchange',
            'field_map'   => [
                'full_name' => 'name',
                'center'    => 'center_id',
                'doctor'    => 'doctor_id',
                'slot'      => 'scheduled_at',
            ],
            'json' => [
                'version'       => '7.0',
                'data_api_version' => '3.0',
                'routing_model' => [
                    'CHOOSE_CENTER' => ['CHOOSE_SLOT'],
                    'CHOOSE_SLOT'   => ['CONFIRM'],
                    'CONFIRM'       => [],
                ],
                'screens' => [
                    [
                        'id'    => 'CHOOSE_CENTER',
                        'title' => 'Book a consultation',
                        // Populated by the endpoint's INIT response.
                        'data'  => [
                            'centers' => [
                                'type'     => 'array',
                                '__example__' => [['id' => '1', 'title' => 'Main Centre']],
                                'items'    => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'id'    => ['type' => 'string'],
                                        'title' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                            'doctors' => [
                                'type'     => 'array',
                                '__example__' => [['id' => '1', 'title' => 'Dr Example']],
                                'items'    => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'id'    => ['type' => 'string'],
                                        'title' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                        'layout' => [
                            'type'     => 'SingleColumnLayout',
                            'children' => [
                                [
                                    'type'     => 'Form',
                                    'name'     => 'center_form',
                                    'children' => [
                                        [
                                            'type'       => 'TextInput',
                                            'name'       => 'full_name',
                                            'label'      => 'Your name',
                                            'input-type' => 'text',
                                            'required'   => true,
                                        ],
                                        [
                                            'type'        => 'Dropdown',
                                            'name'        => 'center',
                                            'label'       => 'Centre',
                                            'required'    => true,
                                            'data-source' => '${data.centers}',
                                        ],
                                        [
                                            'type'        => 'Dropdown',
                                            'name'        => 'doctor',
                                            'label'       => 'Doctor',
                                            'required'    => false,
                                            'data-source' => '${data.doctors}',
                                        ],
                                        [
                                            'type'            => 'Footer',
                                            'label'           => 'See available times',
                                            'on-click-action' => [
                                                'name'    => 'data_exchange',
                                                'payload' => [
                                                    'screen'    => 'CHOOSE_CENTER',
                                                    'full_name' => '${form.full_name}',
                                                    'center'    => '${form.center}',
                                                    'doctor'    => '${form.doctor}',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id'    => 'CHOOSE_SLOT',
                        'title' => 'Pick a time',
                        'data'  => [
                            'slots' => [
                                'type'     => 'array',
                                '__example__' => [['id' => '2026-09-02T10:00', 'title' => 'Tue 2 Sep, 10:00 AM']],
                                'items'    => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'id'    => ['type' => 'string'],
                                        'title' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                            'full_name' => ['type' => 'string', '__example__' => 'Priya'],
                            'center'    => ['type' => 'string', '__example__' => '1'],
                            'doctor'    => ['type' => 'string', '__example__' => '1'],
                        ],
                        'layout' => [
                            'type'     => 'SingleColumnLayout',
                            'children' => [
                                [
                                    'type'     => 'Form',
                                    'name'     => 'slot_form',
                                    'children' => [
                                        [
                                            'type'        => 'RadioButtonsGroup',
                                            'name'        => 'slot',
                                            'label'       => 'Available times',
                                            'required'    => true,
                                            'data-source' => '${data.slots}',
                                        ],
                                        [
                                            'type'            => 'Footer',
                                            'label'           => 'Confirm booking',
                                            'on-click-action' => [
                                                'name'    => 'data_exchange',
                                                'payload' => [
                                                    'screen'    => 'CHOOSE_SLOT',
                                                    'full_name' => '${data.full_name}',
                                                    'center'    => '${data.center}',
                                                    'doctor'    => '${data.doctor}',
                                                    'slot'      => '${form.slot}',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    [
                        'id'       => 'CONFIRM',
                        'title'    => 'Booked',
                        'terminal' => true,
                        'success'  => true,
                        'data'     => [
                            'summary' => ['type' => 'string', '__example__' => 'Tue 2 Sep, 10:00 AM'],
                        ],
                        'layout' => [
                            'type'     => 'SingleColumnLayout',
                            'children' => [
                                [
                                    'type' => 'TextHeading',
                                    'text' => 'Your appointment is confirmed',
                                ],
                                [
                                    'type' => 'TextBody',
                                    'text' => '${data.summary}',
                                ],
                                [
                                    'type'     => 'Form',
                                    'name'     => 'confirm_form',
                                    'children' => [
                                        [
                                            'type'            => 'Footer',
                                            'label'           => 'Done',
                                            'on-click-action' => [
                                                'name'    => 'complete',
                                                'payload' => new stdClass(),
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],

        // ------------------------------------------------------------------
        // 3. Post-consultation feedback — static.
        // ------------------------------------------------------------------
        'feedback' => [
            'label'       => 'Post-consultation feedback',
            'description' => 'A short NPS-style survey to send after a visit.',
            'categories'  => ['SURVEY'],
            'mode'        => 'navigate',
            'field_map'   => [
                'rating'  => 'rating',
                'comment' => 'message',
            ],
            'json' => [
                'version' => '7.0',
                'screens' => [
                    [
                        'id'       => 'FEEDBACK',
                        'title'    => 'How did we do?',
                        'terminal' => true,
                        'layout'   => [
                            'type'     => 'SingleColumnLayout',
                            'children' => [
                                [
                                    'type'     => 'Form',
                                    'name'     => 'feedback_form',
                                    'children' => [
                                        [
                                            'type'        => 'RadioButtonsGroup',
                                            'name'        => 'rating',
                                            'label'       => 'How likely are you to recommend us?',
                                            'required'    => true,
                                            'data-source' => [
                                                ['id' => '5', 'title' => 'Very likely'],
                                                ['id' => '4', 'title' => 'Likely'],
                                                ['id' => '3', 'title' => 'Neutral'],
                                                ['id' => '2', 'title' => 'Unlikely'],
                                                ['id' => '1', 'title' => 'Very unlikely'],
                                            ],
                                        ],
                                        [
                                            'type'     => 'TextArea',
                                            'name'     => 'comment',
                                            'label'    => 'Anything we could do better?',
                                            'required' => false,
                                        ],
                                        [
                                            'type'            => 'Footer',
                                            'label'           => 'Send feedback',
                                            'on-click-action' => [
                                                'name'    => 'complete',
                                                'payload' => [
                                                    'rating'  => '${form.rating}',
                                                    'comment' => '${form.comment}',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    // CRM attributes a flow field can be mapped onto.
    'crm_fields' => [
        'name'               => 'Lead name',
        'mobile'             => 'Mobile',
        'email'              => 'Email',
        'age'                => 'Age',
        'gender'             => 'Gender',
        'city_name'          => 'City',
        'pincode'            => 'Pincode',
        'message'            => 'Remark / notes',
        'trying_since'       => 'Trying to conceive for',
        'previous_treatment' => 'Previous treatment',
        'rating'             => 'Feedback rating',
        'center_id'          => 'Centre',
        'doctor_id'          => 'Doctor',
        'scheduled_at'       => 'Appointment slot',
    ],
];
