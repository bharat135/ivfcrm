@extends('layouts.app')

@section('title', 'Campaigns')

@section('breadcrumb')
    <li class="breadcrumb-item active">Campaigns</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Campaign Management</h5>
    @can('campaigns.create')
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCampaignModal">
        <i class="bi bi-plus-lg me-1"></i>New Campaign
    </button>
    @endcan
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="campaignTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th>Campaign</th><th>Source</th><th>Platform</th>
                        <th>Center</th><th>Leads</th><th>Budget</th>
                        <th>Spend</th><th>CPL</th><th>Status</th><th width="80">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addCampaignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">New Campaign</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Campaign Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="cName" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Lead Source <span class="text-danger">*</span></label>
                        <select class="form-select" id="cSource" required>
                            @foreach($sources as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Platform</label>
                        <select class="form-select" id="cPlatform">
                            <option value="">Select Platform</option>
                            <option value="meta">Meta (Facebook/Instagram)</option>
                            <option value="google">Google</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Center</label>
                        <select class="form-select" id="cCenter">
                            @foreach($centers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Budget (₹)</label>
                        <input type="number" class="form-control" id="cBudget" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Start Date</label>
                        <input type="date" class="form-control" id="cStart">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">End Date</label>
                        <input type="date" class="form-control" id="cEnd">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">UTM Source</label>
                        <input type="text" class="form-control" id="cUtmSource">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">UTM Medium</label>
                        <input type="text" class="form-control" id="cUtmMedium">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">UTM Campaign</label>
                        <input type="text" class="form-control" id="cUtmCampaign">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary btn-sm" id="saveCampaign">Save Campaign</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const table = $('#campaignTable').DataTable({
    processing: true, serverSide: true,
    order: [[4, 'desc']],   // Most leads first
    ajax: '{{ route("campaigns.index") }}',
    columns: [
        { data: 'name' },
        { data: 'source.name', defaultContent: '—' },
        { data: 'platform', defaultContent: '—', render: d => d ? `<span class="badge bg-info text-dark">${d}</span>` : '—' },
        { data: 'center.name', defaultContent: '—' },
        { data: 'leads_count', defaultContent: 0 },
        { data: 'budget', render: d => d ? '₹' + parseFloat(d).toLocaleString('en-IN') : '—' },
        { data: 'spend', render: d => d ? '₹' + parseFloat(d).toLocaleString('en-IN') : '₹0' },
        { data: 'cpl' },
        { data: 'is_active', render: d => `<span class="badge ${d?'bg-success':'bg-secondary'}">${d?'Active':'Inactive'}</span>` },
        { data: 'action', orderable: false, searchable: false },
    ],
    buttons: [{ extend: 'csv', className: 'btn btn-sm btn-outline-secondary' }],
});

$('#saveCampaign').on('click', function() {
    $.post('{{ route("campaigns.store") }}', {
        name: $('#cName').val(), lead_source_id: $('#cSource').val(),
        platform: $('#cPlatform').val(), center_id: $('#cCenter').val(),
        budget: $('#cBudget').val(), start_date: $('#cStart').val(),
        end_date: $('#cEnd').val(), utm_source: $('#cUtmSource').val(),
        utm_medium: $('#cUtmMedium').val(), utm_campaign: $('#cUtmCampaign').val(),
    }).done(r => {
        if (r.success) { toastr.success('Campaign created.'); bootstrap.Modal.getInstance($('#addCampaignModal')[0]).hide(); table.ajax.reload(); }
    });
});
</script>
@endpush
