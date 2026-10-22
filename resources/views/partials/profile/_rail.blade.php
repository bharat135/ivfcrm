{{--
    The detail rail.

    The facts that stay true whichever tab is open: who this person is, where
    they came from, and what is currently being sold to or done for them.
    Expects $patient and $lead (nullable).
--}}
@php
    use App\Support\AcquisitionChannel;

    $channel = $lead?->channel ?: 'manual';
    $clickId = $lead?->clickIdentifier();
    $centre  = $patient->center;

    // A deterministic colour per centre, so the mark reads as an identity
    // rather than decoration.
    $palette = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6'];
    $mark    = $palette[($centre?->id ?? 0) % count($palette)];

    $paidPercent = $cycle && $cycle->package_amount > 0
        ? round(($cycle->paid_amount / $cycle->package_amount) * 100)
        : null;
@endphp

<div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="rail-title">Patient details</h6>
    @can('patients.edit')
        <div class="dropdown">
            <button class="btn btn-xs btn-light" data-bs-toggle="dropdown" aria-label="More">
                <i class="bi bi-three-dots"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#tabNotes" data-bs-toggle="tab">Edit clinical details</a></li>
                @if($lead)
                    <li><a class="dropdown-item" href="{{ route('leads.edit', $lead) }}">Edit contact details</a></li>
                @endif
            </ul>
        </div>
    @endcan
</div>

<div class="rail-row">
    <span class="rail-label"><i class="bi bi-person-vcard"></i>Name</span>
    @if($lead && auth()->user()->can('update', $lead))
        <span class="rail-value d-inline-flex align-items-center justify-content-end gap-1 js-name-wrap">
            <span class="js-name-text text-truncate" title="{{ $lead->name }}">{{ $lead->name ?: '—' }}</span>
            <button type="button" class="btn btn-xs btn-light js-name-edit" title="Edit name" aria-label="Edit name"><i class="bi bi-pencil"></i></button>
            <input type="text" class="form-control form-control-sm d-none js-name-input" value="{{ $lead->name }}" maxlength="191"
                   data-url="{{ route('leads.details', $lead) }}" aria-label="Patient name" style="max-width: 170px">
        </span>
    @else
        <span class="rail-value">{{ $patient->coupleName() }}</span>
    @endif
</div>

<div class="rail-row">
    <span class="rail-label"><i class="bi bi-hash"></i>Patient ID</span>
    <span class="rail-value">{{ $patient->patient_code }}</span>
</div>

