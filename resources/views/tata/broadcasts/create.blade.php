@extends('layouts.app')

@section('title', 'New Broadcast')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('tata.broadcasts.index') }}">Broadcasts</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<h5 class="mb-3">New voice broadcast</h5>

<form id="bcastForm">
    @csrf
    <div class="row g-3">
        {{-- Audience --}}
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Audience (CRM lead segment)</div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label">Stage</label><select name="stage_id" class="form-select js-filter"><option value="">Any</option>@foreach($stages as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="form-label">Source</label><select name="source_id" class="form-select js-filter"><option value="">Any</option>@foreach($sources as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="form-label">Centre</label><select name="center_id" class="form-select js-filter"><option value="">Any</option>@foreach($centers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="form-label">Owner</label><select name="assigned_to" class="form-select js-filter"><option value="">Any</option>@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="form-label">Disposition</label><select name="disposition_id" class="form-select js-filter"><option value="">Any</option>@foreach($dispositions as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="form-label">Status</label><select name="status" class="form-select js-filter"><option value="">Any</option>@foreach(['new','contacted','qualified','converted','lost'] as $st)<option value="{{ $st }}">{{ ucfirst($st) }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="form-label">Created from</label><input type="date" name="created_from" class="form-control js-filter"></div>
                        <div class="col-6"><label class="form-label">Created to</label><input type="date" name="created_to" class="form-control js-filter"></div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 d-flex justify-content-between align-items-center">
                        <span>Matching leads with a mobile number</span>
                        <span class="h5 mb-0" id="previewCount">—</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Broadcast settings --}}
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Broadcast</div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required></div>
                    <div class="mb-2"><label class="form-label">Description</label><input type="text" name="description" class="form-control"></div>
                    <div class="row g-2">
                        <div class="col-5">
                            <label class="form-label">Answer routes to</label>
                            <select name="destination_type" id="destType" class="form-select">
                                <option value="ivr">IVR</option><option value="department">Department</option><option value="agent">Agent</option>
                            </select>
                        </div>
                        <div class="col-7">
                            <label class="form-label">Destination</label>
                            <select name="destination_id" id="destId" class="form-select">
                                <optgroup label="IVR" class="dest-ivr">@foreach($ivrs as $i)<option value="{{ $i->smartflo_id }}" data-type="ivr">{{ $i->name }}</option>@endforeach</optgroup>
                                <optgroup label="Department" class="dest-department">@foreach($departments as $d)<option value="{{ $d->smartflo_id }}" data-type="department">{{ $d->name }}</option>@endforeach</optgroup>
                                <optgroup label="Agent" class="dest-agent">@foreach($agents as $a)<option value="{{ $a->agent_id }}" data-type="agent">{{ $a->name }}</option>@endforeach</optgroup>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6"><label class="form-label">Caller ID</label>
                            <select name="caller_id" class="form-select">
                                @foreach($numbers as $n)<option value="{{ $n->did }}" @selected($n->is_default)>{{ $n->did }} {{ $n->name ? '· ' . $n->name : '' }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-3"><label class="form-label">Timeout</label><input type="number" name="timeout" class="form-control" value="45" min="40" max="120"></div>
                        <div class="col-3"><label class="form-label">Retries</label><input type="number" name="number_of_retry" class="form-control" value="1" min="0" max="5"></div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6"><label class="form-label">Start</label><input type="datetime-local" name="start_at" class="form-control" required value="{{ now()->addHour()->format('Y-m-d\TH:i') }}"></div>
                        <div class="col-6"><label class="form-label">End</label><input type="datetime-local" name="end_at" class="form-control" required value="{{ now()->addHours(5)->format('Y-m-d\TH:i') }}"></div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6"><label class="form-label">Concurrency</label><input type="number" name="concurrent_limit" class="form-control" value="5" min="1" max="50"></div>
                        <div class="col-6"><label class="form-label">Retry after (min, comma-sep)</label><input type="text" name="retry_after_minutes" class="form-control" value="30"></div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-cloud-upload me-1"></i>Create on Smartflo</button>
                    <div class="form-text mt-2">The broadcast is created paused; start it from its detail page.</div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
$(function () {
    function filters() {
        const f = {};
        $('.js-filter').each(function () { if (this.value) f[this.name] = this.value; });
        return f;
    }
    function preview() {
        $.post(@json(route('tata.broadcasts.preview')), { ...filters(), _token: @json(csrf_token()) })
            .done(res => $('#previewCount').text(res.count)).fail(() => $('#previewCount').text('?'));
    }
    $('.js-filter').on('change', preview); preview();

    function syncDest() {
        const t = $('#destType').val();
        $('#destId optgroup').prop('disabled', true).hide();
        $('#destId optgroup.dest-' + t).prop('disabled', false).show();
        const first = $('#destId optgroup.dest-' + t + ' option:first');
        if (first.length) $('#destId').val(first.val());
    }
    $('#destType').on('change', syncDest); syncDest();

    $('#bcastForm').on('submit', function (e) {
        e.preventDefault();
        const $b = $(this).find('[type=submit]').prop('disabled', true);
        $.post(@json(route('tata.broadcasts.store')), $(this).serialize() + '&' + $.param(filters()))
            .done(res => { toastr.success(res.message); if (res.redirect) setTimeout(() => location.href = res.redirect, 1000); })
            .fail(xhr => { toastr.error(xhr.responseJSON?.message || 'Could not create the broadcast.'); $b.prop('disabled', false); });
    });
});
</script>
@endpush
