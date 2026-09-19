@php
    $names = array_filter([$p->wife_name, $p->husband_name]);
    $display = $names ? implode(' & ', $names) : 'Unknown';
    $initial = preg_match('/[A-Za-z]/', $display) ? strtoupper(substr($display, 0, 1)) : '#';
@endphp
<div class="d-flex align-items-center gap-2">
    <span class="person-avatar">{{ $initial }}</span>
    <div class="min-w-0">
        <a href="{{ route('patients.show', $p->id) }}" class="fw-semibold text-body d-block text-truncate">{{ $display }}</a>
        <div class="text-muted small d-flex align-items-center gap-2">
            <span>{{ $p->patient_code }}</span>
            @if($p->mobile)
                <span class="text-body-tertiary">·</span>
                <span>{{ \App\Support\PhoneNumber::display($p->mobile) }}</span>
            @endif
        </div>
    </div>
</div>
