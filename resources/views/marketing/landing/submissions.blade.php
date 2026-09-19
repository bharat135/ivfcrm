@extends('layouts.app')

@section('title', 'Submissions — ' . $page->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('marketing.landing.index') }}">Landing Pages</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($page->name, 30) }} — Submissions</li>
@endsection

@section('content')

<div class="row g-3 mb-3">
    @foreach([
        ['Views',        number_format($page->views),          'bi-eye',          'info'],
        ['Submissions',  number_format($page->submissions),    'bi-inbox',        'primary'],
        ['Conversion',   $page->conversionRate() . '%',        'bi-graph-up',     'success'],
        ['Status',       $page->is_published ? 'Live' : 'Draft','bi-globe',       $page->is_published ? 'success' : 'secondary'],
    ] as [$label, $value, $icon, $color])
        <div class="col-6 col-lg-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="icon-box bg-{{ $color }}-subtle text-{{ $color }} mb-2"><i class="bi {{ $icon }}"></i></div>
                    <div class="h4 mb-0">{{ $value }}</div>
                    <small class="text-muted">{{ $label }}</small>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="fw-semibold me-auto">Form submissions</span>
        <a href="{{ $page->publicUrl() }}" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-box-arrow-up-right me-1"></i>View page
        </a>
        @can('landing_pages.edit')
            <a href="{{ route('marketing.landing.edit', $page) }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="subTable">
                <thead class="table-light">
                    <tr>
                        <th>Contact</th>
                        <th>CRM lead</th>
                        <th>Attribution</th>
                        <th>IP</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#subTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[4, 'desc']],
        ajax: @json(route('marketing.landing.submissions', $page)),
        columns: [
            { data: 'contact', orderable: false },
            { data: 'lead_link', orderable: false, searchable: false },
            { data: 'utm', orderable: false },
            { data: 'ip_address', orderable: false },
            { data: 'submitted', name: 'created_at' },
        ],
    });
});
</script>
@endpush
