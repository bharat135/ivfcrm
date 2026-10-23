<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\TataNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

class TataCallerIdTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    public function test_an_agents_own_did_wins_over_the_centre_and_the_default(): void
    {
        $centre = Center::create(['name' => 'DID Centre', 'code' => 'DID' . random_int(100, 999)]);
        $agent  = User::factory()->create(['is_active' => true, 'center_id' => $centre->id]);
        $other  = User::factory()->create(['is_active' => true, 'center_id' => $centre->id]);

        TataNumber::where('is_default', true)->update(['is_default' => false]);
        TataNumber::create(['did' => '7965000001', 'is_default' => true]);
        TataNumber::create(['did' => '7965000002', 'center_id' => $centre->id]);
        TataNumber::create(['did' => '7965000003', 'user_id' => $agent->id]);

        $this->assertSame('7965000003', TataNumber::callerIdFor($centre->id, $agent->id));
        $this->assertSame('7965000002', TataNumber::callerIdFor($centre->id, $other->id));
        $this->assertSame('7965000001', TataNumber::callerIdFor(null, null));
    }

    public function test_an_admin_assigns_a_did_to_an_agent_from_the_numbers_page(): void
    {
        $admin  = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first();
        $agent  = User::factory()->create(['is_active' => true, 'tata_agent_number' => '9800000111']);
        $number = TataNumber::create(['did' => '7965000004']);

        $this->actingAs($admin)->get(route('tata.numbers.index'))->assertOk()->assertSee('Agent caller ID');

        $this->actingAs($admin)->patchJson(route('tata.numbers.update', $number), ['user_id' => $agent->id])->assertOk();
        $this->assertSame($agent->id, $number->fresh()->user_id);

        // One DID per agent: assigning it elsewhere moves it.
        $second = TataNumber::create(['did' => '7965000005']);
        $this->actingAs($admin)->patchJson(route('tata.numbers.update', $second), ['user_id' => $agent->id])->assertOk();
        $this->assertNull($number->fresh()->user_id);
        $this->assertSame($agent->id, $second->fresh()->user_id);
    }
}
