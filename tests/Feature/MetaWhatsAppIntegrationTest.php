<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
    // A number that belongs to a different Meta app
    // -----------------------------------------------------------------

    public function test_the_webhook_accepts_a_body_signed_by_a_channel_accounts_own_app(): void
    {
        app(SettingsRepository::class)->set('meta', 'app_secret', 'shared-secret');

        ChannelAccount::create([
            'name'        => 'Other business number',
            'channel'     => 'whatsapp',
            'provider'    => 'meta',
            'is_active'   => true,
            'is_default'  => false,
            'credentials' => ['phone_number_id' => 'PN-OTHER', 'app_id' => '222', 'app_secret' => 'other-secret'],
        ]);

        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);

        $post = fn (string $secret) => $this->call(
            'POST',
            '/api/webhooks/meta',
            [], [], [],
            [
                'CONTENT_TYPE'             => 'application/json',
                'HTTP_ACCEPT'              => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, $secret),
            ],
            $body,
        );

        $post('other-secret')->assertOk();
        $post('shared-secret')->assertOk();
        $post('someone-elses-secret')->assertForbidden();
    }

    public function test_a_channel_account_from_another_meta_app_sends_with_its_own_credentials(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('meta', 'access_token', 'shared-token');
        $settings->set('meta', 'app_secret', 'shared-secret');
        $settings->set('meta', 'phone_number_id', 'PN-SHARED');

        $account = ChannelAccount::create([
            'name'        => 'Other business number',
            'channel'     => 'whatsapp',
            'provider'    => 'meta',
            'is_active'   => true,
            'is_default'  => false,
            'credentials' => [
                'phone_number_id' => 'PN-OTHER',
                'waba_id'         => 'WABA-OTHER',
                'access_token'    => 'other-token',
                'app_id'          => '222',
                'app_secret'      => 'other-secret',
            ],
        ]);

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'channel_account_id' => $account->id,
            'contact_identifier' => '919700077665',
            'status'             => 'open',
            'window_expires_at'  => now()->addHours(12),
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OTHERAPP1']]])]);

        $this->actingAs($this->admin)
            ->postJson(route('chat.send', $conversation), ['body' => 'Hello from the other number'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PN-OTHER/messages')
            && $r->hasHeader('Authorization', 'Bearer other-token')
            && ($r['appsecret_proof'] ?? null) === hash_hmac('sha256', 'other-token', 'other-secret'));

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/PN-SHARED/'));

        $this->assertDatabaseHas('messages', [
            'conversation_id'     => $conversation->id,
            'provider_message_id' => 'wamid.OTHERAPP1',
            'status'              => 'sent',
        ]);
    }

    public function test_a_conversation_without_an_account_sends_through_the_default_sender(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('meta', 'access_token', 'shared-token');
        $settings->set('meta', 'phone_number_id', 'PN-SHARED');

        ChannelAccount::query()->update(['is_default' => false]);

        ChannelAccount::create([
            'name'        => 'Default number',
            'channel'     => 'whatsapp',
            'provider'    => 'meta',
            'is_active'   => true,
            'is_default'  => true,
            'credentials' => ['phone_number_id' => 'PN-DEFAULT', 'access_token' => 'default-token'],
        ]);

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '919700088776',
            'status'             => 'open',
            'window_expires_at'  => now()->addHours(12),
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.DEFAULT1']]])]);

        $this->actingAs($this->admin)
            ->postJson(route('chat.send', $conversation), ['body' => 'Hello'])
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/PN-DEFAULT/messages')
            && $r->hasHeader('Authorization', 'Bearer default-token'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/PN-SHARED/'));
    }

    // -----------------------------------------------------------------
    // Third-party WhatsApp API (1automations)
    // -----------------------------------------------------------------

    private function saveProvider(array $overrides = []): ChannelAccount
    {
        ChannelAccount::where('provider', ChannelAccount::PROVIDER_ONEAUTOMATIONS)->delete();

        $this->actingAs($this->admin)
            ->putJson(route('settings.integrations.provider.save'), $overrides + [
                'name'            => 'Prevoir via provider',
                'access_token'    => 'provider-key',
                'app_url'         => 'https://provider.example.com/api/meta/',
                'api_version'     => '21.0',
                'phone_number_id' => 'PN-PROV',
                'waba_id'         => 'WABA-PROV',
                'business_id'     => 'BIZ-1',
                'is_active'       => 1,
                'is_default'      => 1,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        return ChannelAccount::thirdPartyWhatsApp();
    }

    public function test_the_provider_section_saves_an_encrypted_default_account_with_a_webhook_token(): void
    {
        $account = $this->saveProvider();

        $this->assertTrue($account->is_default);
        $this->assertSame('https://provider.example.com', $account->credentials['app_url']);
        $this->assertSame('v21.0', $account->credentials['api_version']);
        $this->assertSame(40, strlen($account->credentials['webhook_token']));
        $this->assertSame(0, ChannelAccount::where('channel', 'whatsapp')->where('is_default', true)->whereKeyNot($account->id)->count());

        // Blank key on a later save keeps the saved one.
        $this->actingAs($this->admin)->putJson(route('settings.integrations.provider.save'), [
            'name' => 'Renamed', 'app_url' => 'https://provider.example.com', 'phone_number_id' => 'PN-PROV',
            'is_active' => 1, 'is_default' => 1,
        ])->assertOk();

        $this->assertSame('provider-key', $account->fresh()->credentials['access_token']);
        $this->actingAs($this->admin)->get(route('settings.integrations.index'))->assertOk()->assertSee('Third-party WhatsApp API');
    }

    public function test_messages_go_through_the_provider_url_with_its_key(): void
    {
        app(SettingsRepository::class)->set('meta', 'app_secret', 'shared-secret');
        $account = $this->saveProvider();

        $conversation = Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '919700044332',
            'status'             => 'open',
            'window_expires_at'  => now()->addHours(5),
        ]);

        Http::fake(['provider.example.com/*' => Http::response(['messages' => [['id' => 'wamid.PROV1']]])]);

        $this->actingAs($this->admin)
            ->postJson(route('chat.send', $conversation), ['body' => 'Hello via provider'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://provider.example.com/api/meta/v21.0/PN-PROV/messages'
            && $r->hasHeader('Authorization', 'Bearer provider-key')
            && ! isset($r['appsecret_proof'])
            && $r['text']['body'] === 'Hello via provider');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graph.facebook.com'));

        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'provider_message_id' => 'wamid.PROV1', 'status' => 'sent']);
    }

    public function test_the_provider_webhook_creates_a_conversation_on_the_provider_account(): void
    {
        $account = $this->saveProvider();
        $token   = $account->credentials['webhook_token'];

        $this->postJson('/api/webhooks/whatsapp-provider/' . str_repeat('x', 40), ['entry' => []])->assertForbidden();

        // The provider's URL check: ?echo=true&challange=XXXX must be echoed.
        $this->get('/api/webhooks/whatsapp-provider/' . $token . '?echo=true&challange=DFLKSD')
            ->assertOk()->assertSeeText('DFLKSD', false);
        $this->assertSame('DFLKSD', $this->get('/api/webhooks/whatsapp-provider/' . $token . '?echo=true&challange=DFLKSD')->getContent());
        $this->get('/api/webhooks/whatsapp-provider/' . str_repeat('x', 40) . '?echo=true&challange=DFLKSD')->assertForbidden();

        // A bare change value, not wrapped in Meta's envelope.
        $this->postJson('/api/webhooks/whatsapp-provider/' . $token, [
            'messaging_product' => 'whatsapp',
            'contacts' => [['profile' => ['name' => 'Provider Lead'], 'wa_id' => '919700033221']],
            'messages' => [[
                'from' => '919700033221', 'id' => 'wamid.PROVIN1', 'timestamp' => (string) now()->timestamp,
                'type' => 'text', 'text' => ['body' => 'Hi from provider'],
            ]],
        ])->assertOk();

        $conversation = Conversation::where('contact_identifier', '919700033221')->first();

        $this->assertNotNull($conversation);
        $this->assertSame($account->id, $conversation->channel_account_id);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'wamid.PROVIN1', 'direction' => 'inbound']);
        $this->assertSame('processed', \App\Models\WebhookEvent::where('source', '1automations')->latest('id')->value('status'));
    }

    public function test_an_inbound_message_on_the_provider_moves_an_old_meta_thread_to_it(): void
    {
        $meta = ChannelAccount::create([
            'name' => 'Old Meta', 'channel' => 'whatsapp', 'provider' => 'meta', 'is_active' => true, 'is_default' => false,
            'credentials' => ['phone_number_id' => 'PN-PROV', 'access_token' => 'meta-token'],
        ]);
        $account = $this->saveProvider();

        $conversation = Conversation::create([
            'channel' => 'whatsapp', 'channel_account_id' => $meta->id, 'contact_identifier' => '919700011009', 'status' => 'open',
        ]);

        $this->postJson('/api/webhooks/whatsapp-provider/' . $account->credentials['webhook_token'], [
            'messages' => [[
                'from' => '919700011009', 'id' => 'wamid.PROVIN2', 'timestamp' => (string) now()->timestamp,
                'type' => 'text', 'text' => ['body' => 'Hii'],
            ]],
        ])->assertOk();

        $this->assertSame($account->id, $conversation->fresh()->channel_account_id);
    }

    public function test_images_upload_and_send_through_the_provider_api_path(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('meta.whatsapp.media_disk'));
        $this->saveProvider();

        $conversation = Conversation::create([
            'channel' => 'whatsapp', 'contact_identifier' => '919700066778', 'status' => 'open', 'window_expires_at' => now()->addHours(5),
        ]);

        // The provider rejects `id`, accepts `media_id` on the retry.
        Http::fake([
            'provider.example.com/api/meta/v21.0/PN-PROV/media' => Http::response(['id' => 'MEDIA-1']),
            'provider.example.com/api/meta/v21.0/PN-PROV/messages' => Http::sequence()
                ->push(['success' => false, 'error' => 'invalid media object'], 200)
                ->push(['messages' => [['id' => 'wamid.IMG1']]]),
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->image('scan.jpg', 20, 20);

        $this->actingAs($this->admin)
            ->post(route('chat.send_media', $conversation), ['file' => $file, 'caption' => 'Report'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://provider.example.com/api/meta/v21.0/PN-PROV/media');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && ($r['image']['id'] ?? null) === 'MEDIA-1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/messages') && ($r['image']['media_id'] ?? null) === 'MEDIA-1');

        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'provider_message_id' => 'wamid.IMG1', 'status' => 'sent']);
    }

    public function test_the_template_picker_offers_only_the_default_senders_templates(): void
    {
        $account = $this->saveProvider();

        \App\Models\WhatsappTemplate::create(['channel_account_id' => $account->id, 'name' => 'provider_tpl', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'body_text' => 'Hi']);
        \App\Models\WhatsappTemplate::create(['channel_account_id' => null, 'name' => 'meta_only_tpl', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'body_text' => 'Hi']);

        $names = \App\Models\WhatsappTemplate::approved()->forDefaultSender()->pluck('name');

        $this->assertContains('provider_tpl', $names);
        $this->assertNotContains('meta_only_tpl', $names);

        $this->actingAs($this->admin)->get(route('chat.index'))->assertOk()->assertSee('provider_tpl')->assertDontSee('meta_only_tpl');
    }

    public function test_the_templates_page_lists_templates_per_provider(): void
    {
        $account = $this->saveProvider();

        \App\Models\WhatsappTemplate::create(['channel_account_id' => $account->id, 'name' => 'prov_only_tpl', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'body_text' => 'Hi']);
        \App\Models\WhatsappTemplate::create(['channel_account_id' => null, 'name' => 'shared_only_tpl', 'language' => 'en', 'category' => 'UTILITY', 'status' => 'APPROVED', 'body_text' => 'Hi']);

        $names = fn (string $source) => collect($this->actingAs($this->admin)
            ->getJson(route('marketing.templates.index', ['source' => $source, 'draw' => 1, 'start' => 0, 'length' => 500]))
            ->assertOk()->json('data'))->pluck('name');

        $this->assertContains('prov_only_tpl', $names((string) $account->id));
        $this->assertNotContains('shared_only_tpl', $names((string) $account->id));
        $this->assertContains('shared_only_tpl', $names('shared'));
        $this->assertNotContains('prov_only_tpl', $names('shared'));
        $this->assertContains('prov_only_tpl', $names(''));

        $this->actingAs($this->admin)->get(route('marketing.templates.index'))
            ->assertOk()->assertSee('1automations (default sender)');
    }

    public function test_registering_the_webhook_posts_the_crm_url_and_stores_the_id(): void
    {
        $account = $this->saveProvider(['webhook_api_url' => 'https://hooks.example.com']);

        Http::fake(['hooks.example.com/v2/webhook' => Http::response(['id' => 'wh_123'])]);

        $this->actingAs($this->admin)->postJson(route('settings.integrations.provider.webhook'))
            ->assertOk()->assertJsonPath('success', true);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://hooks.example.com/v2/webhook'
            && $r->hasHeader('Authorization', 'Bearer provider-key')
            && str_ends_with($r['url'], '/api/webhooks/whatsapp-provider/' . $account->credentials['webhook_token'])
            && in_array('incoming', $r['events'], true));

        $this->assertSame('wh_123', $account->fresh()->credentials['webhook_id']);
    }

    public function test_an_inactive_account_hands_its_conversations_to_the_default_sender(): void
    {
        $meta = ChannelAccount::create([
            'name' => 'Old Meta', 'channel' => 'whatsapp', 'provider' => 'meta', 'is_active' => false, 'is_default' => false,
            'credentials' => ['phone_number_id' => 'PN-OLD', 'access_token' => 'old-token'],
        ]);
        $this->saveProvider();

        $conversation = Conversation::create([
            'channel' => 'whatsapp', 'channel_account_id' => $meta->id, 'contact_identifier' => '919700022110',
            'status' => 'open', 'window_expires_at' => now()->addHours(5),
        ]);

        Http::fake(['provider.example.com/*' => Http::response(['messages' => [['id' => 'wamid.PROV2']]])]);

        $this->actingAs($this->admin)->postJson(route('chat.send', $conversation), ['body' => 'Hi'])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'provider.example.com/api/meta/v21.0/PN-PROV/messages'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'PN-OLD'));
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

    public function test_the_alternate_number_gets_its_own_thread_linked_to_the_lead(): void
    {
        $lead = Lead::create([
            'name'       => 'Two Numbers',
            'mobile'     => '9700077601',
            'alt_mobile' => '9700077602',
            'status'     => 'new',
        ]);

        $primary = $this->actingAs($this->admin)
            ->postJson(route('chat.start'), ['lead_id' => $lead->id, 'slot' => 'mobile'])
            ->assertOk()
            ->json('conversation_id');

        $alternate = $this->actingAs($this->admin)
            ->postJson(route('chat.start'), ['lead_id' => $lead->id, 'slot' => 'alt_mobile'])
            ->assertOk()
            ->json('conversation_id');

        $this->assertNotSame($primary, $alternate, 'Each number is its own WhatsApp account, so each gets a thread.');

        $this->assertDatabaseHas('conversations', [
            'id'                 => $alternate,
            'channel'            => 'whatsapp',
            'contact_identifier' => '919700077602',
            'lead_id'            => $lead->id,
        ]);
    }

    public function test_the_profile_shows_one_whatsapp_window_per_number(): void
    {
        $lead = app(\App\Services\LeadService::class)->create([
            'name'       => 'Profile Windows',
            'mobile'     => '9700077611',
            'alt_mobile' => '9700077612',
        ], autoAssign: false);

        $this->actingAs($this->admin)
            ->get(route('leads.show', [$lead, 'tab' => 'messages']))
            ->assertOk()
            ->assertSee('data-slot="mobile"', false)
            ->assertSee('data-slot="alt_mobile"', false)
            ->assertSee('Primary')
            ->assertSee('Alternate');

        // One number means one window, with no phantom "alternate" panel.
        $single = app(\App\Services\LeadService::class)->create([
            'name'   => 'Profile Single',
            'mobile' => '9700077613',
        ], autoAssign: false);

        $this->actingAs($this->admin)
            ->get(route('leads.show', [$single, 'tab' => 'messages']))
            ->assertOk()
            ->assertSee('data-slot="mobile"', false)
            ->assertDontSee('data-slot="alt_mobile"', false);
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
