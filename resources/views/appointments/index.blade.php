@extends('layouts.app')

@section('title', 'Appointments')

@section('breadcrumb')
    <li class="breadcrumb-item active">Appointments</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Appointment Management</h5>
    @can('appointments.create')
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bookModal">
        <i class="bi bi-plus-lg me-1"></i>Book Appointment
    </button>
    @endcan
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="appointmentTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        {{-- Secondary columns are hidden on phones so the table fits the screen. --}}
                        <th>Patient</th><th class="d-none d-md-table-cell">Mobile</th><th>Doctor</th>
                        <th>Date</th><th class="d-none d-md-table-cell">Slot</th><th class="d-none d-lg-table-cell">Type</th>
                        <th>Status</th><th class="d-none d-lg-table-cell">Center</th><th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

@include('appointments._support')

<!-- Book Modal -->
<div class="modal fade" id="bookModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Book Appointment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Doctor <span class="text-danger">*</span></label>
                        <select class="form-select" id="bookDoctor" required>
                            <option value="">Select Doctor</option>
                            @foreach($doctors as $d)
                                <option value="{{ $d->id }}" data-center="{{ $d->center_id ?? '' }}">
                                    Dr. {{ $d->name }}{{ $d->specialization ? ' — ' . $d->specialization : '' }}{{ $d->center ? ' (' . $d->center->name . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @if($doctors->isEmpty())
                            <div class="form-text text-danger">
                                No active doctors yet.
                                @can('doctors.create')
                                    <a href="{{ route('settings.doctors.index') }}">Add doctors under Settings → Doctors</a> first.
                                @else
                                    Ask an administrator to add doctors under Settings → Doctors.
                                @endcan
                            </div>
                        @endif
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Center <span class="text-danger">*</span></label>
                        <select class="form-select" id="bookCenter" required>
                            @foreach($centers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Date &amp; Time <span class="text-danger">*</span></label>
                        <input type="text" class="form-control flatpickr-dt" id="bookScheduledAt" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Duration (minutes)</label>
                        <select class="form-select" id="bookDuration">
                            <option value="30">30 min</option>
                            <option value="45">45 min</option>
                            <option value="60">60 min</option>
                            <option value="90">90 min</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Type</label>
                        <select class="form-select" id="bookType">
                            <option value="consultation">Consultation</option>
                            <option value="follow_up">Follow-Up</option>
                            <option value="procedure">Procedure</option>
                            <option value="investigation">Investigation</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Patient (if existing)</label>
                        <select class="form-select select2-patient" id="bookPatient">
                            <option value="">New / Walk-In</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea class="form-control" id="bookNotes" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary btn-sm" id="saveAppointment">Book Appointment</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
flatpickr('.flatpickr-dt', { enableTime: true, altInput: true, altFormat: 'd-m-Y h:i K', dateFormat: 'Y-m-d H:i', minDate: 'today', minuteIncrement: 15 });

// Only offer doctors who consult at the chosen centre (doctors with no centre consult everywhere).
function filterDoctorsByCenter() {
    const center = $('#bookCenter').val();
    $('#bookDoctor option[data-center]').each(function () {
        const own = $(this).data('center');
        const show = !center || !own || String(own) === String(center);
        $(this).prop('hidden', !show).prop('disabled', !show);
    });
    if ($('#bookDoctor option:selected').prop('disabled')) $('#bookDoctor').val('');
}
$('#bookCenter').on('change', filterDoctorsByCenter);
$('#bookModal').on('show.bs.modal', filterDoctorsByCenter);
filterDoctorsByCenter();

const table = $('#appointmentTable').DataTable({
    processing: true, serverSide: true,
    order: [[3, 'desc']],   // Scheduled, newest first
    ajax: '{{ route("appointments.index") }}',
    columns: [
        { data: 'patient.wife_name', defaultContent: '—' },
        { data: 'patient.mobile', defaultContent: '—', className: 'd-none d-md-table-cell' },
        { data: 'doctor.name', defaultContent: '—', render: d => d ? 'Dr. ' + d : '—' },
        // On phones the slot column is hidden, so the time rides with the date.
        { data: 'scheduled_at', render: d => FUI.date(d) + '<span class="d-md-none d-block small text-muted">' + FUI.time(d) + '</span>' },
        { data: 'scheduled_at', render: d => FUI.time(d), className: 'd-none d-md-table-cell' },
        { data: 'type', render: d => `<span class="badge bg-info text-dark">${d}</span>`, className: 'd-none d-lg-table-cell' },
        { data: 'status', render: d => {
            const c = {booked:'primary',confirmed:'info',visited:'success',cancelled:'danger',no_show:'warning text-dark',rescheduled:'secondary'};
            return `<span class="badge bg-${c[d]||'secondary'}">${d}</span>`;
        }},
        { data: 'center.code', defaultContent: '—', className: 'd-none d-lg-table-cell' },
        { data: 'action', orderable: false, searchable: false }
    ],
    buttons: [{ extend: 'csv', className: 'btn btn-sm btn-outline-secondary' }],
    language: { emptyTable: FUI.emptyState('bi-calendar-check', 'No appointments', 'Book one from a lead or patient record.') }
});

window.appointmentTable = table;

$('#saveAppointment').on('click', function() {
    $.post('{{ route("appointments.store") }}', {
        doctor_id:        $('#bookDoctor').val(),
        center_id:        $('#bookCenter').val(),
        scheduled_at:     $('#bookScheduledAt').val(),
        duration_minutes: $('#bookDuration').val(),
        type:             $('#bookType').val(),
        patient_id:       $('#bookPatient').val() || null,
        notes:            $('#bookNotes').val(),
    }).done(r => {
        if (r.success) {
            toastr.success('Appointment booked successfully.');
            bootstrap.Modal.getInstance($('#bookModal')[0]).hide();
            table.ajax.reload();
        }
    });
});
</script>
@endpush
