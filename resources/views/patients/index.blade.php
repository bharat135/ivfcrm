@extends('layouts.app')

@section('title', 'Patients')

@section('breadcrumb')
    <li class="breadcrumb-item active">Patients</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h5 class="fw-bold mb-1">Patients</h5>
        <p class="text-muted small mb-0">
            Every enquiry is a patient record from the moment it arrives — paid leads, landing pages and walk-ins in one list.
        </p>
    </div>
    @can('patients.create')
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPatientModal">
        <i class="bi bi-plus-lg me-1"></i>Register patient
    </button>
    @endcan
</div>

{{-- Stage chips double as the primary filter. --}}
<div class="stage-chips mb-3">
    <button type="button" class="stage-chip active" data-stage="">
        All <span class="chip-count">{{ number_format($counts['all'] ?? 0) }}</span>
    </button>
    @foreach($stages as $key => $label)
        <button type="button" class="stage-chip" data-stage="{{ $key }}">
            <i class="bi {{ \App\Support\PatientJourney::icon($key) }}"></i>
            {{ $label }} <span class="chip-count">{{ number_format($counts[$key] ?? 0) }}</span>
        </button>
    @endforeach
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">Channel</label>
                <select class="form-select form-select-sm" id="fChannel">
                    <option value="">All channels</option>
                    @foreach($channels as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">Source</label>
                <select class="form-select form-select-sm" id="fSource">
                    <option value="">All sources</option>
                    @foreach($sources as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">Center</label>
                <select class="form-select form-select-sm" id="fCenter">
                    <option value="">All centers</option>
                    @foreach($centers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">Owner</label>
                <select class="form-select form-select-sm" id="fOwner">
                    <option value="">Anyone</option>
                    @foreach($owners as $o)
                        <option value="{{ $o->id }}">{{ $o->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-semibold mb-1">Enquiry from</label>
                <input type="text" class="form-control form-control-sm flatpickr-date" id="fFrom" placeholder="Any date">
            </div>
            <div class="col-6 col-md-2">
                <div class="d-flex gap-2">
                    <div class="form-check form-switch mt-1">
                        <input class="form-check-input" type="checkbox" id="fDue">
                        <label class="form-check-label small" for="fDue">Follow-up due</label>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" id="fReset" title="Clear filters">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="patientTable" style="width:100%">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Stage</th>
                        <th>Came from</th>
                        <th>Center</th>
                        <th>Owner</th>
                        <th>Next follow-up</th>
                        <th>First enquiry</th>
                        <th width="120">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

@include('patients._create_modal')
@endsection

@push('styles')
<style>
    .stage-chips { display: flex; flex-wrap: wrap; gap: 6px; }
    .stage-chip {
        display: inline-flex; align-items: center; gap: 6px;
        border: 1px solid var(--fui-border-subtle); background: var(--fui-surface);
        color: var(--fui-ink-muted); border-radius: 999px;
        padding: 5px 12px; font-size: var(--fui-text-sm); font-weight: 500;
        transition: background .12s ease, color .12s ease, border-color .12s ease;
    }
    .stage-chip:hover { background: var(--fui-surface-hover); color: var(--fui-ink); }
    .stage-chip.active {
        background: var(--fui-blue-600); border-color: var(--fui-blue-600); color: #fff;
    }
    .stage-chip .chip-count {
        background: rgba(0,0,0,.06); border-radius: 999px;
        padding: 0 7px; font-size: var(--fui-text-xs); font-variant-numeric: tabular-nums;
    }
    .stage-chip.active .chip-count { background: rgba(255,255,255,.22); }
    .person-avatar {
        width: 30px; height: 30px; flex: 0 0 30px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        background: var(--fui-surface-hover); color: var(--fui-ink-muted);
        font-size: var(--fui-text-xs); font-weight: 600;
    }
    .min-w-0 { min-width: 0; }
</style>
@endpush

@push('scripts')
<script>
const filters = { stage: '', channel: '', source_id: '', center_id: '', owner_id: '', from: '', due: 0 };

const table = $('#patientTable').DataTable({
    processing: true,
    serverSide: true,
    order: [[6, 'desc']],            // newest enquiry first
    ajax: {
        url: '{{ route("patients.index") }}',
        data: d => Object.assign(d, filters),
    },
    columns: [
        { data: 'person',            name: 'person' },
        { data: 'stage',             name: 'stage' },
        { data: 'origin',            name: 'origin' },
        { data: 'center_name',       name: 'centers.name' },
        { data: 'owner_name',        name: 'users.name' },
        { data: 'next_follow_up',    name: 'next_follow_up' },
        { data: 'first_enquiry_at',  name: 'patients.first_enquiry_at' },
        { data: 'action',            name: 'action', orderable: false, searchable: false },
    ],
    buttons: [{ extend: 'csv', title: 'Patients', className: 'btn btn-sm btn-outline-secondary' }],
    language: {
        emptyTable: FUI.emptyState('bi-person-vcard', 'Nobody here yet',
            'Enquiries from ads, landing pages and WhatsApp appear here automatically.'),
    },
});

$('.stage-chip').on('click', function () {
    $('.stage-chip').removeClass('active');
    $(this).addClass('active');
    filters.stage = $(this).data('stage') || '';
    table.ajax.reload();
});

$('#fChannel, #fSource, #fCenter, #fOwner, #fFrom, #fDue').on('change', function () {
    filters.channel   = $('#fChannel').val();
    filters.source_id = $('#fSource').val();
    filters.center_id = $('#fCenter').val();
    filters.owner_id  = $('#fOwner').val();
    filters.from      = $('#fFrom').val();
    filters.due       = $('#fDue').is(':checked') ? 1 : 0;
    table.ajax.reload();
});

$('#fReset').on('click', function () {
    $('#fChannel, #fSource, #fCenter, #fOwner').val('');
    $('#fFrom').val('');
    $('#fDue').prop('checked', false);
    $('.stage-chip').removeClass('active').first().addClass('active');
    Object.keys(filters).forEach(k => filters[k] = k === 'due' ? 0 : '');
    table.ajax.reload();
});

$('#savePatient').on('click', function () {
    const btn = $(this).prop('disabled', true);

    $.post('{{ route("patients.store") }}', {
        name: $('#pName').val(),
        mobile: $('#pMobile').val(),
        email: $('#pEmail').val(),
        gender: $('#pGender').val(),
        age: $('#pAge').val(),
        center_id: $('#pCenter').val(),
        source_id: $('#pSource').val(),
        husband_name: $('#pHusband').val(),
    }).done(r => {
        if (r.success && r.redirect) window.location = r.redirect;
    }).fail(xhr => {
        const errors = xhr.responseJSON?.errors || {};
        toastr.error(Object.values(errors).flat().join('<br>') || 'Could not register this patient.');
        btn.prop('disabled', false);
    });
});
</script>
@endpush
