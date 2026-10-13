<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

/**
 * Settings → Doctors.
 *
 * The consultation booking, patient profile and treatment screens all pick
 * from the doctors table, so without this screen a fresh install has nobody
 * to book with. A doctor may optionally be linked to a CRM login so that
 * "my appointments" filters work for them.
 */
class DoctorController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = Doctor::with(['center:id,name', 'user:id,name,email'])
                ->withCount('appointments');

            return DataTables::of($query)
                ->addColumn('status_badge', fn ($d) => $d->is_active
                    ? "<span class='badge bg-success'>Active</span>"
                    : "<span class='badge bg-secondary'>Inactive</span>")
                ->addColumn('login', fn ($d) => $d->user
                    ? e($d->user->email)
                    : "<span class='text-muted'>—</span>")
                ->addColumn('action', fn ($d) => view('settings.doctors._actions', ['doctor' => $d])->render())
                ->rawColumns(['status_badge', 'login', 'action'])
                ->make(true);
        }

        return view('settings.doctors.index', [
            'centers' => Center::active()->orderBy('name')->get(['id', 'name']),
            'users'   => $this->linkableUsers(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        Doctor::create($data);

        return response()->json(['success' => true, 'message' => 'Doctor added.']);
    }

    public function edit(Doctor $doctor)
    {
        return response()->json($doctor);
    }

    public function update(Request $request, Doctor $doctor)
    {
        $data = $this->validated($request, $doctor);

        $doctor->update($data);

        return response()->json(['success' => true, 'message' => 'Doctor updated.']);
    }

    public function destroy(Doctor $doctor)
    {
        // Appointments keep their doctor_id, so a doctor with history is
        // deactivated rather than removed from the dropdowns by deletion.
        if ($doctor->appointments()->exists() || $doctor->treatmentCycles()->exists()) {
            $doctor->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'This doctor has appointments or cycles, so they were marked inactive instead of deleted.',
            ]);
        }

        $doctor->delete();

        return response()->json(['success' => true, 'message' => 'Doctor deleted.']);
    }

    public function toggle(Doctor $doctor)
    {
        $doctor->update(['is_active' => ! $doctor->is_active]);

        return response()->json([
            'success'   => true,
            'is_active' => $doctor->is_active,
            'message'   => 'Doctor ' . ($doctor->is_active ? 'activated' : 'deactivated') . '.',
        ]);
    }

    private function validated(Request $request, ?Doctor $doctor = null): array
    {
        $data = $request->validate([
            'name'                 => ['required', 'string', 'max:191'],
            'code'                 => ['nullable', 'string', 'max:40', Rule::unique('doctors', 'code')->ignore($doctor?->id)->whereNull('deleted_at')],
            'qualification'        => ['nullable', 'string', 'max:191'],
            'specialization'       => ['nullable', 'string', 'max:191'],
            'phone'                => ['nullable', 'string', 'max:20'],
            'email'                => ['nullable', 'email', 'max:191'],
            'center_id'            => ['nullable', 'exists:centers,id'],
            'user_id'              => ['nullable', 'exists:users,id', Rule::unique('doctors', 'user_id')->ignore($doctor?->id)->whereNull('deleted_at')],
            'consultation_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'consultation_fee'     => ['nullable', 'numeric', 'min:0'],
            'is_active'            => ['boolean'],
        ], [
            'user_id.unique' => 'That login is already linked to another doctor.',
        ]);

        // "Dr." is added by every screen that renders the name.
        $data['name'] = preg_replace('/^\s*dr\.?\s+/i', '', $data['name']);
        $data['consultation_minutes'] = $data['consultation_minutes'] ?? 30;
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /**
     * Logins a doctor record can be attached to: users with the Doctor role,
     * plus whoever is already linked (so an edit still shows the current one).
     */
    private function linkableUsers()
    {
        return User::query()
            ->where(fn ($q) => $q->role('Doctor')->orWhereHas('doctor'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
