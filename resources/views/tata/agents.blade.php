@extends('layouts.app')

@section('title', 'Tata Agents')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('tata.index') }}">Tata Teleservices</a></li>
    <li class="breadcrumb-item active">Agents</li>
@endsection

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h5 class="mb-0">Agents</h5>
        <small class="text-muted">{{ $stats['linked'] }} linked · {{ $stats['missing'] }} CRM users without a Smartflo agent · {{ $stats['unlinked'] }} Smartflo agents unlinked</small>
    </div>
    <div class="d-flex gap-2">
        @can('tata.create')
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createSmartfloModal"><i class="bi bi-person-plus me-1"></i>Create Smartflo user</button>
        @endcan
        @can('tata.edit')
            <button class="btn btn-sm btn-outline-primary" id="btnPull"><i class="bi bi-arrow-down-circle me-1"></i>Pull from Smartflo</button>
        @endcan
    </div>
</div>

@include('tata._nav')

@if($dialVia === 'softphone' && $needLoginCalling > 0)
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1 small">
            Calls are set to ring the <strong>Smartflo softphone</strong>, but {{ $needLoginCalling }} agent(s) have
            <strong>login-based calling off</strong>, so Smartflo still rings their mobile. Turn it on, and make sure
            each agent is signed into the Smartflo softphone app.
        </div>
        @can('tata.edit')
            <button class="btn btn-sm btn-warning" id="btnEnableSoftphone"><i class="bi bi-headset me-1"></i>Enable login-based calling for all</button>
        @endcan
    </div>
@endif

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">CRM users</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>User</th><th>Role</th><th>Centre</th><th>Rings on</th><th>Smartflo</th><th class="text-end">Actions</th>
                </tr></thead>
                <tbody>
                @foreach($users as $user)
                    @php $agent = $user->tataAgent; @endphp
                    <tr>
                        <td><div class="fw-semibold">{{ $user->name }}</div><div class="small text-muted">{{ $user->email }}</div></td>
                        <td class="small">{{ $user->roles->pluck('name')->join(', ') }}</td>
                        <td class="small">{{ $user->center?->name ?? '—' }}</td>
                        <td class="font-monospace small">{{ $user->tata_agent_number ?: $user->phone ?: '—' }}</td>
                        <td>
                            @if($agent)
                                <span class="badge bg-{{ $agent->statusBadge() }}-subtle text-{{ $agent->statusBadge() }}">{{ ucfirst($agent->sync_status) }}</span>
                                @if($agent->extension)<span class="small text-muted ms-1">ext {{ $agent->extension }}</span>@endif
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">No agent</span>
                            @endif
                        </td>
                        <td class="text-end">
                            @can('tata.create')
                                @unless($agent && $agent->smartflo_user_id)
                                    <button class="btn btn-sm btn-outline-success js-push" data-url="{{ route('tata.agents.push', $user) }}" data-name="{{ $user->name }}">
                                        <i class="bi bi-cloud-upload me-1"></i>Create in Smartflo
                                    </button>
                                @endunless
                            @endcan
                            @can('tata.edit')
                                @if($agent && $agent->smartflo_user_id)
                                    <button class="btn btn-sm btn-outline-secondary js-update" data-url="{{ route('tata.agents.update', $user) }}" title="Push CRM profile to Smartflo"><i class="bi bi-arrow-repeat me-1"></i>Push</button>
                                    <button class="btn btn-sm btn-outline-primary js-modify" data-id="{{ $agent->id }}"><i class="bi bi-pencil me-1"></i>Modify</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@if($unlinked->isNotEmpty())
<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">Unlinked Smartflo agents</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Login</th><th>Number</th><th>Extension</th><th class="text-end">Link to CRM user</th></tr></thead>
                <tbody>
                @foreach($unlinked as $agent)
                    <tr>
                        <td>{{ $agent->name ?: '—' }}</td>
                        <td class="small">{{ $agent->login_id ?: '—' }}</td>
                        <td class="font-monospace small">{{ $agent->follow_me_number ?: '—' }}</td>
                        <td class="font-monospace small">{{ $agent->extension ?: '—' }}</td>
                        <td class="text-end">
                            @can('tata.edit')
                            <div class="input-group input-group-sm justify-content-end" style="max-width:320px;margin-left:auto">
                                <select class="form-select js-link-user">
                                    <option value="">Choose a user…</option>
                                    @foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                                </select>
                                <button class="btn btn-outline-primary js-link" data-url="{{ route('tata.agents.link', $agent) }}">Link</button>
                            </div>
                            <button class="btn btn-sm btn-outline-primary js-modify mt-1" data-id="{{ $agent->id }}"><i class="bi bi-pencil me-1"></i>Modify</button>
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

{{-- Create Smartflo user --}}
@can('tata.create')
<div class="modal fade" id="createSmartfloModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form id="createSmartfloForm" class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Create Smartflo user</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control form-control-sm" required></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Login id <span class="text-danger">*</span></label><input type="text" name="login_id" class="form-control form-control-sm" required placeholder="email or username"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Email <span class="text-danger">*</span></label><input type="email" name="email" class="form-control form-control-sm" required></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Password <span class="text-danger">*</span></label><input type="text" name="password" class="form-control form-control-sm font-monospace" required minlength="8"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Mobile number <span class="text-danger">*</span></label><input type="text" name="number" class="form-control form-control-sm font-monospace" required placeholder="9876500001"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Route call through</label>
                        <select name="route_call_through" class="form-select form-select-sm">
                            <option value="2" selected>Softphone + mobile</option>
                            <option value="1">Softphone (extension) only</option>
                            <option value="0">Mobile only</option>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Link to CRM user</label>
                        <select name="link_user_id" class="form-select form-select-sm">
                            <option value="">-- none --</option>
                            @foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-4 mt-1">
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="cLbc" checked><label class="form-check-label small" for="cLbc">Login-based calling (softphone)</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="cExt" checked><label class="form-check-label small" for="cExt">Assign extension</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="cBwl"><label class="form-check-label small" for="cBwl">Block web login</label></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Create</button></div>
        </form>
    </div>
