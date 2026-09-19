@php $id = is_object($patient) ? $patient->id : $patient; @endphp
<div class="d-flex gap-1">
    <a href="{{ route('patients.show', $id) }}" class="btn btn-xs btn-outline-secondary" title="Open profile">
        <i class="bi bi-eye"></i>
    </a>
    {{-- The same profile, opened on the follow-up board. --}}
    <a href="{{ route('patients.show', ['patient' => $id, 'tab' => 'tasks']) }}"
       class="btn btn-xs btn-outline-secondary" title="Follow-ups">
        <i class="bi bi-check2-square"></i>
    </a>
    @if($patient->mobile)
        {{-- The inbox searches threads by number, so this lands on this person's
             conversation rather than opening a new one. --}}
        <a href="{{ route('chat.index', ['q' => \App\Support\PhoneNumber::canView() ? $patient->mobile : $patient->patient_code]) }}" class="btn btn-xs btn-outline-secondary" title="Messages">
            <i class="bi bi-whatsapp"></i>
        </a>
    @endif
</div>
