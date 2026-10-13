@extends('layouts.app')

@section('title', 'API & Developers')

@section('breadcrumb')
    <li class="breadcrumb-item active">API &amp; Developers</li>
@endsection

@section('content')
<div class="row g-3">
    {{-- Tokens --}}
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-key me-2"></i>API tokens</span>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newTokenModal"><i class="bi bi-plus-lg me-1"></i>New token</button>
            </div>
            <div class="card-body">
                <p class="small text-muted">A token is a password for the API. Create one, copy it once, and put it in the <code>Authorization: Bearer</code> header. Revoke it any time.</p>
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Name</th><th>Last used</th><th></th></tr></thead>
                    <tbody id="tokenRows">
                        @forelse($tokens as $token)
                            <tr data-id="{{ $token->id }}">
                                <td class="fw-semibold">{{ $token->name }}</td>
                                <td class="small text-muted">{{ $token->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="text-end"><button class="btn btn-xs btn-outline-danger js-revoke" data-id="{{ $token->id }}">Revoke</button></td>
                            </tr>
                        @empty
                            <tr id="noTokens"><td colspan="3" class="text-center text-muted small py-3">No tokens yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-lightning-charge me-2"></i>Quick start</div>
            <div class="card-body">
                <p class="small mb-2">Push a lead in with one request:</p>
                <pre class="bg-dark text-light p-3 rounded small" style="overflow:auto"><code>curl -X POST {{ $baseUrl }}/leads \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Anita Sharma",
    "mobile": "9812345678",
    "email": "anita@example.com",
    "utm_source": "website",
    "remark": "Enquired about IVF"
  }'</code></pre>
            </div>
        </div>
    </div>

    {{-- Reference --}}
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-book me-2"></i>API reference</div>
            <div class="card-body">
                <dl class="row small mb-3">
                    <dt class="col-4 text-muted">Base URL</dt><dd class="col-8 font-monospace">{{ $baseUrl }}</dd>
                    <dt class="col-4 text-muted">Auth</dt><dd class="col-8 font-monospace">Authorization: Bearer &lt;token&gt;</dd>
                    <dt class="col-4 text-muted">Format</dt><dd class="col-8">JSON. Always send <code>Accept: application/json</code>.</dd>
                </dl>

                <h6 class="fw-semibold mt-4"><span class="badge bg-success">POST</span> /leads <span class="text-muted fw-normal">— create a lead</span></h6>
                <p class="small text-muted">A duplicate mobile is not rejected: the enquiry is recorded against the existing lead and its source journey is tracked. Response is <code>201</code> for a new lead, <code>200</code> when deduped.</p>
                <table class="table table-sm">
                    <thead class="table-light"><tr><th>Field</th><th>Type</th><th>Notes</th></tr></thead>
                    <tbody>
                        <tr><td class="font-monospace">mobile</td><td>string</td><td><span class="badge bg-danger">required</span> 10-digit Indian mobile</td></tr>
                        <tr><td class="font-monospace">name</td><td>string</td><td>Full name</td></tr>
                        <tr><td class="font-monospace">alt_mobile</td><td>string</td><td>Alternate 10-digit mobile</td></tr>
                        <tr><td class="font-monospace">email</td><td>string</td><td>Valid email</td></tr>
                        <tr><td class="font-monospace">gender</td><td>string</td><td>male / female / other</td></tr>
                        <tr><td class="font-monospace">age, wife_age, husband_age</td><td>int</td><td>Years</td></tr>
                        <tr><td class="font-monospace">years_married, years_trying</td><td>int</td><td>Years</td></tr>
                        <tr><td class="font-monospace">city_id, state_id, pincode</td><td>int / string</td><td>Location</td></tr>
                        <tr><td class="font-monospace">lead_source_id</td><td>int</td><td>See sources below</td></tr>
                        <tr><td class="font-monospace">campaign_id, adset_id, ad_id</td><td>int</td><td>Marketing ids</td></tr>
                        <tr><td class="font-monospace">center_id</td><td>int</td><td>See centres below</td></tr>
                        <tr><td class="font-monospace">assigned_to</td><td>int</td><td>User id; else auto-assigned</td></tr>
                        <tr><td class="font-monospace">utm_source, utm_medium, utm_campaign, utm_term, utm_content</td><td>string</td><td>Attribution</td></tr>
                        <tr><td class="font-monospace">gclid, fbclid, external_lead_id</td><td>string</td><td>Click / provider ids</td></tr>
                        <tr><td class="font-monospace">source_name</td><td>string</td><td>Free-text source label</td></tr>
                        <tr><td class="font-monospace">lead_date</td><td>datetime</td><td>Original enquiry time</td></tr>
                        <tr><td class="font-monospace">remark</td><td>string</td><td>Free text</td></tr>
                        <tr><td class="font-monospace">extra</td><td>object</td><td>Any custom fields</td></tr>
                    </tbody>
                </table>

                <h6 class="fw-semibold mt-4"><span class="badge bg-primary">GET</span> /leads <span class="text-muted fw-normal">— list leads</span></h6>
                <p class="small text-muted mb-1">Filters: <code>mobile</code>, <code>status</code>, <code>center_id</code>, <code>from</code>, <code>to</code>, <code>per_page</code> (max 100). Paginated.</p>

                <h6 class="fw-semibold mt-4"><span class="badge bg-primary">GET</span> /leads/{id} <span class="text-muted fw-normal">— one lead with its source journey</span></h6>

                <h6 class="fw-semibold mt-4"><span class="badge bg-success">POST</span> /leads/{id}/disposition <span class="text-muted fw-normal">— set the outcome</span></h6>
                <p class="small text-muted">Body: <code>disposition_id</code> (required), <code>sub_disposition_id</code>, <code>remark</code> (required), <code>next_follow_up_at</code>.</p>

                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="fw-semibold small">Lead source ids</h6>
                        <table class="table table-sm"><tbody>
                            @foreach($sources as $s)<tr><td class="font-monospace">{{ $s->id }}</td><td>{{ $s->name }}</td></tr>@endforeach
                        </tbody></table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-semibold small">Centre ids</h6>
                        <table class="table table-sm"><tbody>
                            @foreach($centers as $c)<tr><td class="font-monospace">{{ $c->id }}</td><td>{{ $c->name }} <span class="text-muted">{{ $c->code }}</span></td></tr>@endforeach
                        </tbody></table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- New token modal --}}
