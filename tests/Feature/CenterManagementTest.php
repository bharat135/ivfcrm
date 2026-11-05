<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\City;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

class CenterManagementTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Super Admin');

        return $admin;
    }

    public function test_a_centre_can_be_added_with_the_optional_fields_left_blank(): void
    {
        $code = 'T' . random_int(1000, 9999);

        // Blank inputs reach the controller as null (ConvertEmptyStringsToNull).
        $this->actingAs($this->admin())->post(route('settings.centers.store'), [
            'name' => 'Blank Fields Centre', 'code' => $code, 'phone' => '', 'email' => '', 'address' => '',
            'state_id' => '', 'city_id' => '', 'pincode' => '', 'timezone' => '', 'is_active' => '1',
        ], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $center = Center::where('code', $code)->first();
        $this->assertNotNull($center);
        $this->assertSame('Asia/Kolkata', $center->timezone);
        $this->assertTrue($center->is_active);
    }

    public function test_the_form_lists_the_cities_of_a_state(): void
    {
        $state = State::first();
        $this->assertNotNull($state, 'Seed geography first.');
        $city = City::where('state_id', $state->id)->first() ?? City::create(['state_id' => $state->id, 'name' => 'Test City']);

        $this->actingAs($this->admin())
            ->getJson(route('settings.centers.cities', ['state_id' => $state->id]))
            ->assertOk()
            ->assertJsonFragment(['id' => $city->id, 'name' => $city->name]);
    }
}
