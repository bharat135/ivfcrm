<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\CallTranscript;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\TataAgent;
use App\Models\TataBroadcast;
use App\Models\TataDisposition;
use App\Models\TataNumber;
use App\Models\User;
use App\Services\Telephony\Tata\CallSummaryService;
use App\Services\Telephony\Tata\CallTranscriptService;
use App\Services\Telephony\Tata\TataSyncService;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * Tata Teleservices section: two-way sync (agents, dispositions, numbers),
 * mandatory disposition, per-centre caller id, live transcript + one-line note,
 * outcome push-back, broadcasts, and the section's access control.
 */
class TataTeleservicesTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectDatabase { refreshApplication as projectRefreshApplication; }

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

        $this->agent = User::factory()->create(['name' => 'Tata Agent', 'is_active' => true, 'center_id' => $this->admin->center_id, 'phone' => '+91-9876500001', 'tata_agent_number' => '9876500001']);
        $this->agent->assignRole('Telecaller');

        $this->settings->setMany([
            'provider' => 'tata', 'tata_access_token' => 'portal-token', 'tata_caller_id' => '02240000000',
            'tata_webhook_token' => 'hook-secret', 'force_disposition' => true,
        ], 'telephony');
        Cache::forget('telephony.tata.access_token');
    }

    protected function tearDown(): void
    {
        foreach (['provider', 'tata_access_token', 'tata_caller_id', 'tata_webhook_token', 'force_disposition', 'tata_disposition_list_id', 'tata_login_id', 'tata_password', 'tata_token_expires_at', 'tata_password_days_left', 'softphone_enabled', 'sip_wss_server', 'sip_realm', 'tata_dial_via'] as $k) {
            $this->settings->forget('telephony', $k);
        }
        $this->settings->set('telephony', 'provider', 'asterisk');
        parent::tearDown();
    }

    // ---------------------------------------------------------------- Access
    public function test_the_section_requires_the_tata_permission(): void
    {
        $this->actingAs($this->agent)->get(route('tata.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('tata.index'))->assertOk();
    }

    public function test_any_agent_can_open_their_own_softphone(): void
    {
        $this->actingAs($this->agent)->get(route('tata.softphone.index'))->assertOk();
    }

    // ---------------------------------------------------------------- Agents
    public function test_pulling_agents_mirrors_and_auto_links_by_email(): void
    {
        Http::fake([self::API . '/v1/users*' => Http::response(['has_more' => false, 'results' => [
            ['id' => 501, 'name' => $this->agent->name, 'login_id' => $this->agent->email, 'email' => $this->agent->email,
             'agent' => ['id' => '0501', 'follow_me_number' => '+919876500001', 'status' => 1], 'extension' => '0601', 'user_status' => 1],
            ['id' => 502, 'name' => 'Ghost', 'login_id' => 'ghost@x.com', 'agent' => ['id' => '0502', 'follow_me_number' => '+915512399999'], 'user_status' => 1],
        ]])]);

        $result = app(TataSyncService::class)->pullAgents();

        $this->assertSame(2, $result['pulled']);
        $this->assertSame(1, $result['linked']);

        $agentRow = TataAgent::where('smartflo_user_id', '501')->first();
        $this->assertSame($this->agent->id, $agentRow->user_id);
        $this->assertSame('linked', $agentRow->sync_status);
        $this->assertSame('0601', $agentRow->extension);
    }

    public function test_pushing_an_agent_creates_a_smartflo_user_and_links_it(): void
    {
        $newUser = User::factory()->create(['is_active' => true, 'phone' => '+91-9812300045', 'tata_agent_number' => null]);
        $newUser->assignRole('Telecaller');

        Http::fake([self::API . '/v1/user' => Http::response(['success' => true, 'id' => 777, 'agent_id' => '0777', 'agent_extension' => '0677'])]);

        $agent = app(TataSyncService::class)->pushAgent($newUser);

        $this->assertSame('777', $agent->smartflo_user_id);
        $this->assertSame('0677', $agent->extension);
        $this->assertSame('linked', $agent->sync_status);
        $this->assertSame('9812300045', $newUser->fresh()->tata_agent_number);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/user')
            && $r['create_agent'] === true && $r['number'] === '9812300045' && $r['email'] === $newUser->email);
    }

    public function test_pushing_an_agent_without_a_mobile_is_refused(): void
    {
        $noPhone = User::factory()->create(['is_active' => true, 'phone' => null, 'tata_agent_number' => null]);
        $noPhone->assignRole('Telecaller');

        $this->expectException(\App\Services\Telephony\TataException::class);
        app(TataSyncService::class)->pushAgent($noPhone);
    }

    // ---------------------------------------------------------------- Dispositions
    public function test_dispositions_sync_creates_the_list_pulls_and_pushes(): void
    {
        $localOnly = Disposition::create(['name' => 'CRM Only Outcome', 'slug' => 'crm-only-outcome-' . uniqid(), 'is_active' => true, 'sort_order' => 900]);

        $created = [];
        Http::fake([
            self::API . '/v1/dialer/disposition_list' => function () {
                return Http::response(['results' => [['id' => 'L1', 'name' => TataSyncService::DISPOSITION_LIST_NAME]]]);
            },
            self::API . '/v1/dialer/disposition_status*' => function (Request $r) use (&$created) {
                if ($r->method() === 'POST') { $created[] = $r->data(); return Http::response(['success' => true]); }
                // GET: one remote status the CRM does not have, plus the ones we "created".
                $rows = [['id' => 'R1', 'name' => 'Interested (remote)', 'code' => 'interested-remote', 'dispositionList' => 'L1']];
                foreach ($created as $i => $c) {
                    $rows[] = ['id' => 'C' . $i, 'name' => $c['name'] ?? '', 'code' => $c['code'] ?? '', 'dispositionList' => 'L1', 'parentDisposition' => $c['parentDisposition'] ?? null];
                }
                return Http::response(['results' => $rows]);
            },
        ]);

        $result = app(TataSyncService::class)->syncDispositions();

        $this->assertSame('L1', $result['list_id']);
        $this->assertGreaterThanOrEqual(1, $result['created_in_crm'], 'The remote-only disposition should be created in the CRM.');
        $this->assertGreaterThanOrEqual(1, $result['pushed'], 'CRM dispositions should be pushed to Smartflo.');

        $this->assertDatabaseHas('dispositions', ['slug' => 'interested-remote']);
        $this->assertTrue(TataDisposition::where('disposition_id', $localOnly->id)->exists());
    }

    // ---------------------------------------------------------------- Numbers
    public function test_pulling_numbers_stores_dids_and_sets_a_default(): void
    {
        Http::fake([self::API . '/v1/my_number*' => Http::response(['results' => [
            ['id' => 'N1', 'did' => '02240000000', 'name' => 'Main line', 'destination' => 'ivr', 'destination_name' => 'Welcome'],
            ['id' => 'N2', 'did' => '02240000001', 'name' => 'Andheri'],
        ]])]);

        $count = app(TataSyncService::class)->pullNumbers();

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('tata_numbers', ['did' => '02240000000']);
        $this->assertSame(1, TataNumber::where('is_default', true)->count());
    }

    public function test_per_centre_did_is_used_as_the_caller_id(): void
    {
        TataNumber::create(['did' => '02240000009', 'center_id' => $this->agent->center_id]);
        TataNumber::create(['did' => '02240000000', 'is_default' => true]);

        $this->assertSame('02240000009', app(\App\Services\Telephony\CallService::class)->tataCallerIdFor($this->agent));
    }

    // ---------------------------------------------------------------- Mandatory disposition
    public function test_an_agent_with_an_undispositioned_call_cannot_dial_again(): void
    {
        CallLog::create([
            'user_id' => $this->agent->id, 'provider' => 'tata', 'channel' => 'pstn', 'direction' => 'outbound',
            'to_number' => '9811100011', 'status' => 'completed', 'started_at' => now()->subMinutes(5),
            'answered_at' => now()->subMinutes(4), 'ended_at' => now()->subMinutes(3), 'talk_seconds' => 60,
        ]);

        Http::fake();

        $this->actingAs($this->agent)
            ->postJson(route('calls.dial'), ['number' => '9811100022'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['pending_call_id']);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'click_to_call'));
    }

    public function test_after_dispositioning_the_agent_can_dial_again(): void
    {
        $disposition = Disposition::where('is_active', true)->first();

        $done = CallLog::create([
            'user_id' => $this->agent->id, 'provider' => 'tata', 'channel' => 'pstn', 'direction' => 'outbound',
            'to_number' => '9811100011', 'status' => 'completed', 'disposition_id' => $disposition->id,
            'started_at' => now()->subMinutes(5), 'ended_at' => now()->subMinutes(3), 'talk_seconds' => 60,
        ]);

        Http::fake([self::API . '/v1/click_to_call' => Http::response(['success' => true, 'ref_id' => 'C2C-OK'])]);

        $this->actingAs($this->agent)->postJson(route('calls.dial'), ['number' => '9811100022'])->assertOk()->assertJsonPath('success', true);
    }

    // ---------------------------------------------------------------- Transcript + note
    public function test_transcript_segments_are_stored_and_summarised_into_one_line(): void
    {
        $this->settings->forget('ai', 'anthropic_api_key');
        config(['services.anthropic.key' => null]);

        $lead = Lead::create(['name' => 'Transcript Lead', 'mobile' => '9811100055', 'status' => 'new']);
        $call = CallLog::create([
            'lead_id' => $lead->id, 'user_id' => $this->agent->id, 'provider' => 'tata', 'channel' => 'pstn',
            'direction' => 'outbound', 'to_number' => '9811100055', 'status' => 'completed',
            'started_at' => now()->subMinutes(3), 'answered_at' => now()->subMinutes(3), 'ended_at' => now(), 'talk_seconds' => 90,
        ]);

        $this->actingAs($this->agent)->postJson(route('calls.transcript', $call), [
            'language' => 'en-IN',
            'segments' => [
                ['id' => 'a0', 't' => 1, 'speaker' => 'agent', 'text' => 'Hello, this is the IVF clinic calling.', 'final' => true],
                ['id' => 'a1', 't' => 2, 'speaker' => 'agent', 'text' => 'The patient wants an appointment tomorrow morning for a scan.', 'final' => true],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('call_transcripts', ['call_log_id' => $call->id]);

        $response = $this->actingAs($this->agent)->postJson(route('calls.summarize', $call), [])->assertOk();

        $summary = $response->json('summary');
        $this->assertNotEmpty($summary);
        $this->assertStringNotContainsString("\n", $summary, 'The note must be a single line.');
        $this->assertLessThanOrEqual(CallSummaryService::MAX_LENGTH, strlen($summary));
        $this->assertSame('heuristic', $response->json('source'));

        $this->assertSame('final', CallTranscript::where('call_log_id', $call->id)->value('status'));
    }

    public function test_the_summary_service_enforces_a_single_bounded_line(): void
    {
        $svc = app(CallSummaryService::class);
        $out = $svc->oneLine("Line one\nLine two\t  with   spaces");
        $this->assertStringNotContainsString("\n", $out);
        $this->assertStringNotContainsString("\t", $out);
        $this->assertLessThanOrEqual(CallSummaryService::MAX_LENGTH, strlen($out));
    }

    public function test_the_disposition_note_is_saved_as_a_single_line(): void
    {
        $disposition = Disposition::where('is_active', true)->first();
        $call = CallLog::create([
            'user_id' => $this->agent->id, 'provider' => 'tata', 'channel' => 'pstn', 'direction' => 'outbound',
            'to_number' => '9811100011', 'status' => 'completed', 'started_at' => now()->subMinutes(2), 'ended_at' => now(),
            'channel_id' => '1700.1',
        ]);

        Http::fake([self::API . '/v1/call/note/*' => Http::response(['success' => true])]);

        $this->actingAs($this->agent)->postJson(route('calls.disposition', $call), [
            'disposition_id' => $disposition->id,
            'remark'         => "First line\nSecond line",
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertStringNotContainsString("\n", (string) $call->fresh()->remark);
    }

    // ---------------------------------------------------------------- Outcome push-back
    public function test_saving_an_outcome_pushes_a_call_note_to_smartflo(): void
    {
        $disposition = Disposition::where('is_active', true)->first();
        $call = CallLog::create([
            'user_id' => $this->agent->id, 'provider' => 'tata', 'channel' => 'pstn', 'direction' => 'outbound',
            'to_number' => '9811100011', 'status' => 'completed', 'started_at' => now()->subMinutes(2), 'ended_at' => now(),
            'channel_id' => '1726560000.9', 'talk_seconds' => 45,
        ]);

        Http::fake([self::API . '/v1/call/note/*' => Http::response(['success' => true])]);

        $this->actingAs($this->agent)->postJson(route('calls.disposition', $call), [
            'disposition_id' => $disposition->id, 'remark' => 'Booked a scan for tomorrow.',
        ])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/call/note/1726560000.9')
            && str_contains($r['message'], 'Booked a scan'));

        $this->assertNotNull($call->fresh()->tata_synced_at);
    }

    public function test_recording_url_is_proxied_through_the_crm_not_exposed_raw(): void
    {
        $call = CallLog::create([
            'user_id' => $this->agent->id, 'provider' => 'tata', 'channel' => 'pstn', 'direction' => 'outbound',
            'to_number' => '9811100011', 'status' => 'completed', 'started_at' => now(), 'ended_at' => now(),
            'recording_url' => 'https://recordings.smartflo.example/x.mp3', 'is_recorded' => true,
        ]);

        $this->assertStringContainsString('/calls/' . $call->id . '/recording', $call->recordingUrl());
        $this->assertStringNotContainsString('smartflo.example', $call->recordingUrl());
    }

    // ---------------------------------------------------------------- Broadcasts
    public function test_creating_a_broadcast_snapshots_the_segment_and_dedupes_and_calls_smartflo(): void
    {
        $ids = [];
        foreach (['9800000001', '9800000002', '9800000002', '9800000003'] as $m) {
            $ids[] = Lead::create(['name' => 'BC ' . $m, 'mobile' => $m, 'status' => 'new'])->id;
        }
        $filters = ['lead_ids' => $ids];

        $service = app(\App\Services\Telephony\Tata\TataBroadcastService::class);

        // Four rows, three unique mobiles.
        $this->assertSame(4, $service->previewCount($filters));

        Http::fake([
            self::API . '/v1/broadcast/list'    => fn () => Http::response(['success' => true, 'id' => 'LIST9']),
            self::API . '/v1/broadcast/leads/*' => fn () => Http::response(['success' => true]),
            self::API . '/v1/broadcast'         => fn () => Http::response(['success' => true, 'id' => 'BC77']),
        ]);

        $broadcast = $service->create([
            'name' => 'Reminder', 'destination' => 'ivr|7926', 'caller_id' => '02240000000',
            'start_at' => now()->addHour()->toDateTimeString(), 'end_at' => now()->addHours(5)->toDateTimeString(),
        ], $filters, $this->admin);

        // The snapshot collapses the duplicate mobile to three lead rows.
        $this->assertSame(3, $broadcast->lead_count);
        $this->assertSame(3, $broadcast->leads()->count());
        $this->assertSame('created', $broadcast->status);
        $this->assertSame('BC77', $broadcast->smartflo_id);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/broadcast'));
    }

    public function test_the_broadcast_form_requires_the_tata_create_permission(): void
    {
        $this->actingAs($this->agent)->get(route('tata.broadcasts.create'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('tata.broadcasts.create'))->assertOk();
    }

    // ---------------------------------------------------------------- Regressions (2026-09-17 batch)
    public function test_a_follow_up_outside_calling_hours_is_rejected(): void
    {
        $lead = \App\Models\Lead::create(['name' => 'Hours Lead', 'mobile' => '9800000123', 'status' => 'new']);
        $disp = \App\Models\Disposition::where('is_active', true)->first();

        // 21:30 today (or tomorrow if already past) is outside 09:00-20:00.
        $late = now()->addDay()->setTime(21, 30)->format('Y-m-d H:i');

        $this->actingAs($this->admin)->postJson(route('leads.disposition', $lead), [
            'disposition_id' => $disp->id, 'remark' => 'called late', 'next_follow_up_at' => $late,
        ])->assertStatus(422)->assertJsonValidationErrors('next_follow_up_at');

        $ok = now()->addDay()->setTime(10, 30)->format('Y-m-d H:i');
        $this->actingAs($this->admin)->postJson(route('leads.disposition', $lead), [
            'disposition_id' => $disp->id, 'remark' => 'called morning', 'next_follow_up_at' => $ok,
        ])->assertOk();
    }

    public function test_updating_the_access_token_does_not_wipe_the_webhook_token(): void
    {
        $this->settings->set('telephony', 'tata_webhook_token', 'keep-me-secret');

        $this->actingAs($this->admin)->putJson(route('tata.config.update'), [
            'tata_access_token' => 'a-new-token',
            // webhook token field submitted blank, as the form does
            'tata_webhook_token' => '',
        ])->assertOk();

        $this->assertSame('keep-me-secret', $this->settings->get('telephony', 'tata_webhook_token'));
        $this->assertSame('a-new-token', $this->settings->get('telephony', 'tata_access_token'));
    }

    public function test_dispositioning_the_lead_clears_the_pending_call_gate(): void
    {
        $lead = \App\Models\Lead::create(['name' => 'Gate Lead', 'mobile' => '9800000456', 'status' => 'new']);
        $disp = \App\Models\Disposition::where('is_active', true)->first();

        $call = \App\Models\CallLog::create([
            'lead_id' => $lead->id, 'user_id' => $this->admin->id, 'provider' => 'tata', 'channel' => 'pstn',
            'direction' => 'outbound', 'to_number' => '9800000456', 'status' => 'completed', 'channel_id' => '9911.1',
            'started_at' => now()->subMinutes(4), 'ended_at' => now()->subMinutes(2), 'talk_seconds' => 40,
        ]);

        Http::fake(['https://api-smartflo.tatateleservices.com/v1/call/note/*' => Http::response(['success' => true])]);

        $calls = app(\App\Services\Telephony\CallService::class);
        $this->assertNotNull($calls->pendingDisposition($this->admin->fresh()));

        $this->actingAs($this->admin)->postJson(route('leads.disposition', $lead), [
            'disposition_id' => $disp->id, 'remark' => 'booked',
        ])->assertOk();

        $this->assertSame($disp->id, $call->fresh()->disposition_id);
        $this->assertNull($calls->pendingDisposition($this->admin->fresh()), 'The gate should be cleared after dispositioning.');
    }

    // ---------------------------------------------------------------- Token
    public function test_generate_token_from_email_and_password_saves_the_token(): void
    {
        $this->settings->forget("telephony", "tata_access_token");
        Cache::forget("telephony.tata.access_token");

        Http::fake(["https://api-smartflo.tatateleservices.com/v1/auth/login" => Http::response([
            "success" => true, "access_token" => "GEN-TOKEN-123", "token_type" => "bearer",
            "expires_in" => 3600, "number_of_days_left" => 42,
        ])]);

        $res = $this->actingAs($this->admin)->postJson(route("tata.config.generate_token"), [
            "tata_login_id" => "clinic@example.com",
            "tata_password" => "Sup3rSecret",
        ])->assertOk()->assertJsonPath("success", true);

        $this->assertStringContainsString("42 more day", $res->json("message"));
        $this->assertSame("GEN-TOKEN-123", $this->settings->get("telephony", "tata_access_token"));
        $this->assertSame("clinic@example.com", $this->settings->get("telephony", "tata_login_id"));

        // Stored token is encrypted at rest.
        $raw = \DB::table("settings")->where("group", "telephony")->where("key", "tata_access_token")->value("value");
        $this->assertNotSame("GEN-TOKEN-123", $raw);

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_ends_with($r->url(), "/v1/auth/login")
            && $r["email"] === "clinic@example.com" && $r["password"] === "Sup3rSecret");
    }

    public function test_generate_token_reports_bad_credentials(): void
    {
        Http::fake(["https://api-smartflo.tatateleservices.com/v1/auth/login" => Http::response(["message" => "Invalid credentials."], 401)]);

        $this->actingAs($this->admin)->postJson(route("tata.config.generate_token"), [
            "tata_login_id" => "clinic@example.com", "tata_password" => "wrong",
        ])->assertStatus(422)->assertJsonPath("success", false)->assertJsonPath("message", "Invalid credentials.");
    }

    public function test_generate_token_needs_credentials(): void
    {
        $this->settings->forget("telephony", "tata_login_id");
        $this->settings->forget("telephony", "tata_password");

        $this->actingAs($this->admin)->postJson(route("tata.config.generate_token"), [])
            ->assertStatus(422)->assertJsonPath("success", false);
    }

    // ---------------------------------------------------------------- Smartflo user actions + softphone routing
    public function test_create_smartflo_user_calls_the_api_and_can_link_a_crm_user(): void
    {
        $newUser = User::factory()->create(['is_active' => true]);
        $newUser->assignRole('Telecaller');

        Http::fake(['https://api-smartflo.tatateleservices.com/v1/user' => Http::response([
            'success' => true, 'id' => 909, 'agent_id' => '0509', 'agent_extension' => '0609',
        ])]);

        $this->actingAs($this->admin)->postJson(route('tata.agents.create_user'), [
            'name' => 'Fresh Agent', 'login_id' => 'fresh@example.com', 'email' => 'fresh@example.com',
            'password' => 'Password1!', 'number' => '9876511111', 'route_call_through' => '2',
            'login_based_calling' => 1, 'assign_extension' => 1, 'link_user_id' => $newUser->id,
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseHas('tata_agents', ['smartflo_user_id' => '909', 'extension' => '0609', 'user_id' => $newUser->id]);
        $this->assertSame('9876511111', $newUser->fresh()->tata_agent_number, 'Linked user gets the mobile as dial id; the extension lives on the agent row for softphone mode.');

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/user')
            && $r['create_agent'] === true && $r['number'] === '9876511111' && $r['login_based_calling'] === true);
    }

    public function test_modify_smartflo_user_patches_the_api(): void
    {
        $agent = \App\Models\TataAgent::create([
            'smartflo_user_id' => '777', 'name' => 'Old Name', 'follow_me_number' => '9876500001',
            'is_login_based_calling' => false, 'is_web_login' => true, 'is_active' => true, 'sync_status' => 'unlinked',
        ]);

        Http::fake(['https://api-smartflo.tatateleservices.com/v1/user/777' => Http::response(['success' => true])]);

        $this->actingAs($this->admin)->postJson(route('tata.agents.modify', $agent), [
            'name' => 'New Name', 'number' => '9876599999', 'login_based_calling' => 1, 'block_agent' => 1,
        ])->assertOk()->assertJsonPath('success', true);

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->method() === 'PATCH'
            && str_ends_with($r->url(), '/v1/user/777') && $r['name'] === 'New Name'
            && $r['number'] === '9876599999' && $r['login_based_calling'] === true && $r['block_agent'] === true);

        $agent->refresh();
        $this->assertSame('New Name', $agent->name);
        $this->assertSame('9876599999', $agent->follow_me_number);
        $this->assertTrue($agent->is_login_based_calling);
        $this->assertFalse($agent->is_active, 'block_agent disables the agent.');
    }

    public function test_dial_via_softphone_rings_the_extension_not_the_mobile(): void
    {
        \App\Models\TataAgent::create([
            'user_id' => $this->agent->id, 'smartflo_user_id' => '500', 'extension' => '0605',
            'follow_me_number' => '9876500001', 'is_active' => true, 'sync_status' => 'linked',
        ]);

        $calls = app(\App\Services\Telephony\CallService::class);

        $this->settings->set('telephony', 'tata_dial_via', 'mobile');
        $this->assertSame('9876500001', $calls->tataAgentNumber($this->agent->fresh()));

        $this->settings->set('telephony', 'tata_dial_via', 'softphone');
        $this->assertSame('0605', $calls->tataAgentNumber($this->agent->fresh()), 'Softphone mode rings the extension.');

        $this->settings->forget('telephony', 'tata_dial_via');
    }

    // ---------------------------------------------------------------- Softphone enablement + recordings
    public function test_enable_softphone_turns_on_login_based_calling_for_agents(): void
    {
        \App\Models\TataAgent::create(['smartflo_user_id' => '601', 'name' => 'A1', 'is_login_based_calling' => false, 'is_web_login' => true, 'is_active' => true, 'sync_status' => 'unlinked']);
        \App\Models\TataAgent::create(['smartflo_user_id' => '602', 'name' => 'A2', 'is_login_based_calling' => false, 'is_web_login' => true, 'is_active' => true, 'sync_status' => 'unlinked']);

        Http::fake(['https://api-smartflo.tatateleservices.com/v1/user/*' => Http::response(['success' => true])]);

        $this->actingAs($this->admin)->postJson(route('tata.agents.enable_softphone'))
            ->assertOk()->assertJsonPath('success', true);

        $this->assertTrue(\App\Models\TataAgent::where('smartflo_user_id', '601')->value('is_login_based_calling'));
        $this->assertTrue(\App\Models\TataAgent::where('smartflo_user_id', '602')->value('is_login_based_calling'));

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['login_based_calling'] === true);
    }

    public function test_profile_recordings_reads_live_from_the_smartflo_cdr(): void
    {
        $lead = \App\Models\Lead::create(['name' => 'Rec Lead', 'mobile' => '9812300777', 'status' => 'new']);
        $patient = app(\App\Services\PatientLinker::class)->ensureFor($lead);

        Http::fake([
            'https://api-smartflo.tatateleservices.com/v1/call/records*' => Http::response(['results' => [
                ['call_id' => 'CDR1', 'direction' => 'Outbound', 'status' => 'answered', 'client_number' => '9812300777',
                 'agent_name' => 'Agent A', 'date' => '2026-09-17', 'time' => '10:00:00', 'answered_seconds' => 42,
                 'recording_url' => 'https://recordings.smartflo.tatateleservices.com/CDR1.mp3'],
                ['call_id' => 'CDR2', 'direction' => 'Inbound', 'status' => 'missed', 'client_number' => '9812300777',
                 'date' => '2026-09-16', 'time' => '18:00:00', 'answered_seconds' => 0,
                 'recording_url' => ''],
            ]]),
        ]);

        $res = $this->actingAs($this->admin)->getJson(route('patients.recordings', $patient))->assertOk();

        $this->assertSame('smartflo', $res->json('source'));
        $this->assertSame(1, $res->json('count'), 'Only records that have a recording_url are returned.');
        $rec = $res->json('recordings.0');
        $this->assertStringContainsString('/tata/recording', $rec['url']);
        $this->assertStringNotContainsString('smartflo.tatateleservices.com', $rec['url'], 'Raw Smartflo URL is proxied, not exposed.');

        // Nothing was written to the CRM database.
        $this->assertSame(0, \App\Models\CallLog::whereIn('provider_call_id', ['CDR1', 'CDR2'])->count());
    }

    public function test_the_recording_proxy_only_allows_smartflo_hosts(): void
    {
        $ok = route('tata.recording', ['u' => rtrim(strtr(base64_encode('https://recordings.smartflo.tatateleservices.com/x.mp3'), '+/', '-_'), '=')]);
        $bad = route('tata.recording', ['u' => rtrim(strtr(base64_encode('https://evil.example.com/x.mp3'), '+/', '-_'), '=')]);

        // Allowed host: not a 403/404 host-rejection (the stream itself will try to fetch).
        $this->actingAs($this->admin)->get($bad)->assertForbidden();
    }

    public function test_profile_recordings_falls_back_to_local_when_not_on_tata(): void
    {
        $this->settings->set('telephony', 'provider', 'asterisk');

        $lead = \App\Models\Lead::create(['name' => 'Local Rec', 'mobile' => '9812300888', 'status' => 'new']);
        $patient = app(\App\Services\PatientLinker::class)->ensureFor($lead);
        \App\Models\CallLog::create([
            'lead_id' => $lead->id, 'user_id' => $this->admin->id, 'provider' => 'asterisk', 'channel' => 'pstn',
            'direction' => 'outbound', 'to_number' => '9812300888', 'status' => 'completed', 'talk_seconds' => 20,
            'started_at' => now(), 'ended_at' => now(),
            'recording_url' => 'https://recordings.smartflo.example/local.mp3', 'is_recorded' => true,
        ]);

        $res = $this->actingAs($this->admin)->getJson(route('patients.recordings', $patient))->assertOk();
        $this->assertSame('local', $res->json('source'));
        $this->assertSame(1, $res->json('count'));
    }
    public function test_admin_can_save_tata_configuration_including_ai_key(): void
    {
        $this->actingAs($this->admin)->putJson(route('tata.config.update'), [
            'provider' => 'tata', 'force_disposition' => '1', 'force_disposition_present' => '1',
            'transcription_enabled' => '1', 'transcription_enabled_present' => '1', 'transcription_language' => 'hi-IN',
            'anthropic_api_key' => 'sk-ant-secret',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertSame('hi-IN', $this->settings->get('telephony', 'transcription_language'));
        $this->assertSame('sk-ant-secret', $this->settings->get('ai', 'anthropic_api_key'));

        $raw = \DB::table('settings')->where('group', 'ai')->where('key', 'anthropic_api_key')->value('value');
        $this->assertNotSame('sk-ant-secret', $raw, 'The API key must be encrypted at rest.');

        $this->settings->forget('ai', 'anthropic_api_key');
    }
}
