<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Conversation;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappFlow;
use App\Models\WhatsappFlowResponse;
use App\Models\WhatsappFlowSession;
use App\Services\Meta\Flows\FlowEncryption;
use App\Services\Meta\Flows\FlowEndpointHandler;
use App\Services\Meta\Flows\FlowResponseProcessor;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * WhatsApp Flows, end to end: build → publish → send → run → receive.
 *
 * The encryption is exercised for real — a key pair is generated, a request is
 * encrypted exactly the way Meta does it, and the endpoint's reply is decrypted
 * back. That is the part with no second chance in production: a wrong IV or
 * padding mode fails silently as a 421 loop.
 */
class WhatsAppFlowTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        Model::preventLazyLoading(true);

        $env = [];
        foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\"'");
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host'     => $env['DB_HOST'] ?? '127.0.0.1',
            'database.connections.mysql.port'     => $env['DB_PORT'] ?? '3306',
            'database.connections.mysql.database' => $env['DB_DATABASE'] ?? 'ivfcrm',
            'database.connections.mysql.username' => $env['DB_USERNAME'] ?? 'root',
            'database.connections.mysql.password' => $env['DB_PASSWORD'] ?? '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
    }

    private function makeFlow(array $overrides = []): WhatsappFlow
    {
        $starter = config('whatsapp_flows.starters.lead_qualification');

        return WhatsappFlow::create(array_merge([
            'name'         => 'Test qualification flow',
            'purpose'      => 'lead_qualification',
            'status'       => 'PUBLISHED',
            'external_id'  => 'flow_' . uniqid(),
            'categories'   => ['LEAD_GENERATION'],
            'json_version' => '7.0',
            'flow_json'    => json_encode($starter['json']),
            'field_map'    => $starter['field_map'],
            'auto_create_lead' => true,
            'published_at' => now(),
        ], $overrides));
    }

    // =================================================================
    // Encryption — the part that must be exactly right
    // =================================================================

    public function test_a_key_pair_can_be_generated_and_the_private_half_is_encrypted_at_rest(): void
    {
        $encryption = app(FlowEncryption::class);

        $pair = $encryption->generateKeyPair();

        $this->assertStringContainsString('BEGIN PUBLIC KEY', $pair['public']);
        $this->assertStringContainsString('PRIVATE KEY', $pair['private']);
        $this->assertTrue($encryption->hasKeyPair());
        $this->assertNotNull($encryption->publicKeyFingerprint());

        $stored = \Illuminate\Support\Facades\DB::table('settings')
            ->where('group', 'meta')->where('key', 'flow_private_key')->value('value');

        $this->assertStringNotContainsString('PRIVATE KEY', (string) $stored,
            'The Flow private key must not be readable from the settings table.');
    }

    /**
     * Reproduces Meta's client side exactly: random AES-128 key, RSA-OAEP
     * wrap, AES-GCM body with the tag appended.
     *
     * @return array{body:array, aesKey:string, iv:string}
     */
    private function encryptLikeMeta(array $payload, string $publicKeyPem): array
    {
        $aesKey = random_bytes(16);
        $iv     = random_bytes(16);

        openssl_public_encrypt($aesKey, $wrapped, $publicKeyPem, OPENSSL_PKCS1_OAEP_PADDING);

        $tag = '';
        $ciphertext = openssl_encrypt(
            json_encode($payload), 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16,
        );

        return [
            'body' => [
                'encrypted_aes_key'   => base64_encode($wrapped),
                'encrypted_flow_data' => base64_encode($ciphertext . $tag),
                'initial_vector'      => base64_encode($iv),
            ],
            'aesKey' => $aesKey,
            'iv'     => $iv,
        ];
    }

    /**
     * A throwaway key pair, generated through the same openssl.cnf resolution
     * the service uses — openssl_pkey_new() returns false without it on hosts
     * where OPENSSL_CONF is unset, such as XAMPP.
     */
    private function throwawayPublicKey(): string
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        if ($config = app(FlowEncryption::class)->opensslConfig()) {
            $options['config'] = $config;
        }

        $key = openssl_pkey_new($options);

        $this->assertNotFalse($key, 'Could not generate a throwaway key pair for the test.');

        return openssl_pkey_get_details($key)['key'];
    }

    /** Decrypt an endpoint reply the way the WhatsApp client would. */
    private function decryptLikeMeta(string $base64, string $aesKey, string $iv): array
    {
        $flipped = '';
        for ($i = 0, $n = strlen($iv); $i < $n; $i++) {
            $flipped .= chr(~ord($iv[$i]) & 0xFF);
        }

        $raw        = base64_decode($base64);
        $tag        = substr($raw, -16);
        $ciphertext = substr($raw, 0, -16);

        $plain = openssl_decrypt($ciphertext, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $flipped, $tag);

        $this->assertNotFalse($plain, 'The endpoint response failed its authentication tag check.');

        return json_decode($plain, true);
    }

    public function test_the_encryption_round_trip_matches_metas_scheme(): void
    {
        $encryption = app(FlowEncryption::class);
        $pair = $encryption->generateKeyPair();

        $payload = ['action' => 'ping', 'version' => '3.0'];
        $encrypted = $this->encryptLikeMeta($payload, $pair['public']);

        $decrypted = $encryption->decryptRequest($encrypted['body']);

        $this->assertSame($payload, $decrypted['payload']);
        $this->assertSame($encrypted['aesKey'], $decrypted['aesKey']);

        // And the reply must come back readable with the flipped IV.
        $cipher = $encryption->encryptResponse(['data' => ['status' => 'active']], $decrypted['aesKey'], $decrypted['iv']);

        $this->assertSame(
            ['data' => ['status' => 'active']],
            $this->decryptLikeMeta($cipher, $encrypted['aesKey'], $encrypted['iv']),
        );
    }

    /**
     * Reusing the request IV would repeat a (key, nonce) pair under GCM, which
     * breaks the cipher outright. Meta's protocol flips every bit instead.
     */
    public function test_the_response_uses_a_flipped_iv_not_the_original(): void
    {
        $encryption = app(FlowEncryption::class);
        $pair = $encryption->generateKeyPair();

        $encrypted = $this->encryptLikeMeta(['action' => 'ping'], $pair['public']);
        $decrypted = $encryption->decryptRequest($encrypted['body']);

        $cipher = $encryption->encryptResponse(['data' => ['ok' => true]], $decrypted['aesKey'], $decrypted['iv']);

        $raw = base64_decode($cipher);
        $tag = substr($raw, -16);
        $body = substr($raw, 0, -16);

        // Decrypting with the UNflipped IV must fail.
        $this->assertFalse(
            openssl_decrypt($body, 'aes-128-gcm', $decrypted['aesKey'], OPENSSL_RAW_DATA, $encrypted['iv'], $tag),
            'The response decrypted with the original IV — the flip is missing.',
        );
    }

    public function test_a_request_encrypted_with_the_wrong_key_is_reported_as_a_key_mismatch(): void
    {
        $encryption = app(FlowEncryption::class);
        $encryption->generateKeyPair();

        // A different key pair entirely — as if Meta still held an old one.
        $encrypted = $this->encryptLikeMeta(['action' => 'ping'], $this->throwawayPublicKey());

        try {
            $encryption->decryptRequest($encrypted['body']);
            $this->fail('A mismatched key should not decrypt.');
        } catch (\App\Services\Meta\Flows\FlowDecryptionException $e) {
            $this->assertTrue($e->keyMismatch);
            // 421 tells the client to re-fetch the public key and retry.
            $this->assertSame(421, $e->httpStatus());
        }
    }

    // =================================================================
    // The endpoint over HTTP
    // =================================================================

    public function test_the_endpoint_answers_metas_health_check(): void
    {
        $encryption = app(FlowEncryption::class);
        $pair = $encryption->generateKeyPair();

        $encrypted = $this->encryptLikeMeta(['action' => 'ping', 'version' => '3.0'], $pair['public']);

        $response = $this->postJson('/api/webhooks/whatsapp-flow', $encrypted['body'])->assertOk();

        $this->assertSame(
            ['data' => ['status' => 'active']],
            $this->decryptLikeMeta($response->getContent(), $encrypted['aesKey'], $encrypted['iv']),
        );
    }

    public function test_the_endpoint_rejects_an_unencrypted_request(): void
    {
        app(FlowEncryption::class)->generateKeyPair();

        $this->postJson('/api/webhooks/whatsapp-flow', ['action' => 'ping'])
            ->assertStatus(432);
    }

    public function test_the_endpoint_returns_421_when_the_key_does_not_match(): void
    {
        app(FlowEncryption::class)->generateKeyPair();

        $encrypted = $this->encryptLikeMeta(['action' => 'ping'], $this->throwawayPublicKey());

        $this->postJson('/api/webhooks/whatsapp-flow', $encrypted['body'])->assertStatus(421);
    }

    public function test_the_endpoint_serves_the_first_screen_on_init(): void
    {
        $encryption = app(FlowEncryption::class);
        $pair = $encryption->generateKeyPair();

        $flow = $this->makeFlow(['purpose' => 'appointment_booking', 'endpoint_uri' => url('/api/webhooks/whatsapp-flow')]);

        $session = WhatsappFlowSession::create([
            'whatsapp_flow_id' => $flow->id,
            'flow_token'       => 'flw_init_test',
            'wa_id'            => '919812345678',
            'status'           => 'sent',
        ]);

        $encrypted = $this->encryptLikeMeta([
            'action'     => 'INIT',
            'version'    => '3.0',
            'flow_token' => $session->flow_token,
        ], $pair['public']);

        $response = $this->postJson('/api/webhooks/whatsapp-flow', $encrypted['body'])->assertOk();
        $reply = $this->decryptLikeMeta($response->getContent(), $encrypted['aesKey'], $encrypted['iv']);

        $this->assertSame('CHOOSE_CENTER', $reply['screen']);
        $this->assertArrayHasKey('centers', $reply['data']);

        // Opening the flow is recorded against the session.
        $this->assertSame('opened', $session->fresh()->status);
    }

    public function test_an_unknown_screen_is_reported_without_dead_ending_the_user(): void
    {
        $encryption = app(FlowEncryption::class);
        $pair = $encryption->generateKeyPair();

        $encrypted = $this->encryptLikeMeta([
            'action' => 'data_exchange',
            'data'   => ['screen' => 'NOT_A_SCREEN'],
        ], $pair['public']);

        $response = $this->postJson('/api/webhooks/whatsapp-flow', $encrypted['body'])->assertOk();
        $reply = $this->decryptLikeMeta($response->getContent(), $encrypted['aesKey'], $encrypted['iv']);

        $this->assertArrayHasKey('error_message', $reply['data']);
    }

    // =================================================================
    // Slot availability
    // =================================================================

    public function test_available_slots_exclude_times_already_booked(): void
    {
        $center = Center::where('is_active', true)->first() ?? Center::create(['name' => 'Slot Centre', 'code' => 'SLOT' . random_int(100, 999)]);

        $doctor = Doctor::create([
            'name' => 'Dr Slot Test', 'code' => 'DOCS' . random_int(1000, 9999),
            'center_id' => $center->id, 'is_active' => true,
        ]);

        // Availability every weekday, so the next seven days always contain some.
        foreach (range(0, 6) as $weekday) {
            DoctorSchedule::create([
                'doctor_id' => $doctor->id, 'center_id' => $center->id,
                'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00',
                'slot_minutes' => 30, 'is_active' => true,
            ]);
        }

        $handler = app(FlowEndpointHandler::class);

        $before = $handler->availableSlots($center->id, $doctor->id);
        $this->assertNotEmpty($before, 'Expected open slots from the doctor schedule.');

        // Book the first one.
        \App\Models\Appointment::create([
            'doctor_id'    => $doctor->id,
            'center_id'    => $center->id,
            'scheduled_at' => \Illuminate\Support\Carbon::parse($before[0]['id']),
            'type'         => 'consultation',
            'status'       => 'booked',
        ]);

        $after = $handler->availableSlots($center->id, $doctor->id);

        $this->assertNotContains(
            $before[0]['id'],
            array_column($after, 'id'),
            'A booked slot must not be offered again.',
        );
    }

    // =================================================================
    // Receiving a completion
    // =================================================================

    public function test_a_completed_flow_creates_a_lead_from_the_field_map(): void
    {
        $flow = $this->makeFlow();

        $conversation = Conversation::create([
            'channel' => 'whatsapp', 'contact_identifier' => '919700112233', 'status' => 'open',
        ]);

        $session = WhatsappFlowSession::create([
            'whatsapp_flow_id' => $flow->id,
            'conversation_id'  => $conversation->id,
            'flow_token'       => 'flw_completion_test',
            'wa_id'            => '919700112233',
            'status'           => 'sent',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'type'            => 'text',
            'status'          => 'delivered',
        ]);

        $response = app(FlowResponseProcessor::class)->record([
            'name' => 'flow',
            'body' => 'Sent',
            // Meta delivers the answers as a JSON *string*.
            'response_json' => json_encode([
                'flow_token'         => $session->flow_token,
                'full_name'          => 'Anita Sharma',
                'age'                => '32',
                'city'               => 'Delhi',
                'trying_since'       => '3_5',
                'previous_treatment' => 'iui',
                'notes'              => 'Prefers evening calls.',
            ]),
        ], $conversation, $message);

        $this->assertNotNull($response);
        $this->assertSame('processed', $response->status);

        $lead = Lead::where('mobile', '9700112233')->first();

        $this->assertNotNull($lead, 'A completed flow should create a lead.');
        $this->assertSame('Anita Sharma', $lead->name);
        $this->assertSame(32, $lead->age);
        $this->assertSame('Prefers evening calls.', $lead->remark);

        // Unmapped answers are still kept.
        $this->assertSame('3_5', $lead->extra['answers']['trying_since'] ?? null);

        $this->assertSame('completed', $session->fresh()->status);
        $this->assertSame(1, $flow->fresh()->response_count);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'title'   => 'WhatsApp flow completed',
        ]);
    }

    public function test_a_replayed_completion_does_not_duplicate_the_response(): void
    {
        $flow = $this->makeFlow();

        $conversation = Conversation::create([
            'channel' => 'whatsapp', 'contact_identifier' => '919700114455', 'status' => 'open',
        ]);

        WhatsappFlowSession::create([
            'whatsapp_flow_id' => $flow->id,
            'conversation_id'  => $conversation->id,
            'flow_token'       => 'flw_replay_test',
            'wa_id'            => '919700114455',
            'status'           => 'sent',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound', 'type' => 'text', 'status' => 'delivered',
        ]);

        $payload = [
            'response_json' => json_encode(['flow_token' => 'flw_replay_test', 'full_name' => 'Repeat Sender']),
        ];

        $processor = app(FlowResponseProcessor::class);
        $processor->record($payload, $conversation, $message);
        $processor->record($payload, $conversation, $message);

        $this->assertSame(1, WhatsappFlowResponse::where('flow_token', 'flw_replay_test')->count());
    }

    public function test_a_completion_arriving_through_the_webhook_is_recorded(): void
    {
        app(SettingsRepository::class)->set('meta', 'app_secret', 'flow-test-secret');

        $flow = $this->makeFlow();

        $conversation = Conversation::create([
            'channel' => 'whatsapp', 'contact_identifier' => '919700116677', 'status' => 'open',
        ]);

        WhatsappFlowSession::create([
            'whatsapp_flow_id' => $flow->id,
            'conversation_id'  => $conversation->id,
            'flow_token'       => 'flw_webhook_test',
            'wa_id'            => '919700116677',
            'status'           => 'sent',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id' => 'WABA1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PN1'],
                        'contacts' => [['profile' => ['name' => 'Flow User'], 'wa_id' => '919700116677']],
                        'messages' => [[
                            'from' => '919700116677',
                            'id'   => 'wamid.FLOW001',
                            'type' => 'interactive',
                            'interactive' => [
                                'type' => 'nfm_reply',
                                'nfm_reply' => [
                                    'name' => 'flow',
                                    'body' => 'Sent',
                                    'response_json' => json_encode([
                                        'flow_token' => 'flw_webhook_test',
                                        'full_name'  => 'Webhook Flow User',
                                        'age'        => '29',
                                    ]),
                                ],
                            ],
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);

        $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_ACCEPT'              => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'flow-test-secret'),
        ], $body)->assertOk();

        $this->assertDatabaseHas('whatsapp_flow_responses', [
            'flow_token' => 'flw_webhook_test',
            'status'     => 'processed',
        ]);

        $this->assertDatabaseHas('leads', ['name' => 'Webhook Flow User']);

        // The thread shows it as a form, not as an empty interactive reply.
        $message = Message::where('provider_message_id', 'wamid.FLOW001')->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('📋', $message->preview());
    }

    public function test_a_bad_field_map_fails_the_response_but_keeps_the_answers(): void
    {
        // A map pointing at a column that does not exist must not lose data.
        $flow = $this->makeFlow(['field_map' => ['full_name' => 'name'], 'auto_create_lead' => false]);

        $conversation = Conversation::create([
            'channel' => 'whatsapp', 'contact_identifier' => '919700118899', 'status' => 'open',
        ]);

        WhatsappFlowSession::create([
            'whatsapp_flow_id' => $flow->id, 'conversation_id' => $conversation->id,
            'flow_token' => 'flw_map_test', 'wa_id' => '919700118899', 'status' => 'sent',
        ]);

        $response = app(FlowResponseProcessor::class)->record([
            'response_json' => json_encode([
                'flow_token' => 'flw_map_test',
                'full_name'  => 'Unmapped Person',
                'mystery'    => 'kept anyway',
            ]),
        ], $conversation, null);

        $this->assertNotNull($response);
        $this->assertSame('kept anyway', $response->answers['mystery']);
    }

    // =================================================================
    // Model behaviour
    // =================================================================

    public function test_field_names_are_read_out_of_the_flow_json(): void
    {
        $flow = $this->makeFlow();

        $fields = $flow->fieldNames();

        foreach (['full_name', 'age', 'trying_since', 'previous_treatment', 'city', 'notes'] as $expected) {
            $this->assertContains($expected, $fields);
        }

        // The Form and Footer wrappers carry names but collect nothing.
        $this->assertNotContains('lead_form', $fields);
    }

    public function test_screen_ids_and_first_screen_come_from_the_json(): void
    {
        $flow = $this->makeFlow(['flow_json' => json_encode(config('whatsapp_flows.starters.appointment_booking.json'))]);

        $this->assertSame(['CHOOSE_CENTER', 'CHOOSE_SLOT', 'CONFIRM'], $flow->screenIds());
        $this->assertSame('CHOOSE_CENTER', $flow->firstScreen());
    }

    public function test_a_draft_flow_explains_why_it_cannot_be_sent(): void
    {
        $draft = $this->makeFlow(['status' => 'DRAFT']);

        $this->assertFalse($draft->canSend());
        $this->assertSame('This flow is still a draft. Publish it before sending.', $draft->blockReason());

        $published = $this->makeFlow();
        $this->assertTrue($published->canSend());
        $this->assertNull($published->blockReason());
    }

    public function test_every_starter_template_is_valid_flow_json(): void
    {
        foreach (config('whatsapp_flows.starters') as $key => $starter) {
            $json = $starter['json'];

            $this->assertArrayHasKey('version', $json, "Starter '{$key}' has no version.");
            $this->assertNotEmpty($json['screens'], "Starter '{$key}' has no screens.");

            $terminals = array_filter($json['screens'], fn ($s) => $s['terminal'] ?? false);
            $this->assertNotEmpty($terminals, "Starter '{$key}' has no terminal screen, so it can never finish.");

            // Every mapped field must actually exist in the flow.
            $flow = new WhatsappFlow(['flow_json' => json_encode($json)]);
            $fields = $flow->fieldNames();

            foreach (array_keys($starter['field_map'] ?? []) as $mapped) {
                $this->assertContains($mapped, $fields,
                    "Starter '{$key}' maps '{$mapped}', which no screen collects.");
            }
        }
    }

    // =================================================================
    // Screens + guards
    // =================================================================

    public function test_the_flow_screens_render(): void
    {
        $flow = $this->makeFlow();

        $this->actingAs($this->admin)->get(route('marketing.flows.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('marketing.flows.show', $flow))->assertOk();
    }

    public function test_a_published_flows_json_cannot_be_edited(): void
    {
        $flow = $this->makeFlow();

        $this->actingAs($this->admin)
            ->putJson(route('marketing.flows.update', $flow), [
                'name'      => $flow->name,
                'flow_json' => '{"version":"7.0","screens":[]}',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_invalid_json_is_refused(): void
    {
        $flow = $this->makeFlow(['status' => 'DRAFT']);

        $this->actingAs($this->admin)
            ->putJson(route('marketing.flows.update', $flow), [
                'name'      => $flow->name,
                'flow_json' => '{not json',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The Flow JSON is not valid JSON.']);
    }

    public function test_a_published_flow_cannot_be_deleted(): void
    {
        $flow = $this->makeFlow();

        $this->actingAs($this->admin)
            ->deleteJson(route('marketing.flows.destroy', $flow))
            ->assertStatus(422);

        $this->assertDatabaseHas('whatsapp_flows', ['id' => $flow->id, 'deleted_at' => null]);
    }

    public function test_sending_outside_the_window_is_refused(): void
    {
        $flow = $this->makeFlow();

        $conversation = Conversation::create([
            'channel' => 'whatsapp', 'contact_identifier' => '919700119911',
            'status' => 'open', 'window_expires_at' => now()->subHour(),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('marketing.flows.send', $flow), ['conversation_id' => $conversation->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('whatsapp_flow_sessions', ['conversation_id' => $conversation->id]);
    }

    public function test_a_telecaller_cannot_publish_a_flow(): void
    {
        $telecaller = User::factory()->create(['is_active' => true]);
        $telecaller->assignRole('Telecaller');

        $flow = $this->makeFlow(['status' => 'DRAFT']);

        $this->actingAs($telecaller)->postJson(route('marketing.flows.publish', $flow))->assertForbidden();
        $this->actingAs($telecaller)->get(route('marketing.flows.index'))->assertOk();
    }

    public function test_generating_keys_is_restricted_to_administrators(): void
    {
        $telecaller = User::factory()->create(['is_active' => true]);
        $telecaller->assignRole('Telecaller');

        $this->actingAs($telecaller)->postJson(route('marketing.flows.keys.generate'))->assertForbidden();
    }
}
