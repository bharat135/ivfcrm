<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-end coverage for the WhatsApp inbox, the Meta marketing screens,
 * the webhook endpoint and the public landing pages.
 *
 * Runs against the configured database inside a transaction so it exercises the
 * real schema (including the JSON/index-heavy Meta tables) without leaving rows
 * behind.
 */
class MetaWhatsAppIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    /**
     * Run against the real MySQL schema rather than phpunit.xml's SQLite
     * default: the Meta tables lean on JSON columns, generated defaults and
     * composite indexes that SQLite does not model faithfully. Every test is
     * wrapped in a transaction, so nothing is left behind.
     *
     * Overriding here (not in setUp) is deliberate — it must happen after the
     * application is built but before DatabaseTransactions opens its transaction.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // phpunit.xml exports DB_CONNECTION/DB_DATABASE for an in-memory SQLite
        // run, which also leaks into the mysql connection's `database` key —
        // so the real credentials are read straight from .env here.
        $env = $this->dotenv();

        // Mirror the developer's local environment, where AppServiceProvider
        // turns this on: a missing eager-load must fail the test, not silently
        // issue N+1 queries in the browser.
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(true);

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host'     => $env['DB_HOST'] ?? '127.0.0.1',
            'database.connections.mysql.port'     => $env['DB_PORT'] ?? '3306',
            'database.connections.mysql.database' => $env['DB_DATABASE'] ?? 'ivfcrm',
            'database.connections.mysql.username' => $env['DB_USERNAME'] ?? 'root',
            'database.connections.mysql.password' => $env['DB_PASSWORD'] ?? '',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function dotenv(): array
    {
        static $parsed = null;

        if ($parsed !== null) {
            return $parsed;
        }

        $parsed = [];
        $path = base_path('.env');

        if (! is_file($path)) {
            return $parsed;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $parsed[trim($key)] = trim($value, " \t\"'");
        }

        return $parsed;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first()
            ?? User::first();

        $this->assertNotNull($this->admin, 'Seed the database first: php artisan db:seed');
    }

    // -----------------------------------------------------------------
    // Screens render through the full middleware stack
    // -----------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('marketingPages')]
    public function test_marketing_screens_render(string $route): void
    {
        $this->actingAs($this->admin)
            ->get(route($route))
            ->assertOk();
    }

    public static function marketingPages(): array
    {
        return [
            'whatsapp inbox'      => ['chat.index'],
            'campaigns'           => ['marketing.campaigns.index'],
            'ad sets'             => ['marketing.adsets.index'],
            'ads'                 => ['marketing.ads.index'],
            'lead forms'          => ['marketing.leadforms.index'],
            'templates'           => ['marketing.templates.index'],
            'broadcasts'          => ['marketing.broadcasts.index'],
            'landing pages'       => ['marketing.landing.index'],
            'landing page create' => ['marketing.landing.create'],
            'ad analytics'        => ['marketing.analytics.index'],
            'conversion funnel'   => ['marketing.analytics.funnel'],
            'integrations'        => ['settings.integrations.index'],
        ];
    }

    public function test_guests_are_redirected_from_the_inbox(): void
    {
        $this->get(route('chat.index'))->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // Webhook
    // -----------------------------------------------------------------

    public function test_webhook_verification_handshake(): void
    {
        app(SettingsRepository::class)->set('meta', 'webhook_verify_token', 'test-verify-token');

        $this->get('/api/webhooks/meta?' . http_build_query([
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge'    => '1234567890',
        ]))->assertOk()->assertSee('1234567890');
    }

    public function test_webhook_verification_rejects_a_wrong_token(): void
    {
        app(SettingsRepository::class)->set('meta', 'webhook_verify_token', 'test-verify-token');

        $this->get('/api/webhooks/meta?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=1')
            ->assertForbidden();
    }

    public function test_webhook_rejects_an_unsigned_payload(): void
    {
        app(SettingsRepository::class)->set('meta', 'app_secret', 'test-app-secret');

        $this->postJson('/api/webhooks/meta', ['object' => 'whatsapp_business_account'])
            ->assertForbidden();

        $this->assertDatabaseMissing('webhook_events', ['source' => 'meta']);
    }

    public function test_signed_inbound_message_creates_a_conversation_and_links_the_lead(): void
    {
        app(SettingsRepository::class)->set('meta', 'app_secret', 'test-app-secret');

        $lead = Lead::create(['name' => 'Webhook Lead', 'mobile' => '9700012345', 'status' => 'new']);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id' => 'WABA1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PN1'],
                        'contacts' => [['profile' => ['name' => 'Webhook Lead'], 'wa_id' => '919700012345']],
                        'messages' => [[
                            'from' => '919700012345',
                            'id'   => 'wamid.FEATURE001',
                            'type' => 'text',
                            'text' => ['body' => 'What is the IVF success rate?'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $body = json_encode($payload);

        $this->call(
            'POST',
            '/api/webhooks/meta',
            [], [], [],
            [
                'CONTENT_TYPE'            => 'application/json',
                'HTTP_ACCEPT'             => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'test-app-secret'),
            ],
            $body,
        )->assertOk();

        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'wamid.FEATURE001',
            'direction'           => 'inbound',
        ]);

        $conversation = Conversation::where('contact_identifier', '919700012345')->first();

        $this->assertNotNull($conversation);
        $this->assertSame($lead->id, $conversation->lead_id, 'Inbound message should link to the matching lead.');
        $this->assertTrue($conversation->isWindowOpen(), 'An inbound message opens the 24h window.');
        $this->assertSame('processed', \App\Models\WebhookEvent::latest('id')->value('status'));
    }

    // -----------------------------------------------------------------
    // Inbox API
    // -----------------------------------------------------------------

    public function test_inbox_lists_and_opens_a_conversation(): void
    {
        $lead = Lead::create(['name' => 'Inbox Lead', 'mobile' => '9700099887', 'status' => 'new']);

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '919700099887',
            'lead_id'            => $lead->id,
            'status'             => 'open',
            'unread_count'       => 2,
            'last_message_at'    => now(),
            'last_inbound_at'    => now(),
            'window_expires_at'  => now()->addHours(24),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'type'            => 'text',
            'body'            => 'Hello there',
            'status'          => 'delivered',
        ]);

        $this->actingAs($this->admin)
            ->getJson(route('chat.conversations'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Inbox Lead')
            ->assertJsonPath('data.0.window_open', true);

        $this->actingAs($this->admin)
            ->getJson(route('chat.show', $conversation))
            ->assertOk()
            ->assertJsonPath('conversation.lead.name', 'Inbox Lead')
            ->assertJsonPath('messages.0.body', 'Hello there');

        // Opening a thread clears its unread badge.
        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    public function test_sending_outside_the_window_is_refused_with_a_clear_message(): void
    {
        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '919700055443',
            'status'             => 'open',
            'window_expires_at'  => now()->subHour(),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('chat.send', $conversation), ['body' => 'Hi again'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'The 24-hour WhatsApp window for this contact has closed. Send an approved template to reopen the conversation.']);

        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id, 'direction' => 'outbound']);
    }

    public function test_an_internal_note_is_stored_but_never_sent(): void
    {
        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '919700066554',
            'status'             => 'open',
            'window_expires_at'  => now()->subDay(),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('chat.note', $conversation), ['body' => 'Patient asked for a Hindi-speaking counsellor.'])
            ->assertOk()
            ->assertJsonPath('message.is_note', true);

        $this->assertDatabaseHas('messages', [
            'conversation_id'  => $conversation->id,
            'is_internal_note' => 1,
            'status'           => 'sent',
        ]);
    }

    public function test_starting_a_chat_reuses_an_existing_thread(): void
    {
        $lead = Lead::create(['name' => 'Reuse Lead', 'mobile' => '9700077665', 'status' => 'new']);

        $first = $this->actingAs($this->admin)
            ->postJson(route('chat.start'), ['lead_id' => $lead->id])
            ->assertOk()
            ->json('conversation_id');

        $second = $this->actingAs($this->admin)
            ->postJson(route('chat.start'), ['mobile' => '+91 97000-77665'])
            ->assertOk()
            ->json('conversation_id');

        $this->assertSame($first, $second, 'The same number must not open two threads.');
    }

    // -----------------------------------------------------------------
    // Guard rails when Meta is not configured
    // -----------------------------------------------------------------

    public function test_publishing_a_campaign_without_credentials_fails_gracefully(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->forget('meta', 'access_token');
        $settings->forget('meta', 'ad_account_id');

        $this->actingAs($this->admin)
            ->postJson(route('marketing.campaigns.store'), [
                'name'         => 'Unconfigured campaign',
                'objective'    => 'OUTCOME_LEADS',
                'daily_budget' => 1000,
                'push_to_meta' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('campaigns', ['name' => 'Unconfigured campaign']);
    }

    public function test_a_campaign_can_be_saved_locally_without_meta(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('marketing.campaigns.store'), [
                'name'         => 'Local only campaign',
                'objective'    => 'OUTCOME_LEADS',
                'daily_budget' => 1500,
                'push_to_meta' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('campaigns', [
            'name'        => 'Local only campaign',
            'external_id' => null,
            'status'      => 'PAUSED',
        ]);
    }

    public function test_a_campaign_needs_a_budget(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('marketing.campaigns.store'), [
                'name'         => 'No budget campaign',
                'objective'    => 'OUTCOME_LEADS',
                'push_to_meta' => false,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Set either a daily budget or a lifetime budget.']);
    }

    // -----------------------------------------------------------------
    // Landing pages
    // -----------------------------------------------------------------

    public function test_a_published_landing_page_is_public_and_counts_views(): void
    {
        $page = LandingPage::create([
            'name'         => 'Feature Test Page',
            'headline'     => 'Start Your IVF Journey',
            'subheadline'  => 'Free first consultation',
            'template'     => 'clinic',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->get(route('landing.show', $page->slug))
            ->assertOk()
            ->assertSee('Start Your IVF Journey')
            ->assertSee('name="mobile"', false);

        $this->assertSame(1, $page->fresh()->views);
    }

    public function test_an_unpublished_landing_page_is_not_public(): void
    {
        $page = LandingPage::create([
            'name'         => 'Draft Page',
            'headline'     => 'Draft',
            'template'     => 'clinic',
            'is_published' => false,
        ]);

        $this->get(route('landing.show', $page->slug))->assertNotFound();
    }

    public function test_a_landing_page_submission_creates_an_attributed_lead(): void
    {
        Queue::fake();

        $page = LandingPage::create([
            'name'         => 'Capture Page',
            'headline'     => 'Book a consultation',
            'template'     => 'clinic',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->post(route('landing.submit', $page->slug), [
            'name'         => 'Anita Sharma',
            'mobile'       => '9811122233',
            'email'        => 'anita@example.com',
            'city'         => 'Delhi',
            'message'      => 'Please call in the evening.',
            'utm_source'   => 'facebook',
            'utm_medium'   => 'paid_social',
            'utm_campaign' => 'ivf-delhi-aug',
        ])->assertRedirect();

        $lead = Lead::where('mobile', '9811122233')->first();

        $this->assertNotNull($lead, 'The submission should create a lead.');
        $this->assertSame('Anita Sharma', $lead->name);
        $this->assertSame('facebook', $lead->utm_source);
        $this->assertSame('ivf-delhi-aug', $lead->utm_campaign);
        $this->assertSame($page->slug, $lead->extra['landing_page'] ?? null);

        $this->assertDatabaseHas('landing_page_submissions', [
            'landing_page_id' => $page->id,
            'lead_id'         => $lead->id,
        ]);

        $this->assertSame(1, $page->fresh()->submissions);
    }

    public function test_the_honeypot_silently_absorbs_a_bot_submission(): void
    {
        $page = LandingPage::create([
            'name'         => 'Honeypot Page',
            'headline'     => 'Book now',
            'template'     => 'clinic',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->post(route('landing.submit', $page->slug), [
            'name'    => 'Bot',
            'mobile'  => '9800000000',
            'website' => 'http://spam.example',
        ])->assertRedirect();

        $this->assertDatabaseMissing('leads', ['mobile' => '9800000000']);
        $this->assertSame(0, $page->fresh()->submissions);
    }

    public function test_a_submission_with_an_invalid_mobile_is_rejected(): void
    {
        $page = LandingPage::create([
            'name'         => 'Validation Page',
            'headline'     => 'Book now',
            'template'     => 'clinic',
            'is_published' => true,
            'published_at' => now(),
        ]);

        $this->post(route('landing.submit', $page->slug), ['name' => 'Short', 'mobile' => '123'])
            ->assertSessionHasErrors('mobile');

        $this->assertDatabaseMissing('leads', ['name' => 'Short']);
    }

    // -----------------------------------------------------------------
    // Access control
    // -----------------------------------------------------------------

    public function test_a_telecaller_cannot_open_integration_settings(): void
    {
        $telecaller = User::whereHas('roles', fn ($q) => $q->where('name', 'Telecaller'))->first();

        if (! $telecaller) {
            $this->markTestSkipped('No Telecaller user seeded.');
        }

        $this->actingAs($telecaller)->get(route('settings.integrations.index'))->assertForbidden();
        $this->actingAs($telecaller)->get(route('chat.index'))->assertOk();
    }

    public function test_an_agent_cannot_read_someone_elses_conversation(): void
    {
        $telecaller = User::whereHas('roles', fn ($q) => $q->where('name', 'Telecaller'))->first();

        if (! $telecaller) {
            $this->markTestSkipped('No Telecaller user seeded.');
        }

        $otherAgent = User::whereKeyNot($telecaller->id)->first();

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '919700044332',
            'assigned_to'        => $otherAgent->id,
            'status'             => 'open',
        ]);

        $this->actingAs($telecaller)
            ->getJson(route('chat.show', $conversation))
            ->assertForbidden();
    }
}
