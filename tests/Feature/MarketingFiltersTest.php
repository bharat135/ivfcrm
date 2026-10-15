<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Center;
use App\Models\Lead;
use App\Models\MetaLeadForm;
use App\Models\User;
use App\Services\Meta\MetaSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

class MarketingFiltersTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first();
        $this->assertNotNull($this->admin, 'Seed the database first: php artisan db:seed');
    }

    // ---------------------------------------------------------------- Meta import floor

    public function test_meta_submissions_before_the_floor_are_not_imported(): void
    {
        config(['meta.leads.ignore_before' => '2026-10-14 18:00:00']);

        $sync = app(MetaSyncService::class);

        $payload = fn (string $id, string $time) => [
            'id'           => $id,
            'created_time' => $time,
            'form_id'      => 'form-x',
            'field_data'   => [
                ['name' => 'full_name', 'values' => ['Floor Test ' . $id]],
                ['name' => 'phone_number', 'values' => ['+919876501234']],
            ],
        ];

        // 17:59 IST on 14 Oct is 12:29 UTC: older than the floor, skipped.
        $this->assertFalse($sync->importLead($payload('old-1', '2026-10-14T12:29:00+0000')));
        $this->assertDatabaseMissing('leads', ['external_lead_id' => 'old-1']);

        // 18:01 IST is just after the floor: imported.
        $this->assertTrue($sync->importLead($payload('new-1', '2026-10-14T12:31:00+0000')));
        $this->assertDatabaseHas('leads', ['external_lead_id' => 'new-1']);

        // No floor configured: everything comes in.
        config(['meta.leads.ignore_before' => null]);
        $this->assertTrue($sync->importLead($payload('old-2', '2026-10-01T05:00:00+0000')));
    }

    // ---------------------------------------------------------------- list filters

    public function test_instant_forms_can_be_filtered_by_status_centre_and_routing(): void
    {
        $center = Center::where('is_active', true)->first();

        MetaLeadForm::create(['external_id' => 'f-active-routed', 'name' => 'Routed form', 'status' => 'ACTIVE', 'center_id' => $center->id, 'auto_sync' => true]);
        MetaLeadForm::create(['external_id' => 'f-active-loose', 'name' => 'Loose form', 'status' => 'ACTIVE', 'auto_sync' => false]);
        MetaLeadForm::create(['external_id' => 'f-archived', 'name' => 'Old form', 'status' => 'ARCHIVED', 'auto_sync' => true]);

        $names = fn (array $filters) => collect($this->actingAs($this->admin)
            ->getJson(route('marketing.leadforms.index', $filters + ['draw' => 1, 'start' => 0, 'length' => 100]))
            ->assertOk()->json('data'))->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Routed form', 'Loose form', 'Old form'], $names([]));
        $this->assertEqualsCanonicalizing(['Routed form', 'Loose form'], $names(['status' => 'ACTIVE']));
        $this->assertSame(['Routed form'], $names(['center_id' => $center->id]));
        $this->assertSame(['Routed form'], $names(['routing' => 'routed']));
        $this->assertEqualsCanonicalizing(['Loose form', 'Old form'], $names(['routing' => 'unrouted']));
        $this->assertSame(['Loose form'], $names(['auto_sync' => '0']));
    }

    public function test_campaigns_can_be_filtered_by_objective_and_platform(): void
    {
        Campaign::create(['name' => 'Leads campaign', 'objective' => 'OUTCOME_LEADS', 'platform' => 'meta', 'status' => 'ACTIVE']);
        Campaign::create(['name' => 'Reach campaign', 'objective' => 'OUTCOME_AWARENESS', 'platform' => 'meta', 'status' => 'PAUSED']);
        Campaign::create(['name' => 'Search campaign', 'objective' => 'OUTCOME_LEADS', 'platform' => 'google', 'status' => 'ACTIVE']);

        $names = fn (array $filters) => collect($this->actingAs($this->admin)
            ->getJson(route('marketing.campaigns.index', $filters + ['draw' => 1, 'start' => 0, 'length' => 100]))
            ->assertOk()->json('data'))->pluck('name')->all();

        // The project database may hold other campaigns: assert membership, not the full list.
        $byObjective = $names(['objective' => 'OUTCOME_LEADS']);
        $this->assertContains('Leads campaign', $byObjective);
        $this->assertContains('Search campaign', $byObjective);
        $this->assertNotContains('Reach campaign', $byObjective);

        $byPlatform = $names(['platform' => 'google']);
        $this->assertContains('Search campaign', $byPlatform);
        $this->assertNotContains('Leads campaign', $byPlatform);

        $combined = $names(['objective' => 'OUTCOME_LEADS', 'platform' => 'meta', 'status' => 'ACTIVE']);
        $this->assertContains('Leads campaign', $combined);
        $this->assertNotContains('Search campaign', $combined);
        $this->assertNotContains('Reach campaign', $combined);
    }

    public function test_analytics_breakdown_respects_the_campaign_filter(): void
    {
        $a = Campaign::create(['name' => 'Campaign A', 'objective' => 'OUTCOME_LEADS', 'platform' => 'meta', 'status' => 'ACTIVE']);
        $b = Campaign::create(['name' => 'Campaign B', 'objective' => 'OUTCOME_LEADS', 'platform' => 'meta', 'status' => 'ACTIVE']);

        foreach ([$a, $b] as $campaign) {
            \App\Models\MetaInsight::create([
                'level' => 'campaign', 'entity_id' => $campaign->id, 'external_id' => 'x' . $campaign->id,
                'date' => now()->toDateString(), 'spend' => 100, 'impressions' => 1000, 'clicks' => 10, 'reach' => 900, 'leads' => 0,
            ]);
        }

        Lead::create(['name' => 'Lead one', 'mobile' => '9876540001', 'campaign_id' => $a->id]);
        Lead::create(['name' => 'Lead two', 'mobile' => '9876540002', 'campaign_id' => $a->id]);

        $rows = collect($this->actingAs($this->admin)
            ->getJson(route('marketing.analytics.breakdown', ['campaign_id' => $a->id, 'level' => 'campaign']))
            ->assertOk()->json('data'));

        $this->assertSame(['Campaign A'], $rows->pluck('name')->all());
        $this->assertSame(2, $rows->first()['leads']);

        // Without the filter both campaigns are listed.
        $all = collect($this->actingAs($this->admin)
            ->getJson(route('marketing.analytics.breakdown', ['level' => 'campaign']))
            ->assertOk()->json('data'));

        $this->assertContains('Campaign A', $all->pluck('name')->all());
        $this->assertContains('Campaign B', $all->pluck('name')->all());
    }
}
