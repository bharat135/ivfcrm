<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * Doctors are what consultation booking picks from. A clinic must be able to
 * add them from Settings, and giving a login the Doctor role must be enough
 * to make that person bookable.
 */
class DoctorManagementTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private function admin(): User
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
    }

    public function test_the_doctors_settings_screen_renders_and_lists_doctors(): void
    {
        $doctor = Doctor::create(['name' => 'Phpunit Lister', 'specialization' => 'IVF Specialist']);

        $this->actingAs($this->admin())
            ->get(route('settings.doctors.index'))
            ->assertOk()
            ->assertSee('Add Doctor');

        $this->actingAs($this->admin())
            ->getJson(route('settings.doctors.index'))
            ->assertOk()
            ->assertJsonFragment(['name' => 'Phpunit Lister']);

        $doctor->forceDelete();
    }

    public function test_an_admin_can_add_a_doctor_and_it_appears_in_consultation_booking(): void
    {
        $center = Center::where('is_active', true)->first();

        $this->actingAs($this->admin())
            ->postJson(route('settings.doctors.store'), [
                'name'                 => 'Dr. Phpunit Booker',
                'specialization'       => 'Reproductive Medicine',
                'center_id'            => $center?->id,
                'consultation_minutes' => 20,
                'consultation_fee'     => 1500,
                'is_active'            => 1,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $doctor = Doctor::where('name', 'Phpunit Booker')->first();
        $this->assertNotNull($doctor, 'The "Dr." prefix should be stripped and the doctor stored.');
        $this->assertSame(20, $doctor->consultation_minutes);

        $this->actingAs($this->admin())
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertSee('Dr. Phpunit Booker')
            ->assertDontSee('No active doctors yet');

        $doctor->forceDelete();
    }

    public function test_an_inactive_doctor_is_not_offered_for_booking(): void
    {
        $doctor = Doctor::create(['name' => 'Phpunit Paused', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->patchJson(route('settings.doctors.toggle', $doctor))
            ->assertOk()
            ->assertJson(['is_active' => false]);

        $this->actingAs($this->admin())
            ->get(route('appointments.index'))
            ->assertOk()
            ->assertDontSee('Dr. Phpunit Paused');

        $doctor->forceDelete();
    }

    public function test_a_doctor_with_appointments_is_deactivated_rather_than_deleted(): void
    {
        $doctor = Doctor::create(['name' => 'Phpunit Busy']);
        $center = Center::first();

        \App\Models\Appointment::create([
            'doctor_id'    => $doctor->id,
            'center_id'    => $center->id,
            'scheduled_at' => now()->addDay(),
            'type'         => 'consultation',
            'status'       => 'scheduled',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson(route('settings.doctors.destroy', $doctor))
            ->assertOk();

        $this->assertFalse($doctor->fresh()->is_active);
        $this->assertNull($doctor->fresh()->deleted_at);

        $doctor->appointments()->forceDelete();
        $doctor->forceDelete();
    }

    public function test_creating_a_user_with_the_doctor_role_creates_a_bookable_doctor(): void
    {
        $email = 'phpunit.doctor.' . uniqid() . '@example.com';

        $this->actingAs($this->admin())
            ->postJson(route('settings.users.store'), [
                'name'     => 'Dr. Phpunit Login',
                'email'    => $email,
                'phone'    => '9876543210',
                'password' => 'Secret@123',
                'role'     => 'Doctor',
            ])
            ->assertOk();

        $user   = User::where('email', $email)->firstOrFail();
        $doctor = Doctor::where('user_id', $user->id)->first();

        $this->assertNotNull($doctor, 'A Doctor-role user should get a doctor record.');
        $this->assertSame('Phpunit Login', $doctor->name);
        $this->assertSame('9876543210', $doctor->phone);
        $this->assertTrue($doctor->is_active);

        // Deactivating the login hides the doctor from booking too.
        $this->actingAs($this->admin())
            ->patchJson(route('settings.users.toggle_status', $user))
            ->assertOk();

        $this->assertFalse($doctor->fresh()->is_active);

        $doctor->forceDelete();
        $user->forceDelete();
    }

    public function test_a_telecaller_cannot_manage_doctors(): void
    {
        $telecaller = User::whereHas('roles', fn ($q) => $q->where('name', 'Telecaller'))->first();

        if (! $telecaller) {
            $this->markTestSkipped('No telecaller seeded.');
        }

        $this->actingAs($telecaller)
            ->postJson(route('settings.doctors.store'), ['name' => 'Phpunit Forbidden'])
            ->assertForbidden();
    }
}
