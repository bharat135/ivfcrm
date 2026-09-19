<?php

namespace Tests\Feature;

use App\Jobs\DownloadChannelMediaJob;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ConversationService;
use App\Services\Meta\MessengerService;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Instagram Direct and Messenger in the shared inbox.
 *
 * These arrive in a different webhook envelope from WhatsApp (`entry[].messaging[]`
 * rather than `entry[].changes[]`) and identify a person by an opaque page-scoped
 * id. Both differences have a sharp edge, and each has a test here.
 */
class SocialMessagingTest extends TestCase
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

        app(SettingsRepository::class)->set('meta', 'app_secret', 'social-test-secret');
    }

    /**
     * Post a signed webhook the way Meta does.
     */
    private function webhook(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/meta', [], [], [], [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_ACCEPT'              => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'social-test-secret'),
        ], $body);
    }

    /**
     * A Messenger/Instagram webhook envelope: a flat `messaging[]` array.
     */
    private function envelope(string $object, string $pageId, array $events): array
    {
        return [
            'object' => $object,
            'entry'  => [[
                'id'        => $pageId,
                'time'      => now()->getTimestampMs(),
                'messaging' => $events,
            ]],
        ];
    }

    private function inboundText(string $senderId, string $pageId, string $text, ?string $mid = null): array
    {
        return [
            'sender'    => ['id' => $senderId],
            'recipient' => ['id' => $pageId],
            'timestamp' => now()->getTimestampMs(),
            'message'   => ['mid' => $mid ?? ('m_' . uniqid()), 'text' => $text],
        ];
    }

    // =================================================================
    // Receiving
    // =================================================================

    #[DataProvider('socialChannels')]
    public function test_an_inbound_message_opens_a_thread(string $object, string $channel): void
    {
        Http::fake();   // profile enrichment

        $this->webhook($this->envelope($object, 'PAGE_1', [
            $this->inboundText('USER_100', 'PAGE_1', 'Hi, do you offer IVF?', 'm_first'),
        ]))->assertOk();

        $conversation = Conversation::where('channel', $channel)
            ->where('contact_identifier', 'USER_100')
            ->first();

        $this->assertNotNull($conversation, "No {$channel} thread was opened.");
        $this->assertSame('PAGE_1', $conversation->page_id);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertTrue($conversation->isWindowOpen());

        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'm_first',
            'direction'           => 'inbound',
            'body'                => 'Hi, do you offer IVF?',
        ]);
    }

    public static function socialChannels(): array
    {
        return [
            'messenger' => ['page', 'messenger'],
            'instagram' => ['instagram', 'instagram'],
        ];
    }

    /**
     * The bug this guards: a PSID is an opaque numeric string. Running one
     * through the phone normaliser strips a leading "91" from a 12-digit id,
     * which would point the thread at a different person entirely.
     */
    public function test_a_numeric_psid_is_never_phone_normalised(): void
    {
        Http::fake();

        // Deliberately shaped like an Indian mobile with a country code.
        $psid = '919812345678';

        $this->webhook($this->envelope('page', 'PAGE_1', [
            $this->inboundText($psid, 'PAGE_1', 'Numeric id test', 'm_psid'),
        ]))->assertOk();

        $this->assertDatabaseHas('conversations', [
            'channel'            => 'messenger',
            'contact_identifier' => $psid,
        ]);

        $conversation = Conversation::where('channel', 'messenger')->where('contact_identifier', $psid)->first();

        // A PSID is not a phone number and must not be stored as one.
        $this->assertNull($conversation->wa_id, 'A PSID must not be written to wa_id.');
    }

    /**
     * The most damaging bug in this area: Meta replays messages the Page itself
     * sent. On an echo the sender is the Page and the recipient is the customer.
     * Treating one as inbound would credit the customer with the clinic's own
     * replies and reopen the reply window on its own messages.
     */
    public function test_an_echo_is_stored_as_outbound_and_does_not_reopen_the_window(): void
    {
        Http::fake();

        $conversation = Conversation::create([
            'channel'            => 'messenger',
            'contact_identifier' => 'USER_ECHO',
            'page_id'            => 'PAGE_1',
            'status'             => 'open',
            'last_inbound_at'    => now()->subDays(3),
        ]);

        $before = $conversation->last_inbound_at;

        $this->webhook($this->envelope('page', 'PAGE_1', [[
            // Sender and recipient are reversed on an echo.
            'sender'    => ['id' => 'PAGE_1'],
            'recipient' => ['id' => 'USER_ECHO'],
            'timestamp' => now()->getTimestampMs(),
            'message'   => ['mid' => 'm_echo', 'is_echo' => true, 'text' => 'Sent from Business Suite'],
        ]]))->assertOk();

        $message = Message::where('provider_message_id', 'm_echo')->first();

        $this->assertNotNull($message, 'The echo was not recorded.');
        $this->assertSame('outbound', $message->direction);
        $this->assertTrue((bool) $message->is_echo);
        $this->assertSame($conversation->id, $message->conversation_id);

        $conversation->refresh();

        $this->assertSame(0, $conversation->unread_count, 'An echo must not raise the unread badge.');
        $this->assertEquals(
            $before->timestamp,
            $conversation->last_inbound_at->timestamp,
            'An echo must not reopen the reply window.',
        );
    }

    public function test_a_replayed_batch_does_not_duplicate_a_message(): void
    {
        Http::fake();

        $payload = $this->envelope('page', 'PAGE_1', [
            $this->inboundText('USER_DUP', 'PAGE_1', 'Only once please', 'm_dup'),
        ]);

        $this->webhook($payload)->assertOk();
        $this->webhook($payload)->assertOk();

        $this->assertSame(1, Message::where('provider_message_id', 'm_dup')->count());
    }

    public function test_an_attachment_is_queued_for_download_from_its_url(): void
    {
        Http::fake();
        Queue::fake();

        $this->webhook($this->envelope('instagram', 'IG_1', [[
            'sender'    => ['id' => 'IG_USER'],
            'recipient' => ['id' => 'IG_1'],
            'timestamp' => now()->getTimestampMs(),
            'message'   => [
                'mid'         => 'm_media',
                'attachments' => [[
                    'type'    => 'image',
                    'payload' => ['url' => 'https://cdn.example/scan.jpg'],
                ]],
            ],
        ]]))->assertOk();

        $message = Message::where('provider_message_id', 'm_media')->first();

        $this->assertNotNull($message);
        $this->assertSame('image', $message->media_type);

        $attachment = $message->messageAttachments()->first();

        $this->assertNotNull($attachment);
        // These channels give a URL, not a media id.
        $this->assertSame('https://cdn.example/scan.jpg', $attachment->source_url);
        $this->assertNull($attachment->provider_media_id);

        Queue::assertPushed(DownloadChannelMediaJob::class);
    }

    public function test_an_instagram_story_reply_reads_as_a_story_reply(): void
    {
        Http::fake();

        $this->webhook($this->envelope('instagram', 'IG_1', [[
            'sender'    => ['id' => 'IG_STORY'],
            'recipient' => ['id' => 'IG_1'],
            'timestamp' => now()->getTimestampMs(),
            'message'   => [
                'mid'      => 'm_story',
                'text'     => 'Is this true?',
                'reply_to' => ['story' => ['id' => 'story_1', 'url' => 'https://cdn.example/story.jpg']],
            ],
        ]]))->assertOk();

        $message = Message::where('provider_message_id', 'm_story')->first();

        $this->assertNotNull($message);
        $this->assertStringContainsString('Replied to your story', $message->body);
    }

    public function test_a_read_receipt_marks_everything_up_to_the_watermark(): void
    {
        Http::fake();

        $conversation = Conversation::create([
            'channel' => 'messenger', 'contact_identifier' => 'USER_READ',
            'page_id' => 'PAGE_1', 'status' => 'open',
        ]);

        $sent = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound', 'type' => 'text',
            'body' => 'Earlier reply', 'status' => 'delivered',
            'created_at' => now()->subMinutes(5),
        ]);

        $this->webhook($this->envelope('page', 'PAGE_1', [[
            'sender'    => ['id' => 'USER_READ'],
            'recipient' => ['id' => 'PAGE_1'],
            'read'      => ['watermark' => now()->getTimestampMs()],
        ]]))->assertOk();

        $this->assertSame('read', $sent->fresh()->status);
    }

    public function test_a_click_to_messenger_referral_is_attributed_to_the_thread(): void
    {
        Http::fake();

        $this->webhook($this->envelope('page', 'PAGE_1', [[
            'sender'    => ['id' => 'USER_AD'],
            'recipient' => ['id' => 'PAGE_1'],
            'timestamp' => now()->getTimestampMs(),
            'message'   => ['mid' => 'm_ad', 'text' => 'Saw your ad'],
            'referral'  => ['source' => 'ADS', 'type' => 'OPEN_THREAD', 'ad_id' => 'AD_XYZ'],
        ]]))->assertOk();

        $conversation = Conversation::where('contact_identifier', 'USER_AD')->first();

        $this->assertNotNull($conversation);
        // This is the only event that carries the attribution.
        $this->assertSame('AD_XYZ', $conversation->meta['referral']['ad_id'] ?? null);
    }

    public function test_a_postback_is_recorded_as_a_message(): void
    {
        Http::fake();

        $this->webhook($this->envelope('page', 'PAGE_1', [[
            'sender'    => ['id' => 'USER_PB'],
            'recipient' => ['id' => 'PAGE_1'],
            'timestamp' => now()->getTimestampMs(),
            'postback'  => ['mid' => 'm_pb', 'title' => 'Book a consultation', 'payload' => 'BOOK'],
        ]]))->assertOk();

        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'm_pb',
            'body'                => 'Book a consultation',
            'direction'           => 'inbound',
        ]);
    }

    /**
     * A WhatsApp payload uses `changes[]`. Both envelopes hit the same endpoint,
     * so neither may swallow the other.
     */
    public function test_the_whatsapp_envelope_still_works_alongside_the_social_one(): void
    {
        $this->webhook([
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id' => 'WABA1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'PN1'],
                        'contacts' => [['profile' => ['name' => 'WA User'], 'wa_id' => '919700554433']],
                        'messages' => [[
                            'from' => '919700554433', 'id' => 'wamid.COEXIST',
                            'type' => 'text', 'text' => ['body' => 'Still working'],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('messages', ['provider_message_id' => 'wamid.COEXIST']);
        $this->assertDatabaseHas('conversations', [
            'channel'            => 'whatsapp',
            'contact_identifier' => '919700554433',
        ]);
    }

    // =================================================================
    // Replying
    // =================================================================

    private function socialThread(string $channel, array $overrides = []): Conversation
    {
        return Conversation::create(array_merge([
            'channel'            => $channel,
            'contact_identifier' => 'USER_REPLY_' . strtoupper($channel),
            'page_id'            => 'PAGE_1',
            'status'             => 'open',
            'last_inbound_at'    => now()->subMinutes(10),
        ], $overrides));
    }

    #[DataProvider('socialChannels')]
    public function test_an_agent_can_reply(string $object, string $channel): void
    {
        app(SettingsRepository::class)->setMany([
            'access_token' => 'test-token',
            'page_id'      => 'PAGE_1',
        ], 'meta');

        Http::fake([
            '*/PAGE_1/messages' => Http::response(['message_id' => 'm_out_1', 'recipient_id' => 'USER_X']),
        ]);

        $conversation = $this->socialThread($channel);

        $this->actingAs($this->admin)
            ->postJson(route('chat.send', $conversation), ['body' => 'Yes, we do. Shall I book you in?'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('messages', [
            'conversation_id'     => $conversation->id,
            'direction'           => 'outbound',
            'provider_message_id' => 'm_out_1',
            'status'              => 'sent',
        ]);
    }

    /**
     * Between 24 hours and 7 days Meta still delivers, but only under a
     * HUMAN_AGENT tag — which is exactly the case of a clinic replying to a
     * patient question.
     */
    public function test_a_reply_after_24_hours_is_sent_as_a_human_agent_follow_up(): void
    {
        app(SettingsRepository::class)->setMany([
            'access_token' => 'test-token',
            'page_id'      => 'PAGE_1',
        ], 'meta');

        Http::fake([
            '*/PAGE_1/messages' => Http::response(['message_id' => 'm_tagged']),
        ]);

        $conversation = $this->socialThread('messenger', [
            'contact_identifier' => 'USER_TAGGED',
            'last_inbound_at'    => now()->subDays(2),
        ]);

        $this->assertTrue($conversation->needsHumanAgentTag());

        $this->actingAs($this->admin)
            ->postJson(route('chat.send', $conversation), ['body' => 'Following up on your question.'])
            ->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['messaging_type'] ?? null) === 'MESSAGE_TAG'
                && ($body['tag'] ?? null) === 'HUMAN_AGENT';
        });
    }

    public function test_a_reply_after_seven_days_is_refused_with_a_useful_reason(): void
    {
        $conversation = $this->socialThread('instagram', [
            'contact_identifier' => 'USER_STALE',
            'last_inbound_at'    => now()->subDays(9),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('chat.send', $conversation), ['body' => 'Too late'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // Nothing outbound may be recorded for a message that cannot be sent.
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'status'          => 'sent',
        ]);
    }

    public function test_replying_to_a_contact_who_never_wrote_is_refused(): void
    {
        $conversation = $this->socialThread('messenger', [
            'contact_identifier' => 'USER_SILENT',
            'last_inbound_at'    => null,
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('chat.send', $conversation), ['body' => 'Hello?'])
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);
    }

    public function test_templates_are_refused_on_social_channels(): void
    {
        $template = \App\Models\WhatsappTemplate::create([
            'name' => 'social_guard_tpl', 'language' => 'en', 'category' => 'UTILITY',
            'status' => 'APPROVED', 'body_text' => 'Hello', 'variable_count' => 0,
        ]);

        $conversation = $this->socialThread('instagram', ['contact_identifier' => 'USER_TPL']);

        $this->actingAs($this->admin)
            ->postJson(route('chat.send_template', $conversation), ['template_id' => $template->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_instagram_refuses_a_document_attachment(): void
    {
        app(SettingsRepository::class)->setMany([
            'access_token' => 'test-token', 'page_id' => 'PAGE_1',
        ], 'meta');

        $conversation = $this->socialThread('instagram', ['contact_identifier' => 'USER_DOC']);

        // Instagram Direct carries images and video only.
        $this->expectException(\App\Services\Meta\MetaException::class);

        app(MessengerService::class)->sendMedia(
            'USER_DOC', 'whatsapp/x.pdf', 'local', 'document', 'instagram', 'PAGE_1',
        );
    }

    // =================================================================
    // Inbox surface
    // =================================================================

    public function test_the_inbox_lists_all_three_channels_and_can_filter(): void
    {
        Conversation::create([
            'channel' => 'whatsapp', 'contact_identifier' => '919700990011',
            'status' => 'open', 'last_message_at' => now(),
        ]);
        Conversation::create([
            'channel' => 'messenger', 'contact_identifier' => 'FB_LIST',
            'page_id' => 'PAGE_1', 'status' => 'open', 'last_message_at' => now(),
        ]);
        Conversation::create([
            'channel' => 'instagram', 'contact_identifier' => 'IG_LIST',
            'page_id' => 'PAGE_1', 'status' => 'open', 'last_message_at' => now(),
        ]);

        $all = collect($this->actingAs($this->admin)->getJson(route('chat.conversations'))->assertOk()->json('data'));

        foreach (['919700990011', 'FB_LIST', 'IG_LIST'] as $identifier) {
            $this->assertTrue($all->contains('identifier', $identifier), "{$identifier} is missing from the inbox.");
        }

        $instagramOnly = collect(
            $this->actingAs($this->admin)
                ->getJson(route('chat.conversations', ['channel' => 'instagram']))
                ->assertOk()->json('data')
        );

        $this->assertTrue($instagramOnly->contains('identifier', 'IG_LIST'));
        $this->assertFalse($instagramOnly->contains('identifier', 'FB_LIST'));
        $this->assertTrue($instagramOnly->every(fn ($c) => $c['channel'] === 'instagram'));
    }

    public function test_a_social_thread_is_labelled_by_handle_not_by_its_psid(): void
    {
        $conversation = Conversation::create([
            'channel' => 'instagram', 'contact_identifier' => '17841400000000000',
            'contact_username' => 'priya.sharma', 'page_id' => 'IG_1', 'status' => 'open',
        ]);

        // A raw IGSID tells an agent nothing.
        $this->assertSame('@priya.sharma', $conversation->displayName());

        $unnamed = Conversation::create([
            'channel' => 'messenger', 'contact_identifier' => '99887766554433',
            'page_id' => 'PAGE_1', 'status' => 'open',
        ]);

        $this->assertSame('Messenger user', $unnamed->displayName());
    }

    public function test_a_lead_can_be_linked_to_a_social_thread_by_an_agent(): void
    {
        // A PSID carries no phone number, so the link is manual.
        $lead = Lead::create(['name' => 'Linked Lead', 'mobile' => '9700223344', 'status' => 'new']);

        $conversation = Conversation::create([
            'channel' => 'messenger', 'contact_identifier' => 'USER_LINK',
            'page_id' => 'PAGE_1', 'status' => 'open',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('chat.link', $conversation), ['lead_id' => $lead->id])
            ->assertOk();

        $this->assertSame($lead->id, $conversation->fresh()->lead_id);
    }

    public function test_the_thread_list_carries_channel_presentation(): void
    {
        Conversation::create([
            'channel' => 'instagram', 'contact_identifier' => 'IG_PRESENT',
            'page_id' => 'IG_1', 'status' => 'open', 'last_message_at' => now(),
        ]);

        $row = collect($this->actingAs($this->admin)->getJson(route('chat.conversations'))->json('data'))
            ->firstWhere('identifier', 'IG_PRESENT');

        $this->assertNotNull($row);
        $this->assertSame('Instagram', $row['channel_label']);
        $this->assertSame('bi-instagram', $row['channel_icon']);
        $this->assertTrue($row['is_social']);
        // Templates are WhatsApp-only, and the composer needs to know.
        $this->assertFalse($row['can_template']);
    }

    public function test_the_inbox_page_renders_with_the_channel_filter(): void
    {
        $this->actingAs($this->admin)
            ->get(route('chat.index'))
            ->assertOk()
            ->assertSee('channelFilters', false)
            ->assertSee('bi-instagram', false)
            ->assertSee('bi-messenger', false);
    }

    // =================================================================
    // Service behaviour
    // =================================================================

    public function test_the_conversation_service_knows_which_channels_are_social(): void
    {
        $service = app(ConversationService::class);

        $this->assertTrue($service->isSocial('messenger'));
        $this->assertTrue($service->isSocial('instagram'));
        $this->assertFalse($service->isSocial('whatsapp'));
    }

    public function test_the_window_state_explains_each_stage(): void
    {
        $messenger = app(MessengerService::class);

        $fresh = new Conversation(['channel' => 'messenger', 'last_inbound_at' => now()->subHour()]);
        $this->assertTrue($messenger->windowState($fresh)['allowed']);
        $this->assertNull($messenger->windowState($fresh)['tag']);

        $tagged = new Conversation(['channel' => 'messenger', 'last_inbound_at' => now()->subDays(3)]);
        $this->assertTrue($messenger->windowState($tagged)['allowed']);
        $this->assertSame('HUMAN_AGENT', $messenger->windowState($tagged)['tag']);

        $stale = new Conversation(['channel' => 'instagram', 'last_inbound_at' => now()->subDays(10)]);
        $this->assertFalse($messenger->windowState($stale)['allowed']);
        $this->assertStringContainsString('Instagram', $messenger->windowState($stale)['reason']);

        $never = new Conversation(['channel' => 'messenger', 'last_inbound_at' => null]);
        $this->assertFalse($messenger->windowState($never)['allowed']);
    }
}
