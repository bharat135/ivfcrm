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
        if ($request->ajax() || $request->wantsJson()) {
            $query = Appointment::with(['patient:id,wife_name,mobile', 'doctor:id,name', 'center:id,name,code'])
                ->when(Auth::user()->hasRole('Doctor'),
                    fn($q) => $q->where('doctor_id', Auth::user()->doctor?->id)
                )
                ->when(Auth::user()->hasRole('Center Manager'),
                    fn($q) => $q->where('center_id', Auth::user()->center_id)
                );

            return DataTables::of($query)
                ->addColumn('action', fn($a) => view('appointments._actions', ['appointment' => $a])->render())
                ->rawColumns(['action'])
                ->make(true);
        }

        $centers = Center::active()->get();
        $doctors = Doctor::active()->get();

        return view('appointments.index', compact('centers', 'doctors'));
    }

    public function store(Request $request)
    {
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

        if (!empty($data['lead_id'])) {
            $stage = \App\Models\PipelineStage::where('slug', 'consultation-booked')->first();
            if ($stage) {
                Lead::where('id', $data['lead_id'])->update(['stage_id' => $stage->id]);
            }
        }

        return response()->json(['success' => true, 'id' => $appointment->id]);
    }

    public function update(Request $request, Appointment $appointment)
    {
        $data = $request->validate([
            'scheduled_at'     => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer'],
            'status'           => ['sometimes', 'in:booked,confirmed,visited,no_show,cancelled,rescheduled'],
            'notes'            => ['nullable', 'string'],
        ]);

        $appointment->update($data);

        return response()->json(['success' => true]);
    }

    public function calendar(Request $request)
    {
        $doctorId = $request->get('doctor_id');
        $centerId = $request->get('center_id', Auth::user()->center_id);
        $date     = $request->get('date', today()->toDateString());

        $appointments = Appointment::with(['patient:id,wife_name,mobile', 'doctor:id,name'])
            ->whereDate('scheduled_at', $date)
            ->when($doctorId, fn($q) => $q->where('doctor_id', $doctorId))
            ->when($centerId, fn($q) => $q->where('center_id', $centerId))
            ->get();

        return response()->json($appointments);
    }
}
