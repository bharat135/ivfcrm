<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

class TelecallerPatientEditTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'center_id' => Center::value('id')]);
        $user->assignRole($role);

        return $user;
    }

    /** A lead with its patient record, assigned to someone other than the telecaller. */
    private function someoneElsesPatient(): array
    {
        $other = $this->userWithRole('Telecaller');
        $lead  = Lead::create([
            'name' => 'Other Couple', 'mobile' => '98765' . random_int(10000, 99999),
            'status' => 'new', 'assigned_to' => $other->id, 'center_id' => Center::value('id'),
        ]);
        $patient = Patient::where('lead_id', $lead->id)->first()
            ?? Patient::create(['lead_id' => $lead->id, 'wife_name' => 'Other Couple', 'mobile' => $lead->mobile]);

        return [$lead, $patient];
    }

    public function test_a_telecaller_can_edit_any_patients_details(): void
    {
        $telecaller = $this->userWithRole('Telecaller');
        [$lead, $patient] = $this->someoneElsesPatient();

        $this->actingAs($telecaller)->get(route('patients.show', $patient))->assertOk()
            ->assertSee('Edit clinical details');

        $this->actingAs($telecaller)->put(route('patients.update', $patient), [
            'diagnosis' => 'PCOS',
        ])->assertRedirect();
        $this->assertSame('PCOS', $patient->fresh()->diagnosis);

        $this->actingAs($telecaller)->get(route('leads.edit', $lead))->assertOk();
        $this->actingAs($telecaller)->get(route('patients.index'))->assertOk();
    }

    public function test_a_telecaller_saving_a_lead_with_the_masked_number_keeps_the_real_one(): void
    {
        $telecaller = $this->userWithRole('Telecaller');
        [$lead] = $this->someoneElsesPatient();
        $real = $lead->mobile;

        $this->actingAs($telecaller)->get(route('leads.edit', $lead))->assertOk()
            ->assertSee('disabled title="Number hidden', false);

        // What a form posted before the fields were disabled sent back
        $this->actingAs($telecaller)->put(route('leads.update', $lead), [
            'name'       => 'Renamed Couple',
            'mobile'     => \App\Support\PhoneNumber::mask($real),
            'alt_mobile' => '98XXXXXX11',
        ])->assertSessionHasNoErrors()->assertRedirect(route('leads.show', $lead));

        $lead->refresh();
        $this->assertSame('Renamed Couple', $lead->name);
        $this->assertSame($real, $lead->mobile);
    }

    public function test_a_role_without_patient_editing_cannot_change_a_patient(): void
    {
        $receptionist = $this->userWithRole('Receptionist');
        [, $patient] = $this->someoneElsesPatient();

        $this->actingAs($receptionist)->put(route('patients.update', $patient), [
            'diagnosis' => 'Changed',
        ])->assertForbidden();

        $this->assertNotSame('Changed', $patient->fresh()->diagnosis);
    }
}
