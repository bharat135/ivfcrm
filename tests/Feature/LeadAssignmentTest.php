<?php

namespace Tests\Feature;

use App\Jobs\SyncMetaLeadFormsJob;
use App\Models\AssignmentRule;
use App\Models\Center;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\MetaLeadForm;
use App\Models\User;
use App\Services\AssignmentEngine;
use App\Services\LeadService;
use App\Services\Meta\MetaClient;
use App\Services\Meta\MetaSyncService;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Who may create a lead, who ends up owning it, and how Lead Ads submissions
 * reach the CRM (page subscription + polling backstop).
 *
 * Runs against the real MySQL schema inside a transaction, like CrmSuiteTest.
 */
class LeadAssignmentTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $telecaller;
    private User $colleague;

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
        $this->assertNotNull($this->admin, 'Run `php artisan db:seed` before this suite.');

        $centerId = $this->admin->center_id ?: Center::value('id');

        $this->telecaller = $this->telecaller('Assignment Telecaller', $centerId);
        $this->colleague  = $this->telecaller('Assignment Colleague', $centerId);

        AssignmentEngine::forgetCache();
        app(SettingsRepository::class)->set('assignment', 'fallback', AssignmentEngine::DEFAULT_FALLBACK);
    }

    private function telecaller(string $name, ?int $centerId): User
    {
        $user = User::factory()->create([
            'name'         => $name,
            'is_active'    => true,
            'is_available' => true,
            'center_id'    => $centerId,
        ]);
        $user->assignRole('Telecaller');

        return $user;
    }

    private function mobile(): string
    {
        return '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    // =================================================================
    // Telecallers creating leads
    // =================================================================

    public function test_telecaller_can_open_the_new_lead_form(): void
    {
        $this->actingAs($this->telecaller)
            ->get(route('leads.create'))
            ->assertOk()
            ->assertSee('Assign to me');
    }

    public function test_telecaller_created_lead_belongs_to_them_and_opens_without_403(): void
    {
        $mobile = $this->mobile();

        $response = $this->actingAs($this->telecaller)->post(route('leads.store'), [
            'name'   => 'Walk-in Couple',
            'mobile' => $mobile,
        ]);

        $lead = Lead::where('mobile', $mobile)->first();
        $this->assertNotNull($lead);
        $this->assertSame($this->telecaller->id, $lead->assigned_to);
        $this->assertNotNull($lead->assigned_at);

        $response->assertRedirect(route('leads.show', $lead));

        $this->actingAs($this->telecaller)->get(route('leads.show', $lead))->assertOk();

        $this->assertDatabaseHas('lead_assignments', [
            'lead_id'     => $lead->id,
            'assigned_to' => $this->telecaller->id,
            'method'      => 'manual',
        ]);
    }

    public function test_duplicate_owned_by_a_colleague_redirects_with_a_warning_instead_of_403(): void
    {
        $mobile = $this->mobile();

        app(LeadService::class)->create([
            'name'        => 'Existing Couple',
            'mobile'      => $mobile,
            'assigned_to' => $this->colleague->id,
        ]);

        $this->actingAs($this->telecaller)
            ->post(route('leads.store'), ['name' => 'Same Person', 'mobile' => $mobile])
            ->assertRedirect(route('leads.index'))
            ->assertSessionHas('warning');

        $this->assertSame(1, Lead::where('mobile', $mobile)->count());
    }

    public function test_lead_creation_still_requires_the_permission(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->assignRole('Viewer');

        $this->actingAs($viewer)->get(route('leads.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('leads.store'), ['mobile' => $this->mobile()])->assertForbidden();
    }

    // =================================================================
    // Assignment rules screen + engine
    // =================================================================

    public function test_assignment_rules_page_renders_for_admin_and_is_hidden_from_telecallers(): void
    {
        $this->actingAs($this->admin)->get(route('settings.assignment_rules.index'))
            ->assertOk()
            ->assertSee('Lead Assignment')
            ->assertSee('When no rule matches');

        $this->actingAs($this->telecaller)->get(route('settings.assignment_rules.index'))->assertForbidden();
    }

    public function test_rule_created_from_the_screen_routes_matching_leads(): void
    {
        $source = LeadSource::first();
        $this->assertNotNull($source, 'Seed lead sources first.');

        $this->actingAs($this->admin)->postJson(route('settings.assignment_rules.store'), [
            'name'       => 'Source → colleague',
            'priority'   => 1,
            'method'     => 'round_robin',
            'is_active'  => 1,
            'conditions' => ['lead_source_id' => $source->id, 'center_id' => ''],
            'user_ids'   => [$this->colleague->id],
        ])->assertOk()->assertJson(['success' => true]);

        $rule = AssignmentRule::where('name', 'Source → colleague')->first();
        $this->assertNotNull($rule);
        $this->assertSame(['lead_source_id' => $source->id], $rule->conditions);
        $this->assertSame([$this->colleague->id], $rule->userIds());

        $lead = app(LeadService::class)->create([
            'name'           => 'Routed Couple',
            'mobile'         => $this->mobile(),
            'lead_source_id' => $source->id,
        ]);

        $this->assertSame($this->colleague->id, $lead->assigned_to);
        $this->assertSame(1, $rule->fresh()->leads_count);

        // The listing renders the rule with its resolved labels.
        $this->actingAs($this->admin)
            ->getJson(route('settings.assignment_rules.index'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Source → colleague']);
    }

    public function test_rule_validation_requires_at_least_one_agent(): void
    {
        $this->actingAs($this->admin)->postJson(route('settings.assignment_rules.store'), [
            'name'     => 'Nobody',
            'priority' => 5,
            'method'   => 'round_robin',
            'user_ids' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['user_ids']);
    }

    public function test_fallback_none_leaves_unmatched_leads_unassigned(): void
    {
        AssignmentRule::query()->update(['is_active' => false]);
        AssignmentEngine::forgetCache();

        $this->actingAs($this->admin)
            ->putJson(route('settings.assignment_rules.fallback'), ['fallback' => 'none'])
            ->assertOk();

        $lead = app(LeadService::class)->create(['name' => 'Unrouted', 'mobile' => $this->mobile()]);

        $this->assertNull($lead->assigned_to);

        $this->actingAs($this->admin)
            ->putJson(route('settings.assignment_rules.fallback'), ['fallback' => 'bogus'])
            ->assertStatus(422);
    }

    public function test_engine_condition_matching_covers_channel_and_pincode_prefix(): void
    {
        $engine = app(AssignmentEngine::class);
        $lead   = new Lead(['channel' => 'meta', 'pincode' => '400071', 'center_id' => 2]);

        $this->assertTrue($engine->matchesConditions($lead, ['channel' => 'meta', 'pincode_prefix' => '400']));
        $this->assertTrue($engine->matchesConditions($lead, ['center_id' => '', 'state_id' => null]));
        $this->assertFalse($engine->matchesConditions($lead, ['pincode_prefix' => '411']));
        $this->assertFalse($engine->matchesConditions($lead, ['channel' => 'google']));
        $this->assertFalse($engine->matchesConditions($lead, ['center_id' => 3]));
    }

    // =================================================================
    // Meta Lead Ads delivery
    // =================================================================

    public function test_subscribe_page_registers_the_leadgen_field_with_the_page_token(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('meta', 'access_token', 'user-token');
        $settings->set('meta', 'page_id', '111222333');
        $settings->set('meta', 'page_tokens', ['111222333' => 'page-token']);

        Http::fake([
            'graph.facebook.com/*/111222333/subscribed_apps' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('settings.integrations.subscribe_page'))
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '111222333/subscribed_apps')
                && $request['subscribed_fields'] === 'leadgen'
                && $request->hasHeader('Authorization', 'Bearer page-token');
        });
    }

    public function test_subscribe_page_explains_when_no_page_token_is_saved(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('meta', 'access_token', 'user-token');
        $settings->set('meta', 'page_id', '111222333');
        $settings->set('meta', 'page_tokens', []);

        Http::fake();

        $this->actingAs($this->admin)
            ->postJson(route('settings.integrations.subscribe_page'))
            ->assertStatus(422)
            ->assertJsonFragment(['success' => false]);

        Http::assertNothingSent();
    }

    public function test_scheduled_poll_imports_lead_ads_submissions_the_webhook_missed(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('meta', 'access_token', 'user-token');
        $settings->set('meta', 'page_id', '111222333');
        $settings->set('meta', 'page_tokens', ['111222333' => 'page-token']);

        $form = MetaLeadForm::create([
            'external_id'    => 'form-' . random_int(1000, 9999),
            'page_id'        => '111222333',
            'name'           => 'Poll test form',
            'status'         => 'ACTIVE',
            'auto_sync'      => true,
            'assign_to'      => $this->colleague->id,
            'last_synced_at' => now()->subHour(),
        ]);

        $mobile   = $this->mobile();
        $remoteId = 'lg-' . random_int(100000, 999999);

        Http::fake([
            'graph.facebook.com/*/' . $form->external_id . '/leads*' => Http::response([
                'data' => [[
                    'id'           => $remoteId,
                    'created_time' => now()->toIso8601String(),
                    'form_id'      => $form->external_id,
                    'field_data'   => [
                        ['name' => 'full_name',    'values' => ['Poll Couple']],
                        ['name' => 'phone_number', 'values' => ['+91' . $mobile]],
                    ],
                ]],
            ]),
        ]);

        (new SyncMetaLeadFormsJob)->handle(app(MetaSyncService::class), app(MetaClient::class));

        $lead = Lead::where('external_lead_id', $remoteId)->first();
        $this->assertNotNull($lead, 'The poll should have created the lead.');
        $this->assertSame($this->colleague->id, $lead->assigned_to);
        $this->assertSame(1, $form->fresh()->leads_count);

        // Running again re-reads the same window and must not duplicate.
        (new SyncMetaLeadFormsJob)->handle(app(MetaSyncService::class), app(MetaClient::class));
        $this->assertSame(1, Lead::where('external_lead_id', $remoteId)->count());
    }
}
