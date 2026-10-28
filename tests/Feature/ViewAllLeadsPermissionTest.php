<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * view_all_leads widens a calling role (e.g. Call Quality Analyst) from its
 * own assigned leads to every lead, without touching Center Manager scoping.
 */
class ViewAllLeadsPermissionTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectDatabase;

    private function analyst(): User
    {
        Role::firstOrCreate(['name' => 'Call Quality Analyst']);
        Permission::firstOrCreate(['name' => 'view_all_leads']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('Call Quality Analyst');

        return $user;
    }

    public function test_without_the_permission_an_analyst_sees_only_their_own_leads(): void
    {
        $analyst = $this->analyst();
        $other   = Lead::whereNotNull('id')->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $analyst->id))->first();

        $this->assertNotNull($other, 'needs at least one lead in the project database');
        $this->assertFalse(Lead::forUser($analyst)->whereKey($other->id)->exists());
    }

    public function test_with_the_permission_an_analyst_sees_every_lead(): void
    {
        $analyst = $this->analyst();
        $analyst->givePermissionTo('view_all_leads');
        $analyst->refresh();

        $this->assertSame(Lead::count(), Lead::forUser($analyst)->count());

        $other = Lead::where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', '!=', $analyst->id))->first();
        $this->assertTrue($analyst->can('view', $other));
    }
}
