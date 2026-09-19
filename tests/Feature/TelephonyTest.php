<?php

namespace Tests\Feature;

use App\Models\CallEvent;
use App\Models\CallLog;
use App\Models\Conversation;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\SipExtension;
use App\Models\User;
use App\Models\WhatsappCallPermission;
use App\Services\Telephony\CallService;
use App\Services\Telephony\WhatsAppCallHandler;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Asterisk / MicroSIP click-to-call, the dialplan webhook, and WhatsApp calling.
 *
 * No PBX or Meta credentials exist in CI, so the Graph/AMI calls themselves are
 * only exercised through their failure paths. Everything the CRM owns — call
 * records, correlation, timings, permissions, presence, authorisation and
 * provisioning — is covered end to end.
 */
class TelephonyTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $agent;
    private SipExtension $extension;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // Mirror the developer's local environment.
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

        $this->agent = User::factory()->create([
            'name'      => 'Telephony Agent',
            'is_active' => true,
            'center_id' => $this->admin->center_id,
        ]);
        $this->agent->assignRole('Telecaller');

        $this->extension = SipExtension::create([
            'user_id'    => $this->agent->id,
            'extension'  => '7' . random_int(1000, 9999),
            'domain'     => 'pbx.test',
            'technology' => 'PJSIP',
            'context'    => 'from-internal',
        ]);

        app(SettingsRepository::class)->set('telephony', 'webhook_token', 'test-asterisk-token');
    }

    // -----------------------------------------------------------------
    // Provisioning
    // -----------------------------------------------------------------

    public function test_a_new_extension_gets_a_generated_secret(): void
    {
        $this->assertNotEmpty($this->extension->password);
        $this->assertGreaterThanOrEqual(20, strlen($this->extension->password));
        $this->assertSame($this->extension->extension, $this->extension->username);
    }

    public function test_the_sip_secret_is_encrypted_at_rest(): void
    {
        $raw = \DB::table('sip_extensions')->where('id', $this->extension->id)->value('password');

        $this->assertNotSame($this->extension->password, $raw, 'SIP secret was stored in plain text.');
        $this->assertSame($this->extension->password, $this->extension->fresh()->password);
    }

    public function test_microsip_profile_downloads_and_contains_working_credentials(): void
    {
        $response = $this->actingAs($this->agent)->get(route('telephony.my_microsip'));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="microsip-' . $this->extension->extension . '.ini"');

        $ini = $response->getContent();

        $this->assertStringContainsString('[Account1]', $ini);
        $this->assertStringContainsString('username=' . $this->extension->extension, $ini);
        $this->assertStringContainsString('password=' . $this->extension->password, $ini);
        $this->assertStringContainsString('domain=pbx.test', $ini);
        $this->assertStringContainsString("\r\n", $ini, 'MicroSIP requires CRLF line endings.');
    }

    public function test_an_agent_cannot_download_another_agents_softphone_profile(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Telecaller');

        $this->actingAs($other)
            ->get(route('settings.telephony.extensions.microsip', $this->extension))
            ->assertForbidden();
    }

    public function test_admin_can_generate_a_pjsip_config_block(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson(route('settings.telephony.extensions.pbx_config', $this->extension))
            ->assertOk();

        $config = $response->json('config');

        $this->assertStringContainsString('type=endpoint', $config);
        $this->assertStringContainsString('type=auth', $config);
        $this->assertStringContainsString('type=aor', $config);
        $this->assertStringContainsString('password=' . $this->extension->password, $config);
    }

    public function test_extensions_must_be_unique_and_numeric(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('settings.telephony.extensions.store'), ['extension' => $this->extension->extension])
            ->assertStatus(422)
            ->assertJsonValidationErrors('extension');

        $this->actingAs($this->admin)
            ->postJson(route('settings.telephony.extensions.store'), ['extension' => 'abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('extension');
    }

    public function test_one_agent_cannot_hold_two_extensions(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('settings.telephony.extensions.store'), [
                'extension' => '8001',
                'user_id'   => $this->agent->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    // -----------------------------------------------------------------
    // Click-to-call
    // -----------------------------------------------------------------

    public function test_dialling_without_an_extension_is_refused_with_a_clear_message(): void
    {
        $noExtension = User::factory()->create(['is_active' => true]);
        $noExtension->assignRole('Telecaller');

        $this->actingAs($noExtension)
            ->postJson(route('calls.dial'), ['number' => '9812345678'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment([
                'message' => 'No SIP extension is assigned to your account. Ask an administrator to create one under Settings → Telephony.',
            ]);
    }

    /**
     * With no PBX reachable the Originate must fail cleanly *and* still leave a
     * failed call in the agent's history — never a silent disappearance.
     */
    public function test_a_failed_originate_is_still_recorded_against_the_lead(): void
    {
        app(SettingsRepository::class)->setMany([
            'ami_host'     => '127.0.0.1',
            'ami_port'     => 1,           // nothing listens here
            'ami_username' => 'crm',
            'ami_secret'   => 'secret',
        ], 'telephony');

        $lead = Lead::create(['name' => 'Dial Target', 'mobile' => '9811100011', 'status' => 'new']);

        $this->actingAs($this->agent)
            ->postJson(route('calls.dial'), ['lead_id' => $lead->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $call = CallLog::where('lead_id', $lead->id)->latest('id')->first();

        $this->assertNotNull($call, 'A failed originate must still leave a call record.');
        $this->assertSame('failed', $call->status);
        $this->assertSame('originate_failed', $call->hangup_cause);
        $this->assertSame($this->agent->id, $call->user_id);
        $this->assertNotEmpty($call->remark);
    }

    public function test_dialling_requires_a_number_or_a_contact(): void
    {
        $this->actingAs($this->agent)
            ->postJson(route('calls.dial'), [])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'No phone number available for this contact.']);
    }

    // -----------------------------------------------------------------
    // Number handling + contact resolution
    // -----------------------------------------------------------------

    #[DataProvider('phoneNumbers')]
    public function test_numbers_normalise_to_a_single_form(string $input, string $expected): void
    {
        $this->assertSame($expected, app(CallService::class)->normalize($input));
    }

    public static function phoneNumbers(): array
    {
        return [
            'plain 10 digit'   => ['9812345678', '9812345678'],
            'country code'     => ['919812345678', '9812345678'],
            'plus and spaces'  => ['+91 98123 45678', '9812345678'],
            'dashes'           => ['098-1234-5678', '09812345678'],
            'international 00' => ['00919812345678', '9812345678'],
        ];
    }

    public function test_an_inbound_number_resolves_to_the_matching_lead(): void
    {
        $lead = Lead::create(['name' => 'Inbound Caller', 'mobile' => '9822200033', 'status' => 'new']);

        $resolved = app(CallService::class)->resolveContact('+91 98222-00033');

        $this->assertNotNull($resolved['lead']);
        $this->assertSame($lead->id, $resolved['lead']->id);
    }

    // -----------------------------------------------------------------
    // Asterisk dialplan webhook
    // -----------------------------------------------------------------

    public function test_the_webhook_rejects_a_missing_or_wrong_token(): void
    {
        $this->postJson('/api/webhooks/asterisk/inbound', ['from_number' => '9812345678'])
            ->assertForbidden();

        $this->postJson('/api/webhooks/asterisk/inbound', [
            'token'       => 'wrong-token',
            'from_number' => '9812345678',
        ])->assertForbidden();

        $this->assertDatabaseMissing('call_logs', ['from_number' => '9812345678', 'direction' => 'inbound']);
    }

    public function test_an_inbound_call_creates_a_log_and_routes_to_the_lead_owner(): void
    {
        $lead = Lead::create([
            'name'        => 'Ringing Lead',
            'mobile'      => '9833300044',
            'status'      => 'new',
            'assigned_to' => $this->agent->id,
        ]);

        $response = $this->postJson('/api/webhooks/asterisk/inbound', [
            'token'       => 'test-asterisk-token',
            'channel_id'  => 'asterisk-uid-001',
            'linked_id'   => 'asterisk-link-001',
            'from_number' => '919833300044',
            'to_number'   => '02240001234',
        ])->assertOk();

        // The dialplan uses this to decide which extension to ring.
        $response->assertJsonPath('agent_extension', $this->extension->extension);
        $response->assertJsonPath('lead_id', $lead->id);

        $call = CallLog::where('channel_id', 'asterisk-uid-001')->first();

        $this->assertNotNull($call);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame('ringing', $call->status);
        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame($this->agent->id, $call->user_id);
        $this->assertSame('ringing', $this->agent->fresh()->call_status);
    }

    public function test_a_replayed_inbound_event_does_not_duplicate_the_call(): void
    {
        $payload = [
            'token'       => 'test-asterisk-token',
            'channel_id'  => 'asterisk-uid-dup',
            'from_number' => '9844400055',
        ];

        $this->postJson('/api/webhooks/asterisk/inbound', $payload)->assertOk();
        $this->postJson('/api/webhooks/asterisk/inbound', $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, CallLog::where('channel_id', 'asterisk-uid-dup')->count());
    }

    public function test_the_full_call_lifecycle_computes_ring_and_talk_time(): void
    {
        $lead = Lead::create(['name' => 'Lifecycle Lead', 'mobile' => '9855500066', 'status' => 'new']);

        $call = CallLog::create([
            'lead_id'    => $lead->id,
            'user_id'    => $this->agent->id,
            'channel_id' => 'asterisk-uid-life',
            'direction'  => 'outbound',
            'status'     => 'ringing',
            'started_at' => now()->subSeconds(75),
            'to_number'  => '9855500066',
        ]);

        // Answered 15s after dialling.
        $this->postJson('/api/webhooks/asterisk/answered', [
            'token'      => 'test-asterisk-token',
            'channel_id' => 'asterisk-uid-life',
            'timestamp'  => now()->subSeconds(60)->toIso8601String(),
        ])->assertOk();

        $call->refresh();
        $this->assertSame('answered', $call->status);
        $this->assertSame(15, $call->ring_seconds);
        $this->assertSame('on_call', $this->agent->fresh()->call_status);

        // Hung up 60s later; Asterisk reports its own billsec.
        $this->postJson('/api/webhooks/asterisk/hangup', [
            'token'        => 'test-asterisk-token',
            'channel_id'   => 'asterisk-uid-life',
            'billsec'      => 60,
            'duration'     => 75,
            'hangup_cause' => 16,
        ])->assertOk();

        $call->refresh();

        $this->assertSame('completed', $call->status);
        $this->assertSame(60, $call->talk_seconds);
        $this->assertSame(75, $call->duration_seconds);
        $this->assertSame('01:00', $call->duration_formatted);
        $this->assertNotNull($call->ended_at);
        $this->assertTrue($call->isConnected());

        // The agent drops into wrap-up, not straight back to idle.
        $this->assertSame('wrapup', $this->agent->fresh()->call_status);

        // And the call is mirrored into the lead timeline.
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type'    => 'call',
        ]);
    }

    public function test_an_unanswered_call_is_marked_from_its_hangup_cause(): void
    {
        CallLog::create([
            'channel_id' => 'asterisk-uid-busy',
            'user_id'    => $this->agent->id,
            'direction'  => 'outbound',
            'status'     => 'ringing',
            'started_at' => now()->subSeconds(20),
        ]);

        $this->postJson('/api/webhooks/asterisk/hangup', [
            'token'        => 'test-asterisk-token',
            'channel_id'   => 'asterisk-uid-busy',
            'hangup_cause' => 17,          // user busy
        ])->assertOk();

        $call = CallLog::where('channel_id', 'asterisk-uid-busy')->first();

        $this->assertSame('busy', $call->status);
        $this->assertSame(0, $call->talk_seconds);
        $this->assertFalse($call->isConnected());
        $this->assertSame('User busy', $call->hangupCauseLabel());
    }

    public function test_events_are_recorded_for_the_call_timeline(): void
    {
        CallLog::create([
            'channel_id' => 'asterisk-uid-events',
            'user_id'    => $this->agent->id,
            'direction'  => 'outbound',
            'status'     => 'ringing',
            'started_at' => now(),
        ]);

        foreach (['ringing', 'answered', 'hangup'] as $event) {
            $this->postJson("/api/webhooks/asterisk/{$event}", [
                'token'      => 'test-asterisk-token',
                'channel_id' => 'asterisk-uid-events',
            ])->assertOk();
        }

        $call = CallLog::where('channel_id', 'asterisk-uid-events')->first();

        $this->assertSame(3, CallEvent::where('call_log_id', $call->id)->count());
    }

    public function test_registration_events_update_the_extension_state(): void
    {
        $this->postJson('/api/webhooks/asterisk/registration', [
            'token'     => 'test-asterisk-token',
            'extension' => $this->extension->extension,
            'status'    => 'Reachable',
        ])->assertOk();

        $this->extension->refresh();

        $this->assertSame('Reachable', $this->extension->registration_status);
        $this->assertNotNull($this->extension->last_registered_at);
    }

    public function test_an_unmatched_event_is_acknowledged_without_error(): void
    {
        $this->postJson('/api/webhooks/asterisk/hangup', [
            'token'      => 'test-asterisk-token',
            'channel_id' => 'never-seen-this-channel',
        ])->assertOk()->assertJsonPath('matched', false);
    }

    // -----------------------------------------------------------------
    // Disposition + presence
    // -----------------------------------------------------------------

    public function test_a_disposition_updates_the_call_the_lead_and_agent_presence(): void
    {
        $disposition = Disposition::where('is_active', true)->whereNull('parent_id')->first();
        $this->assertNotNull($disposition, 'Seed dispositions first.');

        $lead = Lead::create(['name' => 'Disposition Lead', 'mobile' => '9866600077', 'status' => 'new']);

        $call = CallLog::create([
            'lead_id'      => $lead->id,
            'user_id'      => $this->agent->id,
            'direction'    => 'outbound',
            'status'       => 'completed',
            'talk_seconds' => 45,
            'started_at'   => now()->subMinute(),
            'ended_at'     => now()->subSeconds(5),
        ]);

        $this->actingAs($this->agent)
            ->postJson(route('calls.disposition', $call), [
                'disposition_id' => $disposition->id,
                'remark'         => 'Interested, wants a Saturday appointment.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $call->refresh();

        $this->assertSame($disposition->id, $call->disposition_id);
        $this->assertSame('Interested, wants a Saturday appointment.', $call->remark);

        $this->assertSame($disposition->id, $lead->fresh()->disposition_id);
        $this->assertSame('idle', $this->agent->fresh()->call_status);
    }

    public function test_an_agent_cannot_disposition_another_agents_call(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Telecaller');

        $call = CallLog::create([
            'user_id'   => $this->agent->id,
            'direction' => 'outbound',
            'status'    => 'completed',
        ]);

        $disposition = Disposition::whereNull('parent_id')->first();

        $this->actingAs($other)
            ->postJson(route('calls.disposition', $call), ['disposition_id' => $disposition->id])
            ->assertForbidden();
    }

    public function test_an_agent_can_set_their_presence(): void
    {
        $this->actingAs($this->agent)
            ->postJson(route('calls.status'), ['status' => 'break'])
            ->assertOk();

        $this->assertSame('break', $this->agent->fresh()->call_status);

        $this->actingAs($this->agent)
            ->postJson(route('calls.status'), ['status' => 'invalid'])
            ->assertStatus(422);
    }

    public function test_a_manual_call_can_be_logged_without_the_pbx(): void
    {
        $lead = Lead::create(['name' => 'Manual Lead', 'mobile' => '9877700088', 'status' => 'new']);

        $this->actingAs($this->agent)
            ->postJson(route('calls.log'), [
                'lead_id'      => $lead->id,
                'direction'    => 'outbound',
                'status'       => 'completed',
                'talk_seconds' => 120,
                'remark'       => 'Called from my mobile.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('call_logs', [
            'lead_id'      => $lead->id,
            'provider'     => 'manual',
            'talk_seconds' => 120,
        ]);
    }

    // -----------------------------------------------------------------
    // Supervisor
    // -----------------------------------------------------------------

    public function test_agents_cannot_see_the_supervisor_wallboard(): void
    {
        $this->actingAs($this->agent)->getJson(route('calls.board'))->assertForbidden();
        $this->actingAs($this->admin)->getJson(route('calls.board'))->assertOk();
    }

    public function test_the_wallboard_lists_agents_with_extensions(): void
    {
        $response = $this->actingAs($this->admin)->getJson(route('calls.board'))->assertOk();

        $names = collect($response->json('agents'))->pluck('name');

        $this->assertTrue($names->contains('Telephony Agent'));
        $response->assertJsonStructure(['agents', 'calls', 'stats' => ['live', 'today', 'connected']]);
    }

    public function test_an_agent_only_sees_their_own_calls(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Telecaller');

        CallLog::create(['user_id' => $this->agent->id, 'direction' => 'outbound', 'status' => 'completed', 'to_number' => '9811111111']);
        CallLog::create(['user_id' => $other->id, 'direction' => 'outbound', 'status' => 'completed', 'to_number' => '9822222222']);

        $numbers = collect(
            $this->actingAs($this->agent)->getJson(route('calls.recent'))->json('data')
        )->pluck('number');

        $this->assertTrue($numbers->contains('9811111111'));
        $this->assertFalse($numbers->contains('9822222222'), 'An agent must not see another agent\'s calls.');
    }

    // -----------------------------------------------------------------
    // Recordings
    // -----------------------------------------------------------------

    public function test_a_missing_recording_returns_404_rather_than_leaking_a_path(): void
    {
        $call = CallLog::create([
            'user_id'        => $this->agent->id,
            'direction'      => 'outbound',
            'status'         => 'completed',
            'recording_path' => 'call-recordings/does-not-exist.wav',
            'recording_disk' => 'local',
        ]);

        $this->actingAs($this->agent)->get(route('calls.recording', $call))->assertNotFound();
    }

    public function test_another_agent_cannot_fetch_a_recording(): void
    {
        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole('Telecaller');

        $call = CallLog::create([
            'user_id'        => $this->agent->id,
            'direction'      => 'outbound',
            'status'         => 'completed',
            'recording_path' => 'call-recordings/private.wav',
        ]);

        $this->actingAs($other)->get(route('calls.recording', $call))->assertForbidden();
    }

    // -----------------------------------------------------------------
    // WhatsApp calling
    // -----------------------------------------------------------------

    public function test_an_inbound_whatsapp_call_is_logged_against_the_lead(): void
    {
        $lead = Lead::create([
            'name'        => 'WhatsApp Caller',
            'mobile'      => '9888800099',
            'status'      => 'new',
            'assigned_to' => $this->agent->id,
        ]);

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '919888800099',
            'lead_id'            => $lead->id,
            'status'             => 'open',
        ]);

        $call = app(WhatsAppCallHandler::class)->handleIncomingWhatsAppCall([
            'id'        => 'wacid.TEST001',
            'from'      => '919888800099',
            'to'        => '918888888888',
            'event'     => 'connect',
            'direction' => 'USER_INITIATED',
            'timestamp' => now()->timestamp,
        ], $conversation);

        $this->assertNotNull($call);
        $this->assertSame('whatsapp', $call->channel);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame($lead->id, $call->lead_id);
        $this->assertSame($this->agent->id, $call->user_id);
        $this->assertSame('ringing', $call->status);
    }

    public function test_a_replayed_whatsapp_connect_does_not_duplicate_the_call(): void
    {
        $payload = [
            'id'        => 'wacid.TEST002',
            'from'      => '919888800100',
            'event'     => 'connect',
            'direction' => 'USER_INITIATED',
        ];

        $handler = app(WhatsAppCallHandler::class);
        $handler->handleIncomingWhatsAppCall($payload, null);
        $handler->handleIncomingWhatsAppCall($payload, null);

        $this->assertSame(1, CallLog::where('wa_call_id', 'wacid.TEST002')->count());
    }

    public function test_a_whatsapp_terminate_closes_the_call_with_metas_duration(): void
    {
        $handler = app(WhatsAppCallHandler::class);

        $handler->handleIncomingWhatsAppCall([
            'id'        => 'wacid.TEST003',
            'from'      => '919888800111',
            'event'     => 'connect',
            'direction' => 'USER_INITIATED',
            'timestamp' => now()->subSeconds(90)->timestamp,
        ], null);

        $handler->handleWhatsAppTerminate([
            'id'        => 'wacid.TEST003',
            'event'     => 'terminate',
            'status'    => 'completed',
            'duration'  => 85,
            'timestamp' => now()->timestamp,
        ]);

        $call = CallLog::where('wa_call_id', 'wacid.TEST003')->first();

        $this->assertSame('completed', $call->status);
        $this->assertSame(85, $call->talk_seconds);
        $this->assertNotNull($call->ended_at);
    }

    public function test_call_permission_responses_are_mirrored_locally(): void
    {
        $handler = app(WhatsAppCallHandler::class);

        $handler->updateWhatsAppPermission([
            'user_wa_id' => '919888800122',
            'response'   => 'accept',
        ]);

        $permission = WhatsappCallPermission::where('wa_id', '919888800122')->first();

        $this->assertNotNull($permission);
        $this->assertSame('approved', $permission->status);
        $this->assertTrue($permission->isUsable());
        $this->assertNull($permission->blockReason());

        $handler->updateWhatsAppPermission([
            'user_wa_id' => '919888800122',
            'response'   => 'reject',
        ]);

        $permission->refresh();

        $this->assertSame('rejected', $permission->status);
        $this->assertFalse($permission->isUsable());
        $this->assertSame('The contact declined call permission.', $permission->blockReason());
    }

    public function test_an_expired_permission_explains_itself(): void
    {
        $permission = WhatsappCallPermission::create([
            'wa_id'      => '919888800133',
            'status'     => 'approved',
            'expires_at' => now()->subHour(),
        ]);

        $this->assertFalse($permission->isUsable());
        $this->assertSame('Call permission has expired — request it again.', $permission->blockReason());
    }

    public function test_a_whatsapp_call_without_permission_is_refused(): void
    {
        $this->actingAs($this->agent)
            ->postJson(route('calls.dial_whatsapp'), ['number' => '919888800144'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_the_meta_webhook_routes_call_events_through_the_handler(): void
    {
        app(SettingsRepository::class)->set('meta', 'app_secret', 'test-app-secret');

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id' => 'WABA1',
                'changes' => [[
                    'field' => 'calls',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PN1'],
                        'calls'    => [[
                            'id'        => 'wacid.WEBHOOK001',
                            'from'      => '919888800155',
                            'to'        => '918888888888',
                            'event'     => 'connect',
                            'direction' => 'USER_INITIATED',
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);

        $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_ACCEPT'              => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'test-app-secret'),
        ], $body)->assertOk();

        $this->assertDatabaseHas('call_logs', [
            'wa_call_id' => 'wacid.WEBHOOK001',
            'channel'    => 'whatsapp',
        ]);
    }

    // -----------------------------------------------------------------
    // Screens
    // -----------------------------------------------------------------

    public function test_the_call_console_renders_for_an_agent_and_a_supervisor(): void
    {
        $this->actingAs($this->agent)->get(route('calls.console'))->assertOk();
        $this->actingAs($this->admin)->get(route('calls.console'))->assertOk();
    }

    public function test_telephony_settings_are_admin_only(): void
    {
        $this->actingAs($this->admin)->get(route('settings.telephony.index'))->assertOk();
        $this->actingAs($this->agent)->get(route('settings.telephony.index'))->assertForbidden();
    }

    public function test_the_call_log_list_and_detail_render(): void
    {
        $call = CallLog::create([
            'user_id'    => $this->agent->id,
            'direction'  => 'outbound',
            'status'     => 'completed',
            'to_number'  => '9899900011',
            'started_at' => now(),
        ]);

        $this->actingAs($this->agent)->get(route('call_logs.index'))->assertOk();
        $this->actingAs($this->agent)->get(route('call_logs.show', $call))->assertOk();

        // The DataTable branch — this is where the old `callogs._actions` typo lived.
        $this->actingAs($this->agent)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('call_logs.index', ['draw' => 1, 'start' => 0, 'length' => 25]))
            ->assertOk()
            ->assertJsonPath('error', null);
    }

    public function test_the_call_handlers_load_on_every_page(): void
    {
        // The visible topbar dialer popup was removed on request; the call-outcome
        // modal and click-to-call handlers must still load so calling keeps working.
        $this->actingAs($this->agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dispositionModal', false)
            ->assertDontSee('id="dialerWidget"', false);
    }
}
