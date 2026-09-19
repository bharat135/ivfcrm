@extends('layouts.app')

@section('title', 'Source Report')

@section('breadcrumb')
    <li class="breadcrumb-item active">Source Report</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Lead Source Performance Report</h5>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Center</label>
                <select name="center_id" class="form-select form-select-sm">
                    <option value="">All Centers</option>
                    @foreach($centers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Apply</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Source</th><th>Channel</th><th>Total Leads</th>
                        <th>Conversions</th><th>Conversion Rate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data as $source)
                    <tr>
                        <td class="fw-semibold">{{ $source->name }}</td>
                        <td><span class="badge bg-info text-dark">{{ $source->channel }}</span></td>
                        <td>{{ $source->total_leads }}</td>
                        <td>{{ $source->won_leads }}</td>
                        <td>
                            @php $rate = $source->total_leads > 0 ? round($source->won_leads / $source->total_leads * 100, 1) : 0; @endphp
                            <span class="badge {{ $rate >= 10 ? 'bg-success' : ($rate >= 5 ? 'bg-warning text-dark' : 'bg-secondary') }}">
                                {{ $rate }}%
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-center py-4 text-muted">No data for selected period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
