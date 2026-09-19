@extends('layouts.app')

@section('title', $broadcast->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('marketing.broadcasts.index') }}">Broadcasts</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($broadcast->name, 40) }}</li>
@endsection

@section('content')

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="me-auto">
        <h5 class="mb-1">
            {{ $broadcast->name }}
            <span class="badge bg-{{ match($broadcast->status) {
                'completed' => 'success', 'running' => 'primary', 'scheduled' => 'info',
                'failed' => 'danger', 'cancelled' => 'secondary', default => 'secondary',
            } }}" id="statusBadge">{{ ucfirst($broadcast->status) }}</span>
        </h5>
        <small class="text-muted">
            Template: {{ $broadcast->template?->name ?? '—' }} ·
            Created by {{ $broadcast->creator?->name ?? 'system' }} {{ $broadcast->created_at->diffForHumans() }}
            @if($broadcast->scheduled_at) · scheduled for {{ $broadcast->scheduled_at->format('d-m-Y, g:i A') }} @endif
        </small>
    </div>

    @can('broadcasts.edit')
        @if(in_array($broadcast->status, ['scheduled', 'running']))
            <button class="btn btn-sm btn-outline-warning" id="btnCancel"><i class="bi bi-x-octagon me-1"></i>Cancel</button>
        @endif
    @endcan
</div>

<div class="row g-3 mb-3">
    @foreach([
        ['Recipients', $broadcast->total_recipients,  'bi-people',         'secondary', 'total'],
        ['Sent',       $broadcast->sent_count,        'bi-send',           'primary',   'sent'],
        ['Delivered',  $broadcast->delivered_count,   'bi-check2-all',     'info',      'delivered'],
        ['Read',       $broadcast->read_count,        'bi-eye',            'success',   'read'],
        ['Failed',     $broadcast->failed_count,      'bi-exclamation-triangle', 'danger', 'failed'],
    ] as [$label, $value, $icon, $color, $key])
        <div class="col-6 col-lg">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="icon-box bg-{{ $color }}-subtle text-{{ $color }} mb-2"><i class="bi {{ $icon }}"></i></div>
                    <div class="h4 mb-0" data-stat="{{ $key }}">{{ number_format($value) }}</div>
                    <small class="text-muted">{{ $label }}</small>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between small mb-1">
            <span>Progress</span>
            <span id="progressLabel">{{ $broadcast->progressPercent() }}%</span>
        </div>
        <div class="progress" style="height:10px">
            <div class="progress-bar progress-bar-striped" id="progressBar"
                 style="width: {{ $broadcast->progressPercent() }}%"></div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">Recipients</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Contact</th><th>Mobile</th><th>Status</th><th>Sent at</th><th>Error</th></tr>
            </thead>
            <tbody>
                @forelse($recipients as $recipient)
                    <tr>
                        <td>
                            @if($recipient->lead)
                                <a href="{{ route('leads.show', $recipient->lead) }}">{{ $recipient->name ?: $recipient->lead->name }}</a>
                            @else
                                {{ $recipient->name ?: '—' }}
                            @endif
                        </td>
                        <td class="font-monospace small">{{ $recipient->mobile }}</td>
                        <td>
                            <span class="badge bg-{{ match($recipient->status) {
                                'read' => 'success', 'delivered' => 'info', 'sent' => 'primary',
                                'failed' => 'danger', 'skipped' => 'secondary', default => 'light text-dark',
                            } }}">{{ ucfirst($recipient->status) }}</span>
                        </td>
                        <td class="small text-muted">{{ $recipient->sent_at?->format('d-m, g:i A') ?: '—' }}</td>
                        <td class="small text-danger">{{ Str::limit($recipient->error, 60) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No recipients.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $recipients->links() }}</div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const status = @json($broadcast->status);

    // Poll only while there is something to watch.
    if (['running', 'scheduled'].includes(status)) {
        const timer = setInterval(() => {
            $.get(@json(route('marketing.broadcasts.progress', $broadcast))).done(res => {
                $('#progressBar').css('width', res.percent + '%');
                $('#progressLabel').text(res.percent + '%');
                $('[data-stat=sent]').text(res.sent.toLocaleString('en-IN'));
                $('[data-stat=delivered]').text(res.delivered.toLocaleString('en-IN'));
                $('[data-stat=read]').text(res.read.toLocaleString('en-IN'));
                $('[data-stat=failed]').text(res.failed.toLocaleString('en-IN'));
                $('#statusBadge').text(res.status.charAt(0).toUpperCase() + res.status.slice(1));

                if (!['running', 'scheduled'].includes(res.status)) {
                    clearInterval(timer);
                    setTimeout(() => location.reload(), 1500);
                }
            });
        }, 5000);
    }

    $('#btnCancel').on('click', function () {
        $.post(@json(route('marketing.broadcasts.cancel', $broadcast)))
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 800); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Cancel failed.'));
    });
});
</script>
@endpush
