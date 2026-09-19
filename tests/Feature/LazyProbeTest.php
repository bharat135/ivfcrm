<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\Adset;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Message;
use App\Models\MetaLeadForm;
use App\Models\User;
use App\Models\WhatsappBroadcast;
use App\Models\WhatsappTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * N+1 guard.
 *
 * AppServiceProvider enables Model::preventLazyLoading() in the local
 * environment, and Laravel enforces it on any result set of more than one row.
 * Every list screen added by the WhatsApp/Meta integration is therefore
 * exercised here with several rows loaded, so a missing eager-load fails the
 * build instead of throwing in the developer's browser.
 */
class LazyProbeTest extends TestCase
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

        $this->seedFixtures();
    }

    /**
     * Several rows per table — a single row would not trip Laravel's guard.
     */
    private function seedFixtures(): void
    {
        $template = WhatsappTemplate::create([
            'name' => 'probe_template', 'language' => 'en', 'category' => 'UTILITY',
            'status' => 'APPROVED', 'body_text' => 'Hi {{1}}, welcome.', 'variable_count' => 1,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $lead = Lead::create([
                'name' => "Probe Lead {$i}", 'mobile' => '97000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'status' => 'new', 'assigned_to' => $this->admin->id,
            ]);

            $conversation = Conversation::create([
                'channel' => 'whatsapp',
                'contact_identifier' => '9197000000' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'lead_id' => $lead->id,
                'assigned_to' => $this->admin->id,
                'status' => 'open',
                'unread_count' => 1,
                'last_message_at' => now()->subMinutes($i),
                'window_expires_at' => now()->addHours(12),
            ]);

            foreach (['Inbound one', 'Inbound two'] as $j => $body) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'direction' => $j % 2 === 0 ? 'inbound' : 'outbound',
                    'user_id' => $j % 2 === 0 ? null : $this->admin->id,
                    'type' => 'text', 'body' => $body, 'status' => 'delivered',
                ]);
            }

            $campaign = Campaign::create([
                'name' => "Probe Campaign {$i}", 'platform' => 'meta', 'external_id' => "CMP{$i}",
                'objective' => 'OUTCOME_LEADS', 'status' => 'ACTIVE', 'daily_budget' => 1000,
            ]);

            $adset = Adset::create([
                'campaign_id' => $campaign->id, 'name' => "Probe Adset {$i}", 'external_id' => "AS{$i}",
                'status' => 'ACTIVE', 'daily_budget' => 500, 'optimization_goal' => 'LEAD_GENERATION',
                'targeting' => ['age_min' => 25, 'age_max' => 40, 'geo_locations' => ['countries' => ['IN']]],
            ]);

            $form = MetaLeadForm::create([
                'external_id' => "FORM{$i}", 'page_id' => 'PAGE1', 'name' => "Probe Form {$i}", 'status' => 'ACTIVE',
            ]);

            Ad::create([
                'adset_id' => $adset->id, 'name' => "Probe Ad {$i}", 'external_id' => "AD{$i}",
                'status' => 'ACTIVE', 'meta_lead_form_id' => $form->id,
            ]);

            LandingPage::create([
                'name' => "Probe Page {$i}", 'headline' => 'Probe', 'template' => 'clinic',
                'campaign_id' => $campaign->id, 'is_published' => true, 'published_at' => now(),
            ]);

            WhatsappBroadcast::create([
                'name' => "Probe Broadcast {$i}", 'whatsapp_template_id' => $template->id,
                'created_by' => $this->admin->id, 'audience_type' => 'leads', 'status' => 'draft',
                'total_recipients' => 10, 'sent_count' => 5,
            ]);
        }
    }

    public function test_the_guard_is_active_for_multi_row_results(): void
    {
        $this->assertTrue(Model::preventsLazyLoading(), 'preventLazyLoading is not active.');

        // Proof the guard actually bites: iterate >1 row and touch an unloaded relation.
        $this->expectException(\Illuminate\Database\LazyLoadingViolationException::class);

        foreach (Conversation::where('channel', 'whatsapp')->limit(3)->get() as $conversation) {
            $conversation->lead?->name;
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('listScreens')]
    public function test_list_screens_load_their_relations(string $route, array $query = []): void
    {
        $this->actingAs($this->admin)
            ->get(route($route, $query))
            ->assertOk();
    }

    public static function listScreens(): array
    {
        return [
            'inbox'             => ['chat.index'],
            'campaign list'     => ['marketing.campaigns.index'],
            'adset list'        => ['marketing.adsets.index'],
            'ad list'           => ['marketing.ads.index'],
            'lead form list'    => ['marketing.leadforms.index'],
            'template list'     => ['marketing.templates.index'],
            'broadcast list'    => ['marketing.broadcasts.index'],
            'landing page list' => ['marketing.landing.index'],
            'analytics'         => ['marketing.analytics.index'],
            'funnel'            => ['marketing.analytics.funnel'],
            'integrations'      => ['settings.integrations.index'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dataTableEndpoints')]
    public function test_datatable_json_endpoints_load_their_relations(string $route): void
    {
        // The controllers branch on $request->ajax(), which keys off
        // X-Requested-With — exactly what jQuery DataTables sends.
        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route($route, ['draw' => 1, 'start' => 0, 'length' => 25]));

        $response->assertOk();

        // Yajra swallows exceptions into an `error` key, so assert on that too.
        $this->assertNull(
            $response->json('error'),
            "DataTable [{$route}] returned an error: " . $response->json('error'),
        );
    }

    public static function dataTableEndpoints(): array
    {
        return [
            'campaigns'     => ['marketing.campaigns.index'],
            'adsets'        => ['marketing.adsets.index'],
            'ads'           => ['marketing.ads.index'],
            'lead forms'    => ['marketing.leadforms.index'],
            'templates'     => ['marketing.templates.index'],
            'broadcasts'    => ['marketing.broadcasts.index'],
            'landing pages' => ['marketing.landing.index'],
        ];
    }

    public function test_conversation_list_and_thread_json(): void
    {
        // Assert on this test's own fixtures rather than a global count: the
        // database is shared, so any real conversation would break an exact count.
        $listed = collect(
            $this->actingAs($this->admin)->getJson(route('chat.conversations'))->assertOk()->json('data')
        )->pluck('identifier');

        foreach (['9197000000 01', '9197000000 02', '9197000000 03'] as $identifier) {
            $this->assertTrue(
                $listed->contains(str_replace(' ', '', $identifier)),
                "Seeded conversation {$identifier} is missing from the inbox list.",
            );
        }

        $conversation = Conversation::where('contact_identifier', 'like', '91970000000%')->first();

        $this->actingAs($this->admin)
            ->getJson(route('chat.show', $conversation))
            ->assertOk()
            ->assertJsonCount(2, 'messages');
    }

    public function test_campaign_and_adset_detail_pages(): void
    {
        $campaign = Campaign::where('name', 'like', 'Probe Campaign%')->first();
        $adset    = Adset::where('name', 'like', 'Probe Adset%')->first();

        $this->actingAs($this->admin)->get(route('marketing.campaigns.show', $campaign))->assertOk();
        $this->actingAs($this->admin)->get(route('marketing.adsets.show', $adset))->assertOk();
    }

    public function test_analytics_json_endpoints(): void
    {
        foreach (['trend', 'landing_pages'] as $endpoint) {
            $this->actingAs($this->admin)
                ->getJson(route("marketing.analytics.{$endpoint}"))
                ->assertOk();
        }

        foreach (['campaign', 'adset', 'ad'] as $level) {
            $this->actingAs($this->admin)
                ->getJson(route('marketing.analytics.breakdown', ['level' => $level]))
                ->assertOk();
        }
    }

    public function test_lead_and_patient_detail_pages_with_the_whatsapp_tab(): void
    {
        $lead = Lead::where('name', 'like', 'Probe Lead%')->first();

        $this->actingAs($this->admin)->get(route('leads.show', $lead))->assertOk();

        $patient = \App\Models\Patient::first();

        if ($patient) {
            $this->actingAs($this->admin)->get(route('patients.show', $patient))->assertOk();
        }
    }
}
