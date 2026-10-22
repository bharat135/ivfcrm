<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\DialSession;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * Bulk calling over Tata Smartflo: selected leads are dialled one after
 * another, each saved outcome dials the next, and only ten-digit Indian
 * mobiles are ever accepted or dialled.
 *
 * Smartflo is faked, so this proves the CRM side of the flow end to end with
 * the two test numbers the clinic asked to try.
 */
class BulkCallTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectDatabase {
        refreshApplication as projectRefreshApplication;
    }

    private const API = 'https://api-smartflo.tatateleservices.com';

    private const FIRST  = '7030336196';
    private const SECOND = '8308908203';

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

        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();

        $this->agent = User::factory()->create([
            'name'              => 'Bulk Caller',
            'is_active'         => true,
            'center_id'         => $admin->center_id,
            'phone'             => '9876500009',
            'tata_agent_number' => '9876500009',
        ]);
        $this->agent->assignRole('Telecaller');

        $this->settings->setMany([
            'provider'           => 'tata',
            'tata_access_token'  => 'portal-token-bulk',
            'tata_caller_id'     => '02240001111',
            'tata_webhook_token' => 'tata-hook-secret',
        ], 'telephony');

        Cache::forget('telephony.tata.access_token');

        Http::fake([
            self::API . '/v1/click_to_call' => Http::response(['success' => true, 'message' => 'ok', 'ref_id' => 'C2C-BULK']),
            self::API . '/*'                => Http::response(['success' => true]),
        ]);
    }

    protected function tearDown(): void
    {
        $this->settings->set('telephony', 'provider', 'asterisk');
        $this->settings->forget('telephony', 'tata_access_token');

        parent::tearDown();
    }

    /**
     * The enquiry for a test number. A number that already exists in the
     * database is the same person (dedup adopts their patient file), so the
     * existing enquiry is handed to the agent rather than duplicated.
     */
    private function lead(string $mobile, string $name): Lead
    {
        $lead = Lead::whereNull('merged_into_id')->where('mobile', $mobile)->orderBy('id')->first();

        if ($lead) {
            $lead->forceFill(['assigned_to' => $this->agent->id, 'assigned_at' => now(), 'center_id' => $this->agent->center_id])->saveQuietly();

            return $lead;
        }

        return Lead::create([
            'name'        => $name,
            'mobile'      => $mobile,
            'status'      => 'new',
            'center_id'   => $this->agent->center_id,
            'assigned_to' => $this->agent->id,
            'assigned_at' => now(),
        ]);
    }

    private function dialAndFinish(Lead $lead): CallLog
    {
        $call = CallLog::find(
            $this->actingAs($this->agent)
                ->postJson(route('calls.dial'), ['lead_id' => $lead->id])
                ->assertOk()
                ->assertJsonPath('success', true)
                ->json('call.id')
        );

        // Smartflo's hangup webhook closes the call; the agent then saves the outcome.
        $this->postJson('/api/webhooks/tata/hangup?token=tata-hook-secret', [
            'call_id'           => 'SF-' . $call->id,
            'custom_identifier' => ['crm_call_id' => (string) $call->id],
            'call_status'       => 'answered',
            'billsec'           => 30,
            'end_stamp'         => now()->format('Y-m-d H:i:s'),
        ])->assertOk();

        return $call->fresh();
    }

    // -----------------------------------------------------------------
    // The run
    // -----------------------------------------------------------------

    public function test_bulk_calling_dials_the_two_test_numbers_one_after_another(): void
    {
        $first  = $this->lead(self::FIRST, 'Bulk Test One');
        $second = $this->lead(self::SECOND, 'Bulk Test Two');

        $start = $this->actingAs($this->agent)
            ->postJson(route('leads.bulk_call.start'), ['lead_ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('skipped', 0);

        $this->assertSame(route('leads.show', ['lead' => $first->id, 'autodial' => 1]), $start->json('redirect'));

        // The first lead's page carries the banner and dials on arrival.
        $this->actingAs($this->agent)
            ->get($start->json('redirect'))
            ->assertOk()
            ->assertSee('Bulk calling')
            ->assertSee('lead 1 of 2')
            ->assertSee('data-autodial="1"', false)
            ->assertSee('data-lead="' . $first->id . '"', false);

        $call = $this->dialAndFinish($first);
        $this->assertSame(self::FIRST, $call->to_number);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/click_to_call')
            && $r['destination_number'] === self::FIRST
            && $r['agent_number'] === '9876500009');

        // Saving the outcome moves the run on to the second lead.
        $disposition = Disposition::whereNull('parent_id')->first();

        $this->actingAs($this->agent)
            ->postJson(route('calls.disposition', $call), [
                'disposition_id' => $disposition->id,
                'remark'         => 'Answered, interested',
            ])
            ->assertOk()
            ->assertJsonPath('next_url', route('leads.show', ['lead' => $second->id, 'autodial' => 1]));

        $session = DialSession::activeFor($this->agent);
        $this->assertSame(2, $session->position());
        $this->assertSame(1, $session->dialed_count);

        $call = $this->dialAndFinish($second);
        $this->assertSame(self::SECOND, $call->to_number);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/click_to_call')
            && $r['destination_number'] === self::SECOND);

        // The last outcome ends the run and sends the agent back to the list.
        $this->actingAs($this->agent)
            ->postJson(route('calls.disposition', $call), [
                'disposition_id' => $disposition->id,
                'remark'         => 'Answered, call back tomorrow',
            ])
            ->assertOk()
            ->assertJsonPath('next_url', route('leads.index'));

        $this->assertNull(DialSession::activeFor($this->agent));
        $this->assertDatabaseHas('dial_sessions', ['user_id' => $this->agent->id, 'status' => 'done', 'dialed_count' => 2, 'cursor' => 2]);

        $this->assertSame(2, CallLog::where('user_id', $this->agent->id)->where('provider', 'tata')->count());
    }

    public function test_the_profile_log_a_call_form_also_moves_the_run_on(): void
    {
        $first  = $this->lead(self::FIRST, 'Form One');
        $second = $this->lead(self::SECOND, 'Form Two');

        $this->actingAs($this->agent)
            ->postJson(route('leads.bulk_call.start'), ['lead_ids' => [$first->id, $second->id]])
            ->assertOk();

        $this->dialAndFinish($first);

        $this->actingAs($this->agent)
            ->postJson(route('leads.disposition', $first), [
                'disposition_id'    => Disposition::whereNull('parent_id')->first()->id,
                'remark'            => 'Spoke, will visit',
                // Every disposition but the stop-calling ones needs a callback time.
                'next_follow_up_at' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i'),
            ])
            ->assertOk()
            ->assertJsonPath('next_url', route('leads.show', ['lead' => $second->id, 'autodial' => 1]));
    }

    public function test_skip_and_stop_control_the_run(): void
    {
        $first  = $this->lead(self::FIRST, 'Skip One');
        $second = $this->lead(self::SECOND, 'Skip Two');

        $this->actingAs($this->agent)
            ->postJson(route('leads.bulk_call.start'), ['lead_ids' => [$first->id, $second->id]])
            ->assertOk();

        $this->actingAs($this->agent)
            ->postJson(route('leads.bulk_call.skip'))
            ->assertOk()
            ->assertJsonPath('redirect', route('leads.show', ['lead' => $second->id, 'autodial' => 1]))
            ->assertJsonPath('session.skipped', 1);

        $this->actingAs($this->agent)
            ->postJson(route('leads.bulk_call.stop'))
            ->assertOk()
            ->assertJsonPath('redirect', route('leads.index'));

        $this->assertNull(DialSession::activeFor($this->agent));
        $this->assertDatabaseHas('dial_sessions', ['user_id' => $this->agent->id, 'status' => 'stopped']);

        // A disposition outside a run falls back to the usual next-lead pick.
        $this->actingAs($this->agent)
            ->getJson(route('leads.bulk_call.current'))
            ->assertOk()
            ->assertJsonPath('session', null);
    }

    public function test_starting_a_run_drops_leads_that_cannot_be_called(): void
    {
        $mobile   = $this->lead(self::FIRST, 'Callable');
        $landline = Lead::create(['name' => 'Landline', 'status' => 'new', 'center_id' => $this->agent->center_id, 'assigned_to' => $this->agent->id]);
        $landline->forceFill(['mobile' => '2212345678'])->saveQuietly();

        $this->actingAs($this->agent)
            ->postJson(route('leads.bulk_call.start'), ['lead_ids' => [$landline->id, $mobile->id]])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('redirect', route('leads.show', ['lead' => $mobile->id, 'autodial' => 1]));

        $this->actingAs($this->agent)
            ->postJson(route('leads.bulk_call.start'), ['lead_ids' => [$landline->id]])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Ten-digit Indian mobiles only
    // -----------------------------------------------------------------

    public function test_only_ten_digit_indian_mobiles_are_accepted(): void
    {
        $this->assertSame('7030336196', PhoneNumber::mobile('+91 70303 36196'));
        $this->assertSame('7030336196', PhoneNumber::mobile('07030336196'));
        $this->assertSame('8308908203', PhoneNumber::mobile('918308908203'));
        $this->assertSame('8308908203', PhoneNumber::mobile('0091 8308908203'));

        $this->assertNull(PhoneNumber::mobile('02212345678'), 'Landlines are not mobiles.');
        $this->assertNull(PhoneNumber::mobile('5030336196'), 'Indian mobiles start 6-9.');
        $this->assertNull(PhoneNumber::mobile('703033619'), 'Nine digits is not a number.');
        $this->assertNull(PhoneNumber::mobile('70303361961'), 'Eleven digits without a trunk 0 is not a number.');
        $this->assertNull(PhoneNumber::mobile('+1 415 555 0100'), 'Foreign numbers are refused, not trimmed.');
    }

    public function test_the_browser_softphone_opens_and_closes_a_call_log(): void
    {
        $lead = $this->lead(self::FIRST, 'WebRTC One');

        // The browser softphone originates the audio; the CRM only logs it.
        $start = $this->actingAs($this->agent)
            ->postJson(route('calls.webrtc_start'), ['lead_id' => $lead->id])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('dial', self::FIRST);

        $call = CallLog::find($start->json('call.id'));
        $this->assertSame('webrtc', $call->channel);
        $this->assertSame('tata', $call->provider);
        $this->assertSame('ringing', $call->status);
        $this->assertSame(self::FIRST, $call->to_number);

        // No Smartflo REST call is made for a browser-originated call.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/v1/click_to_call'));

        $this->actingAs($this->agent)
            ->postJson(route('calls.webrtc_event', $call), ['event' => 'answered'])
            ->assertOk();
        $this->assertNotNull($call->fresh()->answered_at);

        $this->actingAs($this->agent)
            ->postJson(route('calls.webrtc_event', $call), ['event' => 'hangup', 'talk_seconds' => 42])
            ->assertOk();

        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(42, $call->talk_seconds);
    }

    public function test_the_browser_softphone_refuses_a_non_indian_mobile(): void
    {
        $this->actingAs($this->agent)
            ->postJson(route('calls.webrtc_start'), ['number' => '02212345678'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('number');
    }

    public function test_the_profile_calls_tab_shows_the_disposition_note_and_recording(): void
    {
        $lead = $this->lead(self::SECOND, 'History Lead');

        $call = CallLog::create([
            'lead_id' => $lead->id, 'user_id' => $this->agent->id, 'center_id' => $this->agent->center_id,
            'provider' => 'tata', 'channel' => 'pstn', 'direction' => 'outbound',
            'to_number' => self::SECOND, 'status' => 'completed', 'talk_seconds' => 65,
            'started_at' => now(), 'answered_at' => now(), 'ended_at' => now(),
            'disposition_id' => \App\Models\Disposition::whereNull('parent_id')->first()->id,
            'remark' => 'Patient asked for a Tuesday appointment.',
            'recording_url' => 'https://recordings.smartflo.example/abc.mp3', 'is_recorded' => true,
        ]);

        $this->actingAs($this->agent)
            ->get(route('leads.show', [$lead, 'tab' => 'calls']))
            ->assertOk()
            ->assertSee('Patient asked for a Tuesday appointment.')
            ->assertSee($call->disposition->name)
            ->assertSee('01:05')            // talk time mm:ss
            ->assertSee($this->agent->name)
            ->assertSee(route('calls.recording', $call), false);
    }

    public function test_the_profile_can_disconnect_the_live_call(): void
    {
        $lead = $this->lead(self::FIRST, 'On The Line');

        // The Disconnect button ships hidden on every profile; the dialer shows
        // it while the agent's live call belongs to this lead or patient.
        $this->actingAs($this->agent)
            ->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('js-hangup-call d-none', false)
            ->assertSee('data-lead="' . $lead->id . '"', false);

        $call = CallLog::create([
            'lead_id' => $lead->id, 'patient_id' => $lead->patient?->id, 'user_id' => $this->agent->id,
            'center_id' => $this->agent->center_id, 'provider' => 'tata', 'channel' => 'pstn',
            'direction' => 'outbound', 'to_number' => self::FIRST, 'status' => 'answered',
            'started_at' => now()->subMinute(), 'answered_at' => now()->subSeconds(30), 'channel_id' => 'live.1',
        ]);

        $this->actingAs($this->agent)->getJson(route('calls.current'))
            ->assertOk()
            ->assertJsonPath('call.id', $call->id)
            ->assertJsonPath('call.is_live', true)
            ->assertJsonPath('call.lead_id', $lead->id)
            ->assertJsonPath('call.patient_id', $lead->patient?->id);

        Http::fake([self::API . '/v1/call/hangup' => Http::response(['success' => true])]);

        $this->actingAs($this->agent)->postJson(route('calls.hangup', $call))->assertOk();

        $this->assertNotNull($call->fresh()->ended_at);
        $this->actingAs($this->agent)->getJson(route('calls.current'))->assertJsonPath('call.is_live', false);
    }

    public function test_the_edit_form_renders_the_ten_digit_pattern_unescaped(): void
    {
        $lead = $this->lead(self::FIRST, 'Edit Form');

        // Blade's {{ }} once HTML-escaped the quotes inside these attributes,
        // so the browser saw pattern="&quot;[6-9]…&quot;" and rejected every number.
        // Telecallers see a masked, read-only number; an admin gets the editable field.
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first();

        $this->actingAs($admin)
            ->get(route('leads.edit', $lead))
            ->assertOk()
            ->assertSee('pattern="[6-9][0-9]{9}"', false)
            ->assertDontSee('&quot;[6-9]', false)
            ->assertSee('title="10-digit Indian mobile number, without +91 or 0"', false);
    }

    public function test_lead_forms_and_the_dialler_refuse_anything_but_an_indian_mobile(): void
    {
        $this->actingAs($this->agent)
            ->post(route('leads.store'), ['name' => 'Landline Lead', 'mobile' => '02212345678'])
            ->assertSessionHasErrors('mobile');

        $this->actingAs($this->agent)
            ->post(route('leads.store'), ['name' => 'Alt Landline', 'mobile' => self::FIRST, 'alt_mobile' => '+1 415 555 0100'])
            ->assertSessionHasErrors('alt_mobile');

        // A fresh number, so dedup cannot hand back somebody's existing enquiry.
        $fresh = '7' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

        $this->actingAs($this->agent)
            ->post(route('leads.store'), ['name' => 'Formatted Mobile', 'mobile' => '+91 ' . substr($fresh, 0, 5) . '-' . substr($fresh, 5)])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leads', ['name' => 'Formatted Mobile', 'mobile' => $fresh]);

        $this->actingAs($this->agent)
            ->postJson(route('calls.dial'), ['number' => '02212345678'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('number');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v1/click_to_call'));
    }
}
