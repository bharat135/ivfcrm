{{--
    The one funnel, rendered identically on the enquiry screen and the patient
    screen — they are the same person, so they must never show two different
    positions. Expects $patient (nullable) and optionally $compact.
--}}
@php
    use App\Support\PatientJourney;

    $current = $patient?->journey_stage ?: PatientJourney::ENQUIRY;
    $reached = PatientJourney::rank($current);
    $isLost  = $current === PatientJourney::LOST;
    $compact = $compact ?? false;
@endphp

<ol class="journey-stepper mb-0 {{ $compact ? 'compact' : '' }}">
    @foreach(PatientJourney::funnel() as $stage)
        <li class="{{ PatientJourney::rank($stage) <= $reached && ! $isLost ? 'done' : '' }} {{ $stage === $current ? 'current' : '' }}">
            <span class="dot"><i class="bi {{ PatientJourney::icon($stage) }}"></i></span>
            <span class="label">{{ PatientJourney::label($stage) }}</span>
        </li>
    @endforeach
    @if($isLost)
        <li class="current lost">
            <span class="dot"><i class="bi bi-x-circle"></i></span>
            <span class="label">Lost</span>
        </li>
    @endif
</ol>
