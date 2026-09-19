@extends('layouts.app')

@section('title', 'Import #{{ $import->id }} Results')

@section('content')
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0">Import Results</h4>
            <p class="text-muted small mb-0">{{ $import->original_filename }} · {{ $import->created_at->format('d-m-Y h:i A') }}</p>
        </div>
        <div class="d-flex gap-2">
            @if(($import->failed_rows ?? 0) > 0)
            <a href="{{ route('imports.errors', $import) }}" class="btn btn-sm btn-danger">
                <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Error CSV
                <span class="badge bg-white text-danger ms-1">{{ $import->failed_rows }}</span>
            </a>
            @endif
            @can('lead_imports.create')
            <a href="{{ route('imports.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-upload me-1"></i> New Import
            </a>
            @endcan
            <a href="{{ route('imports.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> History
            </a>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="fs-2 fw-bold">{{ $import->total_rows ?? 0 }}</div>
                    <div class="text-muted small">Total Rows</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center border-start border-success border-3">
                <div class="card-body py-3">
                    <div class="fs-2 fw-bold text-success">{{ $import->imported_rows ?? 0 }}</div>
                    <div class="text-muted small">Imported</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center border-start border-warning border-3">
                <div class="card-body py-3">
                    <div class="fs-2 fw-bold text-warning">{{ $import->duplicate_rows ?? 0 }}</div>
                    <div class="text-muted small">Duplicates</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center border-start border-danger border-3">
                <div class="card-body py-3">
                    <div class="fs-2 fw-bold text-danger">{{ $import->failed_rows ?? 0 }}</div>
                    <div class="text-muted small">Failed</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Error rows --}}
    @if($errorRows->count() > 0)
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center py-2">
            <strong class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Failed Rows</strong>
            <a href="{{ route('imports.errors', $import) }}" class="btn btn-sm btn-danger">
                <i class="bi bi-file-earmark-arrow-down me-1"></i> Download Error CSV to Fix &amp; Re-upload
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height:480px;overflow-y:auto;">
                <table class="table table-sm table-bordered table-hover align-top mb-0">
                    <thead class="table-danger sticky-top">
                        <tr>
                            <th style="width:60px">Row #</th>
                            <th>Error Reason</th>
                            <th>Row Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($errorRows as $err)
                        <tr>
                            <td class="text-center fw-semibold">{{ $err->row_number }}</td>
                            <td class="text-danger small fw-semibold">{{ $err->error }}</td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach($err->row_data ?? [] as $col => $val)
                                        @if($val !== null && $val !== '')
                                        <span class="badge bg-light text-dark border small">
                                            <span class="text-muted">{{ $col }}:</span> {{ $val }}
                                        </span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @if($errorRows->hasPages())
        <div class="card-footer d-flex justify-content-end py-2">
            {{ $errorRows->links() }}
        </div>
        @endif
    </div>

    <div class="alert alert-info mt-3 small">
        <i class="bi bi-lightbulb me-1"></i>
        <strong>How to fix:</strong> Download the Error CSV above — it contains only the failed rows with an
        <code>error_reason</code> column explaining each failure. Fix the data, remove the <code>error_reason</code>
        column, and re-upload the file.
    </div>
    @else
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-1"></i> All rows were processed without errors.
    </div>
    @endif

</div>
@endsection
