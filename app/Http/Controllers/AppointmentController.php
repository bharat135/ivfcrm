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
            $query = Appointment::with(['patient:id,wife_name,mobile', 'doctor:id,name', 'center:id,name,code', 'canceller:id,name', 'reopenRequester:id,name'])
                ->select('appointments.*')
                ->visibleTo(Auth::user())
                // Only the ones waiting on an admin to approve a reopen.
                ->when($request->boolean('reopen'), fn ($q) => $q->reopenRequested());

            return DataTables::of($query)
                ->addColumn('action', fn($a) => view('appointments._actions', ['appointment' => $a])->render())
                ->rawColumns(['action'])
                ->make(true);
        }

        $centers = Center::active()->get();
        $doctors = Doctor::active()->with('center:id,name')->orderBy('name')->get();

        $reopenRequests = Auth::user()->can('reopen_appointments')
            ? Appointment::visibleTo(Auth::user())->reopenRequested()->count()
            : 0;

        return view('appointments.index', compact('centers', 'doctors', 'reopenRequests'));
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

        // Booking a consultation is what puts an enquiry on the Patients list.
        $appointment->loadMissing('lead', 'patient.lead');
        if ($lead = $appointment->lead ?: $appointment->patient?->lead) {
            app(\App\Services\PatientLinker::class)->advance($lead, \App\Support\PatientJourney::CONSULTATION, 'appointment booked');
        }

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

        // Taking back a visit or a no-show goes through reopen(), which needs an admin.
        abort_if($appointment->isClosed() && in_array($status, ['booked', 'confirmed', 'rescheduled'], true), 422,
            'This appointment is closed. Reopen it first; that needs admin approval.');

        if ($status === 'cancelled') {
            $data['cancelled_by'] = $user->id;
            $data['cancelled_at'] = now();
        } else {
            unset($data['cancel_reason']);
        }

        $appointment->update($data);

        // The first visit is what turns an enquiry into a patient with an ID.
        if ($status === 'visited' && $appointment->wasChanged('status')) {
            $appointment->loadMissing('lead', 'patient');
            $subject = $appointment->patient ?: $appointment->lead;
            $patient = $subject ? app(\App\Services\PatientLinker::class)->registerVisit($subject) : null;

            if ($patient && ! $appointment->patient_id) {
                $appointment->forceFill(['patient_id' => $patient->id])->saveQuietly();
            }
        }

        if ($appointment->wasChanged('scheduled_at')) {
            $this->logToLead($appointment, 'Appointment rescheduled');
        } elseif ($status && $appointment->wasChanged('status')) {
            $this->logToLead($appointment, 'Appointment ' . str_replace('_', '-', $status),
                $status === 'cancelled' ? ($data['cancel_reason'] ?? null) : null);
        }

        return response()->json(['success' => true]);
    }

    /**
     * An appointment marked done or no-show by mistake goes back to booked.
     *
     * Only reopen_appointments (admins) may do that. Anyone else who could
     * have closed the appointment, or who booked it, sends a request with a
     * reason; it waits on the appointment until an admin approves it here or
     * declines it (declineReopen).
     */
    public function reopen(Request $request, Appointment $appointment)
    {
        $user = Auth::user();
        abort_unless($appointment->isVisibleTo($user) && $appointment->canRequestReopen($user), 403,
            'You are not allowed to reopen this appointment.');
        abort_unless($appointment->isClosed(), 422, 'Only an appointment marked done or no-show can be reopened.');

        $approver = $user->can('reopen_appointments');
        $pending  = $appointment->hasReopenRequest();

        // Approving a request needs no second reason: the requester gave one.
        $data = $request->validate([
            'reason' => [$approver && $pending ? 'nullable' : 'required', 'string', 'min:3', 'max:1000'],
        ], [
            'reason.required' => 'Say why this appointment should be reopened.',
        ]);

        if (! $approver) {
            abort_if($pending, 422, 'A reopen request for this appointment is already waiting for admin approval.');

            $appointment->update([
                'reopen_reason'       => $data['reason'],
                'reopen_requested_by' => $user->id,
                'reopen_requested_at' => now(),
            ]);

            $this->logToLead($appointment, 'Appointment reopen requested', $data['reason']);

            return response()->json([
                'success' => true,
                'pending' => true,
                'message' => 'Sent for admin approval. The appointment stays as it is until an admin approves.',
            ]);
        }

        $appointment->loadMissing('reopenRequester:id,name', 'patient', 'lead.patient');

        $reason    = ($data['reason'] ?? null) ?: $appointment->reopen_reason;
        $requester = $pending ? $appointment->reopenRequester?->name : null;
        $wasVisit  = $appointment->status === 'visited';

        $appointment->update([
            'status'              => 'booked',
            'reopen_reason'       => null,
            'reopen_requested_by' => null,
            'reopen_requested_at' => null,
        ]);

        // The visit never happened, so it must not have made anyone a patient.
        if ($wasVisit) {
            app(\App\Services\PatientLinker::class)->undoVisit($appointment);
        }

        $this->logToLead($appointment, 'Appointment reopened',
            $reason . ($requester ? ' (requested by ' . $requester . ')' : ''));

        return response()->json(['success' => true, 'message' => 'Appointment reopened. It is back to booked.']);
    }

    public function declineReopen(Appointment $appointment)
    {
        $user = Auth::user();
        abort_unless($appointment->isVisibleTo($user) && $user->can('reopen_appointments'), 403);
        abort_unless($appointment->hasReopenRequest(), 422, 'There is no reopen request on this appointment.');

        $reason = $appointment->reopen_reason;

        $appointment->update([
            'reopen_reason'       => null,
            'reopen_requested_by' => null,
            'reopen_requested_at' => null,
        ]);

        $this->logToLead($appointment, 'Appointment reopen declined', $reason);

        return response()->json(['success' => true, 'message' => 'Request declined. The appointment stays as it is.']);
    }

    /**
     * Appointments show in the lead's updates alongside calls and dispositions.
     */
    private function logToLead(Appointment $appointment, string $title, ?string $extra = null): void
    {
        $appointment->loadMissing('doctor:id,name', 'patient:id,lead_id');

        $leadId = $appointment->lead_id ?: $appointment->patient?->lead_id;

        if (! $leadId) {
            return;
        }

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
