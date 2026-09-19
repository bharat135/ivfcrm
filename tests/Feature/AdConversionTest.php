<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use App\Services\Google\GoogleAdsConversionService;
use App\Services\LeadService;
use App\Services\Meta\ClickToWhatsAppAttributor;
use App\Services\Meta\ConversionsApiService;
use App\Services\PatientLinker;
use App\Support\AcquisitionChannel;
use App\Support\PatientJourney;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * Closing the loop on ad spend.
 *
 * Two things have to hold for the clinic to ever know which ad produced a
 * booked cycle: the click that started the journey must be captured, and the
 * outcome must be reported back to the network that sold it. These tests pin
 * both halves — including the rule that a Google-sourced person is never
 * reported to Meta, and vice versa.
 */
class AdConversionTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private function admin(): User
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
    }

    /**
     * These tests delete settings rows to prove the unconfigured path. The rows
     * come back on transaction rollback, but SettingsRepository caches by group,
     * so the cache has to be dropped or the developer's own app would keep
     * serving the deleted state after the suite finishes.
     */
    protected function tearDown(): void
    {
        $repository = app(\App\Support\SettingsRepository::class);
        $repository->flush('meta');
        $repository->flush('google_ads');

        parent::tearDown();
    }

    private function mobile(): string
    {
        return '9' . random_int(100000000, 999999999);
    }

    // -----------------------------------------------------------------
    // Click-to-WhatsApp: the ad click that arrives as a chat message
    // -----------------------------------------------------------------

    public function test_a_click_to_whatsapp_message_opens_an_attributed_enquiry_and_patient(): void
    {
        $phone = $this->mobile();

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '91' . $phone,
            'wa_id'              => '91' . $phone,
            'status'             => 'open',
        ]);

        app(ClickToWhatsAppAttributor::class)->attribute($conversation, [
            'from'     => '91' . $phone,
            'type'     => 'text',
            'text'     => ['body' => 'Hi, I saw your IVF ad'],
            'referral' => [
                'source_type' => 'ad',
                'source_id'   => '120210000000000000',
                'ctwa_clid'   => 'ARBxyzCLICKID',
                'headline'    => 'IVF consultation this week',
            ],
        ], 'Priya');

        $conversation->refresh();

        $this->assertNotNull($conversation->lead_id, 'A click-to-WhatsApp message did not open an enquiry.');
        $this->assertNotNull($conversation->patient_id, 'A click-to-WhatsApp enquiry did not open a patient record.');

        $lead = Lead::find($conversation->lead_id);

        $this->assertSame(AcquisitionChannel::META_ADS, $lead->channel, 'A paid WhatsApp click was recorded as organic.');
        $this->assertSame('ARBxyzCLICKID', $lead->external_lead_id);
        $this->assertSame('ARBxyzCLICKID', $lead->extra['whatsapp_referral']['ctwa_clid']);
    }

    public function test_an_ordinary_whatsapp_message_is_an_enquiry_but_not_a_paid_one(): void
    {
        $phone = $this->mobile();

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '91' . $phone,
            'wa_id'              => '91' . $phone,
            'status'             => 'open',
        ]);

        app(ClickToWhatsAppAttributor::class)->attribute($conversation, [
            'from' => '91' . $phone,
            'type' => 'text',
            'text' => ['body' => 'Do you do IUI?'],
        ], 'Walk-up');

        $lead = Lead::find($conversation->refresh()->lead_id);

        $this->assertNotNull($lead);
        $this->assertSame(AcquisitionChannel::WHATSAPP, $lead->channel);
        $this->assertNotNull($lead->patient_id);
    }

    public function test_attributing_twice_does_not_open_a_second_enquiry(): void
    {
        $phone = $this->mobile();

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '91' . $phone,
            'wa_id'              => '91' . $phone,
            'status'             => 'open',
        ]);

        $message = ['from' => '91' . $phone, 'type' => 'text', 'text' => ['body' => 'hello']];

        $first  = app(ClickToWhatsAppAttributor::class)->attribute($conversation, $message, 'Repeat');
        $second = app(ClickToWhatsAppAttributor::class)->attribute($conversation->refresh(), $message, 'Repeat');

        $this->assertSame($first->id, $second->id, 'A redelivered webhook opened a duplicate enquiry.');
    }

    // -----------------------------------------------------------------
    // Meta Conversions API
    // -----------------------------------------------------------------

    public function test_a_meta_event_carries_hashed_identity_and_the_click_id(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Anita Sharma',
            'mobile' => '9876543210',
            'email'  => 'Anita@Example.COM',
            'fbclid' => 'IwAR_TEST_FBCLID',
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);

        $event = app(ConversionsApiService::class)
            ->buildEvent($patient, $lead, 'Schedule', PatientJourney::CONSULTATION);

        $this->assertSame('Schedule', $event['event_name']);

        // Deterministic id, so a retried job is deduplicated rather than
        // counted as a second booking.
        $this->assertSame('crm.' . $patient->id . '.consultation', $event['event_id']);

        // Identity must be hashed, and normalised before hashing.
        $this->assertSame([hash('sha256', 'anita@example.com')], $event['user_data']['em']);
        $this->assertSame([hash('sha256', '919876543210')], $event['user_data']['ph']);
        $this->assertSame([hash('sha256', 'anita')], $event['user_data']['fn']);
        $this->assertSame([hash('sha256', 'sharma')], $event['user_data']['ln']);

        // No raw phone number or email may appear anywhere in the payload.
        $encoded = json_encode($event);
        $this->assertStringNotContainsString('9876543210', $encoded);
        $this->assertStringNotContainsString('anita@example.com', $encoded);

        // fbc is the structured cookie value, not the bare click id.
        $this->assertStringStartsWith('fb.1.', $event['user_data']['fbc']);
        $this->assertStringEndsWith('.IwAR_TEST_FBCLID', $event['user_data']['fbc']);
    }

    public function test_a_click_to_whatsapp_event_is_marked_as_business_messaging(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Ctwa Person',
            'mobile' => $this->mobile(),
            'extra'  => ['whatsapp_referral' => ['ctwa_clid' => 'ARB_CTWA_CLID']],
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);

        $event = app(ConversionsApiService::class)
            ->buildEvent($patient, $lead, 'Lead', PatientJourney::ENQUIRY);

        $this->assertSame('business_messaging', $event['action_source']);
        $this->assertSame('ARB_CTWA_CLID', $event['user_data']['ctwa_clid']);
    }

    public function test_a_crm_event_without_a_click_is_marked_system_generated(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'No Click',
            'mobile' => $this->mobile(),
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);

        $event = app(ConversionsApiService::class)
            ->buildEvent($patient, $lead, 'Lead', PatientJourney::ENQUIRY);

        $this->assertSame('system_generated', $event['action_source']);
        $this->assertSame('crm', $event['custom_data']['event_source']);
    }

    public function test_nothing_is_sent_to_meta_when_the_dataset_is_not_configured(): void
    {
        // preventStrayRequests() in the base TestCase means an accidental live
        // call would fail loudly rather than reaching the clinic's real pixel.
        $patient = Patient::whereNotNull('lead_id')->first();

        if (! $patient) {
            $this->markTestSkipped('No paired patient available.');
        }

        Setting::where('group', 'meta')->whereIn('key', ['dataset_id', 'pixel_id'])->delete();
        app(SettingsRepository::class)->flush('meta');

        $this->assertNull(app(ConversionsApiService::class)->reportStage($patient, PatientJourney::CONSULTATION));
    }

    // -----------------------------------------------------------------
    // Google Ads
    // -----------------------------------------------------------------

    public function test_a_google_conversion_uses_the_stored_click_id(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Google Person',
            'mobile' => $this->mobile(),
            'gclid'  => 'Cj0KCQ_TEST',
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);

        $conversion = app(GoogleAdsConversionService::class)->buildConversion(
            $lead,
            $patient,
            'customers/1234567890/conversionActions/999',
            PatientJourney::CONSULTATION,
        );

        $this->assertSame('Cj0KCQ_TEST', $conversion['gclid']);
        $this->assertSame('customers/1234567890/conversionActions/999', $conversion['conversionAction']);
        $this->assertSame('crm-' . $patient->id . '-consultation', $conversion['orderId']);

        // Google's own format, offset included.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $conversion['conversionDateTime'],
        );
    }

    public function test_wbraid_wins_over_gclid_because_they_are_mutually_exclusive(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Ios Person',
            'mobile' => $this->mobile(),
            'gclid'  => 'SHOULD_NOT_BE_USED',
            'wbraid' => 'WBRAID_TEST',
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);

        $conversion = app(GoogleAdsConversionService::class)
            ->buildConversion($lead, $patient, 'customers/1/conversionActions/2', PatientJourney::TREATMENT);

        $this->assertSame('WBRAID_TEST', $conversion['wbraid']);
        $this->assertArrayNotHasKey('gclid', $conversion, 'gclid and wbraid may not be sent on the same conversion.');
    }

    public function test_a_lead_with_no_click_falls_back_to_hashed_identifiers(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Phoned In',
            'mobile' => '9123456780',
            'email'  => 'phoned@example.com',
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);

        $conversion = app(GoogleAdsConversionService::class)
            ->buildConversion($lead, $patient, 'customers/1/conversionActions/2', PatientJourney::CONSULTATION);

        $this->assertArrayHasKey('userIdentifiers', $conversion);
        $this->assertContains(['hashedEmail' => hash('sha256', 'phoned@example.com')], $conversion['userIdentifiers']);

        // Google wants E.164 *with* the plus, unlike Meta.
        $this->assertContains(['hashedPhoneNumber' => hash('sha256', '+919123456780')], $conversion['userIdentifiers']);
    }

    public function test_the_google_settings_screen_reports_what_is_still_missing(): void
    {
        Setting::where('group', 'google_ads')->delete();
        app(SettingsRepository::class)->flush('google_ads');

        $this->actingAs($this->admin())
            ->get(route('settings.integrations.google.index'))
            ->assertOk()
            ->assertSee('Google Ads')
            ->assertSee('Developer token');
    }

    public function test_the_refresh_token_is_stored_encrypted(): void
    {
        $this->actingAs($this->admin())
            ->put(route('settings.integrations.google.update'), [
                'client_id'     => 'test-client-id',
                'refresh_token' => 'super-secret-refresh-token',
                'customer_id'   => '123-456-7890',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $stored = Setting::where('group', 'google_ads')->where('key', 'refresh_token')->value('value');

        $this->assertNotSame('super-secret-refresh-token', $stored, 'The refresh token was stored in the clear.');
        $this->assertSame('super-secret-refresh-token', app(SettingsRepository::class)->get('google_ads', 'refresh_token'));
    }

    public function test_a_masked_secret_does_not_wipe_the_stored_one(): void
    {
        app(SettingsRepository::class)->set('google_ads', 'refresh_token', 'original-token');

        $this->actingAs($this->admin())
            ->put(route('settings.integrations.google.update'), [
                'refresh_token' => str_repeat('•', 12) . 'oken',
            ])
            ->assertOk();

        $this->assertSame('original-token', app(SettingsRepository::class)->get('google_ads', 'refresh_token'));
    }

    // -----------------------------------------------------------------
    // Routing: the right network gets the right person
    // -----------------------------------------------------------------

    public function test_a_google_sourced_person_is_never_reported_to_meta(): void
    {
        Http::fake();

        $lead = app(LeadService::class)->create([
            'name'   => 'Routing Test',
            'mobile' => $this->mobile(),
            'gclid'  => 'ROUTING_GCLID',
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);

        // Google is unconfigured in the test environment, so this is a no-op —
        // but crucially it must not fall through to Meta either.
        app(\App\Jobs\ReportConversionJob::class, [
            'patientId' => $patient->id,
            'stage'     => PatientJourney::CONSULTATION,
        ])->handle(
            app(ConversionsApiService::class),
            app(GoogleAdsConversionService::class),
        );

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Channel reporting
    // -----------------------------------------------------------------

    public function test_the_funnel_compares_channels_all_the_way_to_treatment(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Funnel Person',
            'mobile' => $this->mobile(),
            'gclid'  => 'FUNNEL_GCLID',
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);
        app(PatientLinker::class)->advance($patient, PatientJourney::TREATMENT);

        $rows = app(\App\Services\Marketing\FunnelService::class)->byChannel([
            'from' => now()->subDay()->toDateString(),
            'to'   => now()->toDateString(),
        ]);

        $google = $rows->firstWhere('channel', AcquisitionChannel::GOOGLE_ADS);

        $this->assertNotNull($google, 'Google Ads is missing from the channel comparison.');
        $this->assertGreaterThanOrEqual(1, $google['leads']);
        $this->assertGreaterThanOrEqual(1, $google['treatment'], 'A couple in treatment was not credited to the channel that found them.');

        // A paid channel with no synced spend must read as unknown, never as
        // free — otherwise it looks infinitely efficient.
        $this->assertTrue($google['is_paid']);
        $this->assertFalse($google['spend_known']);
        $this->assertNull($google['cost_per_lead']);
    }

    public function test_the_funnel_page_renders_the_channel_comparison(): void
    {
        $this->actingAs($this->admin())
            ->get(route('marketing.analytics.funnel'))
            ->assertOk()
            ->assertSee('Which channel produces patients')
            ->assertSee('Cost / cycle');
    }

    public function test_synced_spend_produces_a_cost_per_cycle(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Costed Person',
            'mobile' => $this->mobile(),
            'gclid'  => 'COSTED_GCLID',
        ], autoAssign: false);

        app(PatientLinker::class)->advance(Patient::find($lead->patient_id), PatientJourney::TREATMENT);

        \App\Models\ChannelSpend::updateOrCreate(
            [
                'channel'              => AcquisitionChannel::GOOGLE_ADS,
                'date'                 => now()->toDateString(),
                'external_campaign_id' => 'test-campaign',
            ],
            ['spend' => 5000, 'clicks' => 100, 'impressions' => 1000],
        );

        $rows = app(\App\Services\Marketing\FunnelService::class)->byChannel([
            'from' => now()->subDay()->toDateString(),
            'to'   => now()->toDateString(),
        ]);

        $google = $rows->firstWhere('channel', AcquisitionChannel::GOOGLE_ADS);

        $this->assertTrue($google['spend_known']);
        $this->assertSame(5000.0, $google['spend']);
        $this->assertNotNull($google['cost_per_cycle']);
    }

    public function test_a_typed_name_replaces_the_whatsapp_profile_name(): void
    {
        $phone = $this->mobile();

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '91' . $phone,
            'wa_id'              => '91' . $phone,
            'status'             => 'open',
        ]);

        // A WhatsApp profile name is whatever the person set on their phone.
        app(ClickToWhatsAppAttributor::class)->attribute($conversation, [
            'from' => '91' . $phone,
            'type' => 'text',
            'text' => ['body' => 'hi'],
        ], 'ppl');

        $lead = Lead::find($conversation->refresh()->lead_id);

        $this->assertSame('ppl', $lead->name);
        $this->assertSame('whatsapp_profile', $lead->extra['name_source']);

        // Once they type their real name into a flow, that must win — otherwise
        // the form answer is silently discarded as a duplicate.
        $processor = app(\App\Services\Meta\Flows\FlowResponseProcessor::class);

        $enrich = new \ReflectionMethod($processor, 'enrichLead');
        $enrich->setAccessible(true);
        $enrich->invoke($processor, $lead, ['name' => 'Priya Nair', 'email' => 'priya@example.com']);

        $lead->refresh();

        $this->assertSame('Priya Nair', $lead->name, 'A typed form answer lost to the WhatsApp profile name.');
        $this->assertSame('priya@example.com', $lead->email);

        // ...and it is no longer provisional.
        $this->assertArrayNotHasKey('name_source', $lead->extra ?? []);
    }
}
