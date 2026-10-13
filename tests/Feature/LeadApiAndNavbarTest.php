<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * The public lead intake API, the token manager, and the navbar quick-search
 * and assigned-leads tray.
 */
class LeadApiAndNavbarTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectDatabase;

    private function admin(): User
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
    }

    private function mobile(): string
    {
        return '9' . random_int(100000000, 999999999);
    }

    // -----------------------------------------------------------------
    // Intake API
    // -----------------------------------------------------------------

    public function test_the_api_creates_a_lead_with_a_token(): void
    {
        Sanctum::actingAs($this->admin());

        $mobile = $this->mobile();

        $this->postJson('/api/v1/leads', [
            'name'       => 'API Lead',
            'mobile'     => '+91 ' . substr($mobile, 0, 5) . ' ' . substr($mobile, 5),
            'email'      => 'api@example.com',
            'utm_source' => 'website',
            'remark'     => 'From the site form',
        ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('deduped', false)
            ->assertJsonPath('data.mobile', $mobile);

        $this->assertDatabaseHas('leads', ['name' => 'API Lead', 'mobile' => $mobile]);
    }

    public function test_the_api_dedupes_a_repeat_mobile_and_reports_it(): void
    {
        Sanctum::actingAs($this->admin());
        $mobile = $this->mobile();

        $this->postJson('/api/v1/leads', ['name' => 'First', 'mobile' => $mobile])->assertStatus(201);

        $this->postJson('/api/v1/leads', ['name' => 'Second', 'mobile' => $mobile, 'utm_source' => 'google'])
            ->assertStatus(200)
            ->assertJsonPath('deduped', true);
    }

    public function test_the_api_rejects_a_non_indian_mobile_and_requires_auth(): void
    {
        $this->postJson('/api/v1/leads', ['mobile' => $this->mobile()])->assertStatus(401);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/v1/leads', ['mobile' => '02212345678'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mobile');
    }

    public function test_the_api_lists_and_shows_leads(): void
    {
        Sanctum::actingAs($this->admin());
        $mobile = $this->mobile();
        $lead = Lead::create(['name' => 'Listable', 'mobile' => $mobile, 'status' => 'new']);

        $this->getJson('/api/v1/leads?mobile=' . $mobile)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $lead->id);

        $this->getJson('/api/v1/leads/' . $lead->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Listable');
    }

    // -----------------------------------------------------------------
    // Token manager + docs
    // -----------------------------------------------------------------

    public function test_an_admin_can_open_the_docs_and_mint_a_token(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('developers.index'))->assertOk()->assertSee('API reference');

        $token = $this->actingAs($admin)
            ->postJson(route('developers.tokens.store'), ['name' => 'Website form'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('token');

        $this->assertNotEmpty($token);
        $this->assertStringContainsString('|', $token, 'A Sanctum token carries its id and secret.');
    }

    public function test_a_telecaller_cannot_open_the_docs(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole('Telecaller');

        $this->actingAs($agent)->get(route('developers.index'))->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Navbar
    // -----------------------------------------------------------------

    public function test_quick_search_finds_a_lead_or_offers_to_add_one(): void
    {
        $admin = $this->admin();
        $mobile = $this->mobile();
        $lead = Lead::create(['name' => 'Findable', 'mobile' => $mobile, 'status' => 'new']);

        $this->actingAs($admin)
            ->getJson(route('leads.quick_search', ['mobile' => '+91 ' . $mobile]))
            ->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('lead.id', $lead->id);

        $this->actingAs($admin)
            ->getJson(route('leads.quick_search', ['mobile' => '9000000123']))
            ->assertOk()
            ->assertJsonPath('found', false)
            ->assertJsonPath('can_create', true);
    }

    public function test_the_assigned_tray_is_scoped_by_role(): void
    {
        $manager = $this->admin();

        $tele = User::factory()->create(['is_active' => true, 'center_id' => $manager->center_id]);
        $tele->assignRole('Telecaller');
        $other = User::factory()->create(['is_active' => true, 'center_id' => $manager->center_id]);
        $other->assignRole('Telecaller');

        $mine = Lead::create(['name' => 'Mine New', 'mobile' => $this->mobile(), 'status' => 'new', 'contact_attempts' => 0, 'assigned_to' => $tele->id, 'assigned_at' => now(), 'center_id' => $manager->center_id]);
        $theirs = Lead::create(['name' => 'Theirs New', 'mobile' => $this->mobile(), 'status' => 'new', 'contact_attempts' => 0, 'assigned_to' => $other->id, 'assigned_at' => now(), 'center_id' => $manager->center_id]);

        // The telecaller sees only their own.
        $mineTray = $this->actingAs($tele)->getJson(route('leads.assigned_tray'))->assertOk();
        $mineIds = collect($mineTray->json('leads'))->pluck('id');
        $this->assertTrue($mineIds->contains($mine->id));
        $this->assertFalse($mineIds->contains($theirs->id));
        $this->assertFalse($mineTray->json('is_admin'));

        // An admin sees both, tagged with the owner's name.
        $adminTray = $this->actingAs($manager)->getJson(route('leads.assigned_tray'))->assertOk();
        $this->assertTrue($adminTray->json('is_admin'));
        $adminIds = collect($adminTray->json('leads'))->pluck('id');
        $this->assertTrue($adminIds->contains($mine->id));
        $this->assertTrue($adminIds->contains($theirs->id));
        $this->assertNotNull(collect($adminTray->json('leads'))->firstWhere('id', $mine->id)['owner']);
    }

    public function test_a_called_lead_drops_off_the_tray(): void
    {
        $admin = $this->admin();
        $tele = User::factory()->create(['is_active' => true, 'center_id' => $admin->center_id]);
        $tele->assignRole('Telecaller');

        $called = Lead::create(['name' => 'Already Called', 'mobile' => $this->mobile(), 'status' => 'new', 'contact_attempts' => 1, 'first_contacted_at' => now(), 'assigned_to' => $tele->id, 'assigned_at' => now(), 'center_id' => $admin->center_id]);

        $ids = collect($this->actingAs($tele)->getJson(route('leads.assigned_tray'))->json('leads'))->pluck('id');
        $this->assertFalse($ids->contains($called->id), 'A lead with a logged call is no longer "new to call".');
    }
}
