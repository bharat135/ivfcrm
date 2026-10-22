<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Center;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

class AppointmentAccessTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private Center $mine;
    private Center $other;
    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine  = Center::create(['name' => 'Appt Home Centre', 'code' => 'AH' . random_int(100, 999)]);
        $this->other = Center::create(['name' => 'Appt Other Centre', 'code' => 'AO' . random_int(100, 999)]);
        $this->doctor = Doctor::create([
            'name' => 'Dr Access', 'code' => 'DA' . random_int(1000, 9999),
            'center_id' => $this->mine->id, 'is_active' => true,
        ]);
    }

    private function user(string $role, ?Center $center = null): User
    {
        $user = User::factory()->create(['is_active' => true, 'center_id' => $center?->id]);
        $user->assignRole($role);

        return $user;
    }

    private function appointment(Center $center, User $bookedBy, string $status = 'booked'): Appointment
    {
        return Appointment::create([
            'doctor_id' => $this->doctor->id, 'center_id' => $center->id, 'created_by' => $bookedBy->id,
            'scheduled_at' => now()->addDay(), 'type' => 'consultation', 'status' => $status,
        ]);
    }

    private function listedIds(User $user): array
    {
        return collect($this->actingAs($user)
            ->getJson(route('appointments.index', ['length' => 500]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_a_telecaller_sees_only_the_appointments_they_booked(): void
    {
        $telecaller = $this->user('Telecaller', $this->mine);
        $colleague  = $this->user('Telecaller', $this->mine);

        $own   = $this->appointment($this->mine, $telecaller);
        $other = $this->appointment($this->mine, $colleague);

        $ids = $this->listedIds($telecaller);
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);

        // and cannot close someone else's
        $this->actingAs($telecaller)->putJson(route('appointments.update', $other), [
            'status' => 'cancelled', 'cancel_reason' => 'Not mine',
        ])->assertForbidden();
    }

    public function test_reception_sees_its_centre_and_closes_visits_with_a_reason_to_cancel(): void
    {
        $reception = $this->user('Receptionist', $this->mine);
        $booker    = $this->user('Telecaller');

        $here  = $this->appointment($this->mine, $booker);
        $there = $this->appointment($this->other, $booker);

        $ids = $this->listedIds($reception);
        $this->assertContains($here->id, $ids);
        $this->assertNotContains($there->id, $ids);

        // Reception closes the loop too: visited, or a new time.
        $visited = $this->appointment($this->mine, $booker);
        $this->actingAs($reception)->putJson(route('appointments.update', $visited), ['status' => 'visited'])->assertOk();
        $this->assertSame('visited', $visited->fresh()->status);

        $moved   = $this->appointment($this->mine, $booker);
        $newTime = now()->addDays(2)->setTime(10, 30);
        $this->actingAs($reception)->putJson(route('appointments.update', $moved), [
            'scheduled_at' => $newTime->format('Y-m-d H:i'), 'status' => 'rescheduled',
        ])->assertOk();
        $this->assertSame($newTime->format('Y-m-d H:i'), $moved->fresh()->scheduled_at->format('Y-m-d H:i'));

        $this->actingAs($reception)->putJson(route('appointments.update', $here), ['status' => 'cancelled'])
            ->assertUnprocessable()->assertJsonValidationErrors('cancel_reason');

        $this->actingAs($reception)->putJson(route('appointments.update', $here), [
            'status' => 'cancelled', 'cancel_reason' => 'Patient called to cancel',
        ])->assertOk();

        $here->refresh();
        $this->assertSame('cancelled', $here->status);
        $this->assertSame('Patient called to cancel', $here->cancel_reason);
        $this->assertSame($reception->id, $here->cancelled_by);
        $this->assertNotNull($here->cancelled_at);

        $this->actingAs($reception)->putJson(route('appointments.update', $there), [
            'status' => 'cancelled', 'cancel_reason' => 'Other centre',
        ])->assertForbidden();
    }

    public function test_a_doctor_sees_the_centre_and_can_mark_done_or_cancel(): void
    {
        $doctorUser = $this->user('Doctor', $this->mine);
        $booker     = $this->user('Telecaller');

        $done   = $this->appointment($this->mine, $booker);
        $cancel = $this->appointment($this->mine, $booker);
        $there  = $this->appointment($this->other, $booker);

        $ids = $this->listedIds($doctorUser);
        $this->assertContains($done->id, $ids);
        $this->assertNotContains($there->id, $ids);

        $this->actingAs($doctorUser)->putJson(route('appointments.update', $done), ['status' => 'visited'])->assertOk();
        $this->actingAs($doctorUser)->putJson(route('appointments.update', $cancel), [
            'status' => 'cancelled', 'cancel_reason' => 'Doctor unavailable',
        ])->assertOk();

        $this->assertSame('visited', $done->fresh()->status);
        $this->assertSame('cancelled', $cancel->fresh()->status);
    }

    public function test_an_admin_sees_every_centre(): void
    {
        $admin  = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first();
        $booker = $this->user('Telecaller');

        $a = $this->appointment($this->mine, $booker);
        $b = $this->appointment($this->other, $booker);

        $ids = $this->listedIds($admin);
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    public function test_a_telecaller_edit_form_has_no_assign_to(): void
    {
        $telecaller = $this->user('Telecaller', $this->mine);
        $owner      = $this->user('Telecaller', $this->mine);
        $lead = \App\Models\Lead::create([
            'name' => 'Owner Kept', 'mobile' => '98765' . random_int(10000, 99999),
            'status' => 'new', 'assigned_to' => $telecaller->id,
        ]);

        $this->actingAs($telecaller)->get(route('leads.edit', $lead))->assertOk()
            ->assertDontSee('name="assigned_to"', false);

        $this->actingAs($telecaller)->put(route('leads.update', $lead), [
            'name' => 'Owner Kept', 'assigned_to' => $owner->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($telecaller->id, $lead->fresh()->assigned_to);
    }
}
