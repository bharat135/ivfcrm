<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Center;
use App\Models\Doctor;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * A number dialled by hand from the call console always lands on a lead, and
 * the Analytics page counts the leads created in one date range.
 */
class CrmAnalyticsAndManualDialTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private function user(string $role, ?Center $center = null): User
    {
        $user = User::factory()->create(['is_active' => true, 'center_id' => $center?->id]);
        $user->assignRole($role);

        return $user;
    }

    public function test_dialling_an_unknown_number_creates_a_lead_owned_by_the_caller(): void
    {
        $center = Center::create(['name' => 'Dial Centre', 'code' => 'DC' . random_int(100, 999)]);
        $agent  = $this->user('Telecaller', $center);
        $mobile = '97' . random_int(10000000, 99999999);

        $res = $this->actingAs($agent)->postJson(route('leads.for_number'), ['mobile' => '+91 ' . $mobile])
            ->assertOk()->assertJson(['success' => true, 'created' => true, 'can_view' => true]);

        $lead = Lead::find($res->json('lead_id'));
        $this->assertSame($mobile, $lead->mobile);
        $this->assertSame($agent->id, $lead->assigned_to);
        $this->assertSame($center->id, $lead->center_id);
        $this->assertSame('manual', $lead->channel);
        $this->assertNotNull($lead->patient_id);
        $this->assertSame(route('leads.show', $lead), $res->json('url'));

        // The same number again is the same lead, not a second one.
        $again = $this->actingAs($agent)->postJson(route('leads.for_number'), ['mobile' => $mobile])
            ->assertOk()->assertJson(['created' => false, 'lead_id' => $lead->id]);
        $this->assertSame($lead->id, $again->json('lead_id'));

        $this->actingAs($agent)->postJson(route('leads.for_number'), ['mobile' => '12345'])->assertUnprocessable();
    }

    public function test_dialling_a_colleagues_lead_returns_it_without_opening_it(): void
    {
        $owner  = $this->user('Telecaller');
        $caller = $this->user('Telecaller');
        $lead   = app(LeadService::class)->create(['name' => 'Someone Else', 'mobile' => '98' . random_int(10000000, 99999999), 'assigned_to' => $owner->id], autoAssign: false);

        $this->actingAs($caller)->postJson(route('leads.for_number'), ['mobile' => $lead->mobile])
            ->assertOk()->assertJson(['created' => false, 'lead_id' => $lead->id, 'can_view' => false, 'owner' => $owner->name]);

        $this->assertSame(1, Lead::where('mobile', $lead->mobile)->count());
    }

    public function test_analytics_counts_the_leads_created_in_the_range_and_follows_the_filters(): void
    {
        Cache::flush();

        $center = Center::create(['name' => 'Analytics Centre', 'code' => 'AC' . random_int(100, 999)]);
        $other  = Center::create(['name' => 'Other Centre', 'code' => 'OC' . random_int(100, 999)]);
        $doctor = Doctor::create(['name' => 'Dr Chart', 'code' => 'DCH' . random_int(1000, 9999), 'center_id' => $center->id, 'is_active' => true]);
        $admin  = $this->user('Admin');
        $agent  = $this->user('Telecaller', $center);

        $make = fn (array $extra) => app(LeadService::class)->create(array_merge([
            'name' => 'Chart Lead', 'mobile' => '96' . random_int(10000000, 99999999),
            'center_id' => $center->id, 'assigned_to' => $agent->id,
        ], $extra), autoAssign: false);

        $visited = $make([]);
        $booked  = $make([]);
        $plain   = $make(['center_id' => $other->id]);
        $old     = $make([]);
        $old->forceFill(['created_at' => now()->subDays(60)])->saveQuietly();

        foreach ([$visited, $booked] as $lead) {
            Appointment::create([
                'lead_id' => $lead->id, 'doctor_id' => $doctor->id, 'center_id' => $center->id, 'created_by' => $agent->id,
                'scheduled_at' => now()->subHour(), 'type' => 'consultation', 'status' => $lead->is($visited) ? 'visited' : 'booked',
            ]);
        }

        $range = ['from' => today()->subDays(6)->toDateString(), 'to' => today()->toDateString()];

        $this->actingAs($admin)->get(route('analytics.index', $range))->assertOk()
            ->assertSee('Conversion funnel')->assertSee('When leads arrive')->assertSee('All agents');

        $data = $this->actingAs($admin)->getJson(route('analytics.data', $range + ['center_id' => $center->id]))->assertOk()->json();

        $kpi = collect($data['kpis'])->keyBy('key');
        $this->assertSame(2, $kpi['leads']['value']);      // the old lead and the other centre are out
        $this->assertSame(2, $kpi['booked']['value']);
        $this->assertSame(1, $kpi['visited']['value']);

        $funnel = collect($data['funnel'])->keyBy('key');
        $this->assertSame(2, $funnel['leads']['count']);
        $this->assertSame(1, $funnel['visited']['count']);
        $this->assertSame(50.0, (float) $funnel['visited']['rate']);

        $top = collect($data['agents'])->firstWhere('user_id', $agent->id);
        $this->assertSame(2, $top['leads']);
        $this->assertSame(1, $top['visited']);

        $this->assertCount(7, $data['heatmap']);
        $this->assertSame(2, array_sum(array_map(fn ($d) => array_sum($d['hours']), $data['heatmap'])));
        $this->assertSame(7, count($data['trend']['labels']));
        $this->assertSame(2, array_sum($data['trend']['series']['leads']));
        $this->assertSame(['Analytics Centre'], array_column($data['centers'], 'label'));

        // Without the centre filter the other centre's lead joins in; the agent filter keeps it to theirs.
        $all = $this->actingAs($admin)->getJson(route('analytics.data', $range))->assertOk()->json();
        $this->assertGreaterThanOrEqual(3, collect($all['kpis'])->firstWhere('key', 'leads')['value']);

        $mine = $this->actingAs($admin)->getJson(route('analytics.data', $range + ['assigned_to' => $agent->id]))->assertOk()->json();
        $this->assertSame(3, collect($mine['kpis'])->firstWhere('key', 'leads')['value']);

        // A centre manager only ever sees their centre's leads, whatever the filters say.
        $manager = $this->user('Center Manager', $other);
        $theirs = $this->actingAs($manager)->getJson(route('analytics.data', $range + ['center_id' => $center->id]))->assertOk()->json();
        $this->assertSame(0, collect($theirs['kpis'])->firstWhere('key', 'leads')['value']);
        $theirs = $this->actingAs($manager)->getJson(route('analytics.data', $range))->assertOk()->json();
        $this->assertSame(1, collect($theirs['kpis'])->firstWhere('key', 'leads')['value']);

        // No reports permission, no Analytics page; the data still feeds the
        // agent's dashboard, counting only the leads that are theirs.
        $this->actingAs($agent)->get(route('analytics.index', $range))->assertForbidden();
        $own = $this->actingAs($agent)->getJson(route('analytics.data', $range + ['center_id' => $other->id]))->assertOk()->json();
        $this->assertSame(1, collect($own['kpis'])->firstWhere('key', 'leads')['value']);
        $this->assertSame(0, collect($this->actingAs($this->user('Telecaller', $center))->getJson(route('analytics.data', $range))->json('kpis'))->firstWhere('key', 'leads')['value']);
    }
}
