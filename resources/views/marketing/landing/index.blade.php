@extends('layouts.app')

@section('title', 'Landing Pages')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">Landing Pages</li>
@endsection

@section('content')

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-window-desktop me-2"></i>Landing Pages</span>

        <select class="form-select form-select-sm w-auto" id="fPublished">
            <option value="">Published and drafts</option>
            <option value="1">Published</option>
            <option value="0">Draft</option>
        </select>

        <select class="form-select form-select-sm w-auto" id="fCampaign">
            <option value="">All campaigns</option>
            @foreach($campaigns as $campaign)
                <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" id="fCenter">
            <option value="">All centres</option>
            @foreach($centers as $center)
                <option value="{{ $center->id }}">{{ $center->name }}</option>
            @endforeach
        </select>

        <button class="btn btn-sm btn-link text-decoration-none" id="fReset" title="Clear filters"><i class="bi bi-x-circle"></i></button>

        @can('landing_pages.create')
            <a href="{{ route('marketing.landing.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New page
            </a>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="pageTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>URL</th>
                        <th>Status</th>
                        <th>Campaign</th>
                        <th class="text-end">Views</th>
                        <th class="text-end">Submissions</th>
                        <th class="text-end">Conv.</th>
                        <th style="width:200px">Actions</th>
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
    const table = $('#pageTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[0, 'desc']],
        ajax: {
            url: @json(route('marketing.landing.index')),
            data: d => {
                d.published   = $('#fPublished').val();
                d.campaign_id = $('#fCampaign').val();
                d.center_id   = $('#fCenter').val();
            },
        },
        columns: [
            { data: 'name' },
            { data: 'url', orderable: false },
            { data: 'status_badge', orderable: false },
            { data: 'campaign.name', defaultContent: '—' },
            { data: 'views', className: 'text-end' },
            { data: 'submissions', className: 'text-end' },
            { data: 'conversion', className: 'text-end', orderable: false },
            { data: 'action', orderable: false, searchable: false },
        ],
    });

    $('#fPublished, #fCampaign, #fCenter').on('change', () => table.ajax.reload());
    $('#fReset').on('click', () => { $('#fPublished, #fCampaign, #fCenter').val(''); table.ajax.reload(); });

    $(document).on('click', '.js-publish', function () {
        const id = $(this).data('id');

        $.post(`{{ url('marketing/landing-pages') }}/${id}/publish`)
            .done(res => { toastr.success(res.message); table.ajax.reload(null, false); })
            .fail(() => toastr.error('Could not change the publish state.'));
    });

    $(document).on('click', '.js-duplicate', function () {
        const id = $(this).data('id');

        $.post(`{{ url('marketing/landing-pages') }}/${id}/duplicate`)
            .done(res => { toastr.success(res.message); location.href = res.redirect; })
            .fail(() => toastr.error('Duplicate failed.'));
    });

    $(document).on('click', '.js-delete', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Delete this landing page?',
            text: 'Leads already captured through it are kept.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('marketing/landing-pages') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); table.ajax.reload(); })
                .fail(() => toastr.error('Delete failed.'));
        });
    });
});
</script>
@endpush