<div class="rail-row">
    <span class="rail-label"><i class="bi bi-building"></i>Centre</span>
    <span class="rail-value d-inline-flex align-items-center justify-content-end gap-2">
        @if($lead && auth()->user()->can('update', $lead))
            {{-- The assigned centre, shown selected for everyone who opens the
                 record; change it here and the patient file follows. --}}
            <select class="form-select form-select-sm js-lead-center" data-url="{{ route('leads.center', $lead) }}" aria-label="Centre" style="max-width: 170px; display: inline-block">
                <option value="" disabled @selected(! $lead->center_id)>Not assigned</option>
                @foreach(\App\Models\Center::where('is_active', true)->orderBy('name')->get(['id', 'name']) as $c)
                    <option value="{{ $c->id }}" @selected($lead->center_id == $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        @elseif($centre)
            <span class="rail-mark" style="background: {{ $mark }}">{{ strtoupper(substr($centre->name, 0, 1)) }}</span>
            <span class="text-truncate">{{ $centre->name }}</span>
        @else
            <span class="text-muted">Not assigned</span>
        @endif
    </span>
</div>

<div class="rail-row">
    <span class="rail-label"><i class="bi bi-clipboard-pulse"></i>Diagnosis</span>
    <span class="rail-value">{{ $patient->diagnosis ?: '—' }}</span>
</div>

<div class="rail-divider"></div>

<div class="rail-row">
    <span class="rail-label"><i class="bi bi-envelope"></i>Email</span>
    <span class="rail-value">
        @if($patient->email)
            <a href="mailto:{{ $patient->email }}">{{ $patient->email }}</a>
        @else
            <span class="text-muted">—</span>
        @endif
    </span>
</div>

<div class="rail-row">
    <span class="rail-label"><i class="bi bi-telephone"></i>Phone</span>
    <span class="rail-value">
        @if($patient->mobile)
            @if(\App\Support\PhoneNumber::canView())
                <a href="tel:{{ $patient->mobile }}">{{ $patient->mobile }}</a>
            @else
                <span title="Number hidden — use the Call button">{{ \App\Support\PhoneNumber::mask($patient->mobile) }}</span>
            @endif
        @else
            <span class="text-muted">—</span>
        @endif
    </span>
</div>

<div class="rail-divider"></div>

<div class="rail-row">
    <span class="rail-label"><i class="bi bi-signpost-split"></i>Stage</span>
    <span class="rail-value">
        <span class="badge bg-{{ $patient->stageColor() }}-subtle text-{{ $patient->stageColor() }}">{{ $patient->stageLabel() }}</span>
    </span>
</div>

<div class="rail-row">
    <span class="rail-label"><i class="bi bi-person"></i>Owner</span>
    <span class="rail-value d-inline-flex align-items-center justify-content-end gap-2">
        @if($lead?->assignedTo)
            <span class="fui-avatar" style="width:20px;height:20px;border-radius:999px;font-size:9px">
                {{ strtoupper(substr($lead->assignedTo->name, 0, 2)) }}
            </span>
            <span class="text-truncate">{{ $lead->assignedTo->name }}</span>
        @else
            <span class="text-muted">Unassigned</span>
        @endif
    </span>
</div>

@if($lead && auth()->user()->can('update', $lead))
    @push('scripts')
    <script>
    $(document).on('change', '.js-lead-center', function () {
        const $s = $(this).prop('disabled', true);
        $.ajax({ url: $s.data('url'), method: 'PATCH', data: { _token: '{{ csrf_token() }}', center_id: $s.val() } })
            .done(res => { toastr.success(res.message); setTimeout(() => window.location.reload(), 600); })
            .fail(xhr => { toastr.error(xhr.responseJSON?.message || 'Could not change the centre.'); $s.prop('disabled', false); });
    });

    // Name, edited in place: pencil opens the box, Enter or leaving it saves, Escape cancels.
    function closeNameEdit($input) {
        const $w = $input.closest('.js-name-wrap');
        $input.addClass('d-none');
        $w.find('.js-name-text, .js-name-edit').removeClass('d-none');
    }
    $(document).on('click', '.js-name-edit', function () {
        const $w = $(this).closest('.js-name-wrap');
        $w.find('.js-name-text, .js-name-edit').addClass('d-none');
        $w.find('.js-name-input').removeClass('d-none').trigger('focus');
    });
    $(document).on('keydown', '.js-name-input', function (e) {
        if (e.key === 'Escape') { $(this).val($(this).closest('.js-name-wrap').find('.js-name-text').text().trim()); closeNameEdit($(this)); }
        if (e.key === 'Enter')  { e.preventDefault(); $(this).trigger('blur'); }
    });
    $(document).on('blur', '.js-name-input', function () {
        const $i = $(this);
        const name = $i.val().trim();
        if (!name || name === $i.closest('.js-name-wrap').find('.js-name-text').text().trim()) { closeNameEdit($i); return; }
        $i.prop('disabled', true);
        $.ajax({ url: $i.data('url'), method: 'PATCH', data: { _token: '{{ csrf_token() }}', name } })
            .done(res => { toastr.success(res.message); setTimeout(() => window.location.reload(), 500); })
            .fail(xhr => { toastr.error(xhr.responseJSON?.message || 'Could not save the name.'); $i.prop('disabled', false); });
    });
    </script>
    @endpush
@endif

@php
    $couple = array_filter([
        'Wife'    => $patient->wife_age_years !== null ? $patient->wife_age_years . ' yrs' : null,
        'Husband' => $patient->husband_age_years !== null ? $patient->husband_age_years . ' yrs' : null,
        'Married' => $patient->years_married !== null ? $patient->years_married . ' yrs' : null,
        'Trying'  => $patient->years_trying !== null ? $patient->years_trying . ' yrs' : null,
    ]);
@endphp
@if($couple)
    <div class="rail-divider"></div>
    {{-- Ages are derived from stored dates, so they keep counting up on their own. --}}
    @foreach($couple as $label => $value)
        <div class="rail-row">
            <span class="rail-label"><i class="bi {{ $label === 'Married' ? 'bi-heart' : ($label === 'Trying' ? 'bi-hourglass-split' : 'bi-person') }}"></i>{{ $label }}</span>
            <span class="rail-value">{{ $value }}</span>
        </div>
    @endforeach
@endif

<div class="rail-divider"></div>

{{-- Attribution. The whole reason the enquiry and the patient are one record:
     a treatment cycle can be traced back to the ad that paid for the click. --}}
<div class="rail-row">
    <span class="rail-label"><i class="bi bi-megaphone"></i>Came from</span>
    <span class="rail-value d-inline-flex align-items-center justify-content-end gap-2">
        <i class="bi {{ AcquisitionChannel::icon($channel) }}" style="color: {{ AcquisitionChannel::color($channel) }}"></i>
        <span class="text-truncate">{{ AcquisitionChannel::label($channel) }}</span>
    </span>
</div>

@if($lead?->campaign)
    <div class="rail-row">
        <span class="rail-label"><i class="bi bi-bullseye"></i>Campaign</span>
        <span class="rail-value" title="{{ $lead->campaign->name }}">{{ $lead->campaign->name }}</span>
    </div>
@endif

@if($lead?->utm_term)
    <div class="rail-row">
        <span class="rail-label"><i class="bi bi-search"></i>Keyword</span>
        <span class="rail-value" title="{{ $lead->utm_term }}">{{ $lead->utm_term }}</span>
    </div>
@endif

@if($clickId)
    <div class="rail-row">
        <span class="rail-label"><i class="bi bi-cursor"></i>{{ $clickId['key'] }}</span>
        <span class="rail-value"><code class="small">{{ $clickId['value'] }}</code></span>
    </div>
@endif

<div class="rail-row">
    <span class="rail-label"><i class="bi bi-calendar-event"></i>First enquiry</span>
    <span class="rail-value">{{ $patient->first_enquiry_at?->format('d-m-Y') ?: '—' }}</span>
</div>

{{-- ------------------------------------------------------------------ --}}
{{-- Treatment: what is actually being delivered, and what it is worth.  --}}
{{-- ------------------------------------------------------------------ --}}
<h6 class="rail-title mt-4 mb-2">Treatment</h6>

@if($cycle)
    <div class="rail-card">
        <div class="rail-card-title">{{ $cycle->type ?: 'Cycle' }} · {{ $cycle->cycle_code }}</div>
        <dl class="rail-card-grid mb-3">
            <div>
                <dt>Package value</dt>
                <dd>{{ $cycle->package_amount ? '₹' . number_format($cycle->package_amount, 0) : '—' }}</dd>
            </div>
            <div>
                <dt>Stage</dt>
                <dd>{{ $cycle->current_stage ?: ucfirst($cycle->status ?? 'Ongoing') }}</dd>
            </div>
            <div>
                <dt>Started</dt>
                <dd>{{ $cycle->started_on?->format('Y-m-d') ?: '—' }}</dd>
            </div>
            <div>
                <dt>Paid</dt>
                <dd class="{{ $paidPercent === 100 ? 'text-success' : '' }}">
                    {{ $paidPercent !== null ? $paidPercent . '%' : '—' }}
                </dd>
            </div>
        </dl>
        <a href="#tabTreatment" data-bs-toggle="tab" class="btn btn-sm btn-light w-100">View details</a>
    </div>
@elseif($nextVisit)
    {{-- No cycle yet, but a visit is booked — that is the next thing that
         matters about this couple, so it takes the card instead. --}}
    <div class="rail-card">
        <div class="rail-card-title">Next appointment</div>
        <dl class="rail-card-grid mb-3">
            <div>
                <dt>When</dt>
                <dd>{{ $nextVisit->scheduled_at?->format('d-m, h:i A') }}</dd>
            </div>
            <div>
                <dt>Type</dt>
                <dd>{{ ucfirst($nextVisit->type ?? 'Consultation') }}</dd>
            </div>
            <div>
                <dt>Doctor</dt>
                <dd>{{ $nextVisit->doctor?->name ? 'Dr. ' . $nextVisit->doctor->name : '—' }}</dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd>{{ ucfirst($nextVisit->status ?? 'booked') }}</dd>
            </div>
        </dl>
        <a href="#tabAppointments" data-bs-toggle="tab" class="btn btn-sm btn-light w-100">View details</a>
    </div>
@else
    <div class="rail-card text-center">
        <p class="text-muted small mb-2">No treatment or appointment yet.</p>
        @can('appointments.create')
            <button type="button" class="btn btn-sm btn-light w-100" data-bs-toggle="modal" data-bs-target="#bookConsultModal">
                <i class="bi bi-calendar-plus me-1"></i>Book a consultation
            </button>
        @endcan
    </div>
@endif
