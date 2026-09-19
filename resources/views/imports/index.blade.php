@extends('layouts.app')

@section('title', 'Import History')

@section('content')
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Import History</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('imports.sample') }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-download me-1"></i> Sample CSV
            </a>
            @can('lead_imports.create')
            <a href="{{ route('imports.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-upload me-1"></i> New Import
            </a>
            @endcan
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>File</th>
                            <th>By</th>
                            <th>Center</th>
                            <th>Status</th>
                            <th class="text-center">Total</th>
                            <th class="text-center text-success">Imported</th>
                            <th class="text-center text-warning">Dupes</th>
                            <th class="text-center text-danger">Failed</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($imports as $imp)
                        <tr>
                            <td class="text-muted small">{{ $imp->id }}</td>
                            <td>
                                <span class="text-truncate d-inline-block" style="max-width:180px" title="{{ $imp->original_filename }}">
                                    {{ $imp->original_filename }}
                                </span>
                            </td>
                            <td>{{ $imp->user?->name ?? '—' }}</td>
                            <td>{{ $imp->center?->name ?? '—' }}</td>
                            <td>
                                @php
                                    $badge = match($imp->status) {
                                        'completed'  => 'bg-success',
                                        'processing' => 'bg-warning text-dark',
                                        'failed'     => 'bg-danger',
                                        default      => 'bg-secondary',
                                    };
                                @endphp
                                <span class="badge {{ $badge }}">{{ ucfirst($imp->status) }}</span>
                            </td>
                            <td class="text-center">{{ $imp->total_rows ?? '—' }}</td>
                            <td class="text-center fw-semibold text-success">{{ $imp->imported_rows ?? '—' }}</td>
                            <td class="text-center text-warning">{{ $imp->duplicate_rows ?? '—' }}</td>
                            <td class="text-center {{ ($imp->failed_rows ?? 0) > 0 ? 'fw-semibold text-danger' : '' }}">
                                {{ $imp->failed_rows ?? '—' }}
                            </td>
                            <td class="small text-muted">{{ $imp->created_at->format('d-m-Y h:i A') }}</td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="{{ route('imports.show', $imp) }}" class="btn btn-xs btn-outline-primary" title="View details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if(($imp->failed_rows ?? 0) > 0)
                                    <a href="{{ route('imports.errors', $imp) }}" class="btn btn-xs btn-outline-danger" title="Download error CSV">
                                        <i class="bi bi-file-earmark-excel"></i> Error CSV
                                    </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="11" class="text-center py-4 text-muted">
                                No imports yet. <a href="{{ route('imports.create') }}">Upload your first CSV</a>.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($imports->hasPages())
        <div class="card-footer d-flex justify-content-end">
            {{ $imports->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
