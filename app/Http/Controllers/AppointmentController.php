<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Center;
use App\Models\Doctor;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(Auth::user()->can('appointments.view'), 403);

        if ($request->ajax() || $request->wantsJson()) {
            // Everyone, their centre's, or only the ones they booked: see Appointment::scopeVisibleTo
            $query = Appointment::with(['patient:id,wife_name,mobile', 'doctor:id,name', 'center:id,name,code', 'canceller:id,name'])
                ->select('appointments.*')
                ->visibleTo(Auth::user());

            return DataTables::of($query)
                ->addColumn('action', fn($a) => view('appointments._actions', ['appointment' => $a])->render())
                ->rawColumns(['action'])
                ->make(true);
        }

        $centers = Center::active()->get();
        $doctors = Doctor::active()->with('center:id,name')->orderBy('name')->get();

        return view('appointments.index', compact('centers', 'doctors'));
    }

    public function store(Request $request)
    {
        abort_unless(Auth::user()->can('appointments.create'), 403);

        $data = $request->validate([
            'patient_id'   => ['nullable', 'exists:patients,id'],
            'lead_id'      => ['nullable', 'exists:leads,id'],
            'doctor_id'    => ['required', 'exists:doctors,id'],
            'center_id'    => ['required', 'exists:centers,id'],
            'scheduled_at' => ['required', 'date'],
            'type'         => ['required', 'string'],
            'notes'        => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
        ]);

        $data['created_by'] = Auth::id();
        $data['status']     = 'booked';

        $appointment = Appointment::create($data);

        $this->logToLead($appointment, 'Appointment booked');

        if (!empty($data['lead_id'])) {
            $stage = \App\Models\PipelineStage::where('slug', 'consultation-booked')->first();
            if ($stage) {
                Lead::where('id', $data['lead_id'])->update(['stage_id' => $stage->id]);
            }
        }

        return response()->json(['success' => true, 'id' => $appointment->id]);
    }

    /**
     * Marking visited / no-show needs appointments.complete, cancelling needs
     * appointments.cancel and a reason, anything else (reschedule, confirm,
     * notes) needs appointments.edit, and only on an appointment the user sees.
     */
    public function update(Request $request, Appointment $appointment)
    {
        $user = Auth::user();
        abort_unless($appointment->isVisibleTo($user), 403);

        $data = $request->validate([
            'scheduled_at'     => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer'],
            'status'           => ['sometimes', 'in:booked,confirmed,visited,no_show,cancelled,rescheduled'],
            'notes'            => ['nullable', 'string'],
            'cancel_reason'    => ['required_if:status,cancelled', 'nullable', 'string', 'min:3', 'max:1000'],
        ], [
            'cancel_reason.required_if' => 'Give a reason for cancelling the appointment.',
        ]);

        $status = $data['status'] ?? null;
        $other  = array_diff(array_keys($data), ['status', 'cancel_reason']);

        $allowed = match (true) {
            in_array($status, ['visited', 'no_show'], true) => $user->can('appointments.complete'),
            $status === 'cancelled'                         => $user->can('appointments.cancel'),
            $status !== null                                => $user->can('appointments.edit'),
            default                                         => true,
        };
        abort_unless($allowed && ($other === [] || $user->can('appointments.edit')), 403,
            'You are not allowed to make this change to the appointment.');

        if ($status === 'cancelled') {
            $data['cancelled_by'] = $user->id;
            $data['cancelled_at'] = now();
        } else {
            unset($data['cancel_reason']);
        }

        $appointment->update($data);

        if ($status && $appointment->wasChanged('status')) {
            $this->logToLead($appointment, 'Appointment ' . str_replace('_', '-', $status),
                $status === 'cancelled' ? ($data['cancel_reason'] ?? null) : null);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Appointments show in the lead's updates alongside calls and dispositions.
     */
    private function logToLead(Appointment $appointment, string $title, ?string $extra = null): void
    {
        $leadId = $appointment->lead_id ?: $appointment->patient?->lead_id;

        if (! $leadId) {
            return;
        }

        $appointment->loadMissing('doctor:id,name', 'patient:id,lead_id');

        $description = implode(' · ', array_filter([
            ucfirst(str_replace('_', ' ', $appointment->type ?: 'consultation')),
            $appointment->doctor ? 'Dr. ' . $appointment->doctor->name : null,
            $appointment->scheduled_at?->format('d-m-Y, h:i A'),
            $extra ? 'Reason: ' . $extra : null,
        ]));

        \App\Models\LeadActivity::create([
            'lead_id'     => $leadId,
            'user_id'     => Auth::id(),
            'type'        => 'appointment',
            'title'       => $title,
            'description' => $description,
            'properties'  => ['appointment_id' => $appointment->id, 'status' => $appointment->status],
        ]);
    }

    public function calendar(Request $request)
    {
        $doctorId = $request->get('doctor_id');
        $centerId = $request->get('center_id', Auth::user()->center_id);
        $date     = $request->get('date', today()->toDateString());

        abort_unless(Auth::user()->can('appointments.view'), 403);

        $appointments = Appointment::with(['patient:id,wife_name,mobile', 'doctor:id,name'])
            ->visibleTo(Auth::user())
            ->whereDate('scheduled_at', $date)
            ->when($doctorId, fn($q) => $q->where('doctor_id', $doctorId))
            ->when($centerId, fn($q) => $q->where('center_id', $centerId))
            ->get();

        return response()->json($appointments);
    }
}