<div class="modal fade" id="newTokenModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h6 class="modal-title">New API token</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div id="tokenForm">
                    <label class="form-label">Token name <span class="text-muted">(who or what will use it)</span></label>
                    <input type="text" class="form-control" id="tokenName" placeholder="Website form" maxlength="60">
                </div>
                <div id="tokenResult" class="d-none">
                    <div class="alert alert-success small mb-2"><i class="bi bi-check-circle me-1"></i>Copy this token now — it will not be shown again.</div>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace" id="tokenValue" readonly>
                        <button class="btn btn-outline-secondary" id="copyToken"><i class="bi bi-clipboard"></i></button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-primary" id="createTokenBtn">Create</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#createTokenBtn').on('click', function () {
        const name = $('#tokenName').val().trim();
        if (!name) return toastr.warning('Give the token a name.');
        const $btn = $(this).prop('disabled', true);

        $.post('{{ route('developers.tokens.store') }}', { name })
            .done(res => {
                $('#tokenForm').addClass('d-none');
                $('#tokenResult').removeClass('d-none');
                $('#tokenValue').val(res.token);
                $btn.addClass('d-none');
                $('#noTokens').remove();
                $('#tokenRows').prepend(`<tr data-id="${res.id}"><td class="fw-semibold">${$('<div>').text(name).html()}</td><td class="small text-muted">never</td><td class="text-end"><button class="btn btn-xs btn-outline-danger js-revoke" data-id="${res.id}">Revoke</button></td></tr>`);
            })
            .fail(xhr => { toastr.error(xhr.responseJSON?.message || 'Could not create the token.'); $btn.prop('disabled', false); });
    });

    $('#copyToken').on('click', function () {
        navigator.clipboard.writeText($('#tokenValue').val()).then(() => toastr.success('Copied.'));
    });

    $('#newTokenModal').on('hidden.bs.modal', function () {
        $('#tokenForm').removeClass('d-none'); $('#tokenResult').addClass('d-none');
        $('#tokenName').val(''); $('#tokenValue').val('');
        $('#createTokenBtn').removeClass('d-none').prop('disabled', false);
    });

    $(document).on('click', '.js-revoke', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Revoke this token?', text: 'Anything using it will stop working.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Revoke', confirmButtonColor: '#dc3545' })
            .then(r => {
                if (!r.isConfirmed) return;
                $.ajax({ url: '{{ url('developers/tokens') }}/' + id, method: 'POST', data: { _method: 'DELETE', _token: '{{ csrf_token() }}' } })
                    .done(() => { $(`tr[data-id="${id}"]`).remove(); toastr.success('Token revoked.'); });
            });
    });
});
</script>
@endpush
