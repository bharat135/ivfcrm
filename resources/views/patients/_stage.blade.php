@php
    use App\Support\PatientJourney;
    $stage = $p->journey_stage ?: PatientJourney::ENQUIRY;
@endphp
<span class="badge rounded-pill text-bg-{{ PatientJourney::color($stage) }}">
    <i class="bi {{ PatientJourney::icon($stage) }} me-1"></i>{{ PatientJourney::label($stage) }}
</span>
