<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadSourceTouch;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * Multi-touch attribution: a lead keeps every channel it has enquired through,
 * and the leads list surfaces sub-source, campaign, and the assigned/added
 * times.
 */
class LeadAttributionTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectDatabase;

    private function service(): LeadService
    {
        return app(LeadService::class);
    }

    private function mobile(): string
    {
        return '9' . random_int(100000000, 999999999);
    }

    public function test_creating_a_lead_records_its_first_source_touch(): void
    {
        $lead = $this->service()->create([
            'name'       => 'First Touch',
            'mobile'     => $this->mobile(),
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'gclid'      => 'GCLID_FIRST',
        ], autoAssign: false);

        $touches = $lead->sourceTouches()->get();
        $this->assertCount(1, $touches);
        $this->assertTrue($touches->first()->is_first);
        $this->assertSame('google_ads', $touches->first()->channel);
    }

    public function test_a_repeat_enquiry_from_another_channel_is_tracked_not_lost(): void
    {
        $mobile = $this->mobile();

        // First: Meta.
        $lead = $this->service()->create([
            'name'             => 'Return Visitor',
            'mobile'           => $mobile,
            'external_lead_id' => 'FB_LEAD_1',
        ], autoAssign: false);

        $this->assertSame('meta_ads', $lead->channel);

        // Later: the same person comes back through Google.
        $again = $this->service()->create([
            'name'   => 'Return Visitor',
            'mobile' => $mobile,
            'gclid'  => 'GCLID_RETURN',
        ], autoAssign: false);

        // Same lead, not a duplicate.
        $this->assertSame($lead->id, $again->id);

        $touches = LeadSourceTouch::where('lead_id', $lead->id)->orderBy('occurred_at')->get();
        $this->assertCount(2, $touches);
        $this->assertSame('meta_ads', $touches[0]->channel);
        $this->assertSame('google_ads', $touches[1]->channel);
        $this->assertFalse($touches[1]->is_first);

        // The lead's own channel keeps the FIRST attribution.
        $this->assertSame('meta_ads', $lead->fresh()->channel);
    }

    public function test_an_identical_repeat_enquiry_does_not_add_a_duplicate_touch(): void
    {
        $mobile = $this->mobile();
        $payload = ['name' => 'Same Source', 'mobile' => $mobile, 'gclid' => 'GCLID_SAME'];

        $lead = $this->service()->create($payload, autoAssign: false);
        $this->service()->create($payload, autoAssign: false);

        $this->assertCount(1, LeadSourceTouch::where('lead_id', $lead->id)->get());
    }

    public function test_the_leads_list_returns_sub_source_campaign_and_assigned_time(): void
    {
        $admin    = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first();
        $source   = LeadSource::firstOrCreate(['slug' => 'attr-test-source'], ['name' => 'Attr Test Source', 'channel' => 'manual', 'is_active' => true]);
        $campaign = Campaign::create(['name' => 'Attr Test Campaign', 'is_active' => true]);

        $lead = Lead::create([
            'name' => 'Column Lead', 'mobile' => $this->mobile(), 'status' => 'new',
            'lead_source_id' => $source->id, 'campaign_id' => $campaign->id,
            'assigned_to' => $admin->id, 'assigned_at' => now(),
        ]);

        $columns = [];
        foreach (['', 'name', 'mobile', 'origin', 'sub_source', 'campaign_name', 'stage_badge', 'disposition_badge', 'assigned_to.name', 'assigned_at', 'center.code', 'score_band', 'next_follow_up_at', 'created_at', 'action'] as $i => $c) {
            $columns[] = ['data' => $c, 'name' => $c === 'origin' ? 'channel' : $c, 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']];
        }

        $row = collect($this->actingAs($admin)
            ->getJson(route('leads.index', ['draw' => 1, 'start' => 0, 'length' => 50, 'columns' => $columns, 'order' => [['column' => 13, 'dir' => 'desc']], 'search' => ['value' => 'Column Lead', 'regex' => 'false']]))
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $lead->id);

        $this->assertSame('Attr Test Source', $row['sub_source']);
        $this->assertSame('Attr Test Campaign', $row['campaign_name']);
        $this->assertNotNull($row['assigned_at']);
    }
}
