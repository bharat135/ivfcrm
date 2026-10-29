{{-- The Smartflo extension a user is rung on, with a button to change or unlink it. --}}
@php
    $agent = $user->tataAgent;
    $label = $agent
        ? ($agent->extension ?: $agent->agent_id ?: $agent->follow_me_number)
        : $user->tata_agent_number;
@endphp
<div class="d-flex align-items-center gap-2">
    @if($label)
        <span class="font-monospace small" title="{{ $agent?->name }}">{{ $label }}</span>
    @else
        <span class="text-muted small">Not linked</span>
    @endif
    @can('users.edit')
        <button class="btn btn-xs btn-outline-secondary btn-smartflo-user" title="Change or unlink Smartflo extension"
                data-id="{{ $user->id }}" data-name="{{ $user->name }}" data-agent="{{ $agent?->id }}" data-number="{{ $user->tata_agent_number }}">
            <i class="bi bi-telephone-forward"></i>
        </button>
    @endcan
</div>
