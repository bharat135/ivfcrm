@extends('layouts.app')

@section('title', 'Lead Report')

@section('breadcrumb')
    <li class="breadcrumb-item active">Lead Report</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Lead Report</h5>
    <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-outline-success btn-sm">
        <i class="bi bi-filetype-csv me-1"></i>Export CSV
    </a>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <form method="GET">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">From</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="{{ $filters['from'] ?? now()->startOfMonth()->toDateString() }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="{{ $filters['to'] ?? today()->toDateString() }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Source</label>
                    <select name="source_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach($sources as $s)
                            <option value="{{ $s->id }}" {{ ($filters['source_id'] ?? '') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Center</label>
                    <select name="center_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach($centers as $c)
                            <option value="{{ $c->id }}" {{ ($filters['center_id'] ?? '') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="new" {{ ($filters['status'] ?? '') == 'new' ? 'selected' : '' }}>New</option>
                        <option value="working" {{ ($filters['status'] ?? '') == 'working' ? 'selected' : '' }}>Working</option>
                        <option value="won" {{ ($filters['status'] ?? '') == 'won' ? 'selected' : '' }}>Won</option>
                        <option value="lost" {{ ($filters['status'] ?? '') == 'lost' ? 'selected' : '' }}>Lost</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">Apply Filters</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Summary Stats -->
<div class="row g-2 mb-3">
    @php $total = $leads->total(); $won = $leads->where('status','won')->count(); @endphp
    <div class="col-6 col-md-2">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fw-bold fs-4">{{ number_format($total) }}</div>
            <div class="text-muted small">Total Leads</div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>Name</th><th>Mobile</th><th>Email</th>
                        <th>Source</th><th>Stage</th><th>Status</th>
                        <th>Assigned To</th><th>Center</th><th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($leads as $lead)
                    <tr>
                        <td>{{ $lead->id }}</td>
                        <td><a href="{{ route('leads.show', $lead) }}" class="text-decoration-none">{{ $lead->name ?? '—' }}</a></td>
                        <td>{{ \App\Support\PhoneNumber::display($lead->mobile) }}</td>
                        <td>{{ $lead->email ?? '—' }}</td>
                        <td>{{ $lead->source?->name ?? '—' }}</td>
                        <td>
                            @if($lead->stage)
                                <span class="badge" style="background:{{ $lead->stage->color }}">{{ $lead->stage->name }}</span>
                            @else —
                            @endif
                        </td>
                        <td><span class="badge bg-secondary">{{ ucfirst($lead->status) }}</span></td>
                        <td>{{ $lead->assignedTo?->name ?? '—' }}</td>
                        <td>{{ $lead->center?->name ?? '—' }}</td>
                        <td>{{ $lead->created_at->format('d-m-Y') }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="text-center py-4 text-muted">No leads found for the selected filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $leads->withQueryString()->links() }}</div>
    </div>
</div>
@endsection
