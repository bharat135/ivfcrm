<?php

namespace Tests\Feature;

use App\Jobs\DelegateLeaveFollowUpsJob;
use App\Models\AgentLeave;
use App\Models\AssignmentRule;
use App\Models\Center;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Services\AssignmentEngine;
use App\Services\LeadService;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The lead log (who did what, when), mobile numbers hidden from telecallers,
 * the holiday calendar with follow-up cover, and the inline centre change.
 */
class LeaveAndPrivacyTest extends TestCase
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

        $centerId = Center::where('is_active', true)->value('id');

        $this->telecaller = $this->telecaller('Privacy Telecaller', $centerId);
        $this->colleague  = $this->telecaller('Privacy Colleague', $centerId);

        AssignmentRule::query()->update(['is_active' => false]);
        AssignmentEngine::forgetCache();
    }

    private function telecaller(string $name, ?int $centerId): User
    {
        $user = User::factory()->create([
            'name' => $name, 'is_active' => true, 'is_available' => true, 'center_id' => $centerId, 'last_seen_at' => now(),
        ]);
        $user->assignRole('Telecaller');

        return $user;
    }

    private function mobile(): string
    {
        return '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    // =================================================================
    // The log
    // =================================================================

    public function test_the_log_records_assignment_owner_change_calls_and_edits_with_who_and_when(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Logged Couple', 'mobile' => $this->mobile(), 'center_id' => $this->telecaller->center_id]);

        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'assignment', 'title' => 'Assigned to ' . $lead->fresh()->assignedTo->name]);

        $this->actingAs($this->admin)->postJson(route('leads.reassign', $lead), ['user_id' => $this->colleague->id, 'reason' => 'Covering'])->assertOk();
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'assignment', 'title' => 'Owner changed to ' . $this->colleague->name, 'user_id' => $this->admin->id]);

        $call = app(\App\Services\Telephony\CallService::class)->recordExternal([
            'provider' => 'tata', 'direction' => 'outbound', 'to_number' => $lead->mobile, 'from_number' => '+917965083657',
            'agent_id' => $this->colleague->id, 'started_at' => now()->subMinutes(3)->toDateTimeString(),
        ]);
        app(\App\Services\Telephony\CallService::class)->finalize($call, ['timestamp' => now()->toDateTimeString(), 'talk_seconds' => 40, 'status' => 'completed']);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'type' => 'call', 'user_id' => $this->colleague->id]);

        $newCentre = Center::where('is_active', true)->where('id', '!=', $lead->center_id)->first();
        $this->actingAs($this->admin)->put(route('leads.update', $lead), ['name' => 'Logged Couple Renamed', 'center_id' => $newCentre->id]);
        $entry = \App\Models\LeadActivity::where('lead_id', $lead->id)->where('title', 'Details updated')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Name: Logged Couple → Logged Couple Renamed', $entry->description);
        $this->assertStringContainsString('Centre: ', $entry->description);
        $this->assertSame($this->admin->id, $entry->user_id);

        $this->actingAs($this->admin)->get(route('leads.show', $lead))
            ->assertOk()->assertSee('every call, owner change, edit and follow-up')->assertSee('Owner changed to ' . $this->colleague->name);
    }

    // =================================================================
    // Numbers hidden from telecallers
    // =================================================================

    public function test_telecallers_see_masked_numbers_everywhere_but_can_still_dial(): void
    {
        $mobile = $this->mobile();
        $lead   = app(LeadService::class)->create(['name' => 'Masked Couple', 'mobile' => $mobile, 'assigned_to' => $this->telecaller->id]);
        $masked = PhoneNumber::mask($mobile);

        $this->assertSame(substr($mobile, 0, 2) . 'XXXXXX' . substr($mobile, -2), $masked);

        // Profile: masked in the rail, call button carries no number, no tel: link.
        $page = $this->actingAs($this->telecaller)->get(route('leads.show', $lead))->assertOk();
        $page->assertSee($masked)->assertDontSee('tel:' . $mobile, false)->assertSee('data-number=""', false);
        $this->assertStringNotContainsString($mobile, $page->getContent());

        // Worklist JSON.
        $this->actingAs($this->telecaller)->getJson(route('leads.index'))->assertOk()->assertJsonFragment(['mobile' => $masked]);

        // Managers see the full number.
        $this->actingAs($this->admin)->get(route('leads.show', $lead))->assertOk()->assertSee('tel:' . $mobile, false);

        // A telecaller cannot change what they cannot see.
        $this->actingAs($this->telecaller)->put(route('leads.update', $lead), ['name' => 'Masked Couple', 'mobile' => '9111111111'])->assertRedirect();
        $this->assertSame($mobile, $lead->fresh()->mobile);
    }

    // =================================================================
    // Holidays & leaves
    // =================================================================

    public function test_a_follow_up_due_during_the_owners_leave_is_covered_without_changing_the_lead_owner(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Leave Couple', 'mobile' => $this->mobile(), 'assigned_to' => $this->telecaller->id]);
        $followUp = FollowUp::create([
            'lead_id' => $lead->id, 'user_id' => $this->telecaller->id, 'type' => 'call', 'status' => 'pending',
            'scheduled_at' => now()->addDay()->setTime(11, 0),
        ]);

        $this->actingAs($this->admin)->postJson(route('settings.leaves.store'), [
            'type' => 'leave', 'user_id' => $this->telecaller->id,
            'starts_on' => now()->addDay()->toDateString(), 'ends_on' => now()->addDays(2)->toDateString(), 'reason' => 'Sick',
        ])->assertOk();

        $followUp->refresh();
        $cover = User::find($followUp->delegated_to);
        $this->assertNotNull($cover, 'A present agent must cover the follow-up.');
        $this->assertNotSame($this->telecaller->id, $cover->id);
        $this->assertTrue($cover->hasRole(['Telecaller', 'Counsellor']));
        $this->assertSame($this->telecaller->id, $lead->fresh()->assigned_to, 'The lead keeps its owner.');
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'title' => 'Follow-up covered by ' . $cover->name]);

        // The cover sees it on their worklist; the owner does not.
        $this->actingAs($cover)->getJson(route('followups.index'))->assertOk()->assertJsonFragment(['id' => $followUp->id]);
        $this->actingAs($this->telecaller)->getJson(route('followups.index'))->assertOk()->assertJsonMissing(['id' => $followUp->id]);

        // No new leads for someone on leave, even if they are online.
        $this->assertTrue($this->telecaller->fresh()->isOnLeave(now()->addDay()));
        AgentLeave::query()->update(['starts_on' => today(), 'ends_on' => today()->addDays(2)]);
        $new = app(LeadService::class)->create(['name' => 'While away', 'mobile' => $this->mobile(), 'center_id' => $this->telecaller->center_id]);
        $this->assertNotNull($new->assigned_to);
        $this->assertNotSame($this->telecaller->id, $new->assigned_to, 'An agent on leave gets no new leads.');

        // Leave removed: cover withdrawn.
        AgentLeave::query()->delete();
        (new DelegateLeaveFollowUpsJob)->handle(app(AssignmentEngine::class));
        $this->assertNull($followUp->fresh()->delegated_to);
    }

    public function test_the_leaves_screen_renders_and_validates(): void
    {
        $this->actingAs($this->admin)->get(route('settings.leaves.index'))->assertOk()->assertSee('Holidays');

        $this->actingAs($this->admin)->postJson(route('settings.leaves.store'), [
            'type' => 'leave', 'starts_on' => today()->toDateString(), 'ends_on' => today()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id', 'ends_on']);

        $this->actingAs($this->admin)->postJson(route('settings.leaves.store'), [
            'type' => 'holiday', 'starts_on' => today()->addMonth()->toDateString(), 'ends_on' => today()->addMonth()->toDateString(), 'reason' => 'Diwali',
        ])->assertOk();
        $this->assertDatabaseHas('agent_leaves', ['user_id' => null, 'reason' => 'Diwali']);

        $this->actingAs($this->telecaller)->get(route('settings.leaves.index'))->assertOk();
        $this->actingAs($this->telecaller)->postJson(route('settings.leaves.store'), ['type' => 'holiday', 'starts_on' => today()->toDateString(), 'ends_on' => today()->toDateString()])->assertForbidden();
    }

    // =================================================================
    // Inline centre change
    // =================================================================

    public function test_a_telecaller_changes_the_centre_from_the_profile_and_it_is_logged(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Centre Couple', 'mobile' => $this->mobile(), 'assigned_to' => $this->telecaller->id, 'center_id' => $this->telecaller->center_id]);
        $target = Center::where('is_active', true)->where('id', '!=', $lead->center_id)->first();

        $this->actingAs($this->telecaller)->get(route('leads.show', $lead))->assertOk()->assertSee('js-lead-center');

        $this->actingAs($this->telecaller)->patchJson(route('leads.center', $lead), ['center_id' => $target->id])
            ->assertOk()->assertJsonFragment(['message' => 'Centre changed to ' . $target->name . '.']);

        $this->assertSame($target->id, $lead->fresh()->center_id);
        $this->assertSame($target->id, $lead->fresh()->patient->center_id);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'title' => 'Centre changed to ' . $target->name, 'user_id' => $this->telecaller->id]);

        // Telecallers edit any patient, so a colleague may move it too; a role
        // without lead editing may not.
        $this->actingAs($this->colleague)->patchJson(route('leads.center', $lead), ['center_id' => $lead->center_id])->assertOk();

        $receptionist = User::factory()->create(['is_active' => true, 'center_id' => $this->telecaller->center_id]);
        $receptionist->assignRole('Receptionist');
        $this->actingAs($receptionist)->patchJson(route('leads.center', $lead), ['center_id' => $target->id])->assertForbidden();
    }
}
