<?php

namespace Tests\Feature;

use App\Models\TataAgent;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * The Users screen links a user to a Smartflo extension, moves it to another
 * user, or unlinks it.
 */
class UserSmartfloLinkTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
    }

    public function test_link_move_and_unlink_an_extension(): void
    {
        $first  = User::factory()->create(['tata_agent_number' => null]);
        $second = User::factory()->create(['tata_agent_number' => null]);
        $agent  = TataAgent::create(['name' => 'Desk 7', 'agent_id' => '0509990007', 'extension' => '0609990007']);

        $this->actingAs($this->admin)
            ->postJson(route('settings.users.smartflo', $first), ['agent_id' => $agent->id])
            ->assertOk();
        $this->assertSame($first->id, $agent->fresh()->user_id);
        $this->assertSame('0609990007', $first->fresh()->tata_agent_number);

        // Moving it releases the first user's number (it is unique per user).
        $this->postJson(route('settings.users.smartflo', $second), ['agent_id' => $agent->id])->assertOk();
        $this->assertSame($second->id, $agent->fresh()->user_id);
        $this->assertNull($first->fresh()->tata_agent_number);
        $this->assertSame('0609990007', $second->fresh()->tata_agent_number);

        $this->postJson(route('settings.users.smartflo', $second), ['agent_id' => null])->assertOk();
        $this->assertNull($agent->fresh()->user_id);
        $this->assertNull($second->fresh()->tata_agent_number);

        $this->get(route('settings.users.index'))->assertOk()->assertSee('smartfloModal');
    }
}
