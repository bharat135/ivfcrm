@extends('layouts.app')

@section('title', 'Voice Broadcasts')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('tata.index') }}">Tata Teleservices</a></li>
    <li class="breadcrumb-item active">Broadcasts</li>
@endsection

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h5 class="mb-0">Voice Broadcasts</h5>
    @can('tata.create')<a href="{{ route('tata.broadcasts.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New broadcast</a>@endcan
</div>

@include('tata._nav')

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Name</th><th>Status</th><th>Leads</th><th>Caller ID</th><th>Window</th><th>Created by</th><th></th></tr></thead>
                <tbody>
                @forelse($broadcasts as $b)
                    <tr>
                        <td><a href="{{ route('tata.broadcasts.show', $b) }}" class="fw-semibold text-decoration-none">{{ $b->name }}</a><div class="small text-muted">{{ $b->description }}</div></td>
                        <td><span class="badge bg-{{ $b->statusBadge() }}-subtle text-{{ $b->statusBadge() }}">{{ ucfirst($b->status) }}</span></td>
                        <td>{{ $b->lead_count }}</td>
                        <td class="font-monospace small">{{ $b->caller_id }}</td>
                        <td class="small text-muted">{{ $b->scheduled_start_at?->format('d-m h:i A') }} – {{ $b->scheduled_end_at?->format('d-m h:i A') }}</td>
                        <td class="small">{{ $b->creator?->name }}</td>
                        <td class="text-end"><a href="{{ route('tata.broadcasts.show', $b) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No broadcasts yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="mt-3">{{ $broadcasts->links() }}</div>
@endsection
