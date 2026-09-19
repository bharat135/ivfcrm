<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\CallLog;
use App\Models\Lead;
use App\Models\User;
use App\Services\Telephony\SmartfloClient;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * Tata Tele Business Services (Smartflo) calling provider.
 *
 * The Smartflo API is faked throughout (TestCase forbids stray requests), so
 * what is covered is everything the CRM owns: provider switching, agent number
 * mapping, the click-to-call request shape, call-log bookkeeping, webhook
 * authentication, correlation and lifecycle, and the settings screen.
 */
class TataSmartfloTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectDatabase {
        refreshApplication as projectRefreshApplication;
    }

    private const API = 'https://api-smartflo.tatateleservices.com';

    private User $admin;
    private User $agent;
    private SettingsRepository $settings;

    protected function refreshApplication(): void
    {
        $this->projectRefreshApplication();

        Model::preventLazyLoading(true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsRepository::class);

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();

        $this->agent = User::factory()->create([
            'name'              => 'Smartflo Agent',
            'is_active'         => true,
            'center_id'         => $this->admin->center_id,
            'phone'             => '+91-9876500001',
            'tata_agent_number' => '9876500001',
        ]);
        $this->agent->assignRole('Telecaller');

        $this->settings->setMany([
            'provider'           => 'tata',
            'tata_access_token'  => 'portal-token-abc',
            'tata_caller_id'     => '02240001111',
            'tata_webhook_token' => 'tata-hook-secret',
        ], 'telephony');

        Cache::forget('telephony.tata.access_token');
    }

    protected function tearDown(): void
    {
        // Settings are not covered by the transaction cache-wise; leave the
        // developer's environment on Asterisk.
        $this->settings->set('telephony', 'provider', 'asterisk');
        $this->settings->forget('telephony', 'tata_access_token');
        $this->settings->forget('telephony', 'tata_login_id');
        $this->settings->forget('telephony', 'tata_password');
        $this->settings->forget('telephony', 'tata_webhook_token');
        $this->settings->forget('telephony', 'tata_caller_id');
        Cache::forget('telephony.tata.access_token');

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Provider switching
    // -----------------------------------------------------------------

    public function test_the_setting_switches_the_active_provider(): void
    {
        $calls = app(\App\Services\Telephony\CallService::class);

        $this->assertSame('tata', $calls->provider());
        $this->assertTrue($calls->usesTata());

        $this->settings->set('telephony', 'provider', 'asterisk');

        $this->assertSame('asterisk', $calls->provider());
        $this->assertFalse($this->agent->fresh()->canPlaceCalls(), 'Without a SIP extension the agent cannot dial on Asterisk.');
    }

    public function test_an_agent_with_a_tata_number_can_place_calls_without_a_sip_extension(): void
    {
        $this->assertTrue($this->agent->canPlaceCalls());
        $this->assertSame('9876500001', $this->agent->dialIdentity());
    }

    public function test_an_unknown_provider_value_falls_back_to_asterisk(): void
    {
        $this->settings->set('telephony', 'provider', 'exotel');

        $this->assertSame('asterisk', app(\App\Services\Telephony\CallService::class)->provider());
    }

    // -----------------------------------------------------------------
    // Click-to-call
    // -----------------------------------------------------------------

    public function test_dialling_without_an_agent_number_is_refused_with_a_clear_message(): void
    {
        $noNumber = User::factory()->create(['is_active' => true, 'phone' => null, 'tata_agent_number' => null]);
        $noNumber->assignRole('Telecaller');

        Http::fake();

        $this->actingAs($noNumber)
            ->postJson(route('calls.dial'), ['number' => '9812345678'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'message' => 'No Tata Smartflo agent number is set on your account. Ask an administrator to add it under Settings → Users.',
            ]);

        Http::assertNothingSent();
    }

    public function test_click_to_call_sends_the_right_request_and_records_the_call(): void
    {
        $lead = Lead::create(['name' => 'Smartflo Target', 'mobile' => '9811100011', 'status' => 'new']);

        Http::fake([
            self::API . '/v1/click_to_call' => Http::response([
                'success' => true,
                'message' => 'Call originated successfully.',
                'ref_id'  => 'C2C2609170001',
            ]),
        ]);

        $response = $this->actingAs($this->agent)
            ->postJson(route('calls.dial'), ['lead_id' => $lead->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('call.provider', 'tata')
            ->assertJsonPath('call.status', 'ringing');

        $this->assertStringContainsString('Smartflo', $response->json('message'));

        $call = CallLog::find($response->json('call.id'));

        $this->assertSame('tata', $call->provider);
        $this->assertSame('outbound', $call->direction);
        $this->assertSame('C2C2609170001', $call->provider_call_id);
        $this->assertSame('9811100011', $call->to_number);
        $this->assertSame('02240001111', $call->from_number, 'The DID shown to the customer is the caller id.');
        $this->assertSame('9876500001', $call->extension, 'The agent leg is the mapped Tata number.');
        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame((string) $call->id, $call->meta['custom_identifier']['crm_call_id']);

        Http::assertSent(function (Request $request) use ($call) {
            return $request->url() === self::API . '/v1/click_to_call'
                && $request->hasHeader('Authorization', 'Bearer portal-token-abc')
                && $request['agent_number'] === '9876500001'
                && $request['destination_number'] === '9811100011'
                && $request['caller_id'] === '02240001111'
                && $request['async'] === 1
                && $request['custom_identifier'] === ['crm_call_id' => (string) $call->id];
        });

        // No login call when a portal token is configured.
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v1/auth/login'));

        $this->assertSame('ringing', $this->agent->fresh()->call_status);
        $this->assertSame($call->id, $this->agent->fresh()->active_call_log_id);
        $this->assertSame(1, $lead->fresh()->contact_attempts);
        $this->assertDatabaseHas('call_events', ['call_log_id' => $call->id, 'source' => 'tata', 'event' => 'originate']);
    }

    public function test_a_refused_click_to_call_is_recorded_as_failed_and_surfaced_to_the_agent(): void
    {
        Http::fake([
            self::API . '/v1/click_to_call' => Http::response([
                'success' => false,
                'message' => 'Invalid destination number.',
            ], 400),
        ]);

        $this->actingAs($this->agent)
            ->postJson(route('calls.dial'), ['number' => '9811100099'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid destination number.');

        $call = CallLog::where('user_id', $this->agent->id)->latest('id')->first();

        $this->assertSame('failed', $call->status);
        $this->assertSame('originate_failed', $call->hangup_cause);
        $this->assertSame('Invalid destination number.', $call->remark);
        $this->assertNotNull($call->ended_at);
    }

    public function test_login_credentials_are_exchanged_for_a_cached_bearer_token(): void
    {
        $this->settings->forget('telephony', 'tata_access_token');
        $this->settings->setMany(['tata_login_id' => 'clinic@example.com', 'tata_password' => 'S3cret!'], 'telephony');

        Http::fake([
            self::API . '/v1/auth/login'    => Http::response(['success' => true, 'access_token' => 'login-token-xyz', 'token_type' => 'bearer', 'expires_in' => 3600]),
            self::API . '/v1/click_to_call' => Http::response(['success' => true, 'ref_id' => 'C2C-1']),
        ]);

        $client = app(SmartfloClient::class);

        $client->clickToCall('9876500001', '9811100011');
        $client->clickToCall('9876500001', '9811100012');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/auth/login')
            && $r['email'] === 'clinic@example.com' && $r['password'] === 'S3cret!');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/click_to_call')
            && $r->hasHeader('Authorization', 'Bearer login-token-xyz'));
    }

    public function test_the_agent_mobile_is_normalised_but_agent_ids_are_passed_through(): void
    {
        $calls = app(\App\Services\Telephony\CallService::class);

        $this->agent->forceFill(['tata_agent_number' => '+91 98765 00001'])->save();
        $this->assertSame('9876500001', $calls->tataAgentNumber($this->agent->fresh()));

        $this->agent->forceFill(['tata_agent_number' => '0501234567'])->save();
        $this->assertSame('0501234567', $calls->tataAgentNumber($this->agent->fresh()));

        $this->agent->forceFill(['tata_agent_number' => null, 'phone' => '+91-9123456789'])->save();
        $this->assertSame('9123456789', $calls->tataAgentNumber($this->agent->fresh()), 'Falls back to the profile phone.');
    }

    public function test_hanging_up_a_tata_call_asks_smartflo_and_closes_the_record(): void
    {
        Http::fake([
            self::API . '/v1/call/hangup' => Http::response(['success' => true]),
        ]);

        $call = CallLog::create([
            'user_id' => $this->agent->id, 'provider' => 'tata', 'channel' => 'pstn', 'direction' => 'outbound',
            'to_number' => '9811100011', 'status' => 'answered', 'started_at' => now()->subMinute(),
            'answered_at' => now()->subSeconds(40), 'channel_id' => '1726560000.1234',
        ]);

        $this->actingAs($this->agent)->postJson(route('calls.hangup', $call))->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/call/hangup') && $r['call_id'] === '1726560000.1234');

        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame('agent', $call->hangup_by);
        $this->assertNotNull($call->ended_at);
        $this->assertSame('wrapup', $this->agent->fresh()->call_status);
    }

    public function test_transfers_are_not_offered_for_tata_calls(): void
    {
        $other = User::factory()->create(['is_active' => true, 'tata_agent_number' => '9876500002']);

        $call = CallLog::create([
            'user_id' => $this->agent->id, 'provider' => 'tata', 'channel' => 'pstn', 'direction' => 'outbound',
            'to_number' => '9811100011', 'status' => 'answered', 'started_at' => now(), 'answered_at' => now(),
        ]);

        $this->actingAs($this->agent)
            ->postJson(route('calls.transfer', $call), ['user_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // -----------------------------------------------------------------
    // Webhook
    // -----------------------------------------------------------------

    public function test_the_webhook_rejects_a_missing_or_wrong_token(): void
    {
        $this->postJson('/api/webhooks/tata/hangup', ['call_id' => '1'])->assertStatus(403);
        $this->postJson('/api/webhooks/tata/hangup?token=nope', ['call_id' => '1'])->assertStatus(403);
        $this->postJson('/api/webhooks/tata/hangup', ['call_id' => '1'], ['X-Webhook-Token' => 'wrong'])->assertStatus(403);
    }

    public function test_the_webhook_accepts_the_token_in_the_url_or_a_header(): void
    {
        $this->postJson('/api/webhooks/tata/hangup?token=tata-hook-secret', [])->assertOk();
        $this->postJson('/api/webhooks/tata/hangup', [], ['X-Webhook-Token' => 'tata-hook-secret'])->assertOk();
    }

    public function test_a_hangup_event_closes_a_click_to_call_by_ref_id_with_durations_and_recording(): void
    {
        $lead = Lead::create(['name' => 'Hangup Lead', 'mobile' => '9811100022', 'status' => 'new']);

        $call = $this->originatedCall($lead, 'C2C2609170002');

        $response = $this->postJson('/api/webhooks/tata/hangup?token=tata-hook-secret', [
            'call_id'               => '1726560000.5555',
            'uuid'                  => 'uuid-5555',
            'ref_id'                => 'C2C2609170002',
            'direction'             => 'click_to_call',
            'call_status'           => 'Answered',
            'call_to_number'        => '9811100022',
            'caller_id_number'      => '02240001111',
            'answered_agent_number' => '+919876500001',
            'start_stamp'           => '2026-09-17 10:00:00',
            'answer_stamp'          => '2026-09-17 10:00:12',
            'end_stamp'             => '2026-09-17 10:02:12',
            'duration'              => 132,
            'billsec'               => 120,
            'hangup_cause'          => 'NORMAL_CLEARING',
            'recording_url'         => 'https://recordings.smartflo.example/abc.mp3',
            'custom_identifier'     => ['crm_call_id' => (string) $call->id],
        ]);

        $response->assertOk()->assertJsonPath('call_id', $call->id)->assertJsonPath('event', 'hangup');

        $call->refresh();

        $this->assertSame('completed', $call->status);
        $this->assertSame(120, $call->talk_seconds);
        $this->assertSame(132, $call->duration_seconds);
        $this->assertSame(12, $call->ring_seconds);
        $this->assertSame('1726560000.5555', $call->channel_id);
        $this->assertSame('uuid-5555', $call->linked_id);
        $this->assertSame('https://recordings.smartflo.example/abc.mp3', $call->recording_url);
        $this->assertTrue($call->is_recorded);
        $this->assertTrue($call->hasRecording());
        $this->assertNotNull($call->answered_at);
        $this->assertNotNull($call->ended_at);

        $this->assertSame('wrapup', $this->agent->fresh()->call_status);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'call']);
        $this->assertSame(1, CallEvent::where('call_log_id', $call->id)->where('event', 'hangup')->count());
    }

    public function test_a_missed_click_to_call_is_logged_as_no_answer_with_zero_talk_time(): void
    {
        $call = $this->originatedCall(null, 'C2C2609170003');

        $this->postJson('/api/webhooks/tata/hangup?token=tata-hook-secret', [
            'call_id'      => '1726560000.6666',
            'ref_id'       => 'C2C2609170003',
            'direction'    => 'click_to_call',
            'call_status'  => 'Missed',
            'end_stamp'    => '2026-09-17 10:05:00',
            'duration'     => 25,
            'billsec'      => 0,
            'hangup_cause' => 'NO_ANSWER',
        ])->assertOk()->assertJsonPath('call_id', $call->id);

        $call->refresh();

        $this->assertSame('no-answer', $call->status);
        $this->assertSame(0, $call->talk_seconds);
        $this->assertNull($call->answered_at);
    }

    public function test_a_busy_hangup_cause_maps_to_busy(): void
    {
        $call = $this->originatedCall(null, 'C2C2609170004');

        $this->postJson('/api/webhooks/tata/hangup?token=tata-hook-secret', [
            'ref_id' => 'C2C2609170004', 'call_status' => 'Missed', 'hangup_cause' => 'USER_BUSY', 'billsec' => 0,
        ])->assertOk();

        $this->assertSame('busy', $call->fresh()->status);
    }

    public function test_a_replayed_hangup_does_not_double_count(): void
    {
        $call = $this->originatedCall(null, 'C2C2609170005');

        $payload = ['ref_id' => 'C2C2609170005', 'call_status' => 'Answered', 'billsec' => 30, 'duration' => 40,
                    'answer_stamp' => '2026-09-17 11:00:05', 'end_stamp' => '2026-09-17 11:00:35'];

        $this->postJson('/api/webhooks/tata/hangup?token=tata-hook-secret', $payload)->assertOk();
        $this->postJson('/api/webhooks/tata/hangup?token=tata-hook-secret', $payload)
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(30, $call->fresh()->talk_seconds);
        $this->assertSame(1, CallLog::where('provider_call_id', 'C2C2609170005')->count());
    }

    public function test_the_event_is_inferred_when_the_url_has_no_event_segment(): void
    {
        $call = $this->originatedCall(null, 'C2C2609170006');

        $this->postJson('/api/webhooks/tata?token=tata-hook-secret', [
            'ref_id' => 'C2C2609170006', 'answer_stamp' => '2026-09-17 11:10:05',
        ])->assertOk()->assertJsonPath('event', 'answered');

        $this->assertSame('answered', $call->fresh()->status);
        $this->assertSame('on_call', $this->agent->fresh()->call_status);

        $this->postJson('/api/webhooks/tata?token=tata-hook-secret', [
            'ref_id' => 'C2C2609170006', 'call_status' => 'Answered', 'billsec' => 50, 'end_stamp' => '2026-09-17 11:10:55',
        ])->assertOk()->assertJsonPath('event', 'hangup');

        $this->assertSame('completed', $call->fresh()->status);
    }

    public function test_an_inbound_call_opens_a_log_attributed_to_the_agent_and_the_lead(): void
    {
        $lead = Lead::create(['name' => 'Inbound Smartflo', 'mobile' => '9822200077', 'status' => 'new']);

        $response = $this->postJson('/api/webhooks/tata/inbound?token=tata-hook-secret', [
            'call_id'               => '1726560000.7777',
            'uuid'                  => 'uuid-7777',
            'direction'             => 'inbound',
            'caller_id_number'      => '+919822200077',
            'call_to_number'        => '02240001111',
            'answered_agent_number' => '+919876500001',
            'start_stamp'           => '2026-09-17 12:00:00',
        ]);

        $response->assertOk()->assertJsonPath('event', 'inbound');

        $call = CallLog::find($response->json('call_id'));

        $this->assertSame('tata', $call->provider);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame('9822200077', $call->from_number);
        $this->assertSame('02240001111', $call->to_number);
        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame($this->agent->id, $call->user_id, 'Matched on the Tata agent number.');
        $this->assertSame('ringing', $call->status);
        $this->assertSame('1726560000.7777', $call->channel_id);

        // The hangup for the same call_id closes that row rather than opening another.
        $this->postJson('/api/webhooks/tata/hangup?token=tata-hook-secret', [
            'call_id' => '1726560000.7777', 'direction' => 'inbound', 'call_status' => 'Answered',
            'caller_id_number' => '+919822200077', 'billsec' => 90, 'duration' => 100,
            'answer_stamp' => '2026-09-17 12:00:10', 'end_stamp' => '2026-09-17 12:01:40',
        ])->assertOk()->assertJsonPath('call_id', $call->id);

        $this->assertSame(1, CallLog::where('channel_id', '1726560000.7777')->count());
        $this->assertSame('completed', $call->fresh()->status);
        $this->assertSame(90, $call->fresh()->talk_seconds);
    }

    public function test_variables_may_arrive_with_their_dollar_prefix_and_custom_identifier_as_json_text(): void
    {
        $call = $this->originatedCall(null, null);

        $this->postJson('/api/webhooks/tata/hangup?token=tata-hook-secret', [
            '$call_id'           => '1726560000.8888',
            '$call_status'       => 'Answered',
            '$billsec'           => 15,
            '$custom_identifier' => json_encode(['crm_call_id' => (string) $call->id]),
        ])->assertOk()->assertJsonPath('call_id', $call->id);

        $this->assertSame('completed', $call->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function test_admin_can_save_tata_settings_and_secrets_are_masked_and_encrypted(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('settings.telephony.update'), [
                'provider'           => 'tata',
                'tata_login_id'      => 'clinic@example.com',
                'tata_password'      => 'Sup3rSecret',
                'tata_caller_id'     => '02240002222',
                'tata_webhook_token' => 'hook-token-1',
                'tata_call_timeout'  => 900,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('tata', $this->settings->get('telephony', 'provider'));
        $this->assertSame('Sup3rSecret', $this->settings->get('telephony', 'tata_password'));
        $this->assertSame(900, $this->settings->get('telephony', 'tata_call_timeout'));

        $raw = \DB::table('settings')->where('group', 'telephony')->where('key', 'tata_password')->value('value');
        $this->assertNotSame('Sup3rSecret', $raw, 'The Smartflo password was stored in plain text.');

        $masked = $this->settings->masked('telephony');
        $this->assertStringStartsWith('••••', $masked['tata_password']);
    }

    public function test_the_caller_id_must_be_a_did_number(): void
    {
        $this->actingAs($this->admin)
            ->putJson(route('settings.telephony.update'), ['tata_caller_id' => 'front-desk'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tata_caller_id');
    }

    public function test_the_test_button_reports_smartflo_connectivity(): void
    {
        Http::fake([
            self::API . '/v1/users*' => Http::response([
                'has_more' => false, 'count' => 2,
                'results'  => [
                    ['id' => 1, 'name' => 'Agent One', 'agent' => ['id' => '0501', 'name' => 'Agent One', 'status' => 1, 'follow_me_number' => '+919876500001'], 'extension' => '0601', 'user_status' => 1],
                    ['id' => 2, 'name' => 'Admin Only', 'agent' => null, 'extension' => null, 'user_status' => 1],
                ],
            ]),
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('settings.telephony.test'), ['provider' => 'tata'])
            ->assertOk();

        $this->assertTrue($response->json('results.smartflo.ok'));
        $this->assertStringContainsString('2 Smartflo user(s), 1 with an agent number', $response->json('results.smartflo.message'));
        $this->assertTrue($response->json('results.webhook.ok'));
        $this->assertTrue($response->json('results.agents.ok'));
    }

    public function test_the_agent_list_marks_smartflo_users_already_mapped_to_crm_users(): void
    {
        Http::fake([
            self::API . '/v1/users*' => Http::response([
                'has_more' => false,
                'results'  => [
                    ['id' => 1, 'name' => 'Agent One', 'agent' => ['id' => '0501', 'follow_me_number' => '+919876500001'], 'extension' => '0601'],
                    ['id' => 2, 'name' => 'Agent Two', 'agent' => ['id' => '0502', 'follow_me_number' => '+919999900002'], 'extension' => '0602'],
                ],
            ]),
        ]);

        $agents = $this->actingAs($this->admin)
            ->getJson(route('settings.telephony.tata_agents'))
            ->assertOk()
            ->json('agents');

        $this->assertSame('Smartflo Agent', $agents[0]['crm_user']);
        $this->assertNull($agents[1]['crm_user']);
    }

    public function test_a_tata_agent_number_can_be_set_on_a_user_and_must_be_unique(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('settings.users.store'), [
                'name' => 'Mapped Caller', 'email' => 'mapped.' . uniqid() . '@example.com', 'password' => 'Password1!',
                'role' => 'Telecaller', 'tata_agent_number' => '9876500001',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tata_agent_number');

        $email = 'mapped.' . uniqid() . '@example.com';

        $this->actingAs($this->admin)
            ->postJson(route('settings.users.store'), [
                'name' => 'Mapped Caller', 'email' => $email, 'password' => 'Password1!',
                'role' => 'Telecaller', 'tata_agent_number' => '0509876543',
            ])
            ->assertOk();

        $this->assertSame('0509876543', User::where('email', $email)->value('tata_agent_number'));
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function originatedCall(?Lead $lead, ?string $refId): CallLog
    {
        $call = CallLog::create([
            'lead_id'          => $lead?->id,
            'user_id'          => $this->agent->id,
            'center_id'        => $this->agent->center_id,
            'provider'         => 'tata',
            'channel'          => 'pstn',
            'direction'        => 'outbound',
            'from_number'      => '02240001111',
            'to_number'        => $lead?->mobile ?? '9811100000',
            'extension'        => '9876500001',
            'provider_call_id' => $refId,
            'status'           => 'ringing',
            'started_at'       => now()->subSeconds(30),
            'is_recorded'      => true,
        ]);

        $call->update(['meta' => ['custom_identifier' => ['crm_call_id' => (string) $call->id], 'ref_id' => $refId]]);

        app(\App\Services\Telephony\CallService::class)->setAgentStatus($this->agent, 'ringing', $call);

        return $call;
    }
}
