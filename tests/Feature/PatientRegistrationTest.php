<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Center;
use App\Models\Doctor;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * An enquiry stays a lead until the couple visits: no patient ID and no row on
 * the Patients list until an appointment is marked visited.
 */
class PatientRegistrationTest extends TestCase
{
    use DatabaseTransactions;
    use UsesProjectDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
        $this->actingAs($this->admin);
    }

    private function patientsListIds(): array
    {
        return collect($this->getJson(route('patients.index', ['length' => 1000]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_a_new_lead_has_no_patient_id_and_is_not_on_the_patients_list(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Visit Pending', 'mobile' => '9791100081'], autoAssign: false);
        $patient = $lead->patient;

        $this->assertNotNull($patient);
        $this->assertNull($patient->patient_code);
        $this->assertFalse($patient->isRegistered());
        $this->assertNotContains($patient->id, $this->patientsListIds());

        $this->get(route('leads.show', $lead))->assertOk()->assertSee('After first visit');
    }

    public function test_booking_alone_does_not_make_a_patient_but_marking_visited_does(): void
    {
        $lead = app(LeadService::class)->create(['name' => 'Visit Booked', 'mobile' => '9791100082'], autoAssign: false);

        $appointment = Appointment::create([
            'lead_id'      => $lead->id,
            'doctor_id'    => Doctor::value('id'),
            'center_id'    => Center::value('id'),
            'scheduled_at' => now()->subHour(),
            'type'         => 'consultation',
            'status'       => 'booked',
            'created_by'   => $this->admin->id,
        ]);

        $this->assertNull($lead->patient->fresh()->patient_code);

        $this->putJson(route('appointments.update', $appointment), ['status' => 'visited'])->assertOk();

        $patient = $lead->patient->fresh();
        $this->assertStringStartsWith('PAT-', $patient->patient_code);
        $this->assertNotNull($patient->registered_at);
        $this->assertSame($patient->id, $appointment->fresh()->patient_id);
        $this->assertContains($patient->id, $this->patientsListIds());

        // A second visit keeps the same ID.
        $code = $patient->patient_code;
        $patient->register();
        $this->assertSame($code, $patient->fresh()->patient_code);
    }
}
