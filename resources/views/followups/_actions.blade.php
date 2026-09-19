<div class="d-flex gap-1">
    @if($followUp->status === 'pending')
    <button class="btn btn-xs btn-success" onclick="completeFU({{ $followUp->id }})" title="Mark Done">
        <i class="bi bi-check"></i>
    </button>
    <button class="btn btn-xs btn-outline-secondary" onclick="cancelFU({{ $followUp->id }})" title="Cancel">
        <i class="bi bi-x"></i>
    </button>
    @endif
    <a href="/leads/{{ $followUp->lead_id }}" class="btn btn-xs btn-outline-primary" title="View Lead">
        <i class="bi bi-arrow-right"></i>
    </a>
</div>