</div>
@endcan

{{-- Modify Smartflo user --}}
@can('tata.edit')
<div class="modal fade" id="modifySmartfloModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form id="modifySmartfloForm" class="modal-content">
            <input type="hidden" name="agent_id">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Modify Smartflo user</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label small fw-semibold">Name</label><input type="text" name="name" class="form-control form-control-sm"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Email</label><input type="email" name="email" class="form-control form-control-sm"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">Mobile number</label><input type="text" name="number" class="form-control form-control-sm font-monospace"></div>
                    <div class="col-md-6"><label class="form-label small fw-semibold">New password <span class="text-muted">(optional)</span></label><input type="text" name="password" class="form-control form-control-sm font-monospace" placeholder="leave blank to keep"></div>
                    <div class="col-12"><div class="small text-muted">Extension: <span class="font-monospace" id="mExt">--</span></div></div>
                    <div class="col-12 d-flex flex-wrap gap-4 mt-1">
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="mLbc"><label class="form-check-label small" for="mLbc">Login-based calling</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="mBwl"><label class="form-check-label small" for="mBwl">Block web login</label></div>
                        <div class="form-check"><input class="form-check-input" type="checkbox" id="mBlk"><label class="form-check-label small" for="mBlk">Block agent (disable)</label></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save changes</button></div>
        </form>
    </div>
</div>
@endcan
@endsection

@push('scripts')
<script>
$(function () {
    const post = (url, data, ok) => $.post(url, data || {}).done(res => { res.success ? toastr.success(res.message) : toastr.warning(res.message); ok && ok(res); })
        .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Action failed.'));

    $('#btnPull').on('click', function () {
        const $b = $(this).prop('disabled', true);
        post(@json(route('tata.agents.pull')), {}, () => setTimeout(() => location.reload(), 1200));
        setTimeout(() => $b.prop('disabled', false), 3000);
    });

    $('.js-push').on('click', function () {
        if (!confirm('Create ' + $(this).data('name') + ' as a Smartflo agent?')) return;
        post($(this).data('url'), {}, () => setTimeout(() => location.reload(), 1200));
    });
    $('.js-update').on('click', function () { post($(this).data('url'), {}, () => setTimeout(() => location.reload(), 1000)); });
    $('.js-link').on('click', function () {
        const user = $(this).closest('.input-group').find('.js-link-user').val();
        if (!user) return toastr.warning('Choose a user first.');
        post($(this).data('url'), { user_id: user }, () => setTimeout(() => location.reload(), 1000));
    });

    // Create Smartflo user
    $('#createSmartfloForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type=submit]').prop('disabled', true);
        const payload = $(this).serializeArray().reduce((o, f) => (o[f.name] = f.value, o), {});
        payload.login_based_calling = $('#cLbc').is(':checked') ? 1 : 0;
        payload.assign_extension    = $('#cExt').is(':checked') ? 1 : 0;
        payload.block_web_login     = $('#cBwl').is(':checked') ? 1 : 0;
        payload._token = @json(csrf_token());
        $.post(@json(route('tata.agents.create_user')), payload)
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 1200); })
            .fail(xhr => { const er = xhr.responseJSON?.errors; toastr.error(er ? Object.values(er).flat().join('<br>') : (xhr.responseJSON?.message || 'Could not create.')); btn.prop('disabled', false); });
    });

    // Modify Smartflo user
    $(document).on('click', '.js-modify', function () {
        const id = $(this).data('id');
        $.get(@json(url('tata/agents')) + '/' + id + '/details').done(d => {
            const f = document.getElementById('modifySmartfloForm');
            f.reset();
            $('[name=agent_id]', f).val(d.id);
            $('[name=name]', f).val(d.name); $('[name=email]', f).val(d.email); $('[name=number]', f).val(d.number);
            $('#mExt').text(d.extension || '--');
            $('#mLbc').prop('checked', d.login_based_calling);
            $('#mBwl').prop('checked', d.block_web_login);
            $('#mBlk').prop('checked', d.block_agent);
            new bootstrap.Modal('#modifySmartfloModal').show();
        }).fail(() => toastr.error('Could not load the user.'));
    });
    $('#modifySmartfloForm').on('submit', function (e) {
        e.preventDefault();
        const id = $('[name=agent_id]', this).val();
        const btn = $(this).find('[type=submit]').prop('disabled', true);
        const payload = {
            _token: @json(csrf_token()),
            name: $('[name=name]', this).val(), email: $('[name=email]', this).val(),
            number: $('[name=number]', this).val(), password: $('[name=password]', this).val(),
            login_based_calling: $('#mLbc').is(':checked') ? 1 : 0,
            block_web_login: $('#mBwl').is(':checked') ? 1 : 0,
            block_agent: $('#mBlk').is(':checked') ? 1 : 0,
        };
        $.post(@json(url('tata/agents')) + '/' + id + '/modify', payload)
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 1000); })
            .fail(xhr => { toastr.error(xhr.responseJSON?.message || 'Could not save.'); btn.prop('disabled', false); });
    });


    $('#btnEnableSoftphone').on('click', function () {
        const btn = $(this).prop('disabled', true);
        $.post(@json(route('tata.agents.enable_softphone')), { _token: @json(csrf_token()) })
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 1500); })
            .fail(xhr => { toastr.error(xhr.responseJSON?.message || 'Failed.'); btn.prop('disabled', false); });
    });

});
</script>
@endpush
