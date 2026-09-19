@extends('layouts.app')

@section('title', 'Pipeline')

@section('breadcrumb')
    <li class="breadcrumb-item active">Pipeline Kanban</li>
@endsection

@push('styles')
<style>
.kanban-board { display: flex; gap: 16px; overflow-x: auto; padding-bottom: 16px; }
.kanban-col { min-width: 240px; max-width: 260px; flex-shrink: 0; }
.kanban-col-header {
    border-radius: 8px 8px 0 0; padding: 10px 14px;
    font-weight: 600; font-size: .85rem; color: #fff;
}
.kanban-col-body {
    background: #f8f9fa; border: 1px solid #e5eaf2;
    border-top: none; border-radius: 0 0 8px 8px;
    min-height: 200px; padding: 8px;
    max-height: calc(100vh - 240px); overflow-y: auto;
}
.kanban-card {
    background: #fff; border-radius: 8px; padding: 10px 12px;
    margin-bottom: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08);
    cursor: pointer; transition: box-shadow .15s;
    font-size: .82rem;
}
.kanban-card:hover { box-shadow: 0 3px 12px rgba(0,0,0,.15); }
.kanban-card .lead-name { font-weight: 600; margin-bottom: 3px; }
.kanban-card .lead-mobile { color: #6c757d; font-size: .78rem; }
.kanban-count { background: rgba(255,255,255,.3); border-radius: 99px; padding: 2px 8px; font-size: .75rem; }
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Lead Pipeline</h5>
    <a href="{{ route('leads.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-table me-1"></i>Table View
    </a>
</div>

<div class="kanban-board">
    @foreach($kanban as $col)
    <div class="kanban-col">
        <div class="kanban-col-header" style="background: {{ $col['stage']->color ?? '#6c757d' }}">
            {{ $col['stage']->name }}
            <span class="kanban-count ms-1">{{ $col['count'] }}</span>
        </div>
        <div class="kanban-col-body" data-stage="{{ $col['stage']->id }}">
            @foreach($col['leads'] as $lead)
            <div class="kanban-card" onclick="window.location='/leads/{{ $lead['id'] }}'">
                <div class="lead-name">{{ $lead['name'] ?? '—' }}</div>
                <div class="lead-mobile">{{ $lead['mobile'] ?? '' }}</div>
                @if(!empty($lead['assigned_to']))
                <div class="mt-1">
                    <span class="badge bg-light text-dark border" style="font-size:.7rem">
                        {{ $lead['assigned_to']['name'] ?? 'Unassigned' }}
                    </span>
                </div>
                @endif
                @if(!empty($lead['score_band']))
                <span class="badge {{ $lead['score_band']==='hot'?'bg-danger':($lead['score_band']==='warm'?'bg-warning text-dark':'bg-info') }} mt-1" style="font-size:.68rem">
                    {{ strtoupper($lead['score_band']) }}
                </span>
                @endif
            </div>
            @endforeach
            @if($col['count'] === 0)
            <div class="text-center text-muted py-3" style="font-size:.8rem">
                <i class="bi bi-inbox d-block mb-1 fs-5"></i>Empty
            </div>
            @endif
        </div>
    </div>
    @endforeach
</div>
@endsection
