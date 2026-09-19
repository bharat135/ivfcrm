@extends('layouts.app')

@section('title', 'Telecaller Report')

@section('breadcrumb')
    <li class="breadcrumb-item active">Telecaller Report</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Telecaller Performance Report</h5>
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
                        <th>Telecaller</th><th>Total Leads</th><th>Total Calls</th>
                        <th>Connected</th><th>Connection Rate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data as $row)
                    <tr>
                        <td class="fw-semibold">{{ $row->name }}</td>
                        <td>{{ $row->total_leads }}</td>
                        <td>{{ $row->total_calls }}</td>
                        <td>{{ $row->connected_calls }}</td>
                        <td>
                            @php $rate = $row->total_calls > 0 ? round($row->connected_calls / $row->total_calls * 100) : 0; @endphp
                            <div class="progress" style="height:18px">
                                <div class="progress-bar bg-success" style="width:{{ $rate }}%">{{ $rate }}%</div>
                            </div>
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
