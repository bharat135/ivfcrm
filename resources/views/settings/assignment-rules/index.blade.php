@extends('layouts.app')

@section('title', 'Lead Assignment')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Lead Assignment</h4>
            <p class="text-muted small mb-0">
                Who gets a new enquiry. Rules run top-down by priority; the first match wins. Every intake
                path — Meta lead ads, landing pages, imports, API, front desk — uses these rules.
            </p>
        </div>
        @can('assignment_rules.create')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#ruleModal">
            <i class="bi bi-plus-lg"></i> Add Rule
        </button>
        @endcan
    </div>

    {{-- Fallback --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label class="form-label fw-semibold mb-1">When no rule matches</label>
                    <select id="fallbackSelect" class="form-select form-select-sm" {{ auth()->user()->can('assignment_rules.edit') ? '' : 'disabled' }}>
                        @foreach($fallbacks as $key => $label)
                            <option value="{{ $key }}" {{ $fallback === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">
                        Round-robin picks the <strong>online</strong> agent with the fewest leads assigned today.
                        An agent must be active, marked available and seen in the app within the online window.
                        Leads that arrive while nobody is online wait and are handed out the minute someone appears.
                        Online now: <strong>{{ $presence['online_now'] }}</strong> agent(s).
                    </div>
                </div>
                <div class="col-md-4 col-lg-2">
                    <label class="form-label fw-semibold mb-1">Online window (min)</label>
                    <input type="number" id="onlineMinutes" class="form-control form-select-sm" min="1" max="120" value="{{ $presence['online_minutes'] }}">
                </div>
                <div class="col-md-4 col-lg-2">
                    <label class="form-label fw-semibold mb-1">Reassign if not called in (min)</label>
                    <input type="number" id="idleMinutes" class="form-control form-select-sm" min="5" max="1440" value="{{ $presence['idle_minutes'] }}">
                </div>
                <div class="col-md-4 col-lg-2">
                    <label class="form-label fw-semibold mb-1">Only for enquiries newer than (hrs)</label>
                    <input type="number" id="idleMaxAge" class="form-control form-select-sm" min="1" max="720" value="{{ $presence['idle_max_age_hours'] }}">
                </div>
                @can('assignment_rules.edit')
                <div class="col-md-2">
                    <button class="btn btn-outline-primary btn-sm" id="btnSaveFallback"><i class="bi bi-check-lg me-1"></i>Save</button>
                </div>
                @endcan
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="rulesTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px">Priority</th>
                            <th>Rule</th>
                            <th>Conditions</th>
                            <th>Method</th>
                            <th>Assign to</th>
                            <th>Routed</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Modal --}}
<div class="modal fade" id="ruleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="ruleForm" novalidate>
            @csrf
            <input type="hidden" id="ruleId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="ruleModalTitle">Add Assignment Rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Rule name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required placeholder="e.g. Meta leads → Mumbai team">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Priority <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="priority" min="0" max="999" value="10" required>
                            <div class="form-text">Lower runs first</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Method</label>
                            <select class="form-select" name="method">
                                @foreach($methods as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <hr class="my-3">
                    <h6 class="fw-semibold small text-uppercase text-muted mb-2">Match when… <span class="fw-normal text-lowercase">(leave blank to match any)</span></h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Center</label>
                            <select class="form-select" name="conditions[center_id]">
                                <option value="">Any center</option>
                                @foreach($centers as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Lead source</label>
                            <select class="form-select" name="conditions[lead_source_id]">
                                <option value="">Any source</option>
                                @foreach($sources as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Channel</label>
                            <select class="form-select" name="conditions[channel]">
                                <option value="">Any channel</option>
                                @foreach($channels as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Campaign</label>
                            <select class="form-select" name="conditions[campaign_id]">
                                <option value="">Any campaign</option>
                                @foreach($campaigns as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">State</label>
                            <select class="form-select" name="conditions[state_id]">
                                <option value="">Any state</option>
                                @foreach($states as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Pincode starts with</label>
                            <input type="text" class="form-control" name="conditions[pincode_prefix]" maxlength="6" placeholder="e.g. 400">
                        </div>
                    </div>

                    <hr class="my-3">
                    <h6 class="fw-semibold small text-uppercase text-muted mb-2">Assign to <span class="text-danger">*</span></h6>
                    <div class="form-text mb-2">Leads rotate among the ticked agents. Unavailable agents are skipped automatically.</div>
                    <div class="row g-2" id="agentList">
                        @forelse($agents as $u)
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input agent-check" type="checkbox" name="user_ids[]" value="{{ $u->id }}" id="agent{{ $u->id }}">
                                    <label class="form-check-label" for="agent{{ $u->id }}">
                                        {{ $u->name }}
                                        @if($u->center)<small class="text-muted">· {{ $u->center->name }}</small>@endif
                                        @unless($u->is_available)<small class="text-warning">· unavailable</small>@endunless
                                    </label>
                                </div>
                            </div>
                        @empty
                            <div class="col-12 text-muted small">No active telecallers or counsellors yet. Add users first.</div>
                        @endforelse
                    </div>

                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="ruleActive" checked>
                        <label class="form-check-label" for="ruleActive">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const base  = '{{ url('settings/assignment-rules') }}';
    const token = '{{ csrf_token() }}';

    const table = $('#rulesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('settings.assignment_rules.index') }}',
        order: [[0, 'asc']],
        columns: [
            { data: 'priority' },
            { data: 'name' },
            { data: 'conditions_summary', orderable: false, searchable: false },
            { data: 'method_label', orderable: false, searchable: false },
            { data: 'targets_summary', orderable: false, searchable: false },
            { data: 'leads_count' },
            { data: 'status_badge', orderable: false, searchable: false },
            { data: 'action', orderable: false, searchable: false },
        ]
    });

    const modal = new bootstrap.Modal('#ruleModal');
    const form  = document.getElementById('ruleForm');

    $('#ruleModal').on('show.bs.modal', function (e) {
        if (e.relatedTarget && !$(e.relatedTarget).data('id')) resetForm('Add Assignment Rule');
    });

    $(form).on('submit', function (e) {
        e.preventDefault();
        const id  = $('#ruleId').val();
        const url = id ? `${base}/${id}` : '{{ route('settings.assignment_rules.store') }}';

        $.ajax({
            url, method: id ? 'PUT' : 'POST',
            data: $(form).serialize() + '&is_active=' + ($('#ruleActive').is(':checked') ? 1 : 0),
            success(res) { if (res.success) { modal.hide(); table.ajax.reload(null, false); toastr.success(res.message); } },
            error(xhr)   {
                const errors = xhr.responseJSON?.errors;
                toastr.error(errors ? Object.values(errors).flat().join('<br>') : (xhr.responseJSON?.message ?? 'Error.'));
            }
        });
    });

    $(document).on('click', '.btn-edit-rule', function () {
        const id = $(this).data('id');
        $.get(`${base}/${id}/edit`, function (r) {
            resetForm('Edit Assignment Rule');
            $('#ruleId').val(r.id);
            $('[name=name]').val(r.name);
            $('[name=priority]').val(r.priority);
            $('[name=method]').val(r.method);
            const c = r.conditions || {};
            $('[name="conditions[center_id]"]').val(c.center_id ?? '');
            $('[name="conditions[lead_source_id]"]').val(c.lead_source_id ?? '');
            $('[name="conditions[channel]"]').val(c.channel ?? '');
            $('[name="conditions[campaign_id]"]').val(c.campaign_id ?? '');
            $('[name="conditions[state_id]"]').val(c.state_id ?? '');
            $('[name="conditions[pincode_prefix]"]').val(c.pincode_prefix ?? '');
            const ids = ((r.targets || {}).user_ids || []).map(String);
            $('.agent-check').each(function () { this.checked = ids.includes(String(this.value)); });
            $('#ruleActive').prop('checked', !!r.is_active);
            modal.show();
        });
    });

    $(document).on('click', '.btn-toggle-rule', function () {
        $.ajax({ url: `${base}/${$(this).data('id')}/toggle`, method: 'PATCH', data: { _token: token },
            success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
            error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
        });
    });

    $(document).on('click', '.btn-delete-rule', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Delete this rule?', text: 'Leads already assigned are not affected.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545' })
            .then(r => {
                if (r.isConfirmed) {
                    $.ajax({ url: `${base}/${id}`, method: 'DELETE', data: { _token: token },
                        success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
                        error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
                    });
                }
            });
    });

    $('#btnSaveFallback').on('click', function () {
        const $btn = $(this).prop('disabled', true);
        $.ajax({ url: '{{ route('settings.assignment_rules.fallback') }}', method: 'PUT',
            data: {
                _token: token,
                fallback: $('#fallbackSelect').val(),
                online_minutes: $('#onlineMinutes').val(),
                idle_minutes: $('#idleMinutes').val(),
                idle_max_age_hours: $('#idleMaxAge').val(),
            },
            success(res) { toastr.success(res.message); },
            error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); },
            complete()  { $btn.prop('disabled', false); }
        });
    });

    function resetForm(title) {
        form.reset();
        $('#ruleId').val('');
        $('#ruleModalTitle').text(title);
        $('[name=priority]').val(10);
        $('#ruleActive').prop('checked', true);
        $('.agent-check').prop('checked', false);
    }
});
</script>
@endpush
